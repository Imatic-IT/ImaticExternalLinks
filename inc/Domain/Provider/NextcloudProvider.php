<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain\Provider;

use ImaticExternalLinks\Contract\NextcloudGateway;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;
use ImaticExternalLinks\Domain\LinkAction;
use ImaticExternalLinks\Domain\LinkMeta;
use ImaticExternalLinks\Domain\LinkProvider;
use ImaticExternalLinks\Domain\MetaValidator;
use ImaticExternalLinks\Domain\NormalizedLink;
use ImaticExternalLinks\Domain\UrlNormalizer;

/**
 * Provider for links pointing at a configured Nextcloud instance. Recognises
 * the common URL shapes, extracts identifiers, and enriches from file metadata
 * through an injected gateway. Office files additionally get an editor action.
 */
final class NextcloudProvider implements LinkProvider
{
    public const KEY = 'nextcloud';

    /** @var array<string,bool> allowed origin => true */
    private $origins;

    /** @var NextcloudGateway|null */
    private $gateway;

    /** @var string 'collabora'|'onlyoffice' */
    private $editor;

    /** @var MetaValidator */
    private $validator;

    /** @var string[] */
    private static $officeMimes = [
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * @param string[]              $baseUrls  configured Nextcloud base URLs
     * @param NextcloudGateway|null $gateway
     */
    public function __construct(
        array $baseUrls,
        ?NextcloudGateway $gateway = null,
        string $editor = 'collabora',
        ?MetaValidator $validator = null
    ) {
        $map = [];
        foreach ($baseUrls as $base) {
            $origin = UrlNormalizer::origin((string) $base);
            if ($origin !== null) {
                $map[$origin] = true;
            }
        }
        $this->origins   = $map;
        $this->gateway   = $gateway;
        $this->editor    = $editor === 'onlyoffice' ? 'onlyoffice' : 'collabora';
        $this->validator = $validator ?: new MetaValidator();
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function matches(string $url): bool
    {
        if (!UrlNormalizer::isHttp($url)) {
            return false;
        }
        $origin = UrlNormalizer::origin($url);
        return $origin !== null && isset($this->origins[$origin]);
    }

    public function normalize(string $url): NormalizedLink
    {
        $trimmed = trim($url);
        if (!$this->matches($trimmed)) {
            throw new InvalidLinkException('Not a recognised Nextcloud URL');
        }
        $meta = self::extractIdentifiers($trimmed);
        return new NormalizedLink(self::KEY, $trimmed, null, $meta);
    }

    /**
     * Pull a file id or share token out of the known Nextcloud URL shapes.
     * Pure. Returns [] when nothing recognisable is present (still a valid NC
     * link — it just falls back to plain open).
     *
     * @return array<string,string>
     */
    public static function extractIdentifiers(string $url): array
    {
        $parts = parse_url($url);
        $path  = isset($parts['path']) ? $parts['path'] : '';

        // /f/<id> or /index.php/f/<id>
        if (preg_match('#/f/(\d+)#', $path, $m)) {
            return ['fileid' => $m[1]];
        }
        // /s/<token> or /index.php/s/<token>
        if (preg_match('#/s/([A-Za-z0-9\-_]+)#', $path, $m)) {
            return ['share' => $m[1]];
        }
        // ?openfile=<id>
        if (isset($parts['query'])) {
            $query = [];
            parse_str($parts['query'], $query);
            if (isset($query['openfile']) && ctype_digit((string) $query['openfile'])) {
                return ['fileid' => (string) $query['openfile']];
            }
        }
        return [];
    }

    public function actions(array $row): array
    {
        $url     = isset($row['url']) ? (string) $row['url'] : '';
        $actions = [new LinkAction('imatic_el_action_open', $url, 'nextcloud', true)];

        $meta   = (isset($row['meta']) && is_array($row['meta'])) ? $row['meta'] : [];
        $fileId = isset($meta['fileid']) ? (string) $meta['fileid'] : '';
        $mime   = isset($meta['mime']) ? (string) $meta['mime'] : '';

        if ($fileId !== '' && $mime !== '' && self::isOfficeMime($mime)) {
            $origin = UrlNormalizer::origin($url);
            if ($origin !== null) {
                $editorUrl = $origin . '/index.php/f/' . rawurlencode($fileId) . '?openfile=true';
                $actions[] = new LinkAction('imatic_el_action_open_editor', $editorUrl, 'editor', true);
            }
        }
        return $actions;
    }

    public function enrich(array $row): ?LinkMeta
    {
        if ($this->gateway === null) {
            return null;
        }
        $meta   = (isset($row['meta']) && is_array($row['meta'])) ? $row['meta'] : [];
        $fileId = isset($meta['fileid']) ? (string) $meta['fileid'] : '';
        if ($fileId === '') {
            return null; // shares / unknown shapes have no fileid to stat
        }
        try {
            $stat = $this->gateway->stat($fileId);
            if ($stat === null) {
                return null;
            }
            $clean = $this->validator->sanitize($stat);
            // Preserve the identifier we resolved by so actions() stays functional.
            $clean['fileid'] = $fileId;
            $title = isset($clean['name']) ? $clean['name'] : (isset($clean['title']) ? $clean['title'] : null);
            return new LinkMeta($title, 'nextcloud', $clean);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isOfficeMime(string $mime): bool
    {
        return in_array(strtolower($mime), self::$officeMimes, true);
    }
}
