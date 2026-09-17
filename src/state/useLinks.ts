import { useState } from 'react';
import { ApiError } from '../api/client';
import { LinksApi } from '../api/links';
import { LinkRow } from '../contracts/link';

export interface UseLinks {
  links: LinkRow[];
  busy: boolean;
  error: string | null;
  clearError: () => void;
  add: (url: string, description: string) => Promise<boolean>;
  remove: (id: number) => Promise<boolean>;
  refresh: (id: number) => Promise<boolean>;
  reload: () => Promise<boolean>;
}

/**
 * Owns the link list and the mutations against it. Each action funnels through
 * one busy/error guard so components stay declarative; failures surface as a
 * human message (server text when available, otherwise a generic fallback) and
 * never throw into the render tree.
 */
export function useLinks(api: LinksApi, initial: LinkRow[], t: (key: string) => string): UseLinks {
  const [links, setLinks] = useState<LinkRow[]>(initial);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run(action: () => Promise<void>): Promise<boolean> {
    setBusy(true);
    setError(null);
    try {
      await action();
      return true;
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t('imatic_el_error_generic'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  return {
    links,
    busy,
    error,
    clearError: () => setError(null),
    add: (url, description) =>
      run(async () => {
        const row = await api.add(url, description);
        setLinks((prev) => [...prev, row]);
      }),
    remove: (id) =>
      run(async () => {
        await api.remove(id);
        setLinks((prev) => prev.filter((l) => l.id !== id));
      }),
    refresh: (id) =>
      run(async () => {
        const row = await api.refresh(id);
        setLinks((prev) => prev.map((l) => (l.id === row.id ? row : l)));
      }),
    reload: () =>
      run(async () => {
        setLinks(await api.list());
      }),
  };
}
