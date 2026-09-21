import { useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import { LinksApi } from '../api/links';
import { NcBrowseResponse } from '../contracts/nextcloud';

interface Props {
  t: (key: string) => string;
  api: LinksApi;
  busy: boolean;
  /** Attach the picked file by its in-scope Nextcloud path. */
  onPick: (path: string) => void;
  onCancel: () => void;
}

/**
 * Nextcloud file picker (ticket 86345): browse the project's allowed folders
 * (served by the service account, scope-enforced server-side) and pick a file
 * to attach. Folders are navigated into; picking a file hands its path up to
 * the parent, which attaches it as a `<base>/f/<id>` link. A visited-path stack
 * powers "up", so navigation can never leave the scope it was given.
 */
export function NextcloudPickerDialog({ t, api, busy, onPick, onCancel }: Props) {
  const [path, setPath] = useState('');
  const [history, setHistory] = useState<string[]>([]);
  const [data, setData] = useState<NcBrowseResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    api
      .browseNextcloud(path)
      .then((res) => {
        if (!cancelled) {
          setData(res);
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setData(null);
          setError(e instanceof ApiError ? e.message : t('imatic_el_error_generic'));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [path, api, t]);

  function openDir(next: string): void {
    setHistory((h) => [...h, path]);
    setPath(next);
  }

  function goUp(): void {
    if (history.length === 0) {
      return;
    }
    const prev = history[history.length - 1];
    setHistory((h) => h.slice(0, -1));
    setPath(prev);
  }

  const here = data?.path ?? (path === '' ? '/' : path);
  const entries = data?.entries ?? [];

  return (
    <div className="imatic-el-customer-picker imatic-el-nc-picker">
      <div className="imatic-el-nc-bar">
        <button
          type="button"
          className="btn btn-xs btn-white btn-round"
          disabled={history.length === 0 || loading}
          onClick={goUp}
        >
          <i className="fa fa-level-up" aria-hidden="true" /> {t('imatic_el_nc_up')}
        </button>
        <span className="imatic-el-nc-path" title={here}>
          {here}
        </span>
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
      {loading && <div className="imatic-el-loading">…</div>}

      {!loading && !error && entries.length === 0 && (
        <div className="imatic-el-loading">{t('imatic_el_nc_empty')}</div>
      )}

      {entries.length > 0 && (
        <ul className="imatic-el-customer-results imatic-el-nc-list">
          {entries.map((entry) =>
            entry.isDir ? (
              <li key={entry.path}>
                <button
                  type="button"
                  className="imatic-el-nc-entry"
                  disabled={loading}
                  onClick={() => openDir(entry.path)}
                >
                  <i className="fa fa-folder imatic-el-nc-icon" aria-hidden="true" />
                  <span className="imatic-el-nc-name">{entry.name}</span>
                </button>
              </li>
            ) : (
              <li key={entry.path}>
                <button
                  type="button"
                  className="imatic-el-nc-entry"
                  disabled={busy}
                  onClick={() => onPick(entry.path)}
                >
                  <i className="fa fa-file-o imatic-el-nc-icon" aria-hidden="true" />
                  <span className="imatic-el-nc-name">{entry.name}</span>
                  <span className="imatic-el-nc-attach">{t('imatic_el_nc_attach')}</span>
                </button>
              </li>
            ),
          )}
        </ul>
      )}
    </div>
  );
}
