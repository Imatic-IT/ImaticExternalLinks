<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Turns raw plugin config into a list of RelationDefinition value objects — the
 * single place that understands the `relation_definitions` shape.
 *
 * Two sources, in priority order:
 *   1. the new `relation_definitions` config array (one entry per type), or
 *   2. a back-compat shim: when that array is empty, synthesise the single
 *      historical "customer" definition from the legacy `customers_project_id`
 *      + `customer_fields` keys. So an old/unconfigured install behaves exactly
 *      as before — no `relation_definitions` means today's customer link,
 *      unchanged — and the legacy config keys keep working.
 *
 * Pure: no I/O, no Mantis, no globals — just array-shaping, so it is covered by
 * the standalone domain test runner. PHP 7.4 baseline.
 */
final class RelationConfig
{
    /** Code provider key for an internal Mantis-issue target (customer today). */
    public const PROVIDER_MANTIS_ISSUE = 'mantis_issue';

    /** Stable key of the historical customer definition. */
    public const KEY_CUSTOMER = 'customer';

    /**
     * @param array<int,mixed>      $definitions          raw `relation_definitions`
     * @param int                   $legacyCustomerProjectId legacy `customers_project_id`
     * @param array<string,string>  $legacyCustomerFields legacy `customer_fields`
     * @param array<int,mixed>      $legacyCustomerEnabledProjects legacy `customer_enabled_projects` ([] = everywhere)
     * @return RelationDefinition[]
     */
    public static function resolve(
        array $definitions,
        int $legacyCustomerProjectId,
        array $legacyCustomerFields,
        array $legacyCustomerEnabledProjects = []
    ): array {
        if ($definitions !== []) {
            return self::fromConfig($definitions);
        }
        return self::legacyShim(
            $legacyCustomerProjectId,
            $legacyCustomerFields,
            $legacyCustomerEnabledProjects
        );
    }

    /**
     * @param array<int,mixed> $definitions
     * @return RelationDefinition[]
     */
    private static function fromConfig(array $definitions): array
    {
        $t_out = [];
        foreach ($definitions as $t_raw) {
            if (!is_array($t_raw)) {
                continue;
            }
            $t_key = isset($t_raw['key']) ? trim((string) $t_raw['key']) : '';
            if ($t_key === '') {
                continue; // a definition without a stable key is unusable
            }
            $t_provider = isset($t_raw['provider']) && trim((string) $t_raw['provider']) !== ''
                ? trim((string) $t_raw['provider'])
                : self::PROVIDER_MANTIS_ISSUE;
            $t_label = (isset($t_raw['label']) && (string) $t_raw['label'] !== '')
                ? (string) $t_raw['label']
                : $t_key;

            $t_out[] = new RelationDefinition(
                $t_key,
                $t_provider,
                $t_label,
                self::asArray($t_raw['enabled_projects'] ?? []),
                self::asArray($t_raw['target_projects'] ?? []),
                self::stringMap($t_raw['fields'] ?? [])
            );
        }
        return $t_out;
    }

    /**
     * @param array<string,string> $fields
     * @param array<int,mixed>     $enabledProjects [] = offered everywhere
     * @return RelationDefinition[]
     */
    private static function legacyShim(int $customerProjectId, array $fields, array $enabledProjects = []): array
    {
        if ($customerProjectId <= 0) {
            return []; // customer link was off — it stays off
        }
        return [
            new RelationDefinition(
                self::KEY_CUSTOMER,
                self::PROVIDER_MANTIS_ISSUE,
                'imatic_el_action_open_customer',
                $enabledProjects,       // where the add-customer flow is offered ([] = everywhere)
                [$customerProjectId],   // search the customers project
                self::stringMap($fields)
            ),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int|string,mixed>
     */
    private static function asArray($value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    private static function stringMap($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $t_out = [];
        foreach ($value as $t_k => $t_v) {
            $t_out[(string) $t_k] = (string) $t_v;
        }
        return $t_out;
    }
}
