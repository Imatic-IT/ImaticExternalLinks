<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain\Exception;

/**
 * Thrown when a raw URL cannot be normalized by a provider (wrong scheme, no
 * host, or not recognised by the provider that was asked to normalize it).
 */
class InvalidLinkException extends \InvalidArgumentException
{
}
