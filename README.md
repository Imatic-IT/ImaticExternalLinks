# ImaticExternalLinks

Attach **links to external objects** to a Mantis issue — Nextcloud files/folders,
GitHub/Jira, or any plain URL — shown as a section on the issue view (next to
Relationships). Rows are decorated per **provider** (icon, title, actions such as
*open* / *open in editor*) and always degrade gracefully to a clickable URL when
enrichment is unavailable. See `DESIGN.md` for the architecture and `PHASES.md`
for the build plan / progress.

> Mantis issue **0086345** — "Spojené soubory z nextcloudu".
> One standalone plugin; the generic platform is its core, providers are internal
> classes. Runtime baseline **PHP 7.4**. Frontend React 19 + TypeScript + **Zod**.

---

## Install

1. Copy `plugins/ImaticExternalLinks/` into the Mantis `plugins/` directory.
2. **Manage → Manage Plugins → Install** the plugin. Installation runs the
   `schema()` migration and creates the `imatic_external_links` table.
3. Open any issue — the **External links** section appears under the notes for
   users at/above the configured *view* threshold.

The built frontend bundle (`files/index.js`) and `files/style.css` are shipped in
the repo, so no Node build is required to run the plugin. Rebuild only when you
change the TypeScript sources (see **Frontend build**).

---

## Configuration

**Manage → Manage Plugins → ImaticExternalLinks → Configure** (Manager only).

| Key | Meaning | Default |
|-----|---------|---------|
| `enabled` | Master switch for the section. | `ON` |
| `view_threshold` | Minimum access level to see links. | `VIEWER` |
| `manage_threshold` | Minimum access level to add / delete links. | `REPORTER` |
| `nextcloud_base_urls` | Allow-listed Nextcloud origins (one per line). | `[]` |
| `proxy_allow_list` | Origins the server may fetch for enrichment (SSRF guard). Empty ⇒ Nextcloud URLs only. | `[]` |
| `nc_auth_mode` | `off` \| `per_user` \| `service_account` (Phase 3). | `off` |
| `nc_online_editor` | `collabora` \| `onlyoffice` (editor action). | `collabora` |

**Enrichment is fail-closed.** Generic `<title>` enrichment fetches a remote page
server-side only for origins in `proxy_allow_list`. With an empty list nothing is
fetched — links still work, just without an auto-title.

---

## What works today (Phase 2 + generic enrichment)

- Add / open / delete a **plain URL** link on an issue, end-to-end.
- Optional manual description; graceful fallback to the raw URL when there is no
  title.
- Generic `<title>` enrichment for allow-listed origins (SSRF-safe HTTP client).
- Nextcloud URL recognition + *open* / *open-in-editor* actions (metadata
  enrichment and the native picker arrive in Phase 3).

CSRF-protected, per-bug access-checked, all output escaped.

---

## Architecture (short)

Layered, dependency rule points inward (`DESIGN.md` has the full picture):

```
Presentation  pages/ajax_links.php · inc/links_view.php · src/ui (React)
Application   inc/Application/LinkService
Domain        inc/Domain/* (providers, registry, URL/SSRF/meta guards — pure)
Infra         inc/Infra/* (LinkStore, CurlHttpClient, AccessGuard, JsonResponder)
Contracts     inc/Contract/* (interfaces the domain/app depend on)
```

The composition root is `inc/bootstrap.php` (`imatic_el_container()`); the domain
is pure (no DB/network/superglobals) and therefore fully unit-testable.

---

## Testing

Pure domain + application, dependency-free (no PHPUnit / Mantis / DB / network),
runs on **PHP 7.4 and 8.x**:

```bash
php plugins/ImaticExternalLinks/tests/run.php
```

Covers URL normalisation, the SSRF allow-list (private/loopback/link-local IPs,
`file://`, `javascript:`, off-list origins, credentials-in-URL), meta validation,
provider matching/normalisation/actions/enrichment, and the `LinkService`
use-cases with fake store/guard. Exit code 0 = green.

---

## Frontend build

React 19 + TypeScript + Webpack + **Zod** (contracts, response parsing, config /
seed parsing, input validation).

```bash
cd plugins/ImaticExternalLinks
npm install
npm run build        # production → files/index.js
npm run build:dev    # development
npm run watch        # watch mode
npm run typecheck    # tsc --noEmit
```

Assets are served through Mantis' `plugin_file.php`; the plugin injects
`files/index.js` + `files/style.css` on the issue view with a cache-busting
`?v=<mtime>`.

---

## Status

Phases **0, 1, 2** complete + the SSRF-safe HTTP client / generic enrichment
slice of Phase 3. The remaining Phase 3 work (Nextcloud WebDAV gateway, encrypted
credential store, proxy endpoint, native picker) is blocked on two decisions —
`nc_auth_mode` (`per_user` vs `service_account`) and `nc_online_editor`
(Collabora vs OnlyOffice). See `PHASES.md`.
