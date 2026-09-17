<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Application\Exception\AccessDeniedException;
use ImaticExternalLinks\Contract\AccessGuard;

/**
 * AccessGuard backed by Mantis' per-bug access checks. Thresholds are injected
 * (read from plugin config in the composition root) so this class does not
 * depend on the plugin singleton and stays a thin adapter over access_api.
 */
final class MantisAccessGuard implements AccessGuard
{
    /** @var int */
    private $viewThreshold;

    /** @var int */
    private $manageThreshold;

    public function __construct(int $viewThreshold, int $manageThreshold)
    {
        $this->viewThreshold   = $viewThreshold;
        $this->manageThreshold = $manageThreshold;
    }

    public function canView(int $bugId): bool
    {
        return access_has_bug_level($this->viewThreshold, $bugId);
    }

    public function canManage(int $bugId): bool
    {
        return access_has_bug_level($this->manageThreshold, $bugId);
    }

    public function ensureCanView(int $bugId): void
    {
        if (!$this->canView($bugId)) {
            throw new AccessDeniedException('View access denied for bug ' . $bugId);
        }
    }

    public function ensureCanManage(int $bugId): void
    {
        if (!$this->canManage($bugId)) {
            throw new AccessDeniedException('Manage access denied for bug ' . $bugId);
        }
    }

    public function currentUserId(): int
    {
        return (int) auth_get_current_user_id();
    }
}
