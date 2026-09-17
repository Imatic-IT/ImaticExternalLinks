<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Contract\HttpClient;
use ImaticExternalLinks\Contract\HttpResponse;

/**
 * cURL-backed HttpClient with the operational safety the domain assumes:
 *
 *   - http/https only (CURLOPT_PROTOCOLS) — no file://, gopher://, etc.,
 *   - redirects NOT followed — a 3xx to an internal host cannot bypass the
 *     caller's origin allow-list; it simply surfaces as a non-2xx response,
 *   - TLS verification on,
 *   - connect + total timeouts,
 *   - response body capped (abort mid-download once the cap is exceeded).
 *
 * The origin allow-list (SSRF) is enforced by the caller before this runs; this
 * class adds the transport-level guarantees. On transport failure it throws
 * \RuntimeException per the HttpClient contract (callers fail soft).
 */
final class CurlHttpClient implements HttpClient
{
    /** @var int connect timeout (seconds) */
    private $connectTimeout;

    /** @var int total timeout (seconds) */
    private $timeout;

    /** @var int max bytes to read from the body */
    private $maxBytes;

    public function __construct(int $connectTimeout = 5, int $timeout = 10, int $maxBytes = 1048576)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout        = $timeout;
        $this->maxBytes       = $maxBytes;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available');
        }

        $t_handle = curl_init();
        if ($t_handle === false) {
            throw new \RuntimeException('Failed to initialise cURL');
        }

        $t_body    = '';
        $t_headers = [];

        $t_options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
            // Abort the transfer once we have read more than the cap.
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$t_body): int {
                $t_body .= $chunk;
                if (strlen($t_body) > $this->maxBytes) {
                    return 0; // returning < chunk length aborts the transfer
                }
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$t_headers): int {
                $t_parts = explode(':', $line, 2);
                if (count($t_parts) === 2) {
                    $t_headers[strtolower(trim($t_parts[0]))] = trim($t_parts[1]);
                }
                return strlen($line);
            },
        ];

        // Restrict protocols where the cURL build supports it (7.19.4+).
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $t_options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($t_handle, $t_options);

        $t_ok     = curl_exec($t_handle);
        $t_status = (int) curl_getinfo($t_handle, CURLINFO_RESPONSE_CODE);
        $t_errno  = curl_errno($t_handle);
        $t_error  = curl_error($t_handle);
        curl_close($t_handle);

        // A body-cap abort (CURLE_WRITE_ERROR) is not a real failure — we keep
        // what we read. Any other cURL error is a transport failure.
        if ($t_ok === false && $t_errno !== 0 && $t_errno !== CURLE_WRITE_ERROR) {
            throw new \RuntimeException('HTTP request failed: ' . $t_error, $t_errno);
        }

        if (strlen($t_body) > $this->maxBytes) {
            $t_body = substr($t_body, 0, $this->maxBytes);
        }

        return new HttpResponse($t_status, $t_body, $t_headers);
    }

    /**
     * @param array<string,string> $headers
     * @return string[]
     */
    private function formatHeaders(array $headers): array
    {
        $t_out = [];
        foreach ($headers as $t_name => $t_value) {
            $t_out[] = $t_name . ': ' . $t_value;
        }
        return $t_out;
    }
}
