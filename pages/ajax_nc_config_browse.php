<?php

declare(strict_types=1);

/**
 * AJAX folder browser for the *config page* per-project mapping. Lets a plugin
 * admin pick a Nextcloud folder instead of typing its path.
 *
 * Unlike ajax_nc_browse.php (issue view, per-project scope, manage-gated), this
 * runs in the admin configuration context: it is gated on the plugin-manage
 * global level and browses the service account's whole tree (the admin is the
 * one defining the scopes). Folders only. CSRF uses the config form token.
 */

use ImaticExternalLinks\Domain\NextcloudScope;
use ImaticExternalLinks\Infra\JsonResponder;
use ImaticExternalLinks\Infra\WebDavNextcloudGateway;

access_ensure_global_level(config_get('manage_plugin_threshold'));

require_once __DIR__ . '/../inc/autoload.php';

$t_responder = new JsonResponder();

form_security_validate('plugin_imatic_external_links_config');

$t_auth  = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_AUTH_MODE);
$t_user  = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_USER);
$t_pass  = (string) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_SERVICE_PASSWORD);
$t_bases = (array) plugin_config_get(ImaticExternalLinksPlugin::CFG_NC_BASE_URLS);
$t_base  = isset($t_bases[0]) ? (string) $t_bases[0] : '';

if ($t_auth !== 'service_account' || $t_user === '' || $t_pass === '' || $t_base === '') {
    $t_responder->error(400, 'Nextcloud service account not configured', 'not_configured');
}

$t_path = NextcloudScope::normalize(gpc_get_string('path', ''));
if ($t_path === null) {
    $t_responder->error(400, 'Invalid path', 'bad_path');
}

$t_gateway = new WebDavNextcloudGateway($t_base, $t_user, $t_pass);

try {
    // Folders only — the config picker selects a directory to map to a project.
    $t_dirs = [];
    foreach ($t_gateway->browse($t_path) as $t_entry) {
        if (!empty($t_entry['isDir'])) {
            $t_dirs[] = ['name' => (string) $t_entry['name'], 'path' => (string) $t_entry['path']];
        }
    }
    $t_responder->ok(['path' => $t_path, 'entries' => $t_dirs]);
} catch (\Throwable $e) {
    $t_responder->error(500, 'Internal error', 'internal_error');
}
