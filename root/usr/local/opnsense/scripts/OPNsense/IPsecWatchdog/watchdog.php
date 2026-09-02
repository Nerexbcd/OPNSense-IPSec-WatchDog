#!/usr/local/bin/php
<?php

/*
 * IPsec Tunnel Watchdog - checks every enabled tunnel (connection/child SA pair) configured
 * on the settings page and force-reconnects any that have been down longer than its own
 * configured threshold.
 *
 * Invoked via configd action "ipsecwatchdog watchdog" - see actions_ipsecwatchdog.conf.
 * Config comes from the plugin's own settings page (VPN > IPsec Watchdog), not CLI args, so a
 * single parameter-less cron job covers every configured tunnel.
 */

require_once 'config.inc';
require_once 'util.inc';

use OPNsense\Core\Config;
use OPNsense\IPsecWatchdog\IPsecWatchdog;
use OPNsense\IPsecWatchdog\Webhook;

openlog('ipsec-watchdog', LOG_PID, LOG_USER);

function ipsec_watchdog_log($msg)
{
    syslog(LOG_NOTICE, $msg);
    echo $msg . "\n";
}

/**
 * Load a tunnel's JSON state (down_since/attempts/notified), or a fresh default if there is none
 * yet (or it's unreadable/corrupt - never let a bad state file wedge the watchdog).
 */
function ipsec_watchdog_load_state($state_file)
{
    $default = ['down_since' => time(), 'attempts' => 0, 'notified' => false];
    if (!file_exists($state_file)) {
        return $default;
    }
    $decoded = json_decode((string)@file_get_contents($state_file), true);
    if (!is_array($decoded) || !isset($decoded['down_since'])) {
        return $default;
    }
    return $decoded + $default;
}

function ipsec_watchdog_save_state($state_file, $state)
{
    @file_put_contents($state_file, json_encode($state));
}

/**
 * Thin wrapper around Webhook::send() (shared with the "Test webhook" button) that also logs
 * the outcome - never lets a slow/unreachable endpoint hold up checking the rest of the tunnels.
 */
function ipsec_watchdog_notify($url, $secret, $payload, $log_prefix)
{
    $result = Webhook::send($url, $secret, $payload);
    if ($result['ok']) {
        ipsec_watchdog_log("$log_prefix webhook sent, HTTP {$result['http_code']}");
    } else {
        $detail = $result['error'] !== '' ? $result['error'] : "HTTP {$result['http_code']}";
        ipsec_watchdog_log("$log_prefix webhook failed: $detail");
    }
}

/**
 * Check and, if needed, reconnect a single tunnel. State (how long it's been down, how many
 * reconnect attempts made, whether the "still down" webhook already fired for this outage) is
 * tracked per connection+child pair, since two rows may share the same connection with different
 * children (or, less usefully, vice versa).
 */
function ipsec_watchdog_check_tunnel(
    $conn,
    $child,
    $threshold,
    $descr,
    $webhookUrl,
    $webhookAttempts,
    $webhookSecret,
    $notifyOnDown,
    $notifyOnStuck,
    $notifyOnUp
) {
    $state_key = preg_replace('/[^A-Za-z0-9_\-.]/', '_', "{$conn}_{$child}");
    $state_file = "/tmp/ipsec_watchdog_{$state_key}_state.json";

    // filter to this specific child SA rather than the whole IKE connection, so a down child
    // isn't masked by its parent IKE_SA still being ESTABLISHED (e.g. while it's rekeying)
    exec(
        '/usr/local/sbin/swanctl --list-sas --ike ' . escapeshellarg($conn) .
        ' --child ' . escapeshellarg($child) . ' 2>/dev/null',
        $out,
        $rc
    );
    $up = false;
    foreach ($out as $line) {
        if (strpos($line, 'INSTALLED') !== false) {
            $up = true;
            break;
        }
    }

    if ($up) {
        if (file_exists($state_file)) {
            // only a tunnel that was actually being tracked as down counts as a "recovery" -
            // a tunnel that's simply healthy every single check never hits this branch at all
            $state = ipsec_watchdog_load_state($state_file);
            @unlink($state_file);
            ipsec_watchdog_log("Tunnel $conn/$child is back up, cleared downtime tracker");
            if ($notifyOnUp && !empty($webhookUrl)) {
                ipsec_watchdog_notify($webhookUrl, $webhookSecret, [
                    'event' => 'ipsec_watchdog_up',
                    'connection' => $conn,
                    'child' => $child,
                    'description' => $descr,
                    'attempts' => $state['attempts'],
                    'timestamp' => gmdate('c'),
                ], "Tunnel $conn/$child recovery");
            }
        }
        return;
    }

    $now = time();
    $state = ipsec_watchdog_load_state($state_file);

    if (!file_exists($state_file)) {
        ipsec_watchdog_save_state($state_file, $state);
        ipsec_watchdog_log("Tunnel $conn/$child detected down, starting downtime tracker");
        // fires once per outage, same as the other two events: this branch (state file didn't
        // exist yet) only runs on the very first check that finds the tunnel down
        if ($notifyOnDown && !empty($webhookUrl)) {
            ipsec_watchdog_notify($webhookUrl, $webhookSecret, [
                'event' => 'ipsec_watchdog_down',
                'connection' => $conn,
                'child' => $child,
                'description' => $descr,
                'timestamp' => gmdate('c'),
            ], "Tunnel $conn/$child down-detected");
        }
        return;
    }

    $elapsed_min = intdiv($now - $state['down_since'], 60);

    ipsec_watchdog_log("Tunnel $conn/$child still down, elapsed {$elapsed_min} min (threshold {$threshold})");

    if ($elapsed_min >= $threshold) {
        ipsec_watchdog_log("Tunnel $conn/$child down for {$elapsed_min} min (>= {$threshold}), initiating");
        exec(
            '/usr/local/sbin/swanctl --initiate --ike ' . escapeshellarg($conn) .
            ' --child ' . escapeshellarg($child) . ' 2>&1',
            $out2,
            $rc2
        );
        ipsec_watchdog_log("swanctl --initiate ($conn/$child) exit code: $rc2 output: " . implode(' | ', $out2));

        // one "attempt" = one reconnect try that didn't clear the tunnel by the next check;
        // restart the per-attempt timer so the next attempt still waits a full $threshold
        $state['attempts']++;
        $state['down_since'] = $now;

        if ($notifyOnStuck && !$state['notified'] && !empty($webhookUrl) && $state['attempts'] >= $webhookAttempts) {
            ipsec_watchdog_notify($webhookUrl, $webhookSecret, [
                'event' => 'ipsec_watchdog_still_down',
                'connection' => $conn,
                'child' => $child,
                'description' => $descr,
                'attempts' => $state['attempts'],
                'threshold_minutes' => $threshold,
                'timestamp' => gmdate('c'),
            ], "Tunnel $conn/$child still-down (attempt {$state['attempts']})");
            $state['notified'] = true;
        }

        ipsec_watchdog_save_state($state_file, $state);
    }
}

$mdl = new IPsecWatchdog();

$webhookUrlGlobal = trim((string)$mdl->general->webhookUrl);
$webhookSecret = (string)$mdl->general->webhookSecret;
$webhookAttempts = (int)(string)$mdl->general->webhookAttempts;
if ($webhookAttempts < 1) {
    $webhookAttempts = 3;
}
$notifyOnDown = (string)$mdl->general->notifyOnDown === '1';
$notifyOnStuck = (string)$mdl->general->notifyOnStuck === '1';
$notifyOnUp = (string)$mdl->general->notifyOnUp === '1';

$checked = 0;
foreach ($mdl->tunnel->iterateItems() as $tunnel) {
    if ((string)$tunnel->enabled !== '1') {
        continue;
    }
    $conn = trim((string)$tunnel->connection);
    $child = trim((string)$tunnel->child);
    if (empty($conn) || empty($child)) {
        // shouldn't happen (both are required to save a row), but don't let one bad row
        // abort the rest
        ipsec_watchdog_log('ipsec-watchdog: skipping a tunnel row with no connection/child set');
        continue;
    }
    $threshold = (int)(string)$tunnel->threshold;
    if ($threshold < 1) {
        $threshold = 10;
    }
    // a tunnel's own webhook URL/attempts, if set, override the global ones independently -
    // e.g. a tunnel can keep the global URL but still alert sooner than the rest
    $webhookUrl = trim((string)$tunnel->webhookUrl);
    if ($webhookUrl === '') {
        $webhookUrl = $webhookUrlGlobal;
    }
    $tunnelWebhookAttempts = trim((string)$tunnel->webhookAttempts);
    $webhookAttemptsForTunnel = $tunnelWebhookAttempts !== '' ? (int)$tunnelWebhookAttempts : $webhookAttempts;
    if ($webhookAttemptsForTunnel < 1) {
        $webhookAttemptsForTunnel = $webhookAttempts;
    }
    ipsec_watchdog_check_tunnel(
        $conn,
        $child,
        $threshold,
        trim((string)$tunnel->descr),
        $webhookUrl,
        $webhookAttemptsForTunnel,
        $webhookSecret,
        $notifyOnDown,
        $notifyOnStuck,
        $notifyOnUp
    );
    $checked++;
}

if ($checked === 0) {
    ipsec_watchdog_log('ipsec-watchdog: no enabled tunnels configured, nothing to do');
}

exit(0);
