<?php

declare(strict_types=1);

/**
 * AJAX controller for the Nextcloud file picker (ticket 86345). Two actions:
 *   - browse: list a folder within the project's allowed scope,
 *   - attach: attach a picked file to the issue.
 *
 * Both are part of the "attach" (manage) flow, so the endpoint is CSRF-protected
 * and manage-gated (the gating + scope enforcement happen inside
 * NextcloudPickerService). Thin controller: authenticate, validate, delegate,
 * emit JSON — no business logic here.
 */

use ImaticExternalLinks\Application\Exception\AccessDeniedException;
use ImaticExternalLinks\Application\Exception\NotFoundException;
use ImaticExternalLinks\Domain\Exception\DuplicateLinkException;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;

auth_ensure_user_authenticated();

require_once __DIR__ . '/../inc/bootstrap.php';

$t_container = imatic_el_container();
$t_responder = $t_container->responder;

if (!plugin_config_get(ImaticExternalLinksPlugin::CFG_ENABLED)) {
    $t_responder->error(404, 'Feature disabled', 'disabled');
}

$t_bug_id = gpc_get_int('bug_id', 0);
if ($t_bug_id <= 0 || !bug_exists($t_bug_id)) {
    $t_responder->error(404, 'Bug not found', 'bug_not_found');
}

// Reveals Nextcloud contents + attaches a link → require the CSRF token.
form_security_validate(ImaticExternalLinksPlugin::CSRF_FORM);

$t_action = gpc_get_string('action', 'browse');
$t_path   = gpc_get_string('path', '');

try {
    if ($t_action === 'attach') {
        $t_result = $t_container->nextcloudPicker->attach($t_bug_id, $t_path);
        $t_responder->ok(['link' => $t_result]);
    } else {
        $t_result = $t_container->nextcloudPicker->browse($t_bug_id, $t_path);
        $t_responder->ok($t_result);
    }
} catch (AccessDeniedException $e) {
    $t_responder->error(403, 'Permission denied', 'access_denied');
} catch (DuplicateLinkException $e) {
    $t_responder->error(409, 'Already attached', 'duplicate');
} catch (InvalidLinkException $e) {
    $t_responder->error(422, 'Invalid link', 'invalid');
} catch (NotFoundException $e) {
    $t_responder->error(404, 'Not found', 'not_found');
} catch (\Throwable $e) {
    $t_responder->error(500, 'Internal error', 'internal_error');
}
