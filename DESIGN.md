# ImaticExternalLinks — Design

> Mantis issue 0086345 — "Spojené soubory z nextcloudu"
> Status: **design + scaffolding** (implementation staged, see `PHASES.md`)
> Author: Imatic software

---

## 1. Goal

A system — analogous to Mantis issue relationships — for attaching **links to
external objects** to an issue. First target: **Nextcloud files/directories**.
Designed from day one as a **generic platform for links to any URL-addressable
object** (Nextcloud now; GitHub repo, Jira issue, plain URL, … later).

Each link renders in a **new section on the issue view page** (next to
"Relationships"). Rows are decorated per **provider**:

- provider **icon / label / metadata** (file name, mime, size …),
- provider **actions** (open in Nextcloud, open in online editor …),
- always available: **open** and **delete**.

When a provider cannot enrich (user not logged in remotely, cross-domain blocked,
unknown domain) it **degrades gracefully** to a clickable URL + optional manual
description. **The feature never hard-fails.**

---

## 2. It is ONE plugin

`ImaticExternalLinks` is a **single, standalone Imatic plugin**. The "generic
platform" is the **core of this plugin**, not a separate plugin. Providers
(Nextcloud, Generic, later GitHub/Jira) are **internal classes within the same
plugin**. One install, one table, one config page. Adding a new target later =
adding one provider class (+ optional client module) and registering it — **no
new plugin, no core changes**.

Structural template: `ImaticChecklist` (section + AJAX + schema + lang + config).
Frontend stack template: `ImaticLiveFields` (React 19 + TypeScript + Webpack),
extended here with **Zod** for runtime contract validation.

No core Mantis files are modified.

---

## 3. Code-quality principles (binding)

These are requirements, not aspirations. Reviews reject violations.

> **Runtime baseline: PHP 7.4** (Mantis production). All plugin code must be
> 7.4-compatible: **no** constructor property promotion, `readonly`, enums,
> `match`, union types, or `str_contains`/`str_starts_with` (use `strpos`).
> Typed properties, nullable types, and arrow functions are fine. The skeletons
> below use promotion/`readonly` only for brevity — the real code spells out
> constructors and private properties.

- **SRP** — one class, one reason to change. Persistence, URL parsing, HTTP,
  provider logic, and request handling live in **separate** units.
- **Dependency inversion** — providers depend on **interfaces**
  (`HttpClient`, `NextcloudGateway`), never on concrete cURL/DB code, so they are
  unit-testable with fakes.
- **Pure core, isolated I/O** — all decision logic (URL normalization, provider
  matching, SSRF allow-list, meta validation) is **pure** (no DB, no network, no
  superglobals) and lives in files unit-tested with **PHPUnit** — mirroring
  `ImaticReminder/core/imatic_reminder_pure.php`. I/O sits at the edges.
- **No duplication (DRY)** — one JSON responder, one auth/access guard, one CSRF
  check, one config accessor. Shared once, reused everywhere.
- **Low coupling** — the core knows only the `LinkProvider` interface; providers
  are mutually unaware; the frontend talks to the backend only through
  Zod-validated DTOs.
- **Fail safe, fail closed** — enrichment failures degrade to fallback; security
  checks (allow-list, access) default to *deny*.
- **Typed contracts end-to-end** — Zod schemas are the single source of truth for
  the wire format; TS types are `z.infer`red from them; every server response is
  parsed through Zod before it reaches the UI.

---

## 4. Architecture — layers

```
┌────────────────────────── Presentation (thin) ──────────────────────────┐
│ PHP  pages/ajax_links.php · pages/ajax_proxy.php   (parse → service → JSON)│
│ TS   ui/ React components  ·  api/ (fetch + Zod parse)                     │
└───────────────┬───────────────────────────────────────────────────────────┘
                │ depends on ↓ (never upward)
┌────────────────────────── Application (use-cases) ───────────────────────┐
│ LinkService   — add / remove / list / refresh a link for a bug            │
│ PickerService — server-side Nextcloud browse (proxied)                    │
└───────────────┬───────────────────────────────────────────────────────────┘
                │
┌──────────────── Domain (pure, no I/O — 100% unit-tested) ─────────────────┐
│ ProviderRegistry · LinkProvider (iface) · NormalizedLink · LinkMeta        │
│ NextcloudProvider · GenericUrlProvider                                     │
│ UrlNormalizer · OriginAllowList (SSRF guard) · MetaValidator               │
└───────────────┬───────────────────────────────────────────────────────────┘
                │ via interfaces ↑
┌────────────────────────── Infrastructure (I/O) ──────────────────────────┐
│ LinkStore (DB) · HttpClient (cURL) · NextcloudGateway (WebDAV/OCS) ·       │
│ CredentialStore (encrypted NC app passwords) · MantisAccess (auth wrapper) │
└───────────────────────────────────────────────────────────────────────────┘
```

Dependency rule: arrows point **inward**. Domain depends on nothing Mantis- or
network-specific; infrastructure implements interfaces the domain declares.

---

## 5. Data model

One provider-agnostic table. Provider extras live in a JSON blob → new providers
need **no migration**.

```
imatic_external_links
──────────────────────────────────────────────────────────────
  id           I      PRIMARY NOTNULL AUTOINCREMENT
  bug_id       I      NOTNULL              -- FK → mantis_bug_table.id
  provider     C(32)  NOTNULL              -- 'nextcloud' | 'generic' | ...
  url          C(2000) NOTNULL             -- canonical URL = source of truth
  title        C(500)                      -- display label
  description  C(2000)                     -- optional manual description (fallback)
  meta         XL                          -- JSON: provider extras (fileid, mime…)
  position     I      NOTNULL DEFAULT '0'
  created_by   I      NOTNULL
  created_at   I      NOTNULL
  updated_at   I      NOTNULL
──────────────────────────────────────────────────────────────
  INDEX idx_bug_id (bug_id)
```

- `url` is durable identity; `title`/`meta` are refreshable cache, never required
  for the link to work, never trusted for security.

---

## 6. Domain — PHP skeletons

### 6.1 Value objects (immutable)

```php
namespace ImaticExternalLinks\Domain;

/** Result of normalizing a raw URL for a specific provider. Immutable. */
final class NormalizedLink
{
    public function __construct(
        public readonly string $provider,   // provider key
        public readonly string $url,         // canonical URL
        public readonly ?string $title,      // best-effort label (may be null)
        public readonly array  $meta         // provider identifiers, e.g. ['fileid'=>..]
    ) {}
}

/** Enrichment fetched from a remote system. Immutable. */
final class LinkMeta
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $icon,       // logical icon key, resolved to asset in UI
        public readonly array  $attributes   // ['mime'=>.., 'size'=>.., ...] (validated)
    ) {}
}

/** A row action offered by a provider. Immutable. */
final class LinkAction
{
    public function __construct(
        public readonly string $label,       // lang key
        public readonly string $url,
        public readonly string $icon,
        public readonly bool   $external = true
    ) {}
}
```

### 6.2 Provider contract

```php
namespace ImaticExternalLinks\Domain;

interface LinkProvider
{
    /** Stable id persisted on the row. */
    public function key(): string;

    /** Does this provider own the URL? Pure — domain/path match only. */
    public function matches(string $url): bool;

    /** Canonicalize + extract identifiers. Pure. Throws InvalidLinkException. */
    public function normalize(string $url): NormalizedLink;

    /** Actions for a stored row. Pure — builds URLs from row data. */
    public function actions(array $row): array; // LinkAction[]

    /**
     * Optional server-side enrichment. Uses injected gateways (I/O at the edge).
     * Returns null on any failure → caller falls back. Never throws to caller.
     */
    public function enrich(array $row): ?LinkMeta;
}
```

`GenericUrlProvider` — catch-all baseline: `matches()` = any `http(s)` URL;
`normalize()` validates scheme + host; `actions()` = just "open"; `enrich()`
optionally fetches `<title>`/OpenGraph via `HttpClient` (through the allow-list).

`NextcloudProvider` — depends on `NextcloudGateway` (interface). Recognises
configured NC origins, extracts `fileid`/share token, offers *open* +
*open-in-editor* (only for office mimes), enriches name/mime/size via the gateway.

### 6.3 Registry (pure resolution)

```php
namespace ImaticExternalLinks\Domain;

final class ProviderRegistry
{
    /** @param LinkProvider[] $providers ordered; last = catch-all generic */
    public function __construct(private array $providers) {}

    /** First provider that matches, else the catch-all (last). Never null. */
    public function forUrl(string $url): LinkProvider { /* ... */ }

    public function byKey(string $key): ?LinkProvider { /* ... */ }
}
```

### 6.4 Pure guards / helpers (heavily unit-tested)

```php
namespace ImaticExternalLinks\Domain;

/** SSRF guard. Pure. No network. Fail-closed. */
final class OriginAllowList
{
    /** @param string[] $allowed canonical origins from config */
    public function __construct(private array $allowed) {}

    /** True only for http(s) URLs whose origin is allow-listed and not internal. */
    public function permits(string $url): bool { /* scheme + origin + private-IP block */ }
}

/** Validates + shapes untrusted enrichment before it is stored/rendered. Pure. */
final class MetaValidator
{
    public function sanitize(array $raw): array { /* whitelist keys, cast, cap lengths */ }
}

/** Provider-agnostic URL canonicalization helpers. Pure. */
final class UrlNormalizer
{
    public static function scheme(string $url): ?string;
    public static function origin(string $url): ?string;   // scheme://host[:port]
    public static function isHttp(string $url): bool;
}
```

> Everything in §6.3/§6.4 and the `normalize()`/`matches()`/`actions()` methods is
> **pure** and lives under `inc/Domain/` with **no** `require` of Mantis. That is
> what makes the PHPUnit suite runnable without a DB (see §11).

---

## 7. Application + Infrastructure — PHP skeletons

### 7.1 Interfaces the domain/app depend on (implemented by infra)

```php
namespace ImaticExternalLinks\Contract;

interface HttpClient {                 // cURL lives behind this
    public function get(string $url, array $headers = []): HttpResponse;
    public function propfind(string $url, string $body, array $headers = []): HttpResponse;
}

interface NextcloudGateway {           // WebDAV/OCS behind this
    public function stat(int $userId, string $fileId): ?array;      // name/mime/size
    public function browse(int $userId, string $path): array;       // folder listing
}

interface CredentialStore {            // encrypted NC app passwords
    public function get(int $userId): ?NextcloudCredential;
    public function put(int $userId, NextcloudCredential $c): void;
    public function delete(int $userId): void;
}

interface AccessGuard {                 // wraps Mantis auth/access (mockable)
    public function ensureCanView(int $bugId): void;
    public function ensureCanManage(int $bugId): void;   // throws → 403
    public function currentUserId(): int;
}
```

### 7.2 Application services (orchestration only)

```php
namespace ImaticExternalLinks\Application;

final class LinkService
{
    public function __construct(
        private LinkStore $store,
        private ProviderRegistry $registry,
        private AccessGuard $access
    ) {}

    /** Normalize → detect provider → (best-effort) enrich → persist → return row. */
    public function add(int $bugId, string $rawUrl, ?string $description): array { /* */ }

    public function remove(int $bugId, int $linkId): void { /* access + delete */ }

    /** @return array[] rows already provider-decorated (actions + meta). */
    public function list(int $bugId): array { /* */ }

    /** Re-run enrichment for one row, update cache. */
    public function refresh(int $bugId, int $linkId): array { /* */ }
}

final class PickerService                // Nextcloud browse, server-side/proxied
{
    public function __construct(
        private NextcloudGateway $nc,
        private AccessGuard $access
    ) {}
    public function browse(int $bugId, string $path): array { /* access → nc->browse */ }
}
```

### 7.3 Infrastructure (the only place with cURL / DB / superglobals)

- `LinkStore` — CRUD over `imatic_external_links` (`db_query`, `db_param`).
- `CurlHttpClient implements HttpClient` — timeouts, size cap, **no off-list
  redirects**, TLS verify on.
- `WebdavNextcloudGateway implements NextcloudGateway` — builds PROPFIND, parses
  XML, uses `CredentialStore`.
- `EncryptedCredentialStore implements CredentialStore` — Mantis crypto.
- `MantisAccessGuard implements AccessGuard` — `access_has_bug_level(...)`.

### 7.4 Presentation (thin controllers)

`pages/ajax_links.php`, `pages/ajax_proxy.php`: authenticate, CSRF-check
state-changing actions, build services (a tiny composition root in
`inc/bootstrap.php`), delegate, emit JSON via a shared `JsonResponder`. No
business logic here.

```php
// inc/bootstrap.php — composition root (wires infra into services). One place.
function imatic_el_container(): Container { /* build & cache services + providers */ }
```

---

## 8. Nextcloud provider specifics

### 8.1 URL recognition
Configurable allow-list of NC origins (`nextcloud_base_urls`). Shapes handled by
`normalize()`: `…/f/<fileid>`, `…/apps/files/?…openfile=<fileid>`,
`…/index.php/s/<token>` (public share). Identifiers go into `meta`.

### 8.2 Actions
- **Open in Nextcloud** → `/f/<fileid>`.
- **Open in online editor** → direct-open URL (Collabora/OnlyOffice), shown only
  when `meta.mime` is an editable office type.
- **Delete** (core).

### 8.3 Native picker (chosen for v1) — cross-domain solved by a server proxy

The official `@nextcloud/dialogs` FilePicker runs only **inside** Nextcloud, so it
cannot be embedded on the different-origin Mantis page. We build a native-feeling
picker on **Nextcloud WebDAV `PROPFIND`, proxied through the plugin**:

```
Mantis JS ──AJAX──► ajax_proxy.php (PHP) ──WebDAV PROPFIND──► Nextcloud
 our picker UI        our origin only            user's files
 (breadcrumb+tree)    → NO browser CORS
```

- Browser talks **only to our origin** → the cross-domain limitation the issue
  anticipates **does not apply**.
- **Auth** (config `nc_auth_mode`): `per_user` NC **app password** stored
  **encrypted** (respects the user's real permissions) — the pragmatic reading of
  "user is logged into Nextcloud"; or `service_account` for read-only listing; or
  `off`.
- If proxy/auth unavailable → dialog **falls back** to manual URL + description.

---

## 9. Frontend — TypeScript + Zod

Same build as `ImaticLiveFields` (React 19 + TS + Webpack, assets served via
`plugin_file.php`). **Zod is the single source of truth for the API contract.**

### 9.1 Layering
```
src/
  contracts/            # Zod schemas — the wire format, one source of truth
    link.ts             #   LinkRowSchema, LinkListSchema, AddLinkResponseSchema
    picker.ts           #   NcEntrySchema, BrowseResponseSchema
    errors.ts           #   ApiErrorSchema
  api/                  # transport: fetch + Zod parse (throws on drift)
    client.ts           #   request(): validates response through a schema
    links.ts            #   addLink / listLinks / deleteLink / refreshLink
    picker.ts           #   browse()
  providers/            # client provider modules (SRP per provider)
    types.ts            #   ClientProvider interface
    registry.ts         #   register / lookup
    nextcloud.ts        #   picker UI driver
    generic.ts          #   URL + description form
  ui/                   # React components (presentation only)
    LinksSection.tsx  AddLinkDialog.tsx  LinkRow.tsx  ProviderPicker.tsx
  state/                # small store/hooks (no fetch here)
  index.tsx             # entry: read config, mount section into the issue view
```

### 9.2 Contract example (Zod → inferred types)
```ts
// contracts/link.ts
import { z } from 'zod';

export const LinkRowSchema = z.object({
  id: z.number().int().positive(),
  provider: z.string(),
  url: z.string().url(),
  title: z.string().nullable(),
  description: z.string().nullable(),
  meta: z.record(z.unknown()).default({}),
  actions: z.array(z.object({
    label: z.string(), url: z.string().url(),
    icon: z.string(), external: z.boolean(),
  })),
});
export type LinkRow = z.infer<typeof LinkRowSchema>;

export const LinkListSchema = z.object({ links: z.array(LinkRowSchema) });
```

```ts
// api/client.ts — every response is validated before use
export async function request<T>(url: string, schema: z.ZodType<T>, init?: RequestInit): Promise<T> {
  const res = await fetch(url, { credentials: 'same-origin', ...init });
  const json = await res.json();
  if (!res.ok) throw ApiError.from(ApiErrorSchema.parse(json));
  return schema.parse(json);            // ← drift/attack surfaces here, not in the UI
}
```

### 9.3 Client provider contract
```ts
// providers/types.ts
export interface ClientProvider {
  key: string;
  /** Optional custom picker; absent → dialog uses the URL field. */
  pick?(ctx: PickerContext): Promise<{ url: string; title?: string }>;
  /** Optional post-render decoration. */
  decorate?(row: HTMLElement, meta: Record<string, unknown>): void;
}
```

---

## 10. Security

- **SSRF** — outbound HTTP only to allow-listed origins (`OriginAllowList`,
  fail-closed); block private/loopback IP ranges; cap size + time; **no off-list
  redirects**; TLS verify on.
- **Stored credentials** — NC app passwords **encrypted** at rest (Mantis
  crypto); never returned to the client.
- **XSS** — all enriched/manual strings escaped on render; `url` restricted to
  `http(s)`; `meta` validated by `MetaValidator`, treated as untrusted.
- **AuthZ** — per-bug `view`/`manage` thresholds via `AccessGuard`; proxy calls
  require auth + view access to the bug.
- **CSRF** — Mantis `form_security_*` tokens on all state-changing AJAX actions.
- **Input** — URL length capped; description length capped; provider key
  whitelisted.

---

## 11. Testing strategy

Two tiers, matching existing plugins.

> **Runner:** the repo's bundled PHPUnit is `^4.8` and does not run under modern
> PHP. To keep the pure-domain suite runnable anywhere (PHP 7.4 *and* 8.x, no
> framework install), the domain tests use a **standalone, dependency-free runner**
> (`tests/run.php`) in the style of `ImaticChecklist/tests/checkbox_toggle_test.php`
> — plain assertions, `php tests/run.php`, non-zero exit on failure. The pure
> domain has no Mantis/DB dependency, so the runner needs no bootstrap.

1. **Unit tests** over the **pure domain** (no DB/network):
   - `UrlNormalizerTest` — scheme/origin/isHttp edge cases.
   - `OriginAllowListTest` — allow/deny, private-IP block, scheme rejection,
     off-list redirect targets, fail-closed on malformed input. **(SSRF)**
   - `MetaValidatorTest` — key whitelist, length caps, type coercion, XSS payloads.
   - `NextcloudProviderTest` — `matches`/`normalize`/`actions` for every URL shape
     + non-NC URLs; `enrich()` with a **fake** `NextcloudGateway`.
   - `GenericUrlProviderTest` — catch-all matching, `enrich()` with a fake
     `HttpClient`.
   - `ProviderRegistryTest` — resolution order, catch-all fallback.
   - `LinkServiceTest` — add/remove/list with **fake** `LinkStore` + `AccessGuard`
     (access-denied paths, enrichment-failure fallback).

2. **Frontend**: Zod schemas make contracts self-checking; add lightweight tests
   for `api/client` parsing (valid / drifted / error payloads) and provider
   registry lookup.

Config: `phpunit.xml` at the plugin root; PSR-4 autoload of `inc/` for tests.

---

## 12. Config & permissions

| Key | Meaning | Default |
|-----|---------|---------|
| `enabled` | master switch | `ON` |
| `view_threshold` | who can see links | `VIEWER` |
| `manage_threshold` | who can add/delete | `REPORTER` |
| `nextcloud_base_urls` | allow-listed NC origins | `[]` |
| `proxy_allow_list` | allow-listed origins for enrichment | = NC urls |
| `nc_auth_mode` | `per_user` \| `service_account` \| `off` | `off` |
| `nc_online_editor` | `collabora` \| `onlyoffice` | `collabora` |

Access via `access_has_bug_level(...)` per issue (like the other plugins).

---

## 13. Proposed file structure

```
plugins/ImaticExternalLinks/
├── ImaticExternalLinks.php          # main class (register/config/schema/hooks)
├── DESIGN.md · PHASES.md · README.md
├── phpunit.xml
├── inc/
│   ├── bootstrap.php                # composition root
│   ├── Domain/
│   │   ├── LinkProvider.php  NormalizedLink.php  LinkMeta.php  LinkAction.php
│   │   ├── ProviderRegistry.php
│   │   ├── OriginAllowList.php  MetaValidator.php  UrlNormalizer.php
│   │   └── Provider/ GenericUrlProvider.php  NextcloudProvider.php
│   ├── Contract/                    # HttpClient, NextcloudGateway, ... interfaces
│   ├── Application/ LinkService.php  PickerService.php
│   ├── Infra/ LinkStore.php  CurlHttpClient.php  WebdavNextcloudGateway.php
│   │          EncryptedCredentialStore.php  MantisAccessGuard.php  JsonResponder.php
│   └── links_view.php               # EVENT_VIEW_BUG_EXTRA section markup
├── pages/ ajax_links.php  ajax_proxy.php  config.php
├── files/  (webpack output: index.js, *.chunk.js, style.css)
├── src/    (TypeScript + Zod — see §9.1)
├── tests/  (PHPUnit — see §11)
└── lang/ strings_czech.txt  strings_english.txt
```

---

## 14. Open questions

1. Nextcloud instance(s) & auth: single company instance? `per_user` app password
   vs. one `service_account` for listing?
2. Online editor: **Collabora** or **OnlyOffice**? (sets the direct-open URL)
3. Should links appear in the REST API / mobile app too, or web-only for v1?
4. Issue-history entries when a link is added/removed — yes/no?
```
