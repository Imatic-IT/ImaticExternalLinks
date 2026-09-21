<?php

/**
 * ImaticExternalLinks — attach links to external objects (Nextcloud files,
 * generic URLs, later GitHub/Jira) to a Mantis issue, rendered as a section on
 * the issue view page. See DESIGN.md / PHASES.md.
 *
 * This class is the Mantis-facing shell only: registration, config, schema,
 * hooks and a few small helpers. All behaviour lives in the layered code under
 * inc/ (Domain / Application / Infra), wired together in inc/bootstrap.php.
 *
 * Runtime baseline: PHP 7.4 (no promotion / readonly / enums / match).
 */
class ImaticExternalLinksPlugin extends MantisPlugin
{
    /** Physical table name (resolved through db_get_table()). */
    public const TABLE = 'imatic_external_links';

    /** Form name used for CSRF tokens on state-changing AJAX actions. */
    public const CSRF_FORM = 'plugin_imatic_external_links';

    public const CFG_ENABLED          = 'enabled';
    public const CFG_VIEW             = 'view_threshold';
    public const CFG_MANAGE           = 'manage_threshold';
    public const CFG_NC_BASE_URLS     = 'nextcloud_base_urls';
    public const CFG_PROXY_ALLOW      = 'proxy_allow_list';
    public const CFG_NC_AUTH_MODE     = 'nc_auth_mode';
    public const CFG_NC_EDITOR        = 'nc_online_editor';
    /** Nextcloud service-account credentials for the file picker (WebDAV). */
    public const CFG_NC_SERVICE_USER     = 'nc_service_user';
    public const CFG_NC_SERVICE_PASSWORD = 'nc_service_password';
    /** Folder scoping for the picker: per-project map + global fallback. */
    public const CFG_NC_PROJECT_FOLDERS  = 'nc_project_folders';
    public const CFG_NC_GLOBAL_FOLDERS   = 'nc_global_folders';
    /** Project id holding customer issues (0 = customer provider disabled). */
    public const CFG_CUSTOMERS_PROJECT = 'customers_project_id';
    /** Map of meta key => Mantis custom field name for customer invoicing data. */
    public const CFG_CUSTOMER_FIELDS   = 'customer_fields';
    /** Project ids where the "add customer" flow is offered ([] = everywhere). */
    public const CFG_CUSTOMER_ENABLED_PROJECTS = 'customer_enabled_projects';
    /** Project ids where the "add link" flow is offered ([] = everywhere). */
    public const CFG_LINKS_ENABLED_PROJECTS = 'links_enabled_projects';
    /**
     * Configurable relation-type definitions (see Domain\RelationConfig). Each
     * entry names a type: {key, provider, label, enabled_projects,
     * target_projects, fields}. Empty (the default) => the legacy
     * customers_project_id / customer_fields keys drive a single "customer"
     * definition via a back-compat shim, so behaviour is unchanged out of the box.
     */
    public const CFG_RELATION_DEFINITIONS = 'relation_definitions';

    public function register(): void
    {
        $this->name        = 'ImaticExternalLinks';
        $this->description = 'Attach links to external objects (Nextcloud, URLs) to an issue.';
        $this->version     = '0.2.0';
        $this->requires    = ['MantisCore' => '2.0.0'];
        $this->author      = 'Imatic software';
        $this->contact     = 'info@imatic.cz';
        $this->url         = 'https://www.imatic.cz/';
        $this->page        = 'config';
    }

    public function config(): array
    {
        return [
            self::CFG_ENABLED           => ON,
            self::CFG_VIEW              => VIEWER,
            self::CFG_MANAGE           => REPORTER,
            self::CFG_NC_BASE_URLS     => [],
            self::CFG_PROXY_ALLOW      => [],
            self::CFG_NC_AUTH_MODE     => 'off',
            self::CFG_NC_EDITOR        => 'collabora',
            self::CFG_NC_SERVICE_USER     => '',
            self::CFG_NC_SERVICE_PASSWORD => '',
            self::CFG_NC_PROJECT_FOLDERS  => [],
            self::CFG_NC_GLOBAL_FOLDERS   => [],
            self::CFG_CUSTOMERS_PROJECT => 0,
            self::CFG_CUSTOMER_FIELDS  => [
                'ico'           => 'IČO',
                'dic'           => 'DIČ',
                'invoice_email' => 'Fakturační e-mail',
                'pohoda_id'     => 'Pohoda ID',
                'contact'       => 'Fakturační kontakt',
            ],
            self::CFG_CUSTOMER_ENABLED_PROJECTS => [],
            self::CFG_LINKS_ENABLED_PROJECTS    => [],
            self::CFG_RELATION_DEFINITIONS => [],
        ];
    }

    public function schema(): array
    {
        return [
            0 => ['CreateTableSQL', [db_get_table(self::TABLE), "
                id          I           PRIMARY NOTNULL UNSIGNED AUTOINCREMENT,
                bug_id      I           NOTNULL UNSIGNED,
                provider    C(32)       NOTNULL,
                url         C(2000)     NOTNULL,
                title       C(500),
                description C(2000),
                meta        XL,
                position    I           NOTNULL DEFAULT '0',
                created_by  I           NOTNULL UNSIGNED,
                created_at  I           NOTNULL DEFAULT '0',
                updated_at  I           NOTNULL DEFAULT '0'
            "]],
            1 => ['CreateIndexSQL', ['imatic_external_links_bug_idx', db_get_table(self::TABLE), 'bug_id']],
        ];
    }

    public function hooks(): array
    {
        return [
            'EVENT_VIEW_BUG_EXTRA'  => 'renderSection',
            'EVENT_LAYOUT_BODY_END' => 'injectAssets',
        ];
    }

    /**
     * Render the "External links" section under the issue notes. Delegates the
     * markup to inc/links_view.php; access is enforced there.
     *
     * Wrapped in a catch-all: a plugin must never break the issue page. On any
     * failure it renders nothing.
     */
    public function renderSection(string $p_event, $p_bug_id = null): void
    {
        try {
            $t_bug_id = $this->activeBugId($p_bug_id);
            if ($t_bug_id === 0) {
                return;
            }
            require_once __DIR__ . '/inc/bootstrap.php';
            if (!imatic_el_container()->access->canView($t_bug_id)) {
                return;
            }
            include __DIR__ . '/inc/links_view.php';
        } catch (\Throwable $e) {
            // Never let the plugin break the page.
        }
    }

    /**
     * Inject the built frontend bundle + stylesheet at the end of the body.
     *
     * EVENT_LAYOUT_BODY_END fires on *every* page, so this is strictly scoped to
     * the issue view page for an existing, viewable bug — otherwise the plugin
     * would emit assets (and run access checks) site-wide. Wrapped in a catch-all
     * so a failure here can never affect an unrelated page.
     */
    public function injectAssets(string $p_event): void
    {
        try {
            $t_bug_id = $this->activeBugId(null);
            if ($t_bug_id === 0) {
                return;
            }
            require_once __DIR__ . '/inc/bootstrap.php';
            $t_container = imatic_el_container();
            if (!$t_container->access->canView($t_bug_id)) {
                return;
            }

            $t_config = [
                'ajaxUrl'           => plugin_page('ajax_links.php'),
                'customerSearchUrl' => plugin_page('ajax_customer_search.php'),
                'customersEnabled'  => $t_container->customerPicker->isEnabled(),
                'linksEnabled'      => $t_container->linksEnabled,
                'bugId'             => $t_bug_id,
                'canManage'         => $t_container->access->canManage($t_bug_id),
                'csrfToken'         => form_security_token(self::CSRF_FORM),
                'csrfField'         => self::CSRF_FORM . '_token',
                'lang'              => $this->langStrings(),
                'backlinks'         => $this->backlinks($t_container, $t_bug_id),
                'relStatuses'       => $this->relationshipStatuses($t_bug_id),
                'nextcloudPickerEnabled' => $t_container->nextcloudPicker->isEnabled(),
                'nextcloudBrowseUrl'     => plugin_page('ajax_nc_browse.php'),
            ];

            $t_json = json_encode($t_config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

            echo '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl('style.css') . '">';
            echo '<script id="imatic-external-links" data-config=\'' . $t_json . '\' type="text/javascript" src="' . $this->assetUrl('index.js') . '"></script>';
        } catch (\Throwable $e) {
            // Never let asset injection break an unrelated page.
        }
    }

    public function isEnabled(): bool
    {
        return (bool) plugin_config_get(self::CFG_ENABLED);
    }

    /**
     * Issues that link to the current one — only on a customer issue (an issue
     * in the customers project). Resolved and access-filtered by the service;
     * here we decorate each id with a view URL, label and status name for the
     * "Linked issues" tab. Empty everywhere else, so the tab stays hidden.
     *
     * @return array<int,array<string,mixed>>
     */
    private function backlinks($container, int $bugId): array
    {
        $t_customers_pid = (int) plugin_config_get(self::CFG_CUSTOMERS_PROJECT);
        if ($t_customers_pid <= 0 || (int) bug_get_field($bugId, 'project_id') !== $t_customers_pid) {
            return [];
        }

        $t_out = [];
        foreach ($container->service->customerBacklinks($bugId) as $t_id) {
            $t_out[] = [
                'id'     => $t_id,
                'url'    => string_get_bug_view_url($t_id),
                'label'  => bug_format_id($t_id) . ' – ' . bug_get_field($t_id, 'summary'),
                'status' => get_enum_element('status', (int) bug_get_field($t_id, 'status')),
            ];
        }
        return $t_out;
    }

    /**
     * Status enum for the current bug's project, tagged with whether each value
     * counts as resolved/closed. The relationships-filter enhancement uses this
     * to hide closed rows by default and to build the status picker; labels match
     * the text the core relationships table renders in `.issue-status`.
     *
     * @return array<int,array<string,mixed>>
     */
    private function relationshipStatuses(int $bugId): array
    {
        $t_project  = (int) bug_get_field($bugId, 'project_id');
        $t_resolved = (int) config_get('bug_resolved_status_threshold', null, null, $t_project);
        $t_enum     = config_get('status_enum_string', null, null, $t_project);

        $t_out = [];
        foreach (MantisEnum::getValues($t_enum) as $t_val) {
            $t_out[] = [
                'label'  => get_enum_element('status', $t_val, null, $t_project),
                'closed' => (int) $t_val >= $t_resolved,
            ];
        }
        return $t_out;
    }

    /**
     * Translated UI strings handed to the frontend so all copy lives in the
     * plugin's lang files (single source of truth) rather than being duplicated
     * in TypeScript. Action labels are the same keys providers emit.
     *
     * @return array<string,string>
     */
    private function langStrings(): array
    {
        $t_keys = [
            'imatic_el_section_title',
            'imatic_el_backlinks_title',
            'imatic_el_empty',
            'imatic_el_add_btn',
            'imatic_el_save',
            'imatic_el_cancel',
            'imatic_el_delete',
            'imatic_el_refresh',
            'imatic_el_confirm_delete',
            'imatic_el_url_label',
            'imatic_el_url_placeholder',
            'imatic_el_description_label',
            'imatic_el_action_open',
            'imatic_el_action_open_editor',
            'imatic_el_action_open_customer',
            'imatic_el_add_customer_btn',
            'imatic_el_nc_add_btn',
            'imatic_el_nc_up',
            'imatic_el_nc_empty',
            'imatic_el_nc_attach',
            'imatic_el_customer_search_placeholder',
            'imatic_el_customer_ico',
            'imatic_el_customer_dic',
            'imatic_el_customer_invoice_email',
            'imatic_el_customer_pohoda_id',
            'imatic_el_customer_all_attached',
            'imatic_el_filter_all',
            'imatic_el_filter_customer',
            'imatic_el_filter_link',
            'imatic_rel_filter_placeholder',
            'imatic_rel_show_all',
            'imatic_rel_only_active',
            'imatic_rel_count',
            'imatic_el_error_generic',
            'imatic_el_error_invalid_url',
        ];

        $t_strings = [];
        foreach ($t_keys as $t_key) {
            $t_strings[$t_key] = lang_get($t_key);
        }
        return $t_strings;
    }

    /**
     * Resolve the issue id for the current request. EVENT_VIEW_BUG_EXTRA passes
     * it as an argument; EVENT_LAYOUT_BODY_END does not, so fall back to the URL.
     */
    private function currentBugId($p_bug_id): int
    {
        if (is_numeric($p_bug_id) && (int) $p_bug_id > 0) {
            return (int) $p_bug_id;
        }
        return (int) gpc_get_int('id', 0);
    }

    /**
     * The bug id this request should act on, or 0 when the plugin must stay out
     * of the way. Guards, in order: feature enabled, we are on the issue view
     * page (view.php), a positive id, and the bug actually exists. The
     * bug_exists() check is essential: EVENT_LAYOUT_BODY_END fires on every page,
     * and calling access_has_bug_level() on a non-bug ?id= would raise
     * ERROR_BUG_NOT_FOUND and disrupt that page.
     */
    private function activeBugId($p_bug_id): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'view.php') {
            return 0;
        }
        $t_bug_id = $this->currentBugId($p_bug_id);
        if ($t_bug_id <= 0 || !bug_exists($t_bug_id)) {
            return 0;
        }
        return $t_bug_id;
    }

    /**
     * Build a plugin asset URL with a cache-busting version from the file mtime,
     * so a fresh build is picked up immediately (matches ImaticLiveFields).
     */
    private function assetUrl(string $file): string
    {
        $t_url  = plugin_file($file);
        $t_path = plugin_file_path($file, plugin_get_current());
        $t_ver  = $t_path !== false && is_file($t_path) ? filemtime($t_path) : $this->version;
        $t_sep  = strpos($t_url, '?') !== false ? '&' : '?';

        return $t_url . $t_sep . 'v=' . $t_ver;
    }
}
