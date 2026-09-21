<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Application\CustomerPickerService;
use ImaticExternalLinks\Application\LinkService;
use ImaticExternalLinks\Application\NextcloudPickerService;
use ImaticExternalLinks\Contract\AccessGuard;

/**
 * Immutable holder for the wired services. Built once in inc/bootstrap.php; the
 * presentation layer reads its public members. No logic here on purpose — it is
 * just the assembled object graph.
 */
final class Container
{
    /** @var AccessGuard */
    public $access;

    /** @var LinkService */
    public $service;

    /** @var CustomerPickerService */
    public $customerPicker;

    /** @var NextcloudPickerService */
    public $nextcloudPicker;

    /** @var JsonResponder */
    public $responder;

    /** @var bool whether the "add link" flow is offered on the current project */
    public $linksEnabled;

    public function __construct(
        AccessGuard $access,
        LinkService $service,
        CustomerPickerService $customerPicker,
        NextcloudPickerService $nextcloudPicker,
        JsonResponder $responder,
        bool $linksEnabled = true
    ) {
        $this->access          = $access;
        $this->service         = $service;
        $this->customerPicker  = $customerPicker;
        $this->nextcloudPicker = $nextcloudPicker;
        $this->responder       = $responder;
        $this->linksEnabled    = $linksEnabled;
    }
}
