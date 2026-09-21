<?php

declare(strict_types=1);

/**
 * Composition root for ImaticExternalLinks.
 *
 * Registers a PSR-4-style autoloader for the plugin's inc/ tree and builds the
 * service graph exactly once. This is the single place that knows which concrete
 * implementations back the domain interfaces, keeping wiring out of the
 * controllers and views. Requires the Mantis runtime (config + DB helpers).
 */

use ImaticExternalLinks\Application\CustomerPickerService;
use ImaticExternalLinks\Application\LinkService;
use ImaticExternalLinks\Application\NextcloudPickerService;
use ImaticExternalLinks\Domain\NextcloudScopeConfig;
use ImaticExternalLinks\Domain\OriginAllowList;
use ImaticExternalLinks\Domain\Provider\CustomerProvider;
use ImaticExternalLinks\Domain\Provider\GenericUrlProvider;
use ImaticExternalLinks\Domain\Provider\NextcloudProvider;
use ImaticExternalLinks\Domain\ProviderRegistry;
use ImaticExternalLinks\Domain\RelationConfig;
use ImaticExternalLinks\Infra\Container;
use ImaticExternalLinks\Infra\CurlHttpClient;
use ImaticExternalLinks\Infra\JsonResponder;
use ImaticExternalLinks\Infra\LinkStore;
use ImaticExternalLinks\Infra\MantisAccessGuard;
use ImaticExternalLinks\Infra\MantisCustomerGateway;
use ImaticExternalLinks\Infra\WebDavNextcloudGateway;

require_once __DIR__ . '/autoload.php';

if (!function_exists('imatic_el_container')) {
    /**
     * Build (and cache for the request) the wired service graph.
     *
     * Generic enrichment (reading a remote page <title>) is wired here via an
     * SSRF-safe HTTP client + origin allow-list, and works without Nextcloud:
     * it only fetches origins the admin allow-listed (empty list = fail-closed,
     * no enrichment). The Nextcloud gateway + credential store (which need the
     * auth-mode decision) are wired in Phase 3; until then NC links degrade
     * gracefully to the plain URL.
     */
    function imatic_el_container(): Container
    {
        static $container = null;
        if ($container !== null) {
            return $container;
        }

        $t_view_threshold   = (int) plugin_config_get(ImaticExternalLinksPlugin::CFG_VIEW);
        $t_manage_threshold = (int) plugin_config_get(ImaticExternalLinksPlugin::CFG_MANAGE);
        $t_base_urls        = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_BASE_URLS);
        $t_proxy_allow      = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_PROXY_ALLOW);
        $t_customers_pid    = (int) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMERS_PROJECT);
        $t_customer_fields  = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_FIELDS);
        $t_customer_enabled = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_ENABLED_PROJECTS);
        $t_links_enabled    = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_LINKS_ENABLED_PROJECTS);
        $t_relation_defs    = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_RELATION_DEFINITIONS);

        // Enrichment allow-list: explicit proxy list, or fall back to the NC
        // origins so a plain NC setup still enriches nothing unexpected.
        $t_allow_origins = $t_proxy_allow !== [] ? $t_proxy_allow : $t_base_urls;
        $t_allow_list    = new OriginAllowList($t_allow_origins);
        $t_http          = new CurlHttpClient();

        // Resolve relation-type definitions from config, or synthesise the single
        // historical "customer" definition from the legacy keys (back-compat shim).
        // With no relation_definitions and customers_project_id=0 this yields an
        // empty list — exactly today's "customer link off" behaviour.
        $t_definitions = RelationConfig::resolve(
            $t_relation_defs,
            $t_customers_pid,
            $t_customer_fields,
            $t_customer_enabled
        );

        // Current bug's project drives per-relation "offered here" gating (P2).
        // The issue view page passes the bug as `id`; the plugin's AJAX endpoints
        // (e.g. customer search) pass it as `bug_id` — accept either so the picker
        // resolves the same project in both contexts. 0 when it cannot be resolved;
        // isEnabledForProject(0) is true only for an empty enabled_projects list,
        // so a *restricted* relation fails closed when the project is unknown.
        $t_current_bug = (int) gpc_get_int('id', 0);
        if ($t_current_bug <= 0) {
            $t_current_bug = (int) gpc_get_int('bug_id', 0);
        }
        $t_current_project = ($t_current_bug > 0 && bug_exists($t_current_bug))
            ? (int) bug_get_field($t_current_bug, 'project_id')
            : 0;

        // Customer provider: wired from its mantis_issue definition (the customer
        // relation). Multiple mantis_issue definitions land in P3, once the
        // provider is generalised to carry its own key; today there is at most one.
        // Its open action links to this Mantis instance's issue view page.
        $t_mantis_base = rtrim((string) config_get_global('path'), '/');
        $t_customer_def = null;
        foreach ($t_definitions as $t_def) {
            if ($t_def->provider() === RelationConfig::PROVIDER_MANTIS_ISSUE) {
                $t_customer_def = $t_def;
                break;
            }
        }

        // Enrichment gateway: available whenever the definition has a target
        // project, independent of the current project — so an already-attached
        // customer link keeps rendering its invoicing data even on a project where
        // *adding* new customer links is not offered.
        $t_enrich_gw = ($t_customer_def !== null && $t_customer_def->primaryTargetProject() > 0)
            ? new MantisCustomerGateway($t_customer_def->primaryTargetProject(), $t_customer_def->fields())
            : null;
        $t_customer  = new CustomerProvider($t_mantis_base, $t_enrich_gw);

        // "Offered here" (P2): the add-customer flow (picker + frontend button) is
        // available only on projects the definition is enabled for. Empty
        // enabled_projects = everywhere = the historical customer behaviour.
        $t_customer_offered = $t_enrich_gw !== null
            && $t_customer_def->isEnabledForProject($t_current_project);
        $t_picker_gw = $t_customer_offered ? $t_enrich_gw : null;

        // Order matters: specific providers first, generic catch-all last.
        $t_registry = new ProviderRegistry([
            $t_customer,
            new NextcloudProvider($t_base_urls, null),
            new GenericUrlProvider($t_http, $t_allow_list),
        ]);

        $t_access  = new MantisAccessGuard($t_view_threshold, $t_manage_threshold);
        $t_store   = new LinkStore(db_get_table(ImaticExternalLinksPlugin::TABLE));
        $t_service = new LinkService($t_store, $t_registry, $t_access);

        // Customer picker uses the project-gated gateway (null when not offered
        // here → empty search results + hidden button, never an error).
        $t_customer_picker = new CustomerPickerService($t_access, $t_picker_gw);

        // Nextcloud file picker (ticket 86345). Server-side WebDAV via a single
        // service account, visibility constrained per project by NextcloudScope.
        // Wired only in service_account mode with credentials + a base URL; the
        // picker instance is the first configured NC base URL.
        $t_nc_auth_mode   = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_AUTH_MODE);
        $t_nc_svc_user    = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_USER);
        $t_nc_svc_pass    = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_PASSWORD);
        $t_nc_proj_folders = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_PROJECT_FOLDERS);
        $t_nc_glob_folders = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_GLOBAL_FOLDERS);
        $t_nc_base         = isset($t_base_urls[0]) ? (string) $t_base_urls[0] : '';

        $t_nc_scope   = NextcloudScopeConfig::forProject($t_nc_proj_folders, $t_nc_glob_folders, $t_current_project);
        $t_nc_gateway = ($t_nc_auth_mode === 'service_account'
            && $t_nc_svc_user !== '' && $t_nc_svc_pass !== '' && $t_nc_base !== '')
            ? new WebDavNextcloudGateway($t_nc_base, $t_nc_svc_user, $t_nc_svc_pass)
            : null;
        $t_nc_picker = new NextcloudPickerService($t_access, $t_service, $t_nc_gateway, $t_nc_scope, $t_nc_base);

        // "Add link" is offered on all projects by default; an explicit list
        // restricts it. Same failing-closed semantics as the customer flow: an
        // unknown current project (0) is offered only when the list is empty.
        $t_links_offered = $t_links_enabled === []
            || in_array($t_current_project, array_map('intval', $t_links_enabled), true);

        return $container = new Container(
            $t_access,
            $t_service,
            $t_customer_picker,
            $t_nc_picker,
            new JsonResponder(),
            $t_links_offered
        );
    }
}
