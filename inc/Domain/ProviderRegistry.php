<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Resolves a URL to the provider that should handle it. Order matters: specific
 * providers first, the catch-all generic provider last.
 */
final class ProviderRegistry
{
    /** @var LinkProvider[] */
    private $providers;

    /** @param LinkProvider[] $providers ordered; last one should be the catch-all */
    public function __construct(array $providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * First provider whose matches() returns true; otherwise the last provider
     * (the catch-all). Its normalize() is responsible for rejecting bad input.
     */
    public function forUrl(string $url): LinkProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->matches($url)) {
                return $provider;
            }
        }
        if ($this->providers === []) {
            throw new \LogicException('ProviderRegistry has no providers registered');
        }
        return $this->providers[count($this->providers) - 1];
    }

    public function byKey(string $key): ?LinkProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }
        return null;
    }
}
