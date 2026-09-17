<?php

declare(strict_types=1);

/**
 * Thin AJAX controller for the customer picker (Phase 3b). Searching a customer
 * is part of the "attach a customer" (manage) flow, so it is CSRF-protected and
 * manage-access-gated (the gating happens inside CustomerPickerService).
 *
 * Responsibilities only: authenticate, validate the bug id, CSRF-check, delegate
 * to CustomerPickerService, emit JSON. No business logic here.
 */

use ImaticExternalLinks\Application\Exception\AccessDeniedException;

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

// Reveals internal customer data + is part of a mutation flow → require the CSRF
// token. form_security_validate() aborts the request on mismatch.
form_security_validate(ImaticExternalLinksPlugin::CSRF_FORM);

$t_query = trim(gpc_get_string('q', ''));

try {
    $t_result = $t_container->customerPicker->search($t_bug_id, $t_query);
    $t_responder->ok($t_result);
} catch (AccessDeniedException $e) {
    $t_responder->error(403, 'Permission denied', 'access_denied');
} catch (\Throwable $e) {
    $t_responder->error(500, 'Internal error', 'internal_error');
}
