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
     * File/folder metadata by Nextcloud path (server-relative to the user's
     * files root, e.g. "/Zakaznici/2024/a.pdf"), or null when it cannot be
     * resolved. Used at attach time to capture authoritative name/mime/fileid.
     *
     * @return array<string,mixed>|null e.g. ['fileid'=>..,'name'=>..,'mime'=>..,'size'=>..,'isDir'=>bool]
     */
    public function statPath(string $path): ?array;

    /**
     * Folder listing for the picker.
     *
     * @return array<int,array<string,mixed>> entries (name/path/mime/size/isDir/fileid)
     */
    public function browse(string $path): array;
}
