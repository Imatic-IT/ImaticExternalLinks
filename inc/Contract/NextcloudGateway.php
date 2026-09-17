<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Nextcloud access boundary. The concrete gateway is constructed per-request
 * with the acting user's credentials, so the domain never handles auth or
 * user identity — it just asks for a file's metadata or a folder listing.
 */
interface NextcloudGateway
{
    /**
     * File/folder metadata by Nextcloud file id, or null if it cannot be
     * resolved (missing, no permission, transport error).
     *
     * @return array<string,mixed>|null e.g. ['name'=>..,'mime'=>..,'size'=>..]
     */
    public function stat(string $fileId): ?array;

    /**
     * Folder listing for the picker.
     *
     * @return array<int,array<string,mixed>> entries (name/path/mime/size/...)
     */
    public function browse(string $path): array;
}
