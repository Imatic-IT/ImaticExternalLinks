import { useEffect, useState } from 'react';
import { LinksApi } from '../api/links';
import { ApiError } from '../api/client';
import { CustomerCandidate } from '../contracts/customer';

interface Props {
  t: (key: string) => string;
  api: LinksApi;
  busy: boolean;
  /** Customer ids already attached to this issue — hidden from the results. */
  excludeIds: ReadonlySet<number>;
  onPick: (customerId: number) => void;
  onCancel: () => void;
}

/**
 * Customer picker (Phase 3b): a debounced fulltext search over the customers
 * project. Picking a candidate hands its id up to the parent, which attaches it
 * as a `customer://<id>` link through the normal add flow. Results are already
 * Zod-validated by the API layer.
 */
export function CustomerPickerDialog({ t, api, busy, excludeIds, onPick, onCancel }: Props) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<CustomerCandidate[]>([]);
  const [searching, setSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const trimmed = query.trim();
    if (trimmed === '') {
      setResults([]);
      setError(null);
      return;
    }

    let cancelled = false;
    setSearching(true);
    const handle = window.setTimeout(async () => {
      try {
        const found = await api.searchCustomers(trimmed);
        if (!cancelled) {
          setResults(found);
          setError(null);
        }
      } catch (e) {
        if (!cancelled) {
          setResults([]);
          setError(e instanceof ApiError ? e.message : t('imatic_el_error_generic'));
        }
      } finally {
        if (!cancelled) {
          setSearching(false);
        }
      }
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(handle);
    };
  }, [query, api, t]);

  // Already-attached customers stay out of the results so a duplicate can't be
  // picked (the add flow would reject it as a duplicate anyway).
  const visible = results.filter((c) => !excludeIds.has(c.id));

  return (
    <div className="imatic-el-customer-picker">
      <div className="imatic-el-field">
        <input
          type="text"
          className="input-sm"
          value={query}
          placeholder={t('imatic_el_customer_search_placeholder')}
          autoFocus
          onChange={(e) => setQuery(e.target.value)}
        />
        <button
          type="button"
          className="btn btn-xs btn-white btn-round"
          disabled={busy}
          onClick={onCancel}
        >
          {t('imatic_el_cancel')}
        </button>
      </div>

      {error && <div className="imatic-el-inline-error">{error}</div>}
      {searching && <div className="imatic-el-loading">…</div>}

      {!searching && results.length > 0 && visible.length === 0 && (
        <div className="imatic-el-loading">{t('imatic_el_customer_all_attached')}</div>
      )}

      {visible.length > 0 && (
        <ul className="imatic-el-customer-results">
          {visible.map((customer) => (
            <li key={customer.id}>
              <button
                type="button"
                className="imatic-el-customer-result"
                disabled={busy}
                onClick={() => onPick(customer.id)}
              >
                <span className="imatic-el-customer-name">{customer.name}</span>
                {customer.ico !== '' && (
                  <span className="imatic-el-customer-ico">
                    {t('imatic_el_customer_ico')}: {customer.ico}
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
