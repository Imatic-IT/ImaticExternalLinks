# Plán: konfiguračná vrstva pre väzby (ImaticExternalLinks)

Handoff pre druhú session. Cieľom je povýšiť dnešné **3 natvrdo zadrôtované providery**
na **konfigurovateľné definície väzieb** — presne to, čo žiada ticket #86345
(imaticit-it) a komentár Honzu Pekára.

> Testuje sa LEN na lokálnom Mantise. Produkcie sa nedotýkame. Commity NErobiť —
> commituje používateľ. PHP baseline 7.4 (žiadne enum / match / constructor
> promotion / readonly). Kontrakt providerov „fail soft" ostáva.

---

## 1. Čo už existuje (východisko, nemeniť zbytočne)

Platforma pre väzby je z veľkej časti hotová:

- `LinkProvider` rozhranie: `key() / matches() / normalize() / actions() / enrich()`
  (`inc/Domain/LinkProvider.php`). `enrich()` smie robiť I/O a musí zlyhať potichu.
- `ProviderRegistry` (`inc/Domain/ProviderRegistry.php`): `forUrl()` (poradie:
  špecifické prv, generic posledný) + `byKey()`.
- Providery: `CustomerProvider` (interné Mantis issue, schéma `customer://<id>`),
  `NextcloudProvider`, `GenericUrlProvider`.
- Gateway `MantisCustomerGateway(projectId, fieldMap)`: `fetch()` + `search()`
  nad JEDNÝM projektom, číta custom polia podľa injektovanej mapy.
- Ukladanie: tabuľka `imatic_external_links`, stĺpec `provider C(32)` už drží
  kľúč providera; N väzieb per `bug_id`; sekcia vo view cez `EVENT_VIEW_BUG_EXTRA`.
- Frontend: React dialóg + picker (`src/providers/*`, `src/state/useLinks.ts`),
  config sa injektuje v `ImaticExternalLinks.php::injectAssets()`.

**Model:** viac väzieb na 1 issue, ako natívne Mantis „Vztahy", len na externé
objekty a iné projekty.

## 2. Čo chýba (to staviame)

| Požiadavka | Dnes | Cieľ |
|---|---|---|
| viac **typov** väzieb definovateľných configom | 3 natvrdo, nový typ = nová PHP trieda | zoznam definícií v configu |
| **per-projekt**: kde sa dá väzba pridávať | globálne (`customers_project_id>0`) | per definícia `enabled_projects` |
| **kde hľadať** cieľ (napr. len customers) | 1 globálne `customers_project_id` | per definícia `target_projects` |
| **názov/label** typu | napevno `customer` | per definícia `label`/`key` |
| admin UI | len `config_inc.php` | sekcia v `pages/config.php` |

## 3. Kľúčové rozhodnutie: 2 vrstvy

Rozlíšiť a nemiešať:

- **Provider (kód)** = *správanie* pre triedu cieľa: match/normalize/actions/enrich.
  Ostáva kód. Dnešné: `mantis_issue` (dnešný CustomerProvider zovšeobecnený),
  `nextcloud`, `generic`. Neskôr `github`, `jira` (ticket #86345, nízka priorita).
- **Definícia väzby (config)** = *pomenované nastavenie*, ktoré hovorí: tento typ
  používa provider X, je dostupný v projektoch [...], hľadá/mieri do projektov [...],
  volá sa L, s voliteľnou mapou polí. **Viac definícií smie zdieľať jeden provider**
  (napr. „Customer" a „Dodávateľ" oba cez `mantis_issue`, iný cieľový projekt).

Do stĺpca `provider` v DB ukladáme **kľúč definície** (nie holý provider key), aby
sa riadok vedel vrátiť k svojej definícii. Dnešné riadky majú `customer` → ostáva
platný kľúč definície (viď P1 back-compat).

## 4. Config model

Nový plugin config kľúč `relation_definitions` (pole). Jedna položka:

```php
[
  'key'              => 'customer',      // stabilný id, ide do stĺpca provider
  'provider'         => 'mantis_issue',  // ktorý kódový provider ho obsluhuje
  'label'            => 'Customer',      // lang kľúč alebo text
  'enabled_projects' => [3],             // kde sa ponúka pridanie (0/[] = všade)
  'target_projects'  => [7],             // kde hľadať cieľ (interné providery)
  'fields'           => [                // voliteľná enrichment mapa (per definícia)
     'ico' => 'IČO', 'dic' => 'DIČ',
     'invoice_email' => 'Fakturační e-mail', 'pohoda_id' => 'Pohoda ID',
  ],
]
```

**Back-compat shim:** keď je `relation_definitions` prázdne, syntetizuj JEDNU
definíciu `customer` zo starých `customers_project_id` + `customer_fields`
(→ `provider='mantis_issue'`, `enabled_projects=[]`, `target_projects=[customers_pid]`,
`fields=customer_fields`). Staré kľúče ponechať funkčné, nič sa nerozbije.

## 5. Zmeny po súboroch

- `ImaticExternalLinks.php`
  - pridať `CFG_RELATION_DEFINITIONS` + default `[]`; ponechať staré kľúče (shim).
  - `injectAssets()`: namiesto boolean `customersEnabled` injektovať pole
    `relationTypes` platných pre **aktuálny projekt** (viď P2): `[{key,label,
    kind:'picker'|'url', searchUrl}]`. `kind='picker'` pre interné (mantis_issue),
    `kind='url'` pre nextcloud/generic.
- `inc/bootstrap.php`
  - poskladať definície (config alebo shim), pre každú postaviť inštanciu providera
    s jej parametrami a vložiť do `ProviderRegistry` **keyed by definition key**.
    Generic ostáva posledný catch-all.
- `inc/Domain/Provider/CustomerProvider.php` → zovšeobecniť na **`MantisIssueProvider`**
  - konštruktor berie `key`, `scheme`, `mantisBaseUrl`, `gateway`. `KEY`/`SCHEME`
    prestanú byť konštanty. Zachovať `customer://<id>` pre existujúce dáta (definícia
    `customer` použije schému `customer`). Ostatné definície dostanú vlastnú schému
    napr. `mantisissue-<key>://<id>`.
- `inc/Infra/MantisCustomerGateway.php` → premenovať na **`MantisIssueGateway`**
  (už teraz je parametrizovaný `projectId` + `fieldMap`; podporiť viac cieľových
  projektov: `int[] $projectIds`, filter vo `fetch()`/`search()` cez `IN`).
- `inc/Application/CustomerPickerService.php` → **`RelationPickerService`**
  - `search(bugId, definitionKey, query, limit)`: over `enabled_projects` pre projekt
    issue, vyber gateway definície, hľadaj v jej `target_projects`.
- `pages/ajax_customer_search.php` → prijať param `type` (kľúč definície); alebo nový
  `ajax_relation_search.php`. Zachovať staré URL ako alias.
- `pages/config.php` (admin UI) — pridať sekciu na správu definícií. **P4**, najväčší
  nový kus. Prvý krok môže byť JSON textarea (nízke úsilie), potom poriadny formulár
  (key, provider dropdown, label, multiselecty projektov, fields).
- Frontend `src/`
  - `contracts/config.ts`: `relationTypes` namiesto `customersEnabled`.
  - `providers/registry.ts` + „Add" UI: ponúkni každý dostupný typ; `picker` → dialóg
    hľadania scoped na `type`, `url` → input URL.

## 6. Fázovanie (na inkrementálny review)

- **P1 — Config model + back-compat shim** *(žiadna zmena správania)*
  Zaviesť `relation_definitions`, shim zo starého configu, registry per-definícia.
  156 testov ostáva zelených. ← **najmenšie, reviewuj prvé.**
- **P2 — Per-projekt gating**
  Filtrovať definície podľa `project_id` aktuálneho bugu; frontend dostane per-projekt
  zoznam typov. Test: definícia mimo projektu sa neponúkne.
- **P3 — Generalizácia providera + pickera**
  `CustomerProvider→MantisIssueProvider`, `MantisCustomerGateway→MantisIssueGateway`,
  `CustomerPickerService→RelationPickerService`. `customer` ostáva ako jedna definícia.
  Test: druhá `mantis_issue` definícia s iným cieľovým projektom funguje paralelne.
- **P4 — Admin UI** v `pages/config.php`.
- **P5 — (voliteľné, nízka priorita)** nové providery `github` / `jira` podľa #86345
  (extra akcie + enrichment; cross-domain rieš fallbackom na plain + ručný popis).

## 7. Akceptačné kritériá (na moju kontrolu)

- [ ] Bez configu sa správa 1:1 ako dnes (shim), 156+ testov zelených.
- [ ] Dá sa nakonfigurovať ≥2 definície nad `mantis_issue` s rôznymi cieľmi.
- [ ] `enabled_projects` reálne skryje/ukáže „Add" v danom projekte.
- [ ] `target_projects` obmedzí výsledky hľadania v pickeri.
- [ ] Stĺpec `provider` drží kľúč definície; staré `customer` riadky sa otvoria a
      klik/enrichment fungujú.
- [ ] Žiadny commit; PHP 7.4; fail-soft enrich; iba lokálny Mantis.

## 8. Otvorená otázka (potvrdiť s kolegom pred P3+)

Ticket #86345 znie na **viac typov väzieb** (customer, nextcloud, github, jira…) —
plán s tým počíta. Ak by kolega chcel len „spraviť tú jednu customer väzbu
konfigurovateľnou", stačí P1–P2 a P3 sa výrazne zjednoduší. Formulácia z ticketu
(„platforma pre väzby", „typ vazby customer") = počítame s viacerými typmi.
