<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Application\Exception;

/** The current user lacks the required access level. Maps to HTTP 403. */
class AccessDeniedException extends \RuntimeException
{
}
