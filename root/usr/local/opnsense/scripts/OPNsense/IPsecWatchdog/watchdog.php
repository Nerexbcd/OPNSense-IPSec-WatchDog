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
 * Check and, if needed, reconnect a single tunnel. State (how long it's been down) is tracked
 * per connection+child pair, since two rows may share the same connection with different
 * children (or, less usefully, vice versa).
 */
function ipsec_watchdog_check_tunnel($conn, $child, $threshold)
{
    $state_key = preg_replace('/[^A-Za-z0-9_\-.]/', '_', "{$conn}_{$child}");
    $state_file = "/tmp/ipsec_watchdog_{$state_key}_down_since";

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

    if (!file_exists($state_file)) {
        file_put_contents($state_file, (string)$now);
        ipsec_watchdog_log("Tunnel $conn/$child detected down, starting downtime tracker");
        return;
    }

    $down_since = (int)trim((string)@file_get_contents($state_file));
    $elapsed_min = intdiv($now - $down_since, 60);

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
        @unlink($state_file);
    }
}

$mdl = new IPsecWatchdog();

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
    ipsec_watchdog_check_tunnel($conn, $child, $threshold);
    $checked++;
}

if ($checked === 0) {
    ipsec_watchdog_log('ipsec-watchdog: no enabled tunnels configured, nothing to do');
}

exit(0);
