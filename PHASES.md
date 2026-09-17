# ImaticExternalLinks — Phased plan & progress log

> Companion to `DESIGN.md`. Tracks what to build, in what order, and what is done.
> Rule for this plugin: **step by step**, security first, SRP, no duplication, low
> coupling, TypeScript + Zod on the frontend. Nothing is committed/pushed without
> an explicit request; commits carry **no** "Authored by Claude" trailer.

---

## Decisions locked (from the discussion)

| # | Decision | Choice |
|---|----------|--------|
| D1 | One plugin or platform of plugins? | **One plugin.** Core = the generic platform; providers are internal classes. |
| D2 | Scope of v1 | **Generic platform + Nextcloud provider** (not Nextcloud-only). |
| D3 | Nextcloud file selection in v1 | **Native picker**, built on WebDAV **proxied through the plugin** (no browser CORS). |
| D4 | Cross-domain / enrichment | All remote calls go **server-side through the plugin proxy**; browser never calls foreign origins. |
| D5 | Fallback | Manual URL + description is always available; feature never hard-fails. |
| D6 | Frontend stack | React 19 + TypeScript + Webpack (as `ImaticLiveFields`) **+ Zod** for contract validation. |
| D7 | Testing | Standalone dependency-free runner over the **pure domain** (repo's PHPUnit is a broken `^4.8`) + Zod-based frontend contract checks. |
| D8 | Runtime baseline | **PHP 7.4** (Mantis prod). No promotion/readonly/enums/match/str_contains. |

---

## Open questions (need input before the phases they affect)

1. **NC instance & auth mode** (`per_user` app password vs `service_account`) — blocks Phase 3.
2. **Online editor**: Collabora vs OnlyOffice — blocks the editor action in Phase 3.
3. **REST/mobile exposure** or web-only for v1 — affects Phase 2 surface.
4. **Issue-history entries** on add/remove — small toggle in Phase 2.

---

## Phase 0 — Design & scaffolding docs  *(in progress)*

- [x] `DESIGN.md` — architecture, layering, skeletons, security, testing.
- [x] `PHASES.md` — this file.
- [x] `README.md` — install + config summary.

**Deliverable:** documents only. No runtime code yet.

---

## Phase 1 — Pure domain core + tests  *(no DB, no network)*  *(done)*

Highest value, lowest risk, fully unit-testable. Build the decision logic first.

- [x] `inc/Domain/` value objects: `NormalizedLink`, `LinkMeta`, `LinkAction`.
- [x] `inc/Domain/UrlNormalizer` — scheme/origin/isHttp + hasCredentials (pure).
- [x] `inc/Domain/OriginAllowList` — SSRF guard, fail-closed, private-IP block.
- [x] `inc/Domain/MetaValidator` — whitelist + length caps + type coercion.
- [x] `inc/Domain/LinkProvider` interface + `ProviderRegistry`.
- [x] `inc/Domain/Provider/GenericUrlProvider` (matches/normalize/actions pure;
      `enrich()` takes an injected `HttpClient`, allow-list enforced, fail-soft).
- [x] `inc/Domain/Provider/NextcloudProvider` (URL shapes; `enrich()` takes an
      injected `NextcloudGateway`).
- [x] `inc/Contract/` interfaces: `HttpClient` (+`HttpResponse`), `NextcloudGateway`,
      `AccessGuard`, `CredentialStore`.
- [x] `tests/run.php` (standalone, dependency-free) covering:
      url normalizer, origin allow-list (SSRF), meta validator, provider registry,
      generic provider, nextcloud provider (fakes for gateways).

**Deliverable:** `php plugins/ImaticExternalLinks/tests/run.php` green (78 checks,
0 failures). No Mantis runtime needed to run it.

> Note: the `NextcloudGateway` contract dropped the per-call `userId` param from
> the DESIGN sketch — the concrete gateway is built per-request with the acting
> user's credentials, keeping the domain free of identity/auth concerns.

**Exit criteria:** SSRF allow-list and URL normalization proven by tests incl.
malicious inputs (private IPs, `file://`, `javascript:`, off-list redirects,
credentials-in-URL, IDN/punycode).

---

## Phase 2 — Persistence, services, issue-view section  *(needs Mantis)*  *(done)*

- [x] `schema()` → `imatic_external_links` table + `bug_id` index (main plugin class).
- [x] `inc/Contract/LinkRepository` (new — DIP boundary) + `inc/Infra/LinkStore`
      implementing it (parameterized queries, JSON meta encode/decode).
- [x] `inc/Infra/MantisAccessGuard` (+ `canView/canManage` added to `AccessGuard`),
      `inc/Infra/JsonResponder`, `inc/Infra/Container` (assembled graph).
- [x] `inc/Application/LinkService` — add/remove/list/refresh (generic + NC
      enrichment paths), `Application/Exception/{AccessDenied,NotFound}`.
- [x] `inc/bootstrap.php` composition root + `inc/autoload.php` (PSR-4 for inc/).
- [x] `pages/ajax_links.php` — thin controller (auth + CSRF `form_security_*` +
      delegate + exception→HTTP mapping via JsonResponder).
- [x] `inc/links_view.php` + `EVENT_VIEW_BUG_EXTRA` widget-box section (seeds the
      decorated list into `#imatic-el-body[data-initial]`); assets + runtime
      config (+ lang strings, CSRF token) via `EVENT_LAYOUT_BODY_END`.
- [x] Frontend baseline: `contracts/` (Zod: link/errors/config/input), `api/`
      (client with Zod-parsed responses + `LinksApi`), `providers/`,
      `ui/{LinksSection,AddLinkDialog,LinkRow}`, `state/useLinks`, `index.tsx`.
      Zod used for wire contracts (types via `z.infer`), response parsing,
      runtime-config + initial-seed parsing, and add-form input validation.
- [x] `lang/` cz + en, `pages/config.php` (CSRF-protected admin form).
- [x] `LinkServiceTest` with fake store/guard (add/list/remove/refresh, access
      denied, invalid-url, NC enrichment).

**Deliverable:** add/open/delete a plain URL link on an issue, end-to-end, with
graceful fallback UI. Nextcloud not required yet.

> Deltas vs. the original sketch: added a `LinkRepository` interface (so
> `LinkService` is unit-tested against a fake, not the DB) and an `Infra\Container`
> object; UI copy is injected from the PHP lang files (single source of truth)
> rather than duplicated in TS. Build verified: `tsc --noEmit` clean, `npm run
> build` → `files/index.js`. Tests: `php tests/run.php` → 103 checks, 0 failures
> (PHP 7.4/8.x). Not committed.

---

## Phase 3 — Nextcloud provider (picker + editor + enrichment)  *(needs NC)*

Blocked by open questions 1 & 2 — except the HTTP-client slice below, which is
independent of the Nextcloud auth/editor decisions and is already done.

- [x] `inc/Infra/CurlHttpClient` (timeouts, size cap, no redirects, TLS, http/https
      only) — wired into `GenericUrlProvider` in the container, so generic
      `<title>` enrichment is live for admin-allow-listed origins (empty list =
      fail-closed, no enrichment). Needs no Nextcloud.
- [ ] `inc/Infra/WebdavNextcloudGateway` (PROPFIND browse + stat).
- [ ] `inc/Infra/EncryptedCredentialStore` + onboarding for NC app password
      (if `per_user`).
- [ ] `pages/ajax_proxy.php` — `nc_browse` / `nc_meta` / generic `enrich`
      (allow-list enforced).
- [ ] `inc/Application/PickerService`.
- [ ] Frontend `providers/nextcloud.ts` — native picker UI (breadcrumb + tree),
      open + open-in-editor actions, decoration from enriched meta.
- [ ] Enrichment wired into `LinkService` for the Nextcloud provider.

**Deliverable:** browse Nextcloud from within Mantis, attach a file/folder, open
it (and office files in the online editor), enriched row (name/mime/size).

---

## Phase 3b — Customer provider (issue → customer, invoicing blocker)  *(backend done)*

> Blocker for the Pohoda invoicing automation (see
> `pohodar/navrh-implementacie-a-kroky.md`). A customer is an **internal Mantis
> issue** in the "iMatic IT Customers" project; the link surfaces its invoicing
> identifiers (IČO, DIČ, invoice e-mail, Pohoda id). Internal object → **no
> cross-domain / SSRF surface**; enrichment reads Mantis custom fields, not HTTP.

- [x] `inc/Contract/CustomerGateway` — fetch(one) + search(picker) boundary.
- [x] `inc/Domain/Provider/CustomerProvider` — owns synthetic `customer://<id>`
      URIs (pure `matches`/`normalize`/`parseId`/`urlForId`); `actions()` builds
      the real Mantis issue URL from the injected base (internal, same-tab);
      `enrich()` reads invoicing fields via the gateway, sanitised + fail-soft.
- [x] `inc/Domain/MetaValidator` — whitelist extended with customer keys
      (`customer_id`, `ico`, `dic`, `invoice_email`, `pohoda_id`, `contact`).
- [x] `inc/Infra/MantisCustomerGateway` — reads the customers project + custom
      fields (field-name→meta-key map injected from config); parameterised search.
- [x] Config: `customers_project_id` (0 = disabled) + `customer_fields` map;
      provider wired **first** in the registry in `bootstrap.php`
      (base URL from `config_get_global('path')`).
- [x] Lang cz+en (open-customer action + invoicing property labels).
- [x] `tests/run.php` — `CustomerProvider` suite (parseId/urlForId/matches/
      normalize/actions/enrich with a fake gateway) + registry + `LinkService`
      end-to-end add. **147 checks, 0 failures** (PHP 7.4/8.x). `php -l` clean.

**Remaining (needs the running local Mantis + customer data):**
- [x] `customer_search` endpoint — dedicated `pages/ajax_customer_search.php`
      (auth + CSRF + manage-gated) over `CustomerPickerService`
      (`Application/CustomerPickerService`, gateway search shaped to a small DTO).
- [x] Frontend `providers/customer.ts` + `contracts/customer.ts` (Zod) —
      debounced `CustomerPickerDialog` (fulltext search), "Attach customer"
      button in `LinksSection`, invoicing-property decoration in `LinkRow` via a
      new optional `ClientProvider.properties()`. Config gains `customersEnabled`
      + `customerSearchUrl` (injected from PHP).
- [ ] Local Mantis setup: create "iMatic IT Customers" project, custom fields
      (IČO/DIČ/Fakturační e-mail/Pohoda ID/kontakt), set `customers_project_id`,
      seed a few sample customers. **(prod not touched — local only)**

**Deliverable:** attach a customer end-to-end — search → pick → stored as
`customer://<id>`, row decorated with the invoicing identifiers, open action
links to the customer's Mantis issue. Only the local customers project must be
created before clicking through the UI. Tests: **156 checks, 0 failures**
(PHP 7.4.33); `tsc --noEmit` clean; `npm run build` → `files/index.js`.

---

## Phase 4 — Polish & extension points

- [ ] Reorder (drag), refresh-enrichment button, thumbnails.
- [ ] Optional: issue-history entry on add/remove (open question 4).
- [ ] Optional: REST/mobile exposure (open question 3).
- [ ] `GitHubProvider`, `JiraProvider` — new provider classes only, proving the
      platform (no core changes).

---

## Progress log

- **2026-07-17** — Phase 0: `DESIGN.md` rewritten (layered architecture, PHP +
  TS/Zod skeletons, security, testing). `PHASES.md` created. No runtime code yet.
- **2026-07-17** — Phase 1 done: pure domain (`inc/Domain/`) + contracts
  (`inc/Contract/`) written PHP 7.4-safe (no promotion/readonly/enums/match/union
  types). Standalone `tests/run.php` green: 78 checks, 0 failures. `php -l` clean
  on all files. SSRF allow-list proven against loopback/private/link-local IPs,
  `file://`, `javascript:`, off-list origins, credentials-in-URL. Not committed.
- **2026-07-17** — Phase 2 done: main plugin class (register/config/schema/hooks),
  `LinkRepository`+`LinkStore`, `MantisAccessGuard`, `JsonResponder`, `Container`,
  `LinkService` (+ Application exceptions), `bootstrap.php`+`autoload.php`,
  `ajax_links.php` (CSRF via `form_security_*`, exception→HTTP mapping),
  `links_view.php` (`EVENT_VIEW_BUG_EXTRA` widget-box + seeded list) and asset
  injection (`EVENT_LAYOUT_BODY_END`), `config.php`, cz+en lang. Frontend baseline
  React 19 + TypeScript + **Zod** (contracts, response parsing, config/seed
  parsing, input validation): `tsc --noEmit` clean, `npm run build` → `files/index.js`.
  Extended `tests/run.php` with `LinkService` suite → **103 checks, 0 failures**
  on PHP 7.4/8.x. All PHP `php -l` clean. Not committed.
- **2026-07-25** — Phase 3b (backend) done: customer provider as the invoicing
  blocker. `CustomerGateway` contract, pure `CustomerProvider` (synthetic
  `customer://<id>` identity, Mantis-issue open action, custom-field enrichment),
  `MetaValidator` whitelist extended, `MantisCustomerGateway` (project + custom
  fields, injected field map), config keys `customers_project_id` +
  `customer_fields`, provider wired first in `bootstrap.php`, cz+en lang. Tests:
  **147 checks, 0 failures** (PHP 7.4.33), `php -l` clean on all changed files.
  Remaining: `customer_search` endpoint + React picker + local customers project
  (prod untouched). Not committed.
- **2026-07-27** — Phase 3b (picker UI + endpoint) done, building on the parallel
  session's backend without touching its provider order / `MetaValidator` /
  existing tests. Added `Application/CustomerPickerService` (+ `Container` field,
  wired in `bootstrap.php`), `pages/ajax_customer_search.php` (auth + CSRF +
  manage-gated), injected `customersEnabled` + `customerSearchUrl` into the
  frontend config. Frontend: `contracts/customer.ts` (Zod), `LinksApi.searchCustomers`,
  `providers/customer.ts` (+ optional `ClientProvider.properties()`),
  `CustomerPickerDialog`, `LinkRow` invoicing decoration, "Attach customer" button.
  Appended a `CustomerPickerService` test suite → **156 checks, 0 failures**
  (PHP 7.4.33), `php -l` clean, `tsc --noEmit` clean, `npm run build` → `files/index.js`.
  No Mantis core touched (plugin-only). Only the local customers project remains
  (manual, prod untouched). Not committed.
