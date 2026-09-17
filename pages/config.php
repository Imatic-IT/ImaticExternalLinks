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

    $t_editor = gpc_get_string('nc_online_editor', 'collabora');
    plugin_config_set(
        ImaticExternalLinksPlugin::CFG_NC_EDITOR,
        in_array($t_editor, ['collabora', 'onlyoffice'], true) ? $t_editor : 'collabora'
    );

    // Customer relation: project holding customer records (0 = disabled) and the
    // meta-key => Mantis custom-field name map used for invoicing enrichment.
    plugin_config_set(ImaticExternalLinksPlugin::CFG_CUSTOMERS_PROJECT, gpc_get_int('customers_project_id', 0));
    plugin_config_set(ImaticExternalLinksPlugin::CFG_CUSTOMER_FIELDS, imatic_el_parse_map(gpc_get_string('customer_fields', '')));

    // Projects where the "add customer" flow is offered. Empty selection = every
    // project (historical behaviour). Values are sanitised to positive ints.
    $t_enabled_raw = gpc_get_int_array('customer_enabled_projects', []);
    $t_enabled_pids = [];
    foreach ($t_enabled_raw as $t_pid) {
        $t_pid = (int) $t_pid;
        if ($t_pid > 0 && !in_array($t_pid, $t_enabled_pids, true)) {
            $t_enabled_pids[] = $t_pid;
        }
    }
    plugin_config_set(ImaticExternalLinksPlugin::CFG_CUSTOMER_ENABLED_PROJECTS, $t_enabled_pids);

    form_security_purge('plugin_imatic_external_links_config');
    print_successful_redirect(plugin_page('config', true));
}

$t_enabled     = plugin_config_get(ImaticExternalLinksPlugin::CFG_ENABLED);
$t_view        = plugin_config_get(ImaticExternalLinksPlugin::CFG_VIEW);
$t_manage      = plugin_config_get(ImaticExternalLinksPlugin::CFG_MANAGE);
$t_base_urls   = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_BASE_URLS);
$t_proxy_allow = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_PROXY_ALLOW);
$t_auth_mode   = plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_AUTH_MODE);
$t_editor      = plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_EDITOR);
$t_customers_pid    = (int) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMERS_PROJECT);
$t_customer_fields  = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_FIELDS);
$t_customer_enabled = array_map('intval', (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_CUSTOMER_ENABLED_PROJECTS));

// Textarea seed: "key = value" lines for the field map.
$t_fields_text = '';
foreach ($t_customer_fields as $t_k => $t_v) {
    $t_fields_text .= $t_k . ' = ' . $t_v . "\n";
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
        <h4 class="widget-title lighter">ImaticExternalLinks — Configuration</h4>
    </div>
    <div class="widget-body">
        <div class="widget-main no-padding">
            <form method="post" action="<?php echo plugin_page('config') ?>">
                <?php echo form_security_field('plugin_imatic_external_links_config') ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-condensed">
                        <tbody>
                            <tr>
                                <th class="category" width="30%">Enabled</th>
                                <td>
                                    <input type="checkbox" name="enabled" value="1" <?php echo $t_enabled ? 'checked' : '' ?> />
                                    <span class="small">Show the external-links section on issue pages.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">View threshold</th>
                                <td>
                                    <?php imatic_el_level_select('view_threshold', $t_view, $t_access_levels) ?>
                                    <span class="small">Minimum access level to see links.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Manage threshold</th>
                                <td>
                                    <?php imatic_el_level_select('manage_threshold', $t_manage, $t_access_levels) ?>
                                    <span class="small">Minimum access level to add / delete links.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Nextcloud base URLs</th>
                                <td>
                                    <textarea name="nextcloud_base_urls" rows="3" class="form-control" placeholder="https://cloud.example.com"><?php echo htmlspecialchars(implode("\n", $t_base_urls), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small">One origin per line. Only these origins are treated as Nextcloud links.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Proxy allow-list</th>
                                <td>
                                    <textarea name="proxy_allow_list" rows="3" class="form-control" placeholder="https://cloud.example.com"><?php echo htmlspecialchars(implode("\n", $t_proxy_allow), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small">One origin per line. Origins the server may fetch for enrichment (SSRF guard). Empty = Nextcloud URLs only.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Nextcloud auth mode</th>
                                <td>
                                    <select name="nc_auth_mode">
                                        <?php foreach (['off', 'per_user', 'service_account'] as $t_mode): ?>
                                            <option value="<?php echo $t_mode ?>" <?php echo $t_auth_mode === $t_mode ? 'selected' : '' ?>><?php echo $t_mode ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="small">How the server authenticates to Nextcloud for enrichment / browsing (Phase 3).</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Online editor</th>
                                <td>
                                    <select name="nc_online_editor">
                                        <?php foreach (['collabora', 'onlyoffice'] as $t_ed): ?>
                                            <option value="<?php echo $t_ed ?>" <?php echo $t_editor === $t_ed ? 'selected' : '' ?>><?php echo $t_ed ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="small">Editor used for the "open in editor" action on office files.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Customers project</th>
                                <td>
                                    <select name="customers_project_id">
                                        <option value="0"<?php echo $t_customers_pid === 0 ? ' selected' : '' ?>>— disabled —</option>
                                        <?php print_project_option_list($t_customers_pid, false) ?>
                                    </select>
                                    <span class="small">Project holding customer records. Enables the "add customer" flow.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Customer fields</th>
                                <td>
                                    <textarea name="customer_fields" rows="5" class="form-control" placeholder="ico = IČO"><?php echo htmlspecialchars(rtrim($t_fields_text), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <span class="small">One "meta_key = Mantis custom field name" per line, for invoicing enrichment.</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="category">Offer "add customer" on</th>
                                <td>
                                    <select name="customer_enabled_projects[]" multiple size="6" class="form-control">
                                        <?php foreach (project_get_all_rows() as $t_project): ?>
                                            <option value="<?php echo (int) $t_project['id'] ?>"<?php echo in_array((int) $t_project['id'], $t_customer_enabled, true) ? ' selected' : '' ?>>
                                                <?php echo htmlspecialchars($t_project['name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="small">Projects where the "add customer" button appears. Select none = every project.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="widget-toolbox padding-8">
                    <input type="hidden" name="save" value="1" />
                    <input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="Save" />
                </div>

            </form>
        </div>
    </div>
</div>
</div>

<?php
layout_page_end();
