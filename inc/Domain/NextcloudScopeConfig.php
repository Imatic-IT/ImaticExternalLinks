<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Turns the raw folder-scoping config into a {@see NextcloudScope} for one
 * project. Two inputs:
 *
 *   - per-project map  (projectId => folder or list of folders), and
 *   - a global fallback list used for any project without its own mapping.
 *
 * A project with an explicit (non-empty) mapping uses exactly those folders;
 * otherwise it falls back to the global list. So admins can say e.g.
 * "project imatic-it-customers → /Zakaznici" and leave everything else on a
 * shared default (or on nothing, which disables the picker there).
 *
 * Pure: array-shaping only, covered by the domain test runner. PHP 7.4.
 */
final class NextcloudScopeConfig
{
    /**
     * @param array<int|string,mixed> $projectFolders projectId => string|string[]
     * @param array<int,mixed>        $globalFolders  fallback folder list
     */
    public static function forProject(array $projectFolders, array $globalFolders, int $projectId): NextcloudScope
    {
        $t_own = self::foldersFor($projectFolders, $projectId);
        if ($t_own !== []) {
            return new NextcloudScope($t_own);
        }
        return new NextcloudScope(self::asFolderList($globalFolders));
    }

    /**
     * Folders explicitly mapped to a project (by int or numeric-string key), as
     * a clean string list. Empty when the project has no mapping.
     *
     * @param array<int|string,mixed> $projectFolders
     * @return string[]
     */
    private static function foldersFor(array $projectFolders, int $projectId): array
    {
        foreach ($projectFolders as $t_key => $t_value) {
            if ((is_int($t_key) || (is_string($t_key) && ctype_digit($t_key))) && (int) $t_key === $projectId) {
                return self::asFolderList($t_value);
            }
        }
        return [];
    }

    /**
     * Accept either a single folder string or a list of them; return a clean
     * list of non-empty trimmed strings.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function asFolderList($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $t_out = [];
        foreach ($value as $t_v) {
            if (!is_string($t_v)) {
                continue;
            }
            $t_v = trim($t_v);
            if ($t_v !== '') {
                $t_out[] = $t_v;
            }
        }
        return $t_out;
    }
}
