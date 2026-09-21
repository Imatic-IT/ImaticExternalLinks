<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Application;

use ImaticExternalLinks\Application\Exception\NotFoundException;
use ImaticExternalLinks\Contract\AccessGuard;
use ImaticExternalLinks\Contract\LinkRepository;
use ImaticExternalLinks\Domain\Exception\DuplicateLinkException;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;
use ImaticExternalLinks\Domain\LinkProvider;
use ImaticExternalLinks\Domain\Provider\CustomerProvider;
use ImaticExternalLinks\Domain\ProviderRegistry;

/**
 * Use-cases for a bug's external links: add / remove / list / refresh.
 *
 * Orchestration only — every side effect goes through an injected boundary
 * (repository, provider registry, access guard), so the whole class is pure
 * enough to unit-test with fakes. Access is enforced first in every method;
 * enrichment is best-effort and never blocks the primary operation.
 */
final class LinkService
{
    /** Hard caps aligned with the DB columns (url C(2000), description C(2000)). */
    private const MAX_URL         = 2000;
    private const MAX_DESCRIPTION = 2000;

    /** @var LinkRepository */
    private $store;

    /** @var ProviderRegistry */
    private $registry;

    /** @var AccessGuard */
    private $access;

    public function __construct(LinkRepository $store, ProviderRegistry $registry, AccessGuard $access)
    {
        $this->store    = $store;
        $this->registry = $registry;
        $this->access   = $access;
    }

    /**
     * Normalise → detect provider → best-effort enrich → persist → return the
     * decorated row ready for the wire.
     *
     * @return array<string,mixed>
     * @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException
     * @throws InvalidLinkException
     */
    public function add(int $bugId, string $rawUrl, ?string $description): array
    {
        $this->access->ensureCanManage($bugId);

        $t_provider   = $this->registry->forUrl($rawUrl);
        $t_normalized = $t_provider->normalize($rawUrl);

        if (strlen($t_normalized->url) > self::MAX_URL) {
            throw new InvalidLinkException('URL exceeds the maximum length');
        }

        // Reject an exact re-attach: the same normalized URL already on this bug.
        // The synthetic `customer://<id>` URI is unique per customer, so this is
        // what stops the same customer being connected twice.
        foreach ($this->store->findByBug($bugId) as $t_existing) {
            if ((string) ($t_existing['url'] ?? '') === $t_normalized->url) {
                throw new DuplicateLinkException('This link is already attached to the issue');
            }
        }

        $t_description = $this->cleanDescription($description);
        $t_meta        = $t_normalized->meta;
        $t_title       = $t_normalized->title;

        $t_enriched = $t_provider->enrich(['url' => $t_normalized->url, 'meta' => $t_meta]);
        if ($t_enriched !== null) {
            $t_meta  = $this->mergeMeta($t_meta, $t_enriched);
            $t_title = $t_enriched->title !== null ? $t_enriched->title : $t_title;
        }

        $t_id = $this->store->insert([
            'bug_id'      => $bugId,
            'provider'    => $t_provider->key(),
            'url'         => $t_normalized->url,
            'title'       => $t_title,
            'description' => $t_description,
            'meta'        => $t_meta,
            'created_by'  => $this->access->currentUserId(),
        ]);

        $t_row = [
            'id'          => $t_id,
            'bug_id'      => $bugId,
            'provider'    => $t_provider->key(),
            'url'         => $t_normalized->url,
            'title'       => $t_title,
            'description' => $t_description,
            'meta'        => $t_meta,
        ];

        return $this->decorate($t_row, $t_provider);
    }

    /**
     * Attach a link whose display metadata is already known authoritatively by
     * the caller (e.g. the Nextcloud picker, which captured name/mime/fileid via
     * a server-side PROPFIND). Same rules as {@see add()} — manage access, URL
     * length, no duplicate — but enrichment is skipped: the supplied title/meta
     * are trusted and merged over whatever the provider derives from the URL.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the decorated row
     */
    public function addResolved(int $bugId, string $rawUrl, ?string $title, array $meta, ?string $description): array
    {
        $this->access->ensureCanManage($bugId);

        $t_provider   = $this->registry->forUrl($rawUrl);
        $t_normalized = $t_provider->normalize($rawUrl);

        if (strlen($t_normalized->url) > self::MAX_URL) {
            throw new InvalidLinkException('URL exceeds the maximum length');
        }
        foreach ($this->store->findByBug($bugId) as $t_existing) {
            if ((string) ($t_existing['url'] ?? '') === $t_normalized->url) {
                throw new DuplicateLinkException('This link is already attached to the issue');
            }
        }

        // Caller-supplied metadata wins over the provider's URL-derived guesses.
        $t_meta        = array_merge($t_normalized->meta, $meta);
        $t_title       = ($title !== null && $title !== '') ? $title : $t_normalized->title;
        $t_description = $this->cleanDescription($description);

        $t_id = $this->store->insert([
            'bug_id'      => $bugId,
            'provider'    => $t_provider->key(),
            'url'         => $t_normalized->url,
            'title'       => $t_title,
            'description' => $t_description,
            'meta'        => $t_meta,
            'created_by'  => $this->access->currentUserId(),
        ]);

        return $this->decorate([
            'id'          => $t_id,
            'bug_id'      => $bugId,
            'provider'    => $t_provider->key(),
            'url'         => $t_normalized->url,
            'title'       => $t_title,
            'description' => $t_description,
            'meta'        => $t_meta,
        ], $t_provider);
    }

    /**
     * @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException
     * @throws NotFoundException
     */
    public function remove(int $bugId, int $linkId): void
    {
        $this->access->ensureCanManage($bugId);
        if (!$this->store->delete($bugId, $linkId)) {
            throw new NotFoundException('Link ' . $linkId . ' not found for bug ' . $bugId);
        }
    }

    /**
     * @return array<string,mixed> {'links': array<int,array<string,mixed>>}
     * @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException
     */
    public function list(int $bugId): array
    {
        $this->access->ensureCanView($bugId);

        $t_links = [];
        foreach ($this->store->findByBug($bugId) as $t_row) {
            $t_links[] = $this->decorate($t_row, $this->providerFor($t_row));
        }
        return ['links' => $t_links];
    }

    /**
     * Bug ids that link to the given customer issue — its backlinks, resolved
     * from the canonical `customer://<id>` URL — filtered to those the current
     * user may view. Orchestration only: the caller renders the issue summaries
     * from Mantis core. Distinct, ascending (repo order).
     *
     * @return array<int,int>
     */
    public function customerBacklinks(int $customerBugId): array
    {
        if ($customerBugId <= 0) {
            return [];
        }

        $t_url = CustomerProvider::urlForId($customerBugId);

        $t_ids = [];
        foreach ($this->store->findByUrl($t_url) as $t_row) {
            $t_bug = (int) ($t_row['bug_id'] ?? 0);
            if ($t_bug > 0 && !in_array($t_bug, $t_ids, true) && $this->access->canView($t_bug)) {
                $t_ids[] = $t_bug;
            }
        }
        return $t_ids;
    }

    /**
     * Re-run enrichment for one row and persist the refreshed cache columns.
     *
     * @return array<string,mixed>
     * @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException
     * @throws NotFoundException
     */
    public function refresh(int $bugId, int $linkId): array
    {
        $this->access->ensureCanManage($bugId);

        $t_row = $this->store->find($bugId, $linkId);
        if ($t_row === null) {
            throw new NotFoundException('Link ' . $linkId . ' not found for bug ' . $bugId);
        }

        $t_provider = $this->providerFor($t_row);
        $t_enriched = $t_provider->enrich($t_row);
        if ($t_enriched !== null) {
            $t_meta        = $this->mergeMeta(is_array($t_row['meta']) ? $t_row['meta'] : [], $t_enriched);
            $t_title       = $t_enriched->title !== null ? $t_enriched->title : ($t_row['title'] ?? null);
            $this->store->updateEnrichment($bugId, $linkId, $t_title, $t_meta);
            $t_row['meta']  = $t_meta;
            $t_row['title'] = $t_title;
        }

        return $this->decorate($t_row, $t_provider);
    }

    /**
     * Resolve the provider for a stored row: by its persisted key first, falling
     * back to URL matching if the key is no longer registered.
     *
     * @param array<string,mixed> $row
     */
    private function providerFor(array $row): LinkProvider
    {
        $t_key      = isset($row['provider']) ? (string) $row['provider'] : '';
        $t_provider = $this->registry->byKey($t_key);
        return $t_provider !== null ? $t_provider : $this->registry->forUrl((string) ($row['url'] ?? ''));
    }

    /**
     * Build the wire DTO for a row, adding provider actions. Presentation still
     * escapes on output; this is data only.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row, LinkProvider $provider): array
    {
        $t_actions = [];
        foreach ($provider->actions($row) as $t_action) {
            $t_actions[] = $t_action->toArray();
        }

        return [
            'id'          => (int) $row['id'],
            'provider'    => (string) $row['provider'],
            'url'         => (string) $row['url'],
            'title'       => isset($row['title']) && $row['title'] !== null ? (string) $row['title'] : null,
            'description' => isset($row['description']) && $row['description'] !== null ? (string) $row['description'] : null,
            'meta'        => is_array($row['meta'] ?? null) ? $row['meta'] : [],
            'actions'     => $t_actions,
        ];
    }

    /**
     * Overlay validated enrichment attributes (+ icon hint) onto the existing
     * identifier meta. Provider identifiers survive unless the provider chose to
     * overwrite them.
     *
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function mergeMeta(array $base, \ImaticExternalLinks\Domain\LinkMeta $enriched): array
    {
        $t_meta = array_merge($base, $enriched->attributes);
        if ($enriched->icon !== null) {
            $t_meta['icon'] = $enriched->icon;
        }
        return $t_meta;
    }

    private function cleanDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $t_trimmed = trim($description);
        if ($t_trimmed === '') {
            return null;
        }
        if (strlen($t_trimmed) > self::MAX_DESCRIPTION) {
            throw new InvalidLinkException('Description exceeds the maximum length');
        }
        return $t_trimmed;
    }
}
