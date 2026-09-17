<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Pure URL inspection helpers. No I/O, no DNS — string-level only, so they are
 * fully unit-testable and safe to share between the SSRF guard and providers.
 */
final class UrlNormalizer
{
    private function __construct()
    {
    }

    /** Lower-cased scheme, or null if the URL cannot be parsed / has no scheme. */
    public static function scheme(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }
        return strtolower($parts['scheme']);
    }

    /** Lower-cased host, or null if absent / unparseable. */
    public static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }
        return strtolower($parts['host']);
    }

    /**
     * Canonical origin `scheme://host[:port]` (lower-cased), or null if scheme
     * or host is missing. The port is kept only when explicitly present.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin;
    }

    /** True only for http / https. */
    public static function isHttp(string $url): bool
    {
        $scheme = self::scheme($url);
        return $scheme === 'http' || $scheme === 'https';
    }

    /** True if the URL carries userinfo (user or user:pass@host) — a red flag. */
    public static function hasCredentials(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        return isset($parts['user']) || isset($parts['pass']);
    }
}
