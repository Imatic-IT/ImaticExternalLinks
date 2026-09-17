<?php

declare(strict_types=1);

/**
 * Standalone, dependency-free test runner for the ImaticExternalLinks pure
 * domain. No PHPUnit, no Mantis, no DB, no network. Runs on PHP 7.4 and 8.x.
 *
 *   php plugins/ImaticExternalLinks/tests/run.php
 *
 * Exit code 0 = all green, 1 = failures.
 */

$root = dirname(__DIR__);

// Load in dependency order (interfaces / VOs before their users).
require $root . '/inc/Contract/HttpResponse.php';
require $root . '/inc/Contract/HttpClient.php';
require $root . '/inc/Contract/NextcloudGateway.php';
require $root . '/inc/Contract/CustomerGateway.php';
require $root . '/inc/Domain/Exception/InvalidLinkException.php';
require $root . '/inc/Domain/Exception/DuplicateLinkException.php';
require $root . '/inc/Domain/NormalizedLink.php';
require $root . '/inc/Domain/LinkMeta.php';
require $root . '/inc/Domain/LinkAction.php';
require $root . '/inc/Domain/UrlNormalizer.php';
require $root . '/inc/Domain/OriginAllowList.php';
require $root . '/inc/Domain/MetaValidator.php';
require $root . '/inc/Domain/LinkProvider.php';
require $root . '/inc/Domain/ProviderRegistry.php';
require $root . '/inc/Domain/RelationDefinition.php';
require $root . '/inc/Domain/RelationConfig.php';
require $root . '/inc/Domain/Provider/GenericUrlProvider.php';
require $root . '/inc/Domain/Provider/NextcloudProvider.php';
require $root . '/inc/Domain/Provider/CustomerProvider.php';
require $root . '/inc/Contract/AccessGuard.php';
require $root . '/inc/Contract/LinkRepository.php';
require $root . '/inc/Application/Exception/AccessDeniedException.php';
require $root . '/inc/Application/Exception/NotFoundException.php';
require $root . '/inc/Application/LinkService.php';
require $root . '/inc/Application/CustomerPickerService.php';

use ImaticExternalLinks\Application\CustomerPickerService;
use ImaticExternalLinks\Application\Exception\AccessDeniedException;
use ImaticExternalLinks\Application\Exception\NotFoundException;
use ImaticExternalLinks\Application\LinkService;
use ImaticExternalLinks\Contract\AccessGuard;
use ImaticExternalLinks\Contract\HttpClient;
use ImaticExternalLinks\Contract\HttpResponse;
use ImaticExternalLinks\Contract\LinkRepository;
use ImaticExternalLinks\Contract\NextcloudGateway;
use ImaticExternalLinks\Contract\CustomerGateway;
use ImaticExternalLinks\Domain\Exception\DuplicateLinkException;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;
use ImaticExternalLinks\Domain\MetaValidator;
use ImaticExternalLinks\Domain\OriginAllowList;
use ImaticExternalLinks\Domain\Provider\GenericUrlProvider;
use ImaticExternalLinks\Domain\Provider\NextcloudProvider;
use ImaticExternalLinks\Domain\Provider\CustomerProvider;
use ImaticExternalLinks\Domain\ProviderRegistry;
use ImaticExternalLinks\Domain\RelationConfig;
use ImaticExternalLinks\Domain\RelationDefinition;
use ImaticExternalLinks\Domain\UrlNormalizer;

// ─── tiny assertion harness ──────────────────────────────────────────────────

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;
$GLOBALS['__group'] = '';

function group(string $name): void
{
    $GLOBALS['__group'] = $name;
    echo "\n" . $name . "\n";
}

function ok(bool $cond, string $msg): void
{
    $GLOBALS['__tests']++;
    if ($cond) {
        echo "  ok   " . $msg . "\n";
    } else {
        $GLOBALS['__fails']++;
        echo "  FAIL " . $msg . "\n";
    }
}

function eq($expected, $actual, string $msg): void
{
    ok($expected === $actual, $msg . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ')');
}

// ─── test doubles ────────────────────────────────────────────────────────────

final class StubHttpClient implements HttpClient
{
    /** @var HttpResponse */
    private $response;
    /** @var string|null */
    public $lastUrl = null;

    public function __construct(HttpResponse $response)
    {
        $this->response = $response;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->lastUrl = $url;
        return $this->response;
    }
}

final class ThrowingHttpClient implements HttpClient
{
    public function get(string $url, array $headers = []): HttpResponse
    {
        throw new \RuntimeException('boom');
    }
}

final class StubNextcloudGateway implements NextcloudGateway
{
    /** @var array<string,mixed>|null */
    private $stat;

    public function __construct(?array $stat)
    {
        $this->stat = $stat;
    }

    public function stat(string $fileId): ?array
    {
        return $this->stat;
    }

    public function browse(string $path): array
    {
        return [];
    }
}

final class StubCustomerGateway implements CustomerGateway
{
    /** @var array<string,mixed>|null */
    private $record;
    /** @var int|null */
    public $lastId = null;

    /** @param array<string,mixed>|null $record */
    public function __construct(?array $record)
    {
        $this->record = $record;
    }

    public function fetch(int $customerId): ?array
    {
        $this->lastId = $customerId;
        return $this->record;
    }

    public function search(string $query, int $limit = 20): array
    {
        return [];
    }
}

final class ThrowingCustomerGateway implements CustomerGateway
{
    public function fetch(int $customerId): ?array
    {
        throw new \RuntimeException('boom');
    }

    public function search(string $query, int $limit = 20): array
    {
        return [];
    }
}

/** In-memory LinkRepository for LinkService tests. */
final class FakeLinkRepository implements LinkRepository
{
    /** @var array<int,array<string,mixed>> */
    public $rows = [];
    /** @var int */
    private $seq = 0;

    public function insert(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = [
            'id'          => $id,
            'bug_id'      => (int) $data['bug_id'],
            'provider'    => (string) $data['provider'],
            'url'         => (string) $data['url'],
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'meta'        => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            'position'    => $id,
            'created_by'  => (int) $data['created_by'],
            'created_at'  => 0,
            'updated_at'  => 0,
        ];
        return $id;
    }

    public function findByBug(int $bugId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['bug_id'] === $bugId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function find(int $bugId, int $linkId): ?array
    {
        $row = $this->rows[$linkId] ?? null;
        return ($row !== null && $row['bug_id'] === $bugId) ? $row : null;
    }

    public function delete(int $bugId, int $linkId): bool
    {
        if (isset($this->rows[$linkId]) && $this->rows[$linkId]['bug_id'] === $bugId) {
            unset($this->rows[$linkId]);
            return true;
        }
        return false;
    }

    public function updateEnrichment(int $bugId, int $linkId, ?string $title, array $meta): bool
    {
        if (!isset($this->rows[$linkId]) || $this->rows[$linkId]['bug_id'] !== $bugId) {
            return false;
        }
        $this->rows[$linkId]['title'] = $title;
        $this->rows[$linkId]['meta']  = $meta;
        return true;
    }
}

/** Configurable AccessGuard for LinkService tests. */
final class FakeAccessGuard implements AccessGuard
{
    /** @var bool */
    private $view;
    /** @var bool */
    private $manage;
    /** @var int */
    private $userId;

    public function __construct(bool $view = true, bool $manage = true, int $userId = 7)
    {
        $this->view   = $view;
        $this->manage = $manage;
        $this->userId = $userId;
    }

    public function canView(int $bugId): bool
    {
        return $this->view;
    }

    public function canManage(int $bugId): bool
    {
        return $this->manage;
    }

    public function ensureCanView(int $bugId): void
    {
        if (!$this->view) {
            throw new AccessDeniedException('view denied');
        }
    }

    public function ensureCanManage(int $bugId): void
    {
        if (!$this->manage) {
            throw new AccessDeniedException('manage denied');
        }
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }
}

/** Build a registry with a real generic provider (and optional NC provider). */
function el_registry(?NextcloudProvider $nc = null): ProviderRegistry
{
    $providers = [];
    if ($nc !== null) {
        $providers[] = $nc;
    }
    $providers[] = new GenericUrlProvider();
    return new ProviderRegistry($providers);
}

// ─── UrlNormalizer ───────────────────────────────────────────────────────────

group('UrlNormalizer');
eq('https', UrlNormalizer::scheme('HTTPS://Example.com/x'), 'scheme lower-cased');
eq(null, UrlNormalizer::scheme('not a url with spaces? ::'), 'unparseable → null scheme');
eq('example.com', UrlNormalizer::host('https://Example.COM/path'), 'host lower-cased');
eq('https://example.com', UrlNormalizer::origin('https://Example.com/a/b?c=d'), 'origin without port');
eq('https://example.com:8443', UrlNormalizer::origin('https://example.com:8443/x'), 'origin keeps explicit port');
ok(UrlNormalizer::isHttp('http://a.b'), 'http is http');
ok(UrlNormalizer::isHttp('https://a.b'), 'https is http');
ok(!UrlNormalizer::isHttp('ftp://a.b'), 'ftp is not http');
ok(!UrlNormalizer::isHttp('javascript:alert(1)'), 'javascript scheme not http');
ok(!UrlNormalizer::isHttp('file:///etc/passwd'), 'file scheme not http');
ok(UrlNormalizer::hasCredentials('https://user:pass@host/'), 'detects user:pass');
ok(UrlNormalizer::hasCredentials('https://user@host/'), 'detects bare user');
ok(!UrlNormalizer::hasCredentials('https://host/'), 'no credentials → false');

// ─── OriginAllowList (SSRF guard) ────────────────────────────────────────────

group('OriginAllowList (SSRF)');
$allow = new OriginAllowList(['https://cloud.example.com', 'https://git.example.com:8443']);
ok($allow->permits('https://cloud.example.com/f/12'), 'allow-listed origin permitted');
ok($allow->permits('https://git.example.com:8443/repo'), 'allow-listed origin with port permitted');
ok(!$allow->permits('https://cloud.example.com:9999/x'), 'wrong port rejected');
ok(!$allow->permits('http://cloud.example.com/f/12'), 'wrong scheme (http vs https) rejected');
ok(!$allow->permits('https://evil.example.com/x'), 'off-list origin rejected');
ok(!$allow->permits('https://user:pass@cloud.example.com/x'), 'credentials-in-URL rejected even if origin listed');
ok(!$allow->permits('javascript:alert(1)'), 'javascript scheme rejected');
ok(!$allow->permits('file:///etc/passwd'), 'file scheme rejected');
ok(!$allow->permits('ftp://cloud.example.com/x'), 'ftp scheme rejected');

$allowLocal = new OriginAllowList([
    'http://localhost', 'http://127.0.0.1', 'http://10.0.0.5',
    'http://169.254.169.254', 'http://[::1]', 'http://192.168.1.1',
]);
ok(!$allowLocal->permits('http://localhost/x'), 'localhost blocked even if listed');
ok(!$allowLocal->permits('http://127.0.0.1/x'), 'loopback IPv4 blocked');
ok(!$allowLocal->permits('http://10.0.0.5/x'), 'private 10/8 blocked');
ok(!$allowLocal->permits('http://192.168.1.1/x'), 'private 192.168/16 blocked');
ok(!$allowLocal->permits('http://169.254.169.254/latest/meta-data'), 'link-local metadata IP blocked');
ok(!$allowLocal->permits('http://[::1]/x'), 'loopback IPv6 blocked');
$emptyAllow = new OriginAllowList([]);
ok(!$emptyAllow->permits('https://cloud.example.com/x'), 'empty allow-list is fail-closed');

// ─── MetaValidator ───────────────────────────────────────────────────────────

group('MetaValidator');
$validator = new MetaValidator();
$clean = $validator->sanitize([
    'name'     => 'report.docx',
    'mime'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'size'     => '1234',
    'editable' => 1,
    'evil'     => '<script>alert(1)</script>',
    'nested'   => ['x' => 'y'],
]);
eq('report.docx', $clean['name'], 'name kept');
eq(1234, $clean['size'], 'size coerced to int');
eq(true, $clean['editable'], 'editable coerced to bool');
ok(!array_key_exists('evil', $clean), 'unknown key dropped (no attribute injection)');
ok(!array_key_exists('nested', $clean), 'unknown non-scalar key dropped');

$xss = $validator->sanitize(['name' => "a<script>b</script>\x00\x07c"]);
eq('a<script>b</script>c', $xss['name'], 'control chars stripped, markup left for output-escaping layer');

$long = str_repeat('x', 900);
$capped = $validator->sanitize(['title' => $long]);
eq(500, strlen($capped['title']), 'string length capped at 500');

// ─── ProviderRegistry ────────────────────────────────────────────────────────

group('ProviderRegistry');
$nc      = new NextcloudProvider(['https://cloud.example.com']);
$generic = new GenericUrlProvider();
$registry = new ProviderRegistry([$nc, $generic]);
eq('nextcloud', $registry->forUrl('https://cloud.example.com/f/42')->key(), 'NC url resolves to nextcloud');
eq('generic', $registry->forUrl('https://github.com/acme/repo')->key(), 'foreign url resolves to generic');
eq('generic', $registry->forUrl('ftp://x/y')->key(), 'non-http falls through to catch-all (generic)');
eq('nextcloud', $registry->byKey('nextcloud')->key(), 'byKey finds provider');
eq(null, $registry->byKey('missing'), 'byKey unknown → null');

// ─── GenericUrlProvider ──────────────────────────────────────────────────────

group('GenericUrlProvider');
$g = new GenericUrlProvider();
$n = $g->normalize('  https://github.com/acme/repo  ');
eq('generic', $n->provider, 'normalize sets provider');
eq('https://github.com/acme/repo', $n->url, 'normalize trims url');

$threw = false;
try {
    $g->normalize('javascript:alert(1)');
} catch (InvalidLinkException $e) {
    $threw = true;
}
ok($threw, 'normalize rejects non-http with InvalidLinkException');

$threwNoHost = false;
try {
    $g->normalize('https:///nohost');
} catch (InvalidLinkException $e) {
    $threwNoHost = true;
}
ok($threwNoHost, 'normalize rejects url without host');

$actions = $g->actions(['url' => 'https://x.y/z']);
eq(1, count($actions), 'generic exposes one action');
eq('imatic_el_action_open', $actions[0]->label, 'generic action is open');

eq('Example Domain', GenericUrlProvider::extractTitle('<html><head><TITLE> Example Domain </TITLE></head>'), 'extractTitle parses + trims');
eq(null, GenericUrlProvider::extractTitle('<html>no title here</html>'), 'extractTitle → null when absent');

// enrich: allow-list enforced, success path, failure paths all soft
$allowG = new OriginAllowList(['https://good.example.com']);
$http = new StubHttpClient(new HttpResponse(200, '<title>Hello</title>'));
$gEnrich = new GenericUrlProvider($http, $allowG);
$meta = $gEnrich->enrich(['url' => 'https://good.example.com/page']);
ok($meta !== null && $meta->title === 'Hello', 'enrich returns title for allow-listed 200');

$httpOff = new StubHttpClient(new HttpResponse(200, '<title>Hello</title>'));
$gOff = new GenericUrlProvider($httpOff, $allowG);
$offList = $gOff->enrich(['url' => 'https://evil.example.com/page']);
eq(null, $offList, 'enrich refuses off-list origin (no fetch)');
eq(null, $httpOff->lastUrl, 'http client was never called for off-list url');

$http404 = new StubHttpClient(new HttpResponse(404, 'nope'));
$g404 = new GenericUrlProvider($http404, $allowG);
eq(null, $g404->enrich(['url' => 'https://good.example.com/x']), 'enrich soft-fails on non-2xx');

$gThrow = new GenericUrlProvider(new ThrowingHttpClient(), $allowG);
eq(null, $gThrow->enrich(['url' => 'https://good.example.com/x']), 'enrich swallows transport exception');

$gNoHttp = new GenericUrlProvider();
eq(null, $gNoHttp->enrich(['url' => 'https://good.example.com/x']), 'enrich → null without http client');

// ─── NextcloudProvider ───────────────────────────────────────────────────────

group('NextcloudProvider');
$ncp = new NextcloudProvider(['https://cloud.example.com'], null, 'collabora');
ok($ncp->matches('https://cloud.example.com/f/42'), 'matches configured origin');
ok(!$ncp->matches('https://other.example.com/f/42'), 'does not match foreign origin');

eq(['fileid' => '42'], NextcloudProvider::extractIdentifiers('https://cloud.example.com/f/42'), 'extract /f/<id>');
eq(['fileid' => '99'], NextcloudProvider::extractIdentifiers('https://cloud.example.com/index.php/f/99'), 'extract /index.php/f/<id>');
eq(['fileid' => '7'], NextcloudProvider::extractIdentifiers('https://cloud.example.com/apps/files/?openfile=7&dir=/'), 'extract ?openfile=<id>');
eq(['share' => 'AbC-123_x'], NextcloudProvider::extractIdentifiers('https://cloud.example.com/s/AbC-123_x'), 'extract /s/<token>');
eq([], NextcloudProvider::extractIdentifiers('https://cloud.example.com/apps/dashboard'), 'unknown shape → []');

$normNc = $ncp->normalize('https://cloud.example.com/f/42');
eq('nextcloud', $normNc->provider, 'normalize sets nextcloud provider');
eq(['fileid' => '42'], $normNc->meta, 'normalize captures identifiers into meta');

$threwNc = false;
try {
    $ncp->normalize('https://other.example.com/f/1');
} catch (InvalidLinkException $e) {
    $threwNc = true;
}
ok($threwNc, 'normalize rejects non-NC origin');

ok(NextcloudProvider::isOfficeMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'docx recognised as office');
ok(!NextcloudProvider::isOfficeMime('image/png'), 'png not office');

// actions: editor action only for office files with a fileid
$openOnly = $ncp->actions(['url' => 'https://cloud.example.com/f/42', 'meta' => ['fileid' => '42', 'mime' => 'image/png']]);
eq(1, count($openOnly), 'non-office file gets open action only');

$withEditor = $ncp->actions([
    'url'  => 'https://cloud.example.com/f/42',
    'meta' => ['fileid' => '42', 'mime' => 'application/vnd.oasis.opendocument.text'],
]);
eq(2, count($withEditor), 'office file gets open + editor actions');
eq('imatic_el_action_open_editor', $withEditor[1]->label, 'second action is editor');
eq('https://cloud.example.com/f/42?openfile=true', $withEditor[1]->url, 'editor url built from origin + fileid');

// enrich: gateway metadata sanitized into LinkMeta
$gw = new StubNextcloudGateway(['name' => 'notes.odt', 'mime' => 'application/vnd.oasis.opendocument.text', 'size' => '55', 'junk' => 'x']);
$ncEnrich = new NextcloudProvider(['https://cloud.example.com'], $gw);
$m = $ncEnrich->enrich(['url' => 'https://cloud.example.com/f/42', 'meta' => ['fileid' => '42']]);
ok($m !== null, 'enrich returns meta on success');
eq('notes.odt', $m->title, 'enrich title from file name');
eq('42', $m->attributes['fileid'], 'enrich preserves resolved fileid');
ok(!array_key_exists('junk', $m->attributes), 'enrich drops unknown gateway keys');

$ncNoId = $ncEnrich->enrich(['url' => 'https://cloud.example.com/s/tok', 'meta' => ['share' => 'tok']]);
eq(null, $ncNoId, 'enrich → null without a fileid (share/unknown)');

$ncNullStat = new NextcloudProvider(['https://cloud.example.com'], new StubNextcloudGateway(null));
eq(null, $ncNullStat->enrich(['url' => 'https://cloud.example.com/f/1', 'meta' => ['fileid' => '1']]), 'enrich → null when gateway cannot stat');

$ncNoGw = new NextcloudProvider(['https://cloud.example.com']);
eq(null, $ncNoGw->enrich(['url' => 'https://cloud.example.com/f/1', 'meta' => ['fileid' => '1']]), 'enrich → null without gateway');

// ─── CustomerProvider ────────────────────────────────────────────────────────

group('CustomerProvider — parseId / urlForId (pure)');
eq(5, CustomerProvider::parseId('customer://5'), 'parseId from customer://<id>');
eq(42, CustomerProvider::parseId('  customer://42  '), 'parseId trims surrounding space');
eq(null, CustomerProvider::parseId('customer:5'), 'parseId rejects schemeless customer:<id> (ambiguous host:port)');
eq(null, CustomerProvider::parseId('customer://0'), 'parseId rejects zero');
eq(null, CustomerProvider::parseId('customer://abc'), 'parseId rejects non-numeric');
eq(null, CustomerProvider::parseId('customer://-3'), 'parseId rejects negative');
eq(null, CustomerProvider::parseId('https://mantis.local/view.php?id=5'), 'parseId rejects plain http url');
eq('customer://7', CustomerProvider::urlForId(7), 'urlForId builds canonical uri');

$urlForIdThrew = false;
try {
    CustomerProvider::urlForId(0);
} catch (InvalidLinkException $e) {
    $urlForIdThrew = true;
}
ok($urlForIdThrew, 'urlForId rejects non-positive id');

group('CustomerProvider — matches / normalize');
$cust = new CustomerProvider('https://mantis.local');
eq('customer', $cust->key(), 'provider key is customer');
ok($cust->matches('customer://5'), 'matches its own canonical uri');
ok(!$cust->matches('https://mantis.local/view.php?id=5'), 'does not claim plain http issue urls (→ generic)');
ok(!$cust->matches('customer://x'), 'does not match malformed id');

$cn = $cust->normalize('customer://12');
eq('customer', $cn->provider, 'normalize sets customer provider');
eq('customer://12', $cn->url, 'normalize canonicalises url');
eq('12', $cn->meta['customer_id'], 'normalize captures customer_id into meta');

$custNormThrew = false;
try {
    $cust->normalize('customer://nope');
} catch (InvalidLinkException $e) {
    $custNormThrew = true;
}
ok($custNormThrew, 'normalize rejects malformed customer reference');

group('CustomerProvider — actions');
$openCust = $cust->actions(['url' => 'customer://12', 'meta' => ['customer_id' => '12']]);
eq(1, count($openCust), 'customer row exposes a single open action');
eq('imatic_el_action_open_customer', $openCust[0]->label, 'action is open-customer');
eq('https://mantis.local/view.php?id=12', $openCust[0]->url, 'action links to the mantis issue view');
eq(false, $openCust[0]->external, 'internal link opens in same tab (external=false)');

$openNoBase = (new CustomerProvider())->actions(['url' => 'customer://9', 'meta' => []]);
eq('view.php?id=9', $openNoBase[0]->url, 'action falls back to relative url without base + parses id from url');

group('CustomerProvider — enrich (fake gateway)');
$custGw = new StubCustomerGateway([
    'name'          => 'ACME s.r.o.',
    'ico'           => '12345678',
    'dic'           => 'CZ12345678',
    'invoice_email' => 'faktury@acme.example',
    'pohoda_id'     => 'ADR-0042',
    'evil'          => '<script>alert(1)</script>',
]);
$custEnrichProv = new CustomerProvider('https://mantis.local', $custGw);
$cm = $custEnrichProv->enrich(['url' => 'customer://12', 'meta' => ['customer_id' => '12']]);
ok($cm !== null, 'enrich returns meta on success');
eq('ACME s.r.o.', $cm->title, 'enrich title from customer name');
eq('customer', $cm->icon, 'enrich sets customer icon');
eq('12345678', $cm->attributes['ico'], 'enrich keeps ico');
eq('CZ12345678', $cm->attributes['dic'], 'enrich keeps dic');
eq('faktury@acme.example', $cm->attributes['invoice_email'], 'enrich keeps invoice email');
eq('ADR-0042', $cm->attributes['pohoda_id'], 'enrich keeps pohoda id');
eq('12', $cm->attributes['customer_id'], 'enrich preserves resolved customer_id');
ok(!array_key_exists('evil', $cm->attributes), 'enrich drops unknown gateway keys (whitelist)');
eq(12, $custGw->lastId, 'enrich resolved id passed to gateway');

$custNoGw = new CustomerProvider('https://mantis.local');
eq(null, $custNoGw->enrich(['url' => 'customer://12', 'meta' => ['customer_id' => '12']]), 'enrich → null without gateway');

$custNull = new CustomerProvider('https://mantis.local', new StubCustomerGateway(null));
eq(null, $custNull->enrich(['url' => 'customer://12', 'meta' => ['customer_id' => '12']]), 'enrich → null when gateway cannot resolve');

$custThrow = new CustomerProvider('https://mantis.local', new ThrowingCustomerGateway());
eq(null, $custThrow->enrich(['url' => 'customer://12', 'meta' => ['customer_id' => '12']]), 'enrich swallows gateway exception (fail soft)');

group('CustomerProvider — registry + LinkService end-to-end');
$custRegistry = new ProviderRegistry([$cust, new GenericUrlProvider()]);
eq('customer', $custRegistry->forUrl('customer://5')->key(), 'customer uri resolves to customer provider');
eq('generic', $custRegistry->forUrl('https://mantis.local/view.php?id=5')->key(), 'plain issue url resolves to generic, not customer');

$custRepo = new FakeLinkRepository();
$custSvc  = new LinkService(
    $custRepo,
    new ProviderRegistry([$custEnrichProv, new GenericUrlProvider()]),
    new FakeAccessGuard(true, true, 7)
);
$custRow = $custSvc->add(500, 'customer://12', null);
eq('customer', $custRow['provider'], 'add resolves customer provider');
eq('customer://12', $custRow['url'], 'add stores canonical customer url');
eq('ACME s.r.o.', $custRow['title'], 'add: title from customer enrichment');
eq('12345678', $custRow['meta']['ico'], 'add: invoicing ico enriched into meta');
eq('customer', $custRow['meta']['icon'], 'add: customer icon merged into meta');
eq(1, count($custRow['actions']), 'add: customer row has one open action');
eq('https://mantis.local/view.php?id=12', $custRow['actions'][0]['url'], 'add: open action links to the customer issue');

// Re-attaching the same customer to the same bug is rejected as a duplicate.
$dupThrew = false;
try {
    $custSvc->add(500, 'customer://12', null);
} catch (DuplicateLinkException $e) {
    $dupThrew = true;
}
ok($dupThrew, 'add: same customer twice on a bug throws DuplicateLinkException');
eq(1, count($custRepo->findByBug(500)), 'add: duplicate customer not persisted');
// A different customer, and the same customer on a different bug, are allowed.
$custSvc->add(500, 'customer://99', null);
$custSvc->add(501, 'customer://12', null);
eq(2, count($custRepo->findByBug(500)), 'add: distinct customer on same bug allowed');
eq(1, count($custRepo->findByBug(501)), 'add: same customer on a different bug allowed');

// ─── LinkService ─────────────────────────────────────────────────────────────

group('LinkService — add (generic)');
$repo    = new FakeLinkRepository();
$service = new LinkService($repo, el_registry(), new FakeAccessGuard(true, true, 7));
$row = $service->add(100, '  https://github.com/acme/repo  ', '  my repo  ');
eq('generic', $row['provider'], 'add resolves generic provider');
eq('https://github.com/acme/repo', $row['url'], 'add trims + stores url');
eq('my repo', $row['description'], 'add trims description');
eq(null, $row['title'], 'add: no title without enrichment');
eq(1, count($row['actions']), 'add: generic row has one action');
eq('imatic_el_action_open', $row['actions'][0]['label'], 'add: action is open');
eq(7, $repo->rows[$row['id']]['created_by'], 'add: created_by = current user');
eq(1, count($repo->findByBug(100)), 'add: persisted to the repo');

$emptyDesc = $service->add(100, 'https://example.org/', '   ');
eq(null, $emptyDesc['description'], 'add: blank description stored as null');

group('LinkService — add access + validation');
$denied = new LinkService(new FakeLinkRepository(), el_registry(), new FakeAccessGuard(true, false));
$accessThrew = false;
try {
    $denied->add(1, 'https://x.y/z', null);
} catch (AccessDeniedException $e) {
    $accessThrew = true;
}
ok($accessThrew, 'add denied without manage access');

$badUrlService = new LinkService(new FakeLinkRepository(), el_registry(), new FakeAccessGuard());
$badThrew = false;
try {
    $badUrlService->add(1, 'javascript:alert(1)', null);
} catch (InvalidLinkException $e) {
    $badThrew = true;
}
ok($badThrew, 'add rejects non-http url via provider normalize');

group('LinkService — add (nextcloud + enrichment)');
$ncGw   = new StubNextcloudGateway(['name' => 'notes.odt', 'mime' => 'application/vnd.oasis.opendocument.text', 'size' => '55']);
$ncProv = new NextcloudProvider(['https://cloud.example.com'], $ncGw, 'collabora');
$ncRepo = new FakeLinkRepository();
$ncSvc  = new LinkService($ncRepo, el_registry($ncProv), new FakeAccessGuard());
$ncRow  = $ncSvc->add(200, 'https://cloud.example.com/f/42', null);
eq('nextcloud', $ncRow['provider'], 'add: NC url resolves to nextcloud');
eq('notes.odt', $ncRow['title'], 'add: title from enrichment');
eq('42', $ncRow['meta']['fileid'], 'add: enrichment preserves fileid');
eq('nextcloud', $ncRow['meta']['icon'], 'add: enrichment icon merged into meta');
eq(2, count($ncRow['actions']), 'add: office NC file has open + editor actions');

group('LinkService — list');
$listSvc = new LinkService($ncRepo, el_registry($ncProv), new FakeAccessGuard());
$listed = $listSvc->list(200);
eq(1, count($listed['links']), 'list returns the stored NC link');
eq('notes.odt', $listed['links'][0]['title'], 'list decorates cached title');

$listDenied = new LinkService($ncRepo, el_registry($ncProv), new FakeAccessGuard(false, true));
$listAccessThrew = false;
try {
    $listDenied->list(200);
} catch (AccessDeniedException $e) {
    $listAccessThrew = true;
}
ok($listAccessThrew, 'list denied without view access');

group('LinkService — remove');
$rmRepo = new FakeLinkRepository();
$rmSvc  = new LinkService($rmRepo, el_registry(), new FakeAccessGuard());
$rmRow  = $rmSvc->add(300, 'https://example.com/', null);
$rmSvc->remove(300, $rmRow['id']);
eq(0, count($rmRepo->findByBug(300)), 'remove deletes the row');

$rmMissingThrew = false;
try {
    $rmSvc->remove(300, 999);
} catch (NotFoundException $e) {
    $rmMissingThrew = true;
}
ok($rmMissingThrew, 'remove throws NotFound for missing link');

$rmDenied = new LinkService($rmRepo, el_registry(), new FakeAccessGuard(true, false));
$rmAccessThrew = false;
try {
    $rmDenied->remove(300, 1);
} catch (AccessDeniedException $e) {
    $rmAccessThrew = true;
}
ok($rmAccessThrew, 'remove denied without manage access');

group('LinkService — refresh');
// Store a NC link whose cached title is stale, then refresh from the gateway.
$refRepo = new FakeLinkRepository();
$refGw   = new StubNextcloudGateway(['name' => 'fresh.odt', 'mime' => 'application/vnd.oasis.opendocument.text']);
$refProv = new NextcloudProvider(['https://cloud.example.com'], $refGw, 'collabora');
$refRepo->insert([
    'bug_id' => 400, 'provider' => 'nextcloud', 'url' => 'https://cloud.example.com/f/9',
    'title' => 'stale.odt', 'description' => null, 'meta' => ['fileid' => '9'], 'created_by' => 7,
]);
$refSvc = new LinkService($refRepo, el_registry($refProv), new FakeAccessGuard());
$refRow = $refSvc->refresh(400, 1);
eq('fresh.odt', $refRow['title'], 'refresh updates title from gateway');
eq('fresh.odt', $refRepo->rows[1]['title'], 'refresh persists new title');

$refMissingThrew = false;
try {
    $refSvc->refresh(400, 999);
} catch (NotFoundException $e) {
    $refMissingThrew = true;
}
ok($refMissingThrew, 'refresh throws NotFound for missing link');

// ─── CustomerPickerService ───────────────────────────────────────────────────

/** Gateway whose search() returns a fixed candidate list (StubCustomerGateway
 *  always returns [], so the picker needs its own fake here). */
$makeSearchGateway = static function (array $candidates): CustomerGateway {
    return new class($candidates) implements CustomerGateway {
        /** @var array<int,array<string,mixed>> */
        private $candidates;
        /** @var string|null */
        public $lastQuery = null;
        public function __construct(array $candidates)
        {
            $this->candidates = $candidates;
        }
        public function fetch(int $customerId): ?array
        {
            return null;
        }
        public function search(string $query, int $limit = 20): array
        {
            $this->lastQuery = $query;
            return $this->candidates;
        }
    };
};

group('CustomerPickerService');
$pickGw = $makeSearchGateway([
    ['id' => 5, 'name' => 'ACME s.r.o.', 'ico' => '12345678'],
    ['id' => 0, 'name' => 'bad id skipped'],           // id <= 0 dropped
    ['name' => 'no id skipped'],                        // missing id dropped
    ['id' => 9, 'name' => 'Beta a.s.'],                 // ico defaults to ''
]);
$picker = new CustomerPickerService(new FakeAccessGuard(true, true), $pickGw);
$found = $picker->search(1, '  acme  ');
eq('acme', $pickGw->lastQuery, 'picker trims the query before searching');
eq(2, count($found['customers']), 'picker drops candidates without a positive id');
eq(5, $found['customers'][0]['id'], 'candidate id kept as int');
eq('ACME s.r.o.', $found['customers'][0]['name'], 'candidate name kept');
eq('12345678', $found['customers'][0]['ico'], 'candidate ico kept');
eq('', $found['customers'][1]['ico'], 'missing ico defaults to empty string');

$emptyQuery = $picker->search(1, '   ');
eq(0, count($emptyQuery['customers']), 'blank query yields no candidates (no gateway hit)');

$disabledPicker = new CustomerPickerService(new FakeAccessGuard(true, true), null);
eq(0, count($disabledPicker->search(1, 'acme')['customers']), 'no gateway (feature off) → empty result');

// isEnabled() mirrors gateway presence — the frontend uses it to show/hide the
// "add customer" button, and (P2) bootstrap passes a null gateway when the
// customer relation is not enabled on the current project.
ok($picker->isEnabled(), 'picker with a gateway is enabled');
ok(!$disabledPicker->isEnabled(), 'picker without a gateway is disabled (off / not offered here)');

$pickDenied = new CustomerPickerService(new FakeAccessGuard(true, false), $pickGw);
$pickAccessThrew = false;
try {
    $pickDenied->search(1, 'acme');
} catch (AccessDeniedException $e) {
    $pickAccessThrew = true;
}
ok($pickAccessThrew, 'picker search denied without manage access');

// ─── RelationConfig (config model + back-compat shim) ────────────────────────

group('RelationConfig');

// Back-compat shim: no relation_definitions + no customers project → nothing
// (customer link stays off, exactly as before).
$relOff = RelationConfig::resolve([], 0, []);
eq(0, count($relOff), 'empty config + no customers project → no definitions');

// Back-compat shim: no relation_definitions + a customers project → one
// synthesised "customer" definition matching today's behaviour.
$relLegacyFields = ['ico' => 'IČO', 'dic' => 'DIČ'];
$relShim = RelationConfig::resolve([], 3, $relLegacyFields);
eq(1, count($relShim), 'legacy shim yields exactly one definition');
$relCust = $relShim[0];
ok($relCust instanceof RelationDefinition, 'shim item is a RelationDefinition');
eq('customer', $relCust->key(), 'shim key is "customer" (stored in provider column)');
eq('mantis_issue', $relCust->provider(), 'shim provider is mantis_issue');
eq([3], $relCust->targetProjects(), 'shim targets the legacy customers project');
eq([], $relCust->enabledProjects(), 'shim is offered everywhere (empty enabled list)');
eq($relLegacyFields, $relCust->fields(), 'shim carries the legacy field map');
ok($relCust->isEnabledForProject(99), 'empty enabled list → enabled in any project');
eq(3, $relCust->primaryTargetProject(), 'primary target = first target project');

// Shim with an explicit enabled-projects list (the config multiselect): the
// customer flow is offered only there, still targeting the customers project.
$relGated = RelationConfig::resolve([], 3, $relLegacyFields, [3]);
eq([3], $relGated[0]->enabledProjects(), 'shim carries the enabled-projects list');
ok($relGated[0]->isEnabledForProject(3), 'shim enabled in a listed project');
ok(!$relGated[0]->isEnabledForProject(1), 'shim disabled in an unlisted project');

// Explicit relation_definitions take precedence; legacy args are ignored.
$relFromCfg = RelationConfig::resolve(
    [
        [
            'key'              => 'supplier',
            'provider'         => 'mantis_issue',
            'label'            => 'Dodavatel',
            'enabled_projects' => [3],
            'target_projects'  => [7, 7, '0', 'x'],   // deduped, non-positive dropped
            'fields'           => ['ico' => 'IČO'],
        ],
    ],
    3,                       // legacy pid — must be ignored when config present
    ['dic' => 'DIČ']         // legacy fields — must be ignored too
);
eq(1, count($relFromCfg), 'explicit config used verbatim (legacy ignored)');
$relSup = $relFromCfg[0];
eq('supplier', $relSup->key(), 'explicit key kept');
eq('Dodavatel', $relSup->label(), 'explicit label kept');
eq([3], $relSup->enabledProjects(), 'explicit enabled_projects kept');
eq([7], $relSup->targetProjects(), 'target_projects deduped, non-positive dropped');
eq(['ico' => 'IČO'], $relSup->fields(), 'explicit fields kept (legacy fields ignored)');
ok(!$relSup->isEnabledForProject(1), 'enabled list set → disabled in other projects');
ok($relSup->isEnabledForProject(3), 'enabled list set → enabled in a listed project');

// Robustness: junk entries are skipped; provider/label default sensibly.
$relMixed = RelationConfig::resolve(
    [
        'not-an-array',                       // skipped
        ['label' => 'no key'],                // skipped (no key)
        ['key' => '  spaced  '],              // trimmed key, defaults applied
    ],
    0,
    []
);
eq(1, count($relMixed), 'junk / keyless entries skipped');
eq('spaced', $relMixed[0]->key(), 'key is trimmed');
eq('mantis_issue', $relMixed[0]->provider(), 'missing provider defaults to mantis_issue');
eq('spaced', $relMixed[0]->label(), 'missing label defaults to the key');
eq(0, $relMixed[0]->primaryTargetProject(), 'no target projects → primary target 0');

// ─── summary ─────────────────────────────────────────────────────────────────

echo "\n────────────────────────────────────────\n";
echo sprintf("%d checks, %d failures\n", $GLOBALS['__tests'], $GLOBALS['__fails']);
exit($GLOBALS['__fails'] === 0 ? 0 : 1);
