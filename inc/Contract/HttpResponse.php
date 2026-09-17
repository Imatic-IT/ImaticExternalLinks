<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Minimal transport-agnostic HTTP response. Immutable value object so the pure
 * domain can reason about results without touching cURL/streams.
 */
final class HttpResponse
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var array<string,string> lower-cased header name => value */
    public $headers;

    /** @param array<string,string> $headers */
    public function __construct(int $status, string $body, array $headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
