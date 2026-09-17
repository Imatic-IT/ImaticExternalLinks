<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Enrichment result for a stored link — the decorated presentation data a
 * provider derived from a remote source. Immutable value object.
 *
 * Never trusted for rendering as-is: `attributes` has already passed through
 * MetaValidator, and the presentation layer still escapes on output.
 */
final class LinkMeta
{
    /** @var string|null resolved display title */
    public $title;

    /** @var string|null icon hint (a key, not markup) */
    public $icon;

    /** @var array<string,mixed> sanitized attribute bag (name/mime/size/...) */
    public $attributes;

    public function __construct(?string $title, ?string $icon = null, array $attributes = [])
    {
        $this->title      = $title;
        $this->icon       = $icon;
        $this->attributes = $attributes;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'title'      => $this->title,
            'icon'       => $this->icon,
            'attributes' => $this->attributes,
        ];
    }
}
