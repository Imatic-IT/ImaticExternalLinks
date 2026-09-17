<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Per-user secret storage boundary (e.g. Nextcloud app passwords). Values are
 * encrypted at rest by the implementation; the domain sees only plaintext it
 * asked for and never persists secrets itself.
 */
interface CredentialStore
{
    public function get(int $userId, string $key): ?string;

    public function put(int $userId, string $key, string $value): void;

    public function delete(int $userId, string $key): void;
}
