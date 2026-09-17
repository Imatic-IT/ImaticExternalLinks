<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Application\Exception;

/** A referenced link/bug does not exist. Maps to HTTP 404. */
class NotFoundException extends \RuntimeException
{
}
