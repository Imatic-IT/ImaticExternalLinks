<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Contract\NextcloudGateway;

/**
 * NextcloudGateway backed by the Nextcloud WebDAV API using a single
 * "service account" (username + app password from plugin config). Browsing and
 * per-path metadata go through PROPFIND against
 * `<base>/remote.php/dav/files/<user>/<path>`.
 *
 * Why a service account: a purely client-side picker cannot list Nextcloud from
 * the Mantis origin (cross-domain / no NC session), so listing is done
 * server-side with one account. Visibility is then constrained by the caller's
 * {@see \ImaticExternalLinks\Domain\NextcloudScope} (per-project folders), and
 * actually *opening* a picked file still happens in the user's own Nextcloud
 * session — so Nextcloud's real permissions apply at click time.
 *
 * cURL is used directly here (this is Infra, like MantisCustomerGateway, and not
 * exercised by the pure domain test runner), mirroring CurlHttpClient's safety:
 * http/https only, no redirect following, TLS verification, timeouts, body cap.
 * On any transport/parse failure it fails soft (null / empty list).
 */
final class WebDavNextcloudGateway implements NextcloudGateway
{
    private const OC_NS  = 'http://owncloud.org/ns';
    private const DAV_NS = 'DAV:';

    /** @var string origin, e.g. "https://cloud.imatic.cz" (no trailing slash) */
    private $baseUrl;

    /** @var string service account user */
    private $user;

    /** @var string service account app password */
    private $password;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /** @var int */
    private $maxBytes;

    public function __construct(
        string $baseUrl,
        string $user,
        string $password,
        int $connectTimeout = 5,
        int $timeout = 15,
        int $maxBytes = 4194304
    ) {
        $this->baseUrl        = rtrim($baseUrl, '/');
        $this->user           = $user;
        $this->password       = $password;
        $this->connectTimeout = $connectTimeout;
        $this->timeout        = $timeout;
        $this->maxBytes       = $maxBytes;
    }

    public function stat(string $fileId): ?array
    {
        // Resolving a bare Nextcloud file id server-side is version-specific and
        // not needed by the picker (attach captures metadata via statPath, which
        // is then persisted in the link meta). Left unimplemented; enrichment by
        // id simply stays off, exactly as before this gateway existed.
        return null;
    }

    public function statPath(string $path): ?array
    {
        $t_entries = $this->propfind($path, 0);
        if ($t_entries === []) {
            return null;
        }
        // Depth 0 returns the resource itself as the single entry.
        return $t_entries[0];
    }

    public function browse(string $path): array
    {
        $t_entries = $this->propfind($path, 1);
        $t_self    = \ImaticExternalLinks\Domain\NextcloudScope::normalize($path);

        // Depth 1 includes the folder itself first — drop it; keep children,
        // folders before files then alphabetical for a predictable picker order.
        $t_out = [];
        foreach ($t_entries as $t_entry) {
            if (($t_entry['path'] ?? null) === $t_self) {
                continue;
            }
            $t_out[] = $t_entry;
        }
        usort($t_out, static function (array $a, array $b): int {
            if (($a['isDir'] ?? false) !== ($b['isDir'] ?? false)) {
                return ($a['isDir'] ?? false) ? -1 : 1;
            }
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        return $t_out;
    }

    /**
     * Run a PROPFIND at the given depth and parse the multistatus into entries.
     *
     * @return array<int,array<string,mixed>>
     */
    private function propfind(string $path, int $depth): array
    {
        $t_url = $this->baseUrl . '/remote.php/dav/files/' . rawurlencode($this->user) . $this->encodePath($path);

        $t_body = '<?xml version="1.0"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            . '<d:prop>'
            . '<oc:fileid/><d:displayname/><d:getcontenttype/><d:getcontentlength/><d:resourcetype/>'
            . '</d:prop></d:propfind>';

        $t_res = $this->request('PROPFIND', $t_url, [
            'Depth: ' . $depth,
            'Content-Type: application/xml; charset=utf-8',
        ], $t_body);

        if ($t_res === null || $t_res['status'] !== 207) {
            return [];
        }
        return $this->parseMultistatus($t_res['body']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseMultistatus(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }
        $t_prev = libxml_use_internal_errors(true);
        $t_doc  = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($t_prev);
        if ($t_doc === false) {
            return [];
        }

        $t_prefix = '/remote.php/dav/files/' . rawurlencode($this->user);
        $t_out    = [];

        foreach ($t_doc->children(self::DAV_NS)->response as $t_resp) {
            $t_dav  = $t_resp->children(self::DAV_NS);
            $t_href = rawurldecode((string) $t_dav->href);

            // Reduce the href to a path relative to the user's files root.
            $t_rel = $t_href;
            $t_pos = strpos($t_href, $t_prefix);
            if ($t_pos !== false) {
                $t_rel = substr($t_href, $t_pos + strlen($t_prefix));
            }
            $t_rel = \ImaticExternalLinks\Domain\NextcloudScope::normalize($t_rel);
            if ($t_rel === null) {
                continue;
            }

            $t_props = $t_dav->propstat->prop;
            $t_isDir = isset($t_props->resourcetype) && isset($t_props->resourcetype->children(self::DAV_NS)->collection);

            $t_name = (string) $t_props->displayname;
            if ($t_name === '') {
                $t_name = $t_rel === '/' ? '/' : basename($t_rel);
            }

            $t_ocProps = $t_props->children(self::OC_NS);
            $t_fileId  = isset($t_ocProps->fileid) ? (string) $t_ocProps->fileid : '';

            $t_out[] = [
                'fileid' => $t_fileId,
                'name'   => $t_name,
                'path'   => $t_rel,
                'isDir'  => $t_isDir,
                'mime'   => $t_isDir ? '' : (string) $t_props->getcontenttype,
                'size'   => (int) (string) $t_props->getcontentlength,
            ];
        }
        return $t_out;
    }

    /** Percent-encode each path segment, preserving the slashes. Leading slash kept. */
    private function encodePath(string $path): string
    {
        $t_norm = \ImaticExternalLinks\Domain\NextcloudScope::normalize($path);
        if ($t_norm === null || $t_norm === '/') {
            return '/';
        }
        $t_segments = array_map('rawurlencode', array_filter(explode('/', $t_norm), static function ($s): bool {
            return $s !== '';
        }));
        return '/' . implode('/', $t_segments);
    }

    /**
     * cURL request with the same operational safety as CurlHttpClient plus
     * PROPFIND support (custom method + request body + basic auth).
     *
     * @param string[] $headers
     * @return array{status:int,body:string}|null null on transport failure
     */
    private function request(string $method, string $url, array $headers, ?string $body): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $t_handle = curl_init();
        if ($t_handle === false) {
            return null;
        }

        $t_buf = '';
        $t_options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->password,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$t_buf): int {
                $t_buf .= $chunk;
                if (strlen($t_buf) > $this->maxBytes) {
                    return 0;
                }
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $t_options[CURLOPT_POSTFIELDS] = $body;
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $t_options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($t_handle, $t_options);
        $t_ok     = curl_exec($t_handle);
        $t_status = (int) curl_getinfo($t_handle, CURLINFO_RESPONSE_CODE);
        $t_errno  = curl_errno($t_handle);
        curl_close($t_handle);

        if ($t_ok === false && $t_errno !== 0 && $t_errno !== CURLE_WRITE_ERROR) {
            return null;
        }
        if (strlen($t_buf) > $this->maxBytes) {
            $t_buf = substr($t_buf, 0, $this->maxBytes);
        }
        return ['status' => $t_status, 'body' => $t_buf];
    }
}
