import { ClientProvider } from './types';

/**
 * Resolves a provider key to its client module, falling back to a catch-all when
 * the key is unknown (e.g. a provider added on the backend before the frontend
 * ships support for it). Mirrors the backend ProviderRegistry contract.
 */
export class ClientProviderRegistry {
  private readonly byKey = new Map<string, ClientProvider>();
  private readonly fallback: ClientProvider;

  constructor(providers: ClientProvider[], fallback: ClientProvider) {
    this.fallback = fallback;
    for (const provider of providers) {
      this.byKey.set(provider.key, provider);
    }
  }

  get(key: string): ClientProvider {
    return this.byKey.get(key) ?? this.fallback;
  }
}
