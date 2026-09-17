<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Authorization boundary. Wraps Mantis access checks so the application layer
 * stays testable with a fake guard.
 *
 * can*() are non-throwing predicates for rendering decisions; ensure*() are the
 * enforcement points used by controllers/services and throw on denial (mapped
 * to HTTP 403 at the edge). Fail closed: unknown state must deny.
 */
interface AccessGuard
{
    public function canView(int $bugId): bool;

    public function canManage(int $bugId): bool;

    /** @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException */
    public function ensureCanView(int $bugId): void;

    /** @throws \ImaticExternalLinks\Application\Exception\AccessDeniedException */
    public function ensureCanManage(int $bugId): void;

    public function currentUserId(): int;
}
