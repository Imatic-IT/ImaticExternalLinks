<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * SSRF guard. Fail-closed allow-list of origins the server-side proxy may call.
 *
 * A URL is permitted only when ALL hold:
 *   - scheme is http/https,
 *   - it carries no userinfo (no user:pass@host),
 *   - its origin exactly matches a configured allowed origin,
 *   - its host is not a private/reserved/loopback IP literal or `localhost`.
 *
 * Limitation: this is a string-level check. It cannot see where a hostname
 * resolves, so DNS-rebinding / DNS-based SSRF must additionally be handled at
 * resolution time by the HTTP client (pin IP, re-validate after redirects).
 */
final class OriginAllowList
{
    /** @var array<string,bool> normalized origin => true */
    private $allowed;

    /** @param string[] $allowedOrigins raw origin/URL strings from config */
    public function __construct(array $allowedOrigins)
    {
        $map = [];
        foreach ($allowedOrigins as $entry) {
            $origin = UrlNormalizer::origin((string) $entry);
            if ($origin !== null) {
                $map[$origin] = true;
            }
        }
        $this->allowed = $map;
    }

    public function permits(string $url): bool
    {
        if (!UrlNormalizer::isHttp($url)) {
            return false;
        }
        if (UrlNormalizer::hasCredentials($url)) {
            return false;
        }
        $origin = UrlNormalizer::origin($url);
        if ($origin === null || !isset($this->allowed[$origin])) {
            return false;
        }
        $host = UrlNormalizer::host($url);
        if ($host === null || $host === '') {
            return false;
        }
        if ($this->isBlockedHost($host)) {
            return false;
        }
        return true;
    }

    private function isBlockedHost(string $host): bool
    {
        $h = strtolower($host);
        if ($h === 'localhost' || $h === 'localhost.localdomain' || substr($h, -6) === '.local') {
            return true;
        }
        // IPv6 literals arrive bracketed in a URL host — strip for validation.
        $ip = trim($h, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                // Valid IP but in a private/reserved/loopback range → block.
                return true;
            }
        }
        return false;
    }
}
