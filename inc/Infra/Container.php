<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

use ImaticExternalLinks\Application\CustomerPickerService;
use ImaticExternalLinks\Application\LinkService;
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

    /** @var JsonResponder */
    public $responder;

    public function __construct(
        AccessGuard $access,
        LinkService $service,
        CustomerPickerService $customerPicker,
        JsonResponder $responder
    ) {
        $this->access         = $access;
        $this->service        = $service;
        $this->customerPicker = $customerPicker;
        $this->responder      = $responder;
    }
}
