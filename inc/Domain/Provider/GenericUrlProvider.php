<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain\Provider;

use ImaticExternalLinks\Contract\HttpClient;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;
use ImaticExternalLinks\Domain\LinkAction;
use ImaticExternalLinks\Domain\LinkMeta;
use ImaticExternalLinks\Domain\LinkProvider;
use ImaticExternalLinks\Domain\NormalizedLink;
use ImaticExternalLinks\Domain\OriginAllowList;
use ImaticExternalLinks\Domain\UrlNormalizer;

/**
 * Catch-all provider for any http(s) URL. Enrichment tries to read the remote
 * page <title>, but only for allow-listed origins and always failing soft.
 */
final class GenericUrlProvider implements LinkProvider
{
    public const KEY = 'generic';

    /** @var HttpClient|null */
    private $http;

    /** @var OriginAllowList|null */
    private $allowList;

    public function __construct(?HttpClient $http = null, ?OriginAllowList $allowList = null)
    {
        $this->http      = $http;
        $this->allowList = $allowList;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function matches(string $url): bool
    {
        return UrlNormalizer::isHttp($url);
    }

    public function normalize(string $url): NormalizedLink
    {
        $trimmed = trim($url);
        if (!UrlNormalizer::isHttp($trimmed)) {
            throw new InvalidLinkException('Only http and https URLs are allowed');
        }
        $host = UrlNormalizer::host($trimmed);
        if ($host === null || $host === '') {
            throw new InvalidLinkException('URL has no host');
        }
        return new NormalizedLink(self::KEY, $trimmed, null, []);
    }

    public function actions(array $row): array
    {
        $url = isset($row['url']) ? (string) $row['url'] : '';
        return [new LinkAction('imatic_el_action_open', $url, 'open', true)];
    }

    public function enrich(array $row): ?LinkMeta
    {
        if ($this->http === null) {
            return null;
        }
        $url = isset($row['url']) ? (string) $row['url'] : '';
        if ($url === '') {
            return null;
        }
        if ($this->allowList !== null && !$this->allowList->permits($url)) {
            return null; // SSRF guard: never fetch off-list origins
        }
        try {
            $response = $this->http->get($url, ['Accept' => 'text/html']);
            if (!$response->isSuccess()) {
                return null;
            }
            $title = self::extractTitle($response->body);
            if ($title === null) {
                return null;
            }
            return new LinkMeta($title, 'link', ['title' => $title]);
        } catch (\Throwable $e) {
            return null; // fail soft — caller keeps the plain-URL fallback
        }
    }

    /** Extract and clean an HTML document <title>. Pure. */
    public static function extractTitle(string $html): ?string
    {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return null;
        }
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/', ' ', $title);
        $title = $title === null ? '' : trim($title);
        if ($title === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($title, 0, 300) : substr($title, 0, 300);
    }
}
