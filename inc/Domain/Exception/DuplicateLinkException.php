<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain\Exception;

/**
 * Thrown when the same target is attached to a bug twice: a link whose
 * normalized URL already exists on that bug (e.g. connecting the same customer
 * again). Lets the controller answer 409 instead of silently creating a dupe.
 */
class DuplicateLinkException extends \RuntimeException
{
}
