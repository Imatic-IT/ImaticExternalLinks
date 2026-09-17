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
    /** Project id holding customer issues (0 = customer provider disabled). */
    public const CFG_CUSTOMERS_PROJECT = 'customers_project_id';
    /** Map of meta key => Mantis custom field name for customer invoicing data. */
    public const CFG_CUSTOMER_FIELDS   = 'customer_fields';
    /** Project ids where the "add customer" flow is offered ([] = everywhere). */
    public const CFG_CUSTOMER_ENABLED_PROJECTS = 'customer_enabled_projects';
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
            self::CFG_CUSTOMERS_PROJECT => 0,
            self::CFG_CUSTOMER_FIELDS  => [
                'ico'           => 'IČO',
                'dic'           => 'DIČ',
                'invoice_email' => 'Fakturační e-mail',
                'pohoda_id'     => 'Pohoda ID',
                'contact'       => 'Fakturační kontakt',
            ],
            self::CFG_CUSTOMER_ENABLED_PROJECTS => [],
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
                'bugId'             => $t_bug_id,
                'canManage'         => $t_container->access->canManage($t_bug_id),
                'csrfToken'         => form_security_token(self::CSRF_FORM),
                'csrfField'         => self::CSRF_FORM . '_token',
                'lang'              => $this->langStrings(),
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
     * Translated UI strings handed to the frontend so all copy lives in the
     * plugin's lang files (single source of truth) rather than being duplicated
     * in TypeScript. Action labels are the same keys providers emit.
     *
     * @return array<string,string>
     */
    private function langStrings(): array
    {
        $t_keys = [
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
            'imatic_el_customer_search_placeholder',
            'imatic_el_customer_ico',
            'imatic_el_customer_dic',
            'imatic_el_customer_invoice_email',
            'imatic_el_customer_pohoda_id',
            'imatic_el_customer_all_attached',
            'imatic_el_filter_all',
            'imatic_el_filter_customer',
            'imatic_el_filter_link',
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
