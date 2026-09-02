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
 * POST a JSON payload to a webhook URL, optionally HMAC-signed. Best-effort: a slow or
 * unreachable endpoint must never hold up checking the rest of the tunnels, hence the short
 * timeout, and any failure is only logged, never thrown.
 */
function ipsec_watchdog_send_webhook($url, $secret, $payload)
{
    $body = json_encode($payload);
    $headers = ['Content-Type: application/json'];
    if (!empty($secret)) {
        $headers[] = 'X-Watchdog-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            ipsec_watchdog_log("Webhook POST to $url failed: $err");
            return;
        }
        ipsec_watchdog_log("Webhook POST to $url returned HTTP $code");
        return;
    }

    // no curl extension available - fall back to a plain HTTP stream context
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $status_line = isset($http_response_header[0]) ? $http_response_header[0] : '(no response)';
    ipsec_watchdog_log("Webhook POST to $url (no curl extension) response: $status_line" . ($resp === false ? ' - request failed' : ''));
}

/**
 * Check and, if needed, reconnect a single tunnel. State (how long it's been down, how many
 * reconnect attempts made, whether a webhook already fired for this outage) is tracked per
 * connection+child pair, since two rows may share the same connection with different children
 * (or, less usefully, vice versa).
 */
function ipsec_watchdog_check_tunnel($conn, $child, $threshold, $descr, $webhookUrl, $webhookAttempts, $webhookSecret)
{
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
            @unlink($state_file);
            ipsec_watchdog_log("Tunnel $conn/$child is back up, cleared downtime tracker");
        }
        return;
    }

    $now = time();
    $state = ipsec_watchdog_load_state($state_file);

    if (!file_exists($state_file)) {
        ipsec_watchdog_save_state($state_file, $state);
        ipsec_watchdog_log("Tunnel $conn/$child detected down, starting downtime tracker");
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

        if (!$state['notified'] && !empty($webhookUrl) && $state['attempts'] >= $webhookAttempts) {
            ipsec_watchdog_log(
                "Tunnel $conn/$child still down after {$state['attempts']} attempts, sending webhook"
            );
            ipsec_watchdog_send_webhook($webhookUrl, $webhookSecret, [
                'event' => 'ipsec_watchdog_still_down',
                'connection' => $conn,
                'child' => $child,
                'description' => $descr,
                'attempts' => $state['attempts'],
                'threshold_minutes' => $threshold,
                'timestamp' => gmdate('c'),
            ]);
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
    // a tunnel's own webhook URL, if set, overrides the global one
    $webhookUrl = trim((string)$tunnel->webhookUrl);
    if ($webhookUrl === '') {
        $webhookUrl = $webhookUrlGlobal;
    }
    ipsec_watchdog_check_tunnel(
        $conn,
        $child,
        $threshold,
        trim((string)$tunnel->descr),
        $webhookUrl,
        $webhookAttempts,
        $webhookSecret
    );
    $checked++;
}

if ($checked === 0) {
    ipsec_watchdog_log('ipsec-watchdog: no enabled tunnels configured, nothing to do');
}

exit(0);
