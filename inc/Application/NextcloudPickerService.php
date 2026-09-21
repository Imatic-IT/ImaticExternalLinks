<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Application;

use ImaticExternalLinks\Application\Exception\AccessDeniedException;
use ImaticExternalLinks\Application\Exception\NotFoundException;
use ImaticExternalLinks\Contract\AccessGuard;
use ImaticExternalLinks\Contract\NextcloudGateway;
use ImaticExternalLinks\Domain\NextcloudScope;

/**
 * Use-case behind the Nextcloud file picker (ticket 86345): browse the allowed
 * folders and attach a picked file as an external link.
 *
 * Policy lives here, transport in the gateway:
 *   - manage access is required (attaching is a mutation, browsing reveals data),
 *   - every requested path is validated against the {@see NextcloudScope} so the
 *     service account can never be steered outside the project's mapped folders,
 *   - attach re-reads the file server-side (authoritative name/mime/fileid) and
 *     stores it via {@see LinkService::addResolved()} as a `<base>/f/<id>` link,
 *     which opens in the user's own Nextcloud session.
 *
 * Disabled (empty results, hidden button) when no gateway is wired or the scope
 * has no folders for this project — never an error.
 */
final class NextcloudPickerService
{
    /** @var AccessGuard */
    private $access;

    /** @var LinkService */
    private $links;

    /** @var NextcloudGateway|null */
    private $gateway;

    /** @var NextcloudScope */
    private $scope;

    /** @var string base origin used to build the openable file link */
    private $baseUrl;

    public function __construct(
        AccessGuard $access,
        LinkService $links,
        ?NextcloudGateway $gateway,
        NextcloudScope $scope,
        string $baseUrl
    ) {
        $this->access  = $access;
        $this->links   = $links;
        $this->gateway = $gateway;
        $this->scope   = $scope;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Whether the picker is offered (gateway wired + at least one allowed folder). */
    public function isEnabled(): bool
    {
        return $this->gateway !== null && $this->scope->isEnabled();
    }

    /**
     * List a folder within scope. With several allowed roots an empty request
     * returns the roots themselves as folders (the picker's top level); with a
     * single root it opens that root directly.
     *
     * @return array<string,mixed> {'path':string,'roots':string[],'entries':array<int,array<string,mixed>>}
     * @throws AccessDeniedException
     */
    public function browse(int $bugId, string $path): array
    {
        $this->access->ensureCanManage($bugId);

        $t_roots = $this->scope->roots();
        if (!$this->isEnabled()) {
            return ['path' => '/', 'roots' => $t_roots, 'entries' => []];
        }

        $t_trimmed = trim($path);
        if (($t_trimmed === '' || $t_trimmed === '/') && count($t_roots) > 1) {
            return ['path' => '/', 'roots' => $t_roots, 'entries' => $this->rootsAsEntries($t_roots)];
        }

        $t_resolved = $this->scope->resolve($path);
        if ($t_resolved === null) {
            throw new AccessDeniedException('Path is outside the allowed Nextcloud folders');
        }

        return [
            'path'    => $t_resolved,
            'roots'   => $t_roots,
            'entries' => $this->gateway->browse($t_resolved),
        ];
    }

    /**
     * Attach a picked file (given by its in-scope Nextcloud path) to the issue.
     *
     * @return array<string,mixed> the decorated link row
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public function attach(int $bugId, string $path): array
    {
        $this->access->ensureCanManage($bugId);

        if (!$this->isEnabled()) {
            throw new NotFoundException('Nextcloud picker is not enabled');
        }
        $t_resolved = $this->scope->resolve($path);
        if ($t_resolved === null) {
            throw new AccessDeniedException('Path is outside the allowed Nextcloud folders');
        }

        $t_stat = $this->gateway->statPath($t_resolved);
        if ($t_stat === null || !empty($t_stat['isDir'])) {
            throw new NotFoundException('File not found in Nextcloud: ' . $t_resolved);
        }

        $t_fileId = isset($t_stat['fileid']) ? (string) $t_stat['fileid'] : '';
        $t_name   = isset($t_stat['name']) && (string) $t_stat['name'] !== ''
            ? (string) $t_stat['name']
            : basename($t_resolved);

        // Stable, user-openable link. `/index.php/f/<id>` survives renames/moves
        // and opens the file in the viewer's own Nextcloud session. We route via
        // index.php explicitly: instances without the pretty-URL rewrite return a
        // hard 404 on a bare `/f/<id>`.
        $t_url = $t_fileId !== ''
            ? $this->baseUrl . '/index.php/f/' . rawurlencode($t_fileId)
            : $this->baseUrl . '/remote.php/dav/files' . $t_resolved;

        $t_meta = [
            'fileid'  => $t_fileId,
            'mime'    => isset($t_stat['mime']) ? (string) $t_stat['mime'] : '',
            'size'    => isset($t_stat['size']) ? (int) $t_stat['size'] : 0,
            'nc_path' => $t_resolved,
        ];

        return $this->links->addResolved($bugId, $t_url, $t_name, $t_meta, null);
    }

    /**
     * Present the configured roots as folder entries for the picker's top level.
     *
     * @param string[] $roots
     * @return array<int,array<string,mixed>>
     */
    private function rootsAsEntries(array $roots): array
    {
        $t_out = [];
        foreach ($roots as $t_root) {
            $t_out[] = [
                'fileid' => '',
                'name'   => $t_root === '/' ? '/' : basename($t_root),
                'path'   => $t_root,
                'isDir'  => true,
                'mime'   => '',
                'size'   => 0,
            ];
        }
        return $t_out;
    }
}
