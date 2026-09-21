<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Persistence boundary for stored links. The application layer depends on this
 * interface, never on the concrete DB implementation, so LinkService is
 * unit-testable with an in-memory fake.
 *
 * Rows are plain associative arrays with keys:
 *   id, bug_id, provider, url, title, description, meta (array), position,
 *   created_by, created_at, updated_at
 * The `meta` field is always an array on the way in and out (the store handles
 * JSON encoding/decoding); callers never see the raw JSON string.
 */
interface LinkRepository
{
    /**
     * Insert a new link and return its id.
     *
     * @param array<string,mixed> $data provider, url, title, description,
     *                                   meta (array), bug_id, created_by
     */
    public function insert(array $data): int;

    /**
     * All links for a bug, ordered for display (position, then id).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByBug(int $bugId): array;

    /**
     * A single link scoped to its bug (so one bug can never read another's row).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $bugId, int $linkId): ?array;

    /**
     * All links whose canonical URL matches exactly, across every bug. Used to
     * find the issues that point at a given target (e.g. a customer issue's
     * backlinks via its `customer://<id>` URL). Ordered by bug id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUrl(string $url): array;

    /** Delete a link scoped to its bug. Returns false when nothing matched. */
    public function delete(int $bugId, int $linkId): bool;

    /**
     * Persist refreshed enrichment (cache columns only).
     *
     * @param array<string,mixed> $meta
     */
    public function updateEnrichment(int $bugId, int $linkId, ?string $title, array $meta): bool;
}
