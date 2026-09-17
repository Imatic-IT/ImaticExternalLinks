<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain\Provider;

use ImaticExternalLinks\Contract\CustomerGateway;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;
use ImaticExternalLinks\Domain\LinkAction;
use ImaticExternalLinks\Domain\LinkMeta;
use ImaticExternalLinks\Domain\LinkProvider;
use ImaticExternalLinks\Domain\MetaValidator;
use ImaticExternalLinks\Domain\NormalizedLink;

/**
 * Provider for links to a customer record living inside Mantis (the "iMatic IT
 * Customers" project). This is the blocker for the invoicing automation: it lets
 * an issue point at a customer and surface its invoicing identifiers (IČO, DIČ,
 * invoice e-mail, Pohoda contact id).
 *
 * Unlike the URL-based providers, the target is an *internal* object, so there
 * is no cross-domain / SSRF concern. The link identity is a synthetic,
 * provider-owned URI `customer://<id>` (durable, pure to match/parse); the real,
 * clickable Mantis issue URL is produced as an action from the injected base.
 * Enrichment reads invoicing fields from Mantis through an injected gateway and
 * fails soft, exactly like the other providers.
 */
final class CustomerProvider implements LinkProvider
{
    public const KEY = 'customer';

    /** Synthetic scheme owning this provider's canonical URLs. */
    private const SCHEME = 'customer';

    /** @var string base URL of this Mantis instance, used to build open actions */
    private $mantisBaseUrl;

    /** @var CustomerGateway|null */
    private $gateway;

    /** @var MetaValidator */
    private $validator;

    public function __construct(
        string $mantisBaseUrl = '',
        ?CustomerGateway $gateway = null,
        ?MetaValidator $validator = null
    ) {
        $this->mantisBaseUrl = rtrim($mantisBaseUrl, '/');
        $this->gateway       = $gateway;
        $this->validator     = $validator ?: new MetaValidator();
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Owns only its synthetic `customer://<positive-int>` URIs. Pure — never
     * claims plain http(s) issue URLs (those would fall through to generic),
     * so it cannot hijack unrelated internal links.
     */
    public function matches(string $url): bool
    {
        return self::parseId(trim($url)) !== null;
    }

    public function normalize(string $url): NormalizedLink
    {
        $t_id = self::parseId(trim($url));
        if ($t_id === null) {
            throw new InvalidLinkException('Not a recognised customer reference');
        }
        return new NormalizedLink(
            self::KEY,
            self::SCHEME . '://' . $t_id,
            null,
            ['customer_id' => (string) $t_id]
        );
    }

    /**
     * Build a canonical customer URI from a raw Mantis issue id. Used by the
     * dedicated "add customer" flow (the picker submits an id, not a URL). Pure.
     *
     * @throws InvalidLinkException
     */
    public static function urlForId(int $customerId): string
    {
        if ($customerId <= 0) {
            throw new InvalidLinkException('Customer id must be a positive integer');
        }
        return self::SCHEME . '://' . $customerId;
    }

    /**
     * Extract a positive integer id from a `customer://<id>` URI. Returns null
     * for anything else (wrong scheme, non-numeric, non-positive). Pure; trims
     * defensively so callers need not. The canonical shape is always
     * `customer://<id>` (host carries the id) — the schemeless `customer:<id>`
     * form is intentionally not accepted (parse_url reads it as host:port).
     */
    public static function parseId(string $url): ?int
    {
        $t_parts = parse_url(trim($url));
        if ($t_parts === false || !isset($t_parts['scheme'], $t_parts['host'])) {
            return null;
        }
        if (strtolower($t_parts['scheme']) !== self::SCHEME) {
            return null;
        }
        $t_raw = $t_parts['host'];
        if (!ctype_digit($t_raw)) {
            return null;
        }
        $t_id = (int) $t_raw;
        return $t_id > 0 ? $t_id : null;
    }

    public function actions(array $row): array
    {
        $t_id = self::rowId($row);
        if ($t_id === null) {
            return [];
        }
        $t_url = $this->mantisBaseUrl !== ''
            ? $this->mantisBaseUrl . '/view.php?id=' . $t_id
            : 'view.php?id=' . $t_id;

        // Internal link — open in the same tab (external = false).
        return [new LinkAction('imatic_el_action_open_customer', $t_url, 'customer', false)];
    }

    public function enrich(array $row): ?LinkMeta
    {
        if ($this->gateway === null) {
            return null;
        }
        $t_id = self::rowId($row);
        if ($t_id === null) {
            return null;
        }
        try {
            $t_data = $this->gateway->fetch($t_id);
            if ($t_data === null) {
                return null;
            }
            $t_clean = $this->validator->sanitize($t_data);
            // Preserve the identifier we resolved by so actions() stays functional.
            $t_clean['customer_id'] = (string) $t_id;
            $t_title = isset($t_clean['name']) ? $t_clean['name'] : null;
            return new LinkMeta($t_title, 'customer', $t_clean);
        } catch (\Throwable $e) {
            return null; // fail soft — caller keeps the plain fallback
        }
    }

    /**
     * Resolve the customer id from a stored row: prefer the persisted meta,
     * fall back to parsing the canonical url. Pure.
     *
     * @param array<string,mixed> $row
     */
    private static function rowId(array $row): ?int
    {
        $t_meta = (isset($row['meta']) && is_array($row['meta'])) ? $row['meta'] : [];
        if (isset($t_meta['customer_id']) && ctype_digit((string) $t_meta['customer_id'])) {
            $t_id = (int) $t_meta['customer_id'];
            if ($t_id > 0) {
                return $t_id;
            }
        }
        return self::parseId(isset($row['url']) ? (string) $row['url'] : '');
    }
}
