<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Contract\LinkRepository;

/**
 * MySQL-backed link repository over the imatic_external_links table. The only
 * place that touches the DB for links. All queries are parameterised through
 * db_param()/db_query(); the `meta` column is JSON encoded/decoded here so the
 * rest of the code works with arrays.
 */
final class LinkStore implements LinkRepository
{
    /** @var string fully-resolved physical table name */
    private $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function insert(array $data): int
    {
        $t_now      = time();
        $t_position = $this->nextPosition((int) $data['bug_id']);

        db_param_push();
        $t_query = 'INSERT INTO ' . $this->table
            . ' (bug_id, provider, url, title, description, meta, position, created_by, created_at, updated_at)'
            . ' VALUES (' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ','
            . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ')';

        db_query($t_query, [
            (int) $data['bug_id'],
            (string) $data['provider'],
            (string) $data['url'],
            $this->nullableString($data['title'] ?? null),
            $this->nullableString($data['description'] ?? null),
            $this->encodeMeta($data['meta'] ?? []),
            $t_position,
            (int) $data['created_by'],
            $t_now,
            $t_now,
        ]);

        return (int) db_insert_id($this->table);
    }

    public function findByBug(int $bugId): array
    {
        db_param_push();
        $t_result = db_query(
            'SELECT * FROM ' . $this->table . ' WHERE bug_id = ' . db_param() . ' ORDER BY position ASC, id ASC',
            [$bugId]
        );

        $t_rows = [];
        while ($t_row = db_fetch_array($t_result)) {
            $t_rows[] = $this->hydrate($t_row);
        }
        return $t_rows;
    }

    public function find(int $bugId, int $linkId): ?array
    {
        db_param_push();
        $t_result = db_query(
            'SELECT * FROM ' . $this->table . ' WHERE id = ' . db_param() . ' AND bug_id = ' . db_param(),
            [$linkId, $bugId]
        );

        $t_row = db_fetch_array($t_result);
        return $t_row === false ? null : $this->hydrate($t_row);
    }

    public function findByUrl(string $url): array
    {
        db_param_push();
        $t_result = db_query(
            'SELECT * FROM ' . $this->table . ' WHERE url = ' . db_param() . ' ORDER BY bug_id ASC, id ASC',
            [$url]
        );

        $t_rows = [];
        while ($t_row = db_fetch_array($t_result)) {
            $t_rows[] = $this->hydrate($t_row);
        }
        return $t_rows;
    }

    public function delete(int $bugId, int $linkId): bool
    {
        db_param_push();
        db_query(
            'DELETE FROM ' . $this->table . ' WHERE id = ' . db_param() . ' AND bug_id = ' . db_param(),
            [$linkId, $bugId]
        );

        return db_affected_rows() > 0;
    }

    public function updateEnrichment(int $bugId, int $linkId, ?string $title, array $meta): bool
    {
        db_param_push();
        db_query(
            'UPDATE ' . $this->table . ' SET title = ' . db_param() . ', meta = ' . db_param()
            . ', updated_at = ' . db_param() . ' WHERE id = ' . db_param() . ' AND bug_id = ' . db_param(),
            [$this->nullableString($title), $this->encodeMeta($meta), time(), $linkId, $bugId]
        );

        return db_affected_rows() >= 0;
    }

    /** Next display position for a bug (max + 1, starting at 0). */
    private function nextPosition(int $bugId): int
    {
        db_param_push();
        $t_result = db_query(
            'SELECT MAX(position) AS max_pos FROM ' . $this->table . ' WHERE bug_id = ' . db_param(),
            [$bugId]
        );
        $t_row = db_fetch_array($t_result);
        if ($t_row === false || $t_row['max_pos'] === null) {
            return 0;
        }
        return ((int) $t_row['max_pos']) + 1;
    }

    /**
     * Turn a raw DB row into the array shape the contract promises: typed ids,
     * decoded meta, null-safe strings.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'bug_id'      => (int) $row['bug_id'],
            'provider'    => (string) $row['provider'],
            'url'         => (string) $row['url'],
            'title'       => $row['title'] !== null ? (string) $row['title'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'meta'        => $this->decodeMeta($row['meta'] ?? null),
            'position'    => (int) $row['position'],
            'created_by'  => (int) $row['created_by'],
            'created_at'  => (int) $row['created_at'],
            'updated_at'  => (int) $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $meta */
    private function encodeMeta(array $meta): string
    {
        $t_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $t_json === false ? '{}' : $t_json;
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function decodeMeta($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $t_decoded = json_decode($raw, true);
        return is_array($t_decoded) ? $t_decoded : [];
    }

    /** @param mixed $value */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t_str = (string) $value;
        return $t_str === '' ? null : $t_str;
    }
}
