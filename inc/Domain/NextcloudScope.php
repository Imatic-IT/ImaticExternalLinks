<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * The set of Nextcloud folders the file picker may browse in a given context,
 * plus the path-safety rules. The service account can technically read a lot,
 * so the picker is constrained to these roots server-side — a requested path is
 * refused unless it normalises to a location at or under one of them. This is
 * the "kdo co vidí" scoping (see ticket 86345): admins map projects to folders,
 * and the picker can never wander outside the mapped subtree, nor climb out of
 * it with `..`.
 *
 * Note: this governs *browsing/listing* only. Opening a picked file happens in
 * the user's own Nextcloud session, so real read permission is still enforced
 * by Nextcloud at click time.
 *
 * Pure value object (no I/O, no globals) so it is covered by the standalone
 * domain test runner. PHP 7.4 baseline.
 */
final class NextcloudScope
{
    /** @var string[] normalised allowed roots, each like "/Zakaznici" (no trailing slash, except "/") */
    private $roots;

    /** @param string[] $roots raw folder paths */
    public function __construct(array $roots)
    {
        $t_norm = [];
        foreach ($roots as $t_raw) {
            $t_path = self::normalize((string) $t_raw);
            if ($t_path !== null && !in_array($t_path, $t_norm, true)) {
                $t_norm[] = $t_path;
            }
        }
        $this->roots = $t_norm;
    }

    /** @return string[] */
    public function roots(): array
    {
        return $this->roots;
    }

    /** The picker is offered only when at least one root is configured. */
    public function isEnabled(): bool
    {
        return $this->roots !== [];
    }

    /**
     * Normalise a client-supplied path and confirm it is inside the scope.
     * Returns the safe, normalised path (e.g. "/Zakaznici/2024") or null when it
     * is syntactically invalid or escapes every root. An empty/"/" request is
     * only valid when a single root is configured (then it resolves to that
     * root); with several roots the caller lists the roots themselves instead.
     */
    public function resolve(string $path): ?string
    {
        $t_trimmed = trim($path);
        if ($t_trimmed === '' || $t_trimmed === '/') {
            return count($this->roots) === 1 ? $this->roots[0] : null;
        }
        $t_norm = self::normalize($t_trimmed);
        if ($t_norm === null) {
            return null; // traversal or garbage
        }
        return $this->contains($t_norm) ? $t_norm : null;
    }

    /** True when $normalisedPath is a root or lives under one. */
    public function contains(string $normalisedPath): bool
    {
        foreach ($this->roots as $t_root) {
            if ($normalisedPath === $t_root) {
                return true;
            }
            $t_prefix = $t_root === '/' ? '/' : $t_root . '/';
            if (strpos($normalisedPath, $t_prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Canonicalise a path: force a leading slash, collapse duplicate slashes,
     * drop "." segments and apply ".." segments. Returns null if ".." would
     * climb above the root (a traversal attempt) — never silently clamped.
     * No trailing slash except for the root "/".
     */
    public static function normalize(string $path): ?string
    {
        $t_path = str_replace('\\', '/', trim($path));
        if ($t_path === '' || $t_path === '/') {
            return '/';
        }
        $t_segments = explode('/', $t_path);
        $t_stack = [];
        foreach ($t_segments as $t_seg) {
            if ($t_seg === '' || $t_seg === '.') {
                continue;
            }
            if ($t_seg === '..') {
                if ($t_stack === []) {
                    return null; // escapes above root
                }
                array_pop($t_stack);
                continue;
            }
            $t_stack[] = $t_seg;
        }
        return '/' . implode('/', $t_stack);
    }
}
