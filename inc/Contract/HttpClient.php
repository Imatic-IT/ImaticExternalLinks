<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Contract;

/**
 * Outbound HTTP boundary. Implementations enforce the operational safety
 * concerns (timeouts, response-size cap, TLS verification, redirect policy).
 * The domain depends only on this interface (dependency inversion).
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws \RuntimeException on transport failure
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
