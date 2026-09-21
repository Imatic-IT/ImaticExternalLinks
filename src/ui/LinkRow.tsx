import { LinkRow as LinkRowData } from '../contracts/link';
import { ClientProvider } from '../providers/types';
import { safeHref } from '../util/url';
import { actionIcon } from './icons';

interface Props {
  row: LinkRowData;
  provider: ClientProvider;
  t: (key: string) => string;
  canManage: boolean;
  busy: boolean;
  onDelete: (id: number) => void;
  onRefresh: (id: number) => void;
}

/**
 * One link row: provider icon, a title (falling back to the raw URL when
 * enrichment is unavailable — the graceful-degradation guarantee), optional
 * manual description, provider actions, and manage controls. React escapes all
 * text; hrefs are additionally sanitised to http(s) only.
 */
export function LinkRow({ row, provider, t, canManage, busy, onDelete, onRefresh }: Props) {
  const titleTarget = provider.titleHref ? provider.titleHref(row) : row.url;
  const primaryHref = titleTarget ? safeHref(titleTarget) : null;
  const label = row.title && row.title.trim() !== '' ? row.title : row.url;
  const properties = provider.properties ? provider.properties(row) : [];

  return (
    <li className="imatic-el-row">
      <span className={`imatic-el-row-icon fa ${provider.rowIcon(row)}`} aria-hidden="true" />

      <span className="imatic-el-row-main">
        <span className="imatic-el-row-title">
          {primaryHref ? (
            <a href={primaryHref} target="_blank" rel="noopener noreferrer">
              {label}
            </a>
          ) : (
            label
          )}
        </span>
        {row.description && <span className="imatic-el-row-desc">{row.description}</span>}
        {properties.length > 0 && (
          <span className="imatic-el-row-props">
            {properties.map((prop) => (
              <span className="imatic-el-prop" key={prop.labelKey}>
                <span className="imatic-el-prop-label">{t(prop.labelKey)}:</span>{' '}
                <span className="imatic-el-prop-value">{prop.value}</span>
              </span>
            ))}
          </span>
        )}
      </span>

      <span className="imatic-el-row-actions">
        {row.actions.map((action, index) => {
          const href = safeHref(action.url);
          // Skip an action that already backs the title link (customer open),
          // so the clickable name isn't duplicated by a redundant button.
          if (!href || href === primaryHref) {
            return null;
          }
          return (
            <a
              key={index}
              className="imatic-el-action btn btn-xs btn-white btn-round"
              href={href}
              target={action.external ? '_blank' : undefined}
              rel={action.external ? 'noopener noreferrer' : undefined}
              title={t(action.label)}
            >
              <i className={`fa ${actionIcon(action.icon)}`} aria-hidden="true" /> {t(action.label)}
            </a>
          );
        })}

        {canManage && (
          <>
            <button
              type="button"
              className="imatic-el-refresh btn btn-xs btn-white btn-round"
              disabled={busy}
              onClick={() => onRefresh(row.id)}
              title={t('imatic_el_refresh')}
            >
              <i className="fa fa-refresh" aria-hidden="true" />
            </button>
            <button
              type="button"
              className="imatic-el-delete btn btn-xs btn-danger btn-round"
              disabled={busy}
              onClick={() => onDelete(row.id)}
              title={t('imatic_el_delete')}
            >
              <i className="fa fa-times" aria-hidden="true" />
            </button>
          </>
        )}
      </span>
    </li>
  );
}
