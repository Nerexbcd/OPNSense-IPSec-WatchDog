<?php

namespace OPNsense\IPsecWatchdog;

/**
 * Class Webhook
 * Shared "POST a JSON payload, optionally HMAC-signed" helper, used both by the scheduled
 * watchdog check (scripts/watchdog.php) and the settings page's "Test webhook" button
 * (Api\ServiceController::testwebhookAction) - one implementation of the actual HTTP call, so a
 * fix or behavior change only has to happen in one place. Lives here (rather than in scripts/)
 * so it autoloads the same way for both a CLI script and an MVC controller.
 * @package OPNsense\IPsecWatchdog
 */
class Webhook
{
    /**
     * POST a JSON payload to a webhook URL, optionally HMAC-signed. Always best-effort: a slow
     * or unreachable endpoint must never throw - the caller (a once-a-minute cron check, or a
     * user waiting on a "Test webhook" click) just gets ['ok' => false, ...] back.
     * @param string $url destination URL
     * @param string $secret if non-empty, adds an X-Watchdog-Signature: sha256=<hmac> header
     * @param array $payload will be json_encode()'d as the request body
     * @return array{ok: bool, http_code: int, error: string}
     */
    public static function send($url, $secret, array $payload)
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
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) {
                return ['ok' => false, 'http_code' => 0, 'error' => $err];
            }
            return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'error' => ''];
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
        $status_line = isset($http_response_header[0]) ? $http_response_header[0] : '';
        $code = 0;
        if (preg_match('/\s(\d{3})\s/', $status_line, $m)) {
            $code = (int)$m[1];
        }
        if ($resp === false) {
            return ['ok' => false, 'http_code' => $code, 'error' => 'request failed'];
        }
        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'error' => ''];
    }
}
