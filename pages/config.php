<?php

/**
 * Admin configuration page for ImaticExternalLinks. Managers only.
 */

access_ensure_global_level(config_get('manage_plugin_threshold'));

/**
 * Parse a textarea (one entry per line) into a clean, de-duplicated list.
 *
 * @return string[]
 */
function imatic_el_parse_lines(string $raw): array
{
    $t_lines = preg_split('/\r\n|\r|\n/', $raw);
    $t_out   = [];
    foreach ($t_lines as $t_line) {
        $t_trimmed = trim($t_line);
        if ($t_trimmed !== '' && !in_array($t_trimmed, $t_out, true)) {
            $t_out[] = $t_trimmed;
        }
    }
    return $t_out;
}

/**
 * Parse a textarea of "key = value" lines into a string map (one entry per
 * line; blank / "="-less lines skipped; first "=" splits key from value).
 *
 * @return array<string,string>
 */
function imatic_el_parse_map(string $raw): array
{
    $t_out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $t_line) {
        $t_line = trim($t_line);
        if ($t_line === '' || strpos($t_line, '=') === false) {
            continue;
        }
        [$t_k, $t_v] = explode('=', $t_line, 2);
        $t_k = trim($t_k);
        if ($t_k !== '') {
            $t_out[$t_k] = trim($t_v);
        }
    }
    return $t_out;
}

/**
 * Sanitise a posted list of project ids to unique positive ints.
 *
 * @param array<int|string,mixed> $raw
 * @return array<int,int>
 */
function imatic_el_sanitize_pids(array $raw): array
{
    $t_out = [];
    foreach ($raw as $t_pid) {
        $t_pid = (int) $t_pid;
        if ($t_pid > 0 && !in_array($t_pid, $t_out, true)) {
            $t_out[] = $t_pid;
        }
    }
    return $t_out;
}

if (gpc_get_bool('save', false)) {
    form_security_validate('plugin_imatic_external_links_config');

    plugin_config_set(ImaticExternalLinksPlugin::CFG_ENABLED, gpc_get_bool('enabled', false) ? ON : OFF);
    plugin_config_set(ImaticExternalLinksPlugin::CFG_VIEW, gpc_get_int('view_threshold', VIEWER));
    plugin_config_set(ImaticExternalLinksPlugin::CFG_MANAGE, gpc_get_int('manage_threshold', REPORTER));
    plugin_config_set(ImaticExternalLinksPlugin::CFG_NC_BASE_URLS, imatic_el_parse_lines(gpc_get_string('nextcloud_base_urls', '')));
    plugin_config_set(ImaticExternalLinksPlugin::CFG_PROXY_ALLOW, imatic_el_parse_lines(gpc_get_string('proxy_allow_list', '')));

    $t_auth_mode = gpc_get_string('nc_auth_mode', 'off');
    plugin_config_set(
        ImaticExternalLinksPlugin::CFG_NC_AUTH_MODE,
        in_array($t_auth_mode, ['off', 'per_user', 'service_account'], true) ? $t_auth_mode : 'off'
    );

    // Customer relation: project holding customer records (0 = disabled) and the
    // meta-key => Mantis custom-field name map used for invoicing enrichment.
    plugin_config_set(ImaticExternalLinksPlugin::CFG_CUSTOMERS_PROJECT, gpc_get_int('customers_project_id', 0));
    plugin_config_set(ImaticExternalLinksPlugin::CFG_CUSTOMER_FIELDS, imatic_el_parse_map(gpc_get_string('customer_fields', '')));

    // Projects where the "add customer" / "add link" flows are offered. Empty
    // selection = every project (historical behaviour).
    plugin_config_set(
        ImaticExternalLinksPlugin::CFG_CUSTOMER_ENABLED_PROJECTS,
        imatic_el_sanitize_pids(gpc_get_int_array('customer_enabled_projects', []))
    );
    plugin_config_set(
        ImaticExternalLinksPlugin::CFG_LINKS_ENABLED_PROJECTS,
        imatic_el_sanitize_pids(gpc_get_int_array('links_enabled_projects', []))
    );

    // Nextcloud file picker: service-account credentials + folder scoping. A
    // blank password field keeps the stored one (so the secret is never echoed
    // into the page and re-saving other settings doesn't wipe it).
    plugin_config_set(ImaticExternalLinksPlugin::CFG_NC_SERVICE_USER, gpc_get_string('nc_service_user', ''));
    $t_nc_password = gpc_get_string('nc_service_password', '');
    if ($t_nc_password !== '') {
        plugin_config_set(ImaticExternalLinksPlugin::CFG_NC_SERVICE_PASSWORD, $t_nc_password);
    }
    plugin_config_set(ImaticExternalLinksPlugin::CFG_NC_GLOBAL_FOLDERS, imatic_el_parse_lines(gpc_get_string('nc_global_folders', '')));

    // Per-project folder mapping comes from paired row inputs (project select +
    // folder path). Zip them into a { projectId => folder } map, dropping rows
    // with no project or an empty path. A project chosen twice → last wins.
    $t_pf_pids  = gpc_get_int_array('nc_pf_pid', []);
    $t_pf_paths = gpc_get_string_array('nc_pf_path', []);
    $t_pf_map   = [];
    foreach ($t_pf_pids as $t_i => $t_pf_pid) {
        $t_pf_pid  = (int) $t_pf_pid;
        $t_pf_path = isset($t_pf_paths[$t_i]) ? trim((string) $t_pf_paths[$t_i]) : '';
        if ($t_pf_pid > 0 && $t_pf_path !== '') {
            $t_pf_map[(string) $t_pf_pid] = $t_pf_path;
        }
    }
    plugin_config_set(ImaticExternalLinksPlugin::CFG_NC_PROJECT_FOLDERS, $t_pf_map);

    form_security_purge('plugin_imatic_external_links_config');
    print_successful_redirect(plugin_page('config', true));
}

$t_enabled     = plugin_config_get(ImaticExternalLinksPlugin::CFG_ENABLED);
$t_view        = plugin_config_get(ImaticExternalLinksPlugin::CFG_VIEW);
$t_manage      = plugin_config_get(ImaticExternalLinksPlugin::CFG_MANAGE);
$t_base_urls   = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_BASE_URLS);
$t_proxy_allow = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_PROXY_ALLOW);
$t_auth_mode   = plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_AUTH_MODE);
$t_customers_pid    = (int) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMERS_PROJECT);
$t_customer_fields  = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_FIELDS);
$t_customer_enabled = array_map('intval', (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_ENABLED_PROJECTS));
$t_links_enabled    = array_map('intval', (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_LINKS_ENABLED_PROJECTS));

// Textarea seed: "key = value" lines for the field map.
$t_fields_text = '';
foreach ($t_customer_fields as $t_k => $t_v) {
    $t_fields_text .= $t_k . ' = ' . $t_v . "\n";
}

// Nextcloud picker seeds. The password is never echoed back; we only signal
// whether one is already stored.
$t_nc_service_user    = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_USER);
$t_nc_has_password    = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_PASSWORD) !== '';
$t_nc_global_folders  = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_GLOBAL_FOLDERS);
$t_nc_project_folders = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_PROJECT_FOLDERS);
$t_nc_project_text    = '';
foreach ($t_nc_project_folders as $t_pid => $t_folder) {
    $t_nc_project_text .= $t_pid . ' = ' . $t_folder . "\n";
}

$t_access_levels = MantisEnum::getAssocArrayIndexedByValues(config_get('access_levels_enum_string'));

/** Render an access-level <select>. */
function imatic_el_level_select(string $name, $current, array $levels): void
{
    echo '<select name="' . $name . '">';
    foreach ($levels as $t_level => $t_label) {
        echo '<option value="' . (int) $t_level . '"' . ($t_level == $current ? ' selected' : '') . '>'
            . htmlspecialchars($t_label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
}

layout_page_header(plugin_lang_get('title'));
layout_page_begin('manage_overview_page.php');
print_manage_menu('manage_plugin_page.php');

?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>
<div class="widget-box widget-color-blue2">
    <div class="widget-header widget-header-small">
        <h4 class="widget-title lighter"><?php echo string_display_line(lang_get('imatic_el_cfg_title')) ?></h4>
    </div>
    <div class="widget-body">
        <div class="widget-main no-padding">
            <form method="post" action="<?php echo plugin_page('config') ?>">
                <?php echo form_security_field('plugin_imatic_external_links_config') ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-condensed">
                        <tbody>
                            <tr>
                                <th class="category" width="30%"><?php echo string_display_line(lang_get('imatic_el_cfg_enabled')) ?></th>
                                <td>
                                    <input type="checkbox" name="enabled" value="1" <?php echo $t_enabled ? 'checked' : '' ?> />
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_enabled_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_view')) ?></th>
                                <td>
                                    <?php imatic_el_level_select('view_threshold', $t_view, $t_access_levels) ?>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_view_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_manage')) ?></th>
                                <td>
                                    <?php imatic_el_level_select('manage_threshold', $t_manage, $t_access_levels) ?>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_manage_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_base_urls')) ?></th>
                                <td>
                                    <textarea name="nextcloud_base_urls" rows="3" class="form-control" placeholder="https://cloud.example.com"><?php echo htmlspecialchars(implode("\n", $t_base_urls), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_base_urls_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_proxy')) ?></th>
                                <td>
                                    <textarea name="proxy_allow_list" rows="3" class="form-control" placeholder="https://cloud.example.com"><?php echo htmlspecialchars(implode("\n", $t_proxy_allow), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_proxy_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_auth')) ?></th>
                                <td>
                                    <select name="nc_auth_mode">
                                        <?php foreach (['off', 'per_user', 'service_account'] as $t_mode): ?>
                                            <option value="<?php echo $t_mode ?>" <?php echo $t_auth_mode === $t_mode ? 'selected' : '' ?>><?php echo $t_mode ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_auth_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_nc_account')) ?></th>
                                <td>
                                    <input type="text" name="nc_service_user" class="form-control" autocomplete="off"
                                           value="<?php echo htmlspecialchars($t_nc_service_user, ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="mantis" />
                                    <input type="password" name="nc_service_password" class="form-control" autocomplete="new-password"
                                           placeholder="<?php echo htmlspecialchars($t_nc_has_password ? lang_get('imatic_el_cfg_pw_stored') : lang_get('imatic_el_cfg_pw_placeholder'), ENT_QUOTES, 'UTF-8') ?>" />
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_nc_account_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_folders_global')) ?></th>
                                <td>
                                    <textarea name="nc_global_folders" rows="3" class="form-control" placeholder="/Sdilene"><?php echo htmlspecialchars(implode("\n", $t_nc_global_folders), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_folders_global_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_folders_project')) ?></th>
                                <td>
                                    <div id="imatic-el-ncpf" data-browse-url="<?php echo htmlspecialchars(plugin_page('ajax_nc_config_browse.php'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php foreach ($t_nc_project_folders as $t_pf_pid => $t_pf_folder): ?>
                                            <div class="imatic-el-ncpf-row">
                                                <select name="nc_pf_pid[]" class="imatic-el-ncpf-project" data-placeholder="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_pick_project'), ENT_QUOTES, 'UTF-8') ?>">
                                                    <option value="0"></option>
                                                    <?php print_project_option_list((int) $t_pf_pid, false) ?>
                                                </select>
                                                <input type="text" name="nc_pf_path[]" class="imatic-el-ncpf-path form-control"
                                                       value="<?php echo htmlspecialchars((string) $t_pf_folder, ENT_QUOTES, 'UTF-8') ?>"
                                                       placeholder="/Zakaznici" />
                                                <button type="button" class="btn btn-xs btn-white btn-round imatic-el-ncpf-browse" title="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_browse'), ENT_QUOTES, 'UTF-8') ?>"><i class="ace-icon fa fa-folder-open"></i></button>
                                                <button type="button" class="btn btn-xs btn-white btn-round imatic-el-ncpf-del" title="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_remove'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-xs btn-white btn-round" id="imatic-el-ncpf-add"><?php echo string_display_line(lang_get('imatic_el_cfg_add_mapping')) ?></button>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_folders_project_help')) ?></span>

                                    <template id="imatic-el-ncpf-template">
                                        <div class="imatic-el-ncpf-row">
                                            <select name="nc_pf_pid[]" class="imatic-el-ncpf-project" data-placeholder="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_pick_project'), ENT_QUOTES, 'UTF-8') ?>">
                                                <option value="0"></option>
                                                <?php print_project_option_list(0, false) ?>
                                            </select>
                                            <input type="text" name="nc_pf_path[]" class="imatic-el-ncpf-path form-control" placeholder="/Zakaznici" />
                                            <button type="button" class="btn btn-xs btn-white btn-round imatic-el-ncpf-browse" title="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_browse'), ENT_QUOTES, 'UTF-8') ?>"><i class="ace-icon fa fa-folder-open"></i></button>
                                            <button type="button" class="btn btn-xs btn-white btn-round imatic-el-ncpf-del" title="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_remove'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
                                        </div>
                                    </template>

                                    <div id="imatic-el-ncbrowse" class="imatic-el-ncbrowse" hidden
                                         data-i18n-loading="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_loading'), ENT_QUOTES, 'UTF-8') ?>"
                                         data-i18n-empty="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_no_subfolders'), ENT_QUOTES, 'UTF-8') ?>"
                                         data-i18n-error="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_error'), ENT_QUOTES, 'UTF-8') ?>"
                                         data-i18n-connerr="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_conn_error'), ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="imatic-el-ncbrowse-box">
                                            <div class="imatic-el-ncbrowse-bar">
                                                <button type="button" class="btn btn-xs btn-white btn-round" id="imatic-el-ncbrowse-up"><i class="ace-icon fa fa-level-up"></i> <?php echo string_display_line(lang_get('imatic_el_cfg_up')) ?></button>
                                                <span class="imatic-el-ncbrowse-path"></span>
                                                <button type="button" class="btn btn-xs btn-white btn-round" id="imatic-el-ncbrowse-cancel"><?php echo string_display_line(lang_get('imatic_el_cfg_close')) ?></button>
                                            </div>
                                            <ul class="imatic-el-ncbrowse-list"></ul>
                                            <div class="imatic-el-ncbrowse-actions">
                                                <button type="button" class="btn btn-xs btn-primary btn-round" id="imatic-el-ncbrowse-pick"><?php echo string_display_line(lang_get('imatic_el_cfg_pick_folder')) ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_customers_project')) ?></th>
                                <td>
                                    <select name="customers_project_id">
                                        <option value="0"<?php echo $t_customers_pid === 0 ? ' selected' : '' ?>><?php echo string_display_line(lang_get('imatic_el_cfg_disabled_opt')) ?></option>
                                        <?php print_project_option_list($t_customers_pid, false) ?>
                                    </select>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_customers_project_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_customer_fields')) ?></th>
                                <td>
                                    <textarea name="customer_fields" rows="5" class="form-control" placeholder="ico = IČO"><?php echo htmlspecialchars(rtrim($t_fields_text), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_customer_fields_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_offer_link')) ?></th>
                                <td>
                                    <select name="links_enabled_projects[]" id="imatic-el-links-projects" multiple
                                            class="form-control imatic-el-project-select" data-placeholder="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_search_project'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php print_project_option_list($t_links_enabled, false) ?>
                                    </select>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_offer_link_help')) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category"><?php echo string_display_line(lang_get('imatic_el_cfg_offer_customer')) ?></th>
                                <td>
                                    <select name="customer_enabled_projects[]" id="imatic-el-customer-projects" multiple
                                            class="form-control imatic-el-project-select" data-placeholder="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_search_project'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php // Core helper: only accessible projects, ordered hierarchically with
                                              // subprojects indented — same order as the project menu. ?>
                                        <?php print_project_option_list($t_customer_enabled, false) ?>
                                    </select>
                                    <span class="small"><?php echo string_display_line(lang_get('imatic_el_cfg_offer_customer_help')) ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="widget-toolbox padding-8">
                    <input type="hidden" name="save" value="1" />
                    <input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo htmlspecialchars(lang_get('imatic_el_cfg_save'), ENT_QUOTES, 'UTF-8') ?>" />
                </div>

            </form>
        </div>
    </div>
</div>
</div>

<?php
// select2 (vendored locally so it loads from 'self' under the Mantis CSP) turns
// the project multi-selects into searchable tag pickers — no cmd-click, and a
// stray click can't wipe the whole selection.
?>
<link rel="stylesheet" type="text/css" href="<?php echo htmlspecialchars(plugin_file('vendor/select2.min.css'), ENT_QUOTES, 'UTF-8') ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo htmlspecialchars(plugin_file('config.css'), ENT_QUOTES, 'UTF-8') ?>" />
<script src="<?php echo htmlspecialchars(plugin_file('vendor/select2.full.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

<?php
// External file (not inline) so it passes the Mantis CSP (script-src 'self');
// cache-busted by file mtime like the issue-view assets.
$t_cfg_js      = plugin_file('config.js');
$t_cfg_js_path = plugin_file_path('config.js', plugin_get_current());
$t_cfg_js_ver  = $t_cfg_js_path !== false && is_file($t_cfg_js_path) ? filemtime($t_cfg_js_path) : '';
$t_cfg_js_sep  = strpos($t_cfg_js, '?') !== false ? '&' : '?';
?>
<script src="<?php echo htmlspecialchars($t_cfg_js . $t_cfg_js_sep . 'v=' . $t_cfg_js_ver, ENT_QUOTES, 'UTF-8') ?>"></script>

<?php
layout_page_end();
