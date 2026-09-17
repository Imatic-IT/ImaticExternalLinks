<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Contract\CustomerGateway;

/**
 * CustomerGateway backed by Mantis itself: customers are issues in a configured
 * project ("iMatic IT Customers"), their invoicing data lives in custom fields.
 *
 * The only place that reads customer records from the DB / custom-field API.
 * The custom-field name → meta-key map is injected (from plugin config) so the
 * domain never hard-codes field names and admins can rename fields freely.
 *
 * Runs only inside the Mantis runtime (like MantisAccessGuard / LinkStore); it
 * is not exercised by the standalone domain test runner.
 */
final class MantisCustomerGateway implements CustomerGateway
{
    /** @var int project id that holds customer issues */
    private $projectId;

    /** @var array<string,string> meta key => Mantis custom field name */
    private $fieldMap;

    /**
     * @param array<string,string> $fieldMap e.g.
     *   ['ico'=>'IČO','dic'=>'DIČ','invoice_email'=>'Fakturační e-mail','pohoda_id'=>'Pohoda ID']
     */
    public function __construct(int $projectId, array $fieldMap)
    {
        $this->projectId = $projectId;
        $this->fieldMap  = $fieldMap;
    }

    public function fetch(int $customerId): ?array
    {
        if ($customerId <= 0 || !bug_exists($customerId)) {
            return null;
        }
        // Only issues from the customers project are valid customers.
        if ((int) bug_get_field($customerId, 'project_id') !== $this->projectId) {
            return null;
        }
        // Respect Mantis view access for the acting user.
        if (!access_has_bug_level(VIEWER, $customerId)) {
            return null;
        }

        $t_out = ['name' => (string) bug_get_field($customerId, 'summary')];

        foreach ($this->fieldMap as $t_metaKey => $t_fieldName) {
            $t_value = $this->customFieldValue($customerId, (string) $t_fieldName);
            if ($t_value !== null && $t_value !== '') {
                $t_out[$t_metaKey] = $t_value;
            }
        }
        return $t_out;
    }

    public function search(string $query, int $limit = 20): array
    {
        $t_query = trim($query);
        if ($t_query === '') {
            return [];
        }
        $t_limit = $limit > 0 && $limit <= 50 ? $limit : 20;
        // Case-insensitive substring match, portable across MySQL/Postgres:
        // LOWER() on both sides (plain LIKE is case-sensitive on Postgres).
        $t_like  = '%' . mb_strtolower($t_query) . '%';

        $t_bugTable = db_get_table('bug');

        db_param_push();
        $t_sql = 'SELECT id, summary FROM ' . $t_bugTable
            . ' WHERE project_id = ' . db_param()
            . ' AND LOWER(summary) LIKE ' . db_param()
            . ' ORDER BY summary ASC';

        $t_result = db_query($t_sql, [$this->projectId, $t_like], $t_limit);

        $t_rows = [];
        while ($t_row = db_fetch_array($t_result)) {
            $t_id = (int) $t_row['id'];
            $t_rows[] = [
                'id'   => $t_id,
                'name' => (string) $t_row['summary'],
                'ico'  => $this->icoFor($t_id),
            ];
        }
        return $t_rows;
    }

    /** IČO of a customer for the picker list, or '' if unset / not mapped. */
    private function icoFor(int $customerId): string
    {
        if (!isset($this->fieldMap['ico'])) {
            return '';
        }
        $t_value = $this->customFieldValue($customerId, (string) $this->fieldMap['ico']);
        return $t_value === null ? '' : $t_value;
    }

    /**
     * Read a single custom field value by field name, or null if the field is
     * unknown or has no value for this issue.
     */
    private function customFieldValue(int $bugId, string $fieldName): ?string
    {
        if ($fieldName === '') {
            return null;
        }
        $t_fieldId = custom_field_get_id_from_name($fieldName);
        if ($t_fieldId === false) {
            return null;
        }
        if (!custom_field_is_linked($t_fieldId, $this->projectId)) {
            return null;
        }
        $t_value = custom_field_get_value($t_fieldId, $bugId);
        if ($t_value === false || $t_value === null) {
            return null;
        }
        return (string) $t_value;
    }
}
