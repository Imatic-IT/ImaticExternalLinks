<?php

declare(strict_types=1);

/**
 * Thin AJAX controller for external links. Responsibilities only:
 *   - authenticate the request,
 *   - resolve + validate the bug id,
 *   - CSRF-check state-changing actions,
 *   - delegate to LinkService,
 *   - map domain exceptions to HTTP status codes via the shared JsonResponder.
 *
 * No business logic lives here.
 */

use ImaticExternalLinks\Application\Exception\AccessDeniedException;
use ImaticExternalLinks\Application\Exception\NotFoundException;
use ImaticExternalLinks\Domain\Exception\DuplicateLinkException;
use ImaticExternalLinks\Domain\Exception\InvalidLinkException;

auth_ensure_user_authenticated();

require_once __DIR__ . '/../inc/bootstrap.php';

$t_container = imatic_el_container();
$t_responder = $t_container->responder;
$t_service   = $t_container->service;

if (!plugin_config_get(ImaticExternalLinksPlugin::CFG_ENABLED)) {
    $t_responder->error(404, 'Feature disabled', 'disabled');
}

$t_action = gpc_get_string('action', '');
$t_bug_id = gpc_get_int('bug_id', 0);

if ($t_bug_id <= 0 || !bug_exists($t_bug_id)) {
    $t_responder->error(404, 'Bug not found', 'bug_not_found');
}

// CSRF: every state-changing action must carry a valid form token. Read-only
// listing is exempt. form_security_validate() aborts the request on mismatch.
$t_state_changing = in_array($t_action, ['add', 'delete', 'refresh'], true);
if ($t_state_changing) {
    form_security_validate(ImaticExternalLinksPlugin::CSRF_FORM);
}

try {
    switch ($t_action) {
        case 'list':
            $t_responder->ok($t_service->list($t_bug_id));
            break;

        case 'add':
            $t_url = trim(gpc_get_string('url', ''));
            if ($t_url === '') {
                $t_responder->error(400, 'URL is required', 'url_required');
            }
            $t_description = gpc_get_string('description', '');
            $t_row = $t_service->add($t_bug_id, $t_url, $t_description);
            $t_responder->ok(['link' => $t_row]);
            break;

        case 'delete':
            $t_link_id = gpc_get_int('link_id', 0);
            if ($t_link_id <= 0) {
                $t_responder->error(400, 'Invalid link id', 'invalid_link_id');
            }
            $t_service->remove($t_bug_id, $t_link_id);
            $t_responder->ok(['success' => true]);
            break;

        case 'refresh':
            $t_link_id = gpc_get_int('link_id', 0);
            if ($t_link_id <= 0) {
                $t_responder->error(400, 'Invalid link id', 'invalid_link_id');
            }
            $t_row = $t_service->refresh($t_bug_id, $t_link_id);
            $t_responder->ok(['link' => $t_row]);
            break;

        default:
            $t_responder->error(400, 'Unknown action', 'unknown_action');
    }
} catch (AccessDeniedException $e) {
    $t_responder->error(403, 'Permission denied', 'access_denied');
} catch (NotFoundException $e) {
    $t_responder->error(404, 'Not found', 'not_found');
} catch (DuplicateLinkException $e) {
    $t_responder->error(409, lang_get('imatic_el_error_duplicate'), 'duplicate_link');
} catch (InvalidLinkException $e) {
    $t_responder->error(400, $e->getMessage(), 'invalid_link');
} catch (\Throwable $e) {
    $t_responder->error(500, 'Internal error', 'internal_error');
}
