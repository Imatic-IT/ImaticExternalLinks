<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Application;

use ImaticExternalLinks\Contract\AccessGuard;
use ImaticExternalLinks\Contract\CustomerGateway;

/**
 * Use-case behind the customer picker: search the customers project for
 * candidates to attach. Orchestration only — access is enforced first, the
 * actual lookup goes through the injected gateway, and the result is shaped into
 * a small, predictable DTO for the wire.
 *
 * Searching is part of the "attach a customer" (manage) flow, so it requires
 * manage access — the same threshold as adding a link. When no customers project
 * is configured the gateway is null and the search yields an empty set (feature
 * simply off, never an error).
 */
final class CustomerPickerService
{
    /** @var AccessGuard */
    private $access;

    /** @var CustomerGateway|null */
    private $gateway;

    public function __construct(AccessGuard $access, ?CustomerGateway $gateway)
    {
        $this->access  = $access;
        $this->gateway = $gateway;
    }

    /**
     * Whether the customer picker is offered at all. False when no gateway is
     * wired — either the customers project is unconfigured, or (P2) the customer
     * relation is not enabled on the current bug's project. The frontend uses
     * this to decide whether to show the "add customer" affordance; search()
     * itself also stays empty, so the endpoint is safe even if called directly.
     */
    public function isEnabled(): bool
    {
        return $this->gateway !== null;
    }

    /**
     * @return array<string,mixed> {'customers': array<int,array{id:int,name:string,ico:string}>}
     * @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException
     */
    public function search(int $bugId, string $query, int $limit = 20): array
    {
        $this->access->ensureCanManage($bugId);

        if ($this->gateway === null) {
            return ['customers' => []];
        }
        $t_query = trim($query);
        if ($t_query === '') {
            return ['customers' => []];
        }

        $t_out = [];
        foreach ($this->gateway->search($t_query, $limit) as $t_row) {
            $t_id = isset($t_row['id']) ? (int) $t_row['id'] : 0;
            if ($t_id <= 0) {
                continue;
            }
            $t_out[] = [
                'id'   => $t_id,
                'name' => isset($t_row['name']) ? (string) $t_row['name'] : '',
                'ico'  => isset($t_row['ico']) ? (string) $t_row['ico'] : '',
            ];
        }
        return ['customers' => $t_out];
    }
}
