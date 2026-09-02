<?php

namespace OPNsense\IPsecWatchdog\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\IPsec\Swanctl;
use OPNsense\IPsecWatchdog\Webhook;

/**
 * Class ServiceController
 * Manual trigger + status endpoints for the IPsec watchdog.
 * @package OPNsense\IPsecWatchdog\Api
 */
class ServiceController extends ApiControllerBase
{
    /**
     * Send a one-off test payload to a webhook URL, so the "Test webhook" button can confirm a
     * URL/secret actually works without waiting for a real outage. Deliberately reads url/secret
     * straight from the POST body (whatever's currently typed in the form) rather than the saved
     * config, so it can be tried before hitting Save - runs directly in this API request (no
     * configd action involved), since sending an HTTP POST needs no elevated privilege.
     * @return array
     */
    public function testwebhookAction()
    {
        $url = trim((string)$this->request->getPost('url'));
        $secret = (string)$this->request->getPost('secret');
        if ($url === '') {
            return ['result' => 'failed', 'error' => 'No webhook URL provided.'];
        }
        $result = Webhook::send($url, $secret, [
            'event' => 'ipsec_watchdog_test',
            'message' => 'This is a test notification from OPNsense IPsec WatchDog.',
            'timestamp' => gmdate('c'),
        ]);
        return [
            'result' => $result['ok'] ? 'ok' : 'failed',
            'http_code' => $result['http_code'],
            'error' => $result['error'],
        ];
    }

    /**
     * Run one watchdog check/action cycle immediately (same code path the cron job uses).
     * @return array
     */
    public function runAction()
    {
        $backend = new Backend();
        $output = trim($backend->configdRun('ipsecwatchdog watchdog'));
        return ['result' => 'ok', 'output' => $output];
    }

    /**
     * Show "swanctl --list-sas" output for the configured connection, parsed into a per-tunnel
     * table (one row per child SA) so multiple tunnels are easy to scan, plus the raw text for
     * full detail. @return array
     */
    public function statusAction()
    {
        $backend = new Backend();
        $raw = trim($backend->configdRun('ipsecwatchdog status'));
        $daemonRunning = !$this->isDaemonUnreachable($raw);
        $rows = $daemonRunning ? $this->parseStatus($raw) : [];
        if (!empty($rows)) {
            [$connLabels, $childLabels] = $this->getDescriptionLabels();
            foreach ($rows as &$row) {
                $row['connection_label'] = $connLabels[$row['connection']] ?? $row['connection'];
                $row['child_label'] = $childLabels[$row['child']] ?? $row['child'];
            }
            unset($row);
        }
        return [
            'result' => 'ok',
            'output' => $raw,
            'daemon_running' => $daemonRunning,
            'rows' => $rows,
        ];
    }

    /**
     * Build [uuid => description] lookups from OPNsense's native IPsec (Swanctl) model, so real
     * connections/children - often auto-named with an opaque UUID - can be shown by their actual
     * description instead of that id. Degrades to empty maps (raw ids shown as-is) if that model
     * can't be loaded, e.g. on an OPNsense version where its internals have changed shape.
     * @return array{0: array<string,string>, 1: array<string,string>} [connection uuid => descr, child uuid => descr]
     */
    private function getDescriptionLabels()
    {
        $connLabels = [];
        $childLabels = [];
        try {
            $mdl = new Swanctl();
            foreach ($mdl->Connections->Connection->iterateItems() as $conn) {
                $uuid = $conn->getAttribute('uuid');
                $descr = trim((string)$conn->description);
                if (!empty($uuid) && $descr !== '') {
                    $connLabels[$uuid] = $descr;
                }
            }
            foreach ($mdl->children->child->iterateItems() as $child) {
                $uuid = $child->getAttribute('uuid');
                $descr = trim((string)$child->description);
                if (!empty($uuid) && $descr !== '') {
                    $childLabels[$uuid] = $descr;
                }
            }
        } catch (\Throwable $e) {
            // fall through with whatever was collected so far; raw ids remain usable as-is
        }
        return [$connLabels, $childLabels];
    }

    /**
     * Detect strongSwan's fixed "can't reach charon/vici" failure message, as opposed to a normal
     * (possibly empty) connection/SA listing. swanctl prints this plus its usage/help text on
     * stdout (merged from stderr, see actions.d) instead of any real data when that happens, which
     * would otherwise get misparsed into bogus entries like "usage" or "options".
     * @param string $raw raw swanctl output
     * @return bool
     */
    private function isDaemonUnreachable($raw)
    {
        return stripos((string)$raw, 'URI failed') !== false;
    }

    /**
     * Parse the human-readable output of "swanctl --list-sas" into a flat list of
     * [connection, ike_state, child, child_state] rows, one per child SA, so the GUI can render
     * a table instead of a raw text dump when several tunnels/SAs are active at once.
     * IKE/child SA states are strongSwan's own fixed vocabulary (ike_sa.h / child_sa.h), stable
     * across connection/child naming, same technique as parseConnections()'s mode detection.
     * @param string $raw raw "swanctl --list-sas" output
     * @return array
     */
    private function parseStatus($raw)
    {
        $result = [];
        $connection = null;
        $ikeState = null;
        $ikeStates = 'ESTABLISHING|ESTABLISHED|PASSIVE|REKEYING|REKEYED|DELETING|DESTROYING';
        $childStates = 'CREATED|ROUTED|INSTALLING|INSTALLED|UPDATING|REKEYING|REKEYED|RETRYING|DELETING|DELETED|' .
            'DESTROYING';
        $modes = 'TUNNEL|TRANSPORT|PASS|DROP|BEET';
        foreach (preg_split('/\r?\n/', (string)$raw) as $line) {
            if ($line === '') {
                continue;
            } elseif (
                $line[0] !== ' ' &&
                preg_match('/^([A-Za-z0-9\-_.]{1,64}):\s*#\d+,\s*(' . $ikeStates . ')\b/', $line, $m)
            ) {
                $connection = $m[1];
                $ikeState = $m[2];
            } elseif (
                $connection !== null &&
                preg_match(
                    '/^  ([A-Za-z0-9\-_.]{1,64}):\s*#\d+,.*?,\s*(' . $childStates . '),\s*(' . $modes . ')\b/',
                    $line,
                    $m
                )
            ) {
                $result[] = [
                    'connection' => $connection,
                    'ike_state' => $ikeState,
                    'child' => $m[1],
                    'child_state' => $m[2],
                ];
            }
        }
        return $result;
    }
}
