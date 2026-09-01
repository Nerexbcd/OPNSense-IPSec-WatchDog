#!/usr/local/bin/php
<?php

/*
 * Registers a single "IPsec Tunnel Watchdog" cron job (every minute) the
 * first time this package is installed, so it works out of the box with no
 * manual cron step. Called once from the package's post-install script.
 *
 * Deliberately create-only, never remove/overwrite:
 * - Idempotent across upgrades: identifies "our" job via the cron model's
 *   own `origin` field (set to "ipsecwatchdog"); if one already exists this
 *   is a no-op, so `pkg upgrade` (which re-runs post-install) never creates
 *   a duplicate.
 * - Never auto-removed on uninstall, and never overwritten on upgrade, so a
 *   schedule the user has since customized in the GUI is never silently
 *   reset or wiped out from under them. The tradeoff: deleting the package
 *   entirely leaves this cron entry behind - see the README for how to
 *   remove it by hand if you're uninstalling for good.
 */

require_once 'config.inc';
require_once 'util.inc';

use OPNsense\Core\Config;
use OPNsense\Cron\Cron;

const CRON_ORIGIN = 'ipsecwatchdog';

$mdl = new Cron();

foreach ($mdl->jobs->job->iterateItems() as $job) {
    if ((string)$job->origin === CRON_ORIGIN) {
        // already registered (fresh install found none, or this is a
        // pkg upgrade re-running post-install) - leave it exactly as is
        exit(0);
    }
}

$job = $mdl->jobs->job->Add();
$job->origin = CRON_ORIGIN;
$job->enabled = '1';
$job->minutes = '*';
$job->hours = '*';
$job->days = '*';
$job->months = '*';
$job->weekdays = '*';
$job->who = 'root';
$job->command = 'ipsecwatchdog watchdog';
$job->description = 'IPsec Tunnel Watchdog (auto-added, runs every minute)';

$mdl->serializeToConfig();
Config::getInstance()->save();

exit(0);
