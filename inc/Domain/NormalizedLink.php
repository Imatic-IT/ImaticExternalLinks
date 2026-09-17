<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Result of normalizing a raw URL for a specific provider. Immutable value
 * object (no setters). PHP 7.4: no constructor promotion / readonly.
 */
final class NormalizedLink
{
    /** @var string provider key that owns the URL */
    public $provider;

    /** @var string canonical URL — the durable identity of the link */
    public $url;

    /** @var string|null best-effort display label (may be null) */
    public $title;

    /** @var array<string,mixed> provider identifiers, e.g. ['fileid' => '42'] */
    public $meta;

    public function __construct(string $provider, string $url, ?string $title = null, array $meta = [])
    {
        $this->provider = $provider;
        $this->url      = $url;
        $this->title    = $title;
        $this->meta     = $meta;
    }
}
