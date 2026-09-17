<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Access boundary for customer records held inside Mantis (the "iMatic IT
 * Customers" project — one issue per customer, invoicing data in custom fields).
 *
 * Mirrors NextcloudGateway: the concrete implementation is built per-request
 * with the acting user's Mantis session, so the domain never handles auth or
 * user identity — it just asks for one customer's data or a search result set.
 *
 * Everything returned here is *internal* Mantis data (no cross-domain / SSRF
 * surface), but it is still funnelled through MetaValidator before storage and
 * escaped on output, exactly like remote enrichment.
 */
interface CustomerGateway
{
    /**
     * Invoicing data for one customer by its Mantis issue id, or null if it
     * cannot be resolved (missing, not a customer, no permission).
     *
     * @return array<string,mixed>|null e.g.
     *   ['name'=>.., 'ico'=>.., 'dic'=>.., 'invoice_email'=>.., 'pohoda_id'=>..]
     */
    public function fetch(int $customerId): ?array;

    /**
     * Fulltext search over the customers project for the picker.
     *
     * @return array<int,array<string,mixed>> ordered candidates, each at least
     *   ['id'=>int, 'name'=>string] (+ optional 'ico').
     */
    public function search(string $query, int $limit = 20): array;
}
