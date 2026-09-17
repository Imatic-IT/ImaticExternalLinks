<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * A named relation type: which code provider backs it, where it is offered
 * (enabledProjects), where it searches for its target (targetProjects), plus a
 * label and an optional enrichment field map.
 *
 * This is the config-driven half of the two-layer model (see RelationConfig):
 * a new relation type becomes a config entry, not a new PHP class. Several
 * definitions may share one code provider (e.g. "Customer" and "Dodavatel" both
 * over `mantis_issue`, different target projects).
 *
 * The `key` is stable and is what the DB `provider` column stores, so a stored
 * row can always find its definition again.
 *
 * Immutable value object; pure (no I/O, no globals). PHP 7.4 baseline: no
 * constructor promotion / readonly / enums.
 */
final class RelationDefinition
{
    /** @var string stable id, stored in the DB `provider` column */
    private $key;

    /** @var string code provider that handles this type (e.g. 'mantis_issue') */
    private $provider;

    /** @var string label: a lang key or a literal string */
    private $label;

    /** @var int[] projects where "Add" is offered (empty = everywhere) */
    private $enabledProjects;

    /** @var int[] projects to search for a target (empty = no restriction) */
    private $targetProjects;

    /** @var array<string,string> meta key => Mantis custom field name */
    private $fields;

    /**
     * @param int[]                 $enabledProjects
     * @param int[]                 $targetProjects
     * @param array<string,string>  $fields
     */
    public function __construct(
        string $key,
        string $provider,
        string $label,
        array $enabledProjects,
        array $targetProjects,
        array $fields
    ) {
        $this->key             = $key;
        $this->provider        = $provider;
        $this->label           = $label;
        $this->enabledProjects = self::positiveInts($enabledProjects);
        $this->targetProjects  = self::positiveInts($targetProjects);
        $this->fields          = $fields;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return int[] */
    public function enabledProjects(): array
    {
        return $this->enabledProjects;
    }

    /** @return int[] */
    public function targetProjects(): array
    {
        return $this->targetProjects;
    }

    /** @return array<string,string> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * True when this type is offered in the given project. An empty
     * enabledProjects list means "everywhere" (the historical customer default).
     */
    public function isEnabledForProject(int $projectId): bool
    {
        return $this->enabledProjects === []
            || in_array($projectId, $this->enabledProjects, true);
    }

    /** First target project, or 0 when none is configured. */
    public function primaryTargetProject(): int
    {
        return $this->targetProjects === [] ? 0 : $this->targetProjects[0];
    }

    /**
     * Keep only positive integers, de-duplicated and re-indexed. Defensive so a
     * mixed config array (ints, numeric strings, junk) yields a clean int list.
     *
     * @param array<int|string,mixed> $values
     * @return int[]
     */
    private static function positiveInts(array $values): array
    {
        $t_out = [];
        foreach ($values as $t_v) {
            if (is_int($t_v) || (is_string($t_v) && ctype_digit($t_v))) {
                $t_n = (int) $t_v;
                if ($t_n > 0) {
                    $t_out[$t_n] = $t_n;
                }
            }
        }
        return array_values($t_out);
    }
}
