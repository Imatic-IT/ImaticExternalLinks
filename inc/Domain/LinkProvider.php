<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

use ImaticExternalLinks\Domain\Exception\InvalidLinkException;

/**
 * Strategy for one class of external object (generic URL, Nextcloud, ...).
 *
 * Contract:
 *   - matches/normalize/actions are pure and MUST NOT perform I/O.
 *   - enrich() MAY perform I/O via an injected gateway and MUST fail soft:
 *     return null on any failure, never throw to the caller. This is what makes
 *     the "graceful fallback" guarantee hold at the domain boundary.
 */
interface LinkProvider
{
    /** Stable identifier persisted with each row (e.g. 'generic', 'nextcloud'). */
    public function key(): string;

    /** Whether this provider claims the given URL. */
    public function matches(string $url): bool;

    /**
     * Canonicalise a raw URL for storage.
     *
     * @throws InvalidLinkException if the URL is not acceptable for this provider
     */
    public function normalize(string $url): NormalizedLink;

    /**
     * User-facing actions for a stored row.
     *
     * @param array<string,mixed> $row stored row (expects 'url', optional 'meta')
     * @return LinkAction[]
     */
    public function actions(array $row): array;

    /**
     * Best-effort decoration from a remote source. MUST return null on failure.
     *
     * @param array<string,mixed> $row stored row
     */
    public function enrich(array $row): ?LinkMeta;
}
