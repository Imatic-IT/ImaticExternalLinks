<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * A user-facing action offered for a link (open, open-in-editor, ...).
 * `label` is a language-string key, never pre-translated text. `icon` is a
 * key. Immutable value object.
 */
final class LinkAction
{
    /** @var string language-string key, e.g. 'imatic_el_action_open' */
    public $label;

    /** @var string target URL for the action */
    public $url;

    /** @var string icon key */
    public $icon;

    /** @var bool whether it opens a foreign origin (new tab / rel=noopener) */
    public $external;

    public function __construct(string $label, string $url, string $icon, bool $external = true)
    {
        $this->label    = $label;
        $this->url      = $url;
        $this->icon     = $icon;
        $this->external = $external;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'label'    => $this->label,
            'url'      => $this->url,
            'icon'     => $this->icon,
            'external' => $this->external,
        ];
    }
}
