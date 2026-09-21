import { useState } from 'react';
import { LinksApi } from '../api/links';
import { makeTranslator, RuntimeConfig } from '../contracts/config';
import { LinkRow as LinkRowData } from '../contracts/link';
import { customerIdOf, customerProvider } from '../providers/customer';
import { genericProvider } from '../providers/generic';
import { nextcloudProvider } from '../providers/nextcloud';
import { ClientProviderRegistry } from '../providers/registry';
import { useLinks } from '../state/useLinks';
import { AddLinkDialog } from './AddLinkDialog';
import { CustomerPickerDialog } from './CustomerPickerDialog';
import { LinkRow } from './LinkRow';
import { NextcloudPickerDialog } from './NextcloudPickerDialog';

interface Props {
  api: LinksApi;
  config: RuntimeConfig;
  initial: LinkRowData[];
}

const registry = new ClientProviderRegistry(
  [customerProvider, genericProvider, nextcloudProvider],
  genericProvider,
);

/**
 * Root component for the issue section. Wires the state hook to the presentation:
 * a toolbar (add — managers only), an error banner, and the link list with an
 * empty state. All copy comes from the injected translator.
 */
export function LinksSection({ api, config, initial }: Props) {
  const t = makeTranslator(config);
  const links = useLinks(api, initial, t);
  const [adding, setAdding] = useState(false);
  const [pickingCustomer, setPickingCustomer] = useState(false);
  const [pickingNc, setPickingNc] = useState(false);
  const [filter, setFilter] = useState<'all' | 'customer' | 'link'>('all');
  // Reverse references (issues linking to this customer). Only present on a
  // customer issue; when there are none the tab bar stays hidden.
  const backlinks = config.backlinks;
  const showTabs = backlinks.length > 0;

  // On a customer issue the linked issues are the point of the page, so open
  // that tab first; elsewhere (no backlinks) the links tab is the only one.
  const [tab, setTab] = useState<'links' | 'backlinks'>(showTabs ? 'backlinks' : 'links');

  // Customer ids already attached — kept out of the picker so the same customer
  // can't be added twice.
  const attachedCustomerIds = new Set<number>();
  for (const row of links.links) {
    const id = customerIdOf(row);
    if (id !== null) {
      attachedCustomerIds.add(id);
    }
  }
  const customerCount = attachedCustomerIds.size;
  const linkCount = links.links.length - customerCount;
  // The type filter only earns its place once both kinds are present.
  const showFilter = customerCount > 0 && linkCount > 0;
  const visibleLinks = links.links.filter((row) => {
    if (filter === 'all') return true;
    const isCustomer = customerIdOf(row) !== null;
    return filter === 'customer' ? isCustomer : !isCustomer;
  });

  async function handleAdd(url: string, description: string): Promise<boolean> {
    const ok = await links.add(url, description);
    if (ok) {
      setAdding(false);
    }
    return ok;
  }

  async function handlePickCustomer(customerId: number): Promise<void> {
    // Attach through the normal add flow using the provider's synthetic URI.
    const ok = await links.add(`customer://${customerId}`, '');
    if (ok) {
      setPickingCustomer(false);
    }
  }

  async function handlePickNc(path: string): Promise<void> {
    // The server attaches the file (authoritative name/mime) and returns the row.
    const ok = await links.attachNextcloud(path);
    if (ok) {
      setPickingNc(false);
    }
  }

  function handleDelete(id: number) {
    if (window.confirm(t('imatic_el_confirm_delete'))) {
      void links.remove(id);
    }
  }

  return (
    <div className="imatic-el">
      {showTabs && (
        <ul className="imatic-el-tabs nav nav-tabs">
          <li className={tab === 'links' ? 'active' : ''}>
            <a href="#" onClick={(e) => { e.preventDefault(); setTab('links'); }}>
              {t('imatic_el_section_title')}
            </a>
          </li>
          <li className={tab === 'backlinks' ? 'active' : ''}>
            <a href="#" onClick={(e) => { e.preventDefault(); setTab('backlinks'); }}>
              {`${t('imatic_el_backlinks_title')} (${backlinks.length})`}
            </a>
          </li>
        </ul>
      )}

      {tab === 'backlinks' ? (
        <ul className="imatic-el-list imatic-el-backlinks">
          {backlinks.map((row) => (
            <li className="imatic-el-row" key={row.id}>
              <span className="imatic-el-row-icon fa fa-link" aria-hidden="true" />
              <span className="imatic-el-row-main">
                <span className="imatic-el-row-title">
                  <a href={row.url}>{row.label}</a>
                </span>
                {row.status !== '' && <span className="imatic-el-row-desc">{row.status}</span>}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <>
      {(config.canManage || showFilter) && (
        <div className="imatic-el-toolbar">
          {config.canManage && config.linksEnabled && (
            <button
              type="button"
              className="btn btn-xs btn-primary btn-round"
              disabled={links.busy || adding}
              onClick={() => setAdding(true)}
            >
              <i className="fa fa-plus" aria-hidden="true" /> {t('imatic_el_add_btn')}
            </button>
          )}
          {config.canManage && config.customersEnabled && (
            <button
              type="button"
              className="btn btn-xs btn-white btn-round"
              disabled={links.busy || pickingCustomer}
              onClick={() => setPickingCustomer(true)}
            >
              <i className="fa fa-building-o" aria-hidden="true" /> {t('imatic_el_add_customer_btn')}
            </button>
          )}
          {config.canManage && config.nextcloudPickerEnabled && (
            <button
              type="button"
              className="btn btn-xs btn-white btn-round"
              disabled={links.busy || pickingNc}
              onClick={() => setPickingNc(true)}
            >
              <i className="fa fa-cloud" aria-hidden="true" /> {t('imatic_el_nc_add_btn')}
            </button>
          )}
          {showFilter && (
            <select
              className="input-sm imatic-el-filter"
              value={filter}
              aria-label={t('imatic_el_filter_all')}
              onChange={(e) => setFilter(e.target.value as typeof filter)}
            >
              <option value="all">{t('imatic_el_filter_all')}</option>
              <option value="customer">{`${t('imatic_el_filter_customer')} (${customerCount})`}</option>
              <option value="link">{`${t('imatic_el_filter_link')} (${linkCount})`}</option>
            </select>
          )}
        </div>
      )}

      {adding && (
        <AddLinkDialog t={t} busy={links.busy} onSubmit={handleAdd} onCancel={() => setAdding(false)} />
      )}

      {pickingCustomer && (
        <CustomerPickerDialog
          t={t}
          api={api}
          busy={links.busy}
          excludeIds={attachedCustomerIds}
          onPick={(id) => void handlePickCustomer(id)}
          onCancel={() => setPickingCustomer(false)}
        />
      )}

      {pickingNc && (
        <NextcloudPickerDialog
          t={t}
          api={api}
          busy={links.busy}
          onPick={(p) => void handlePickNc(p)}
          onCancel={() => setPickingNc(false)}
        />
      )}

      {links.error && (
        <div className="imatic-el-error alert alert-danger" onClick={links.clearError}>
          {links.error}
        </div>
      )}

      {links.links.length === 0 ? (
        <div className="imatic-el-empty">{t('imatic_el_empty')}</div>
      ) : (
        <ul className="imatic-el-list">
          {visibleLinks.map((row) => (
            <LinkRow
              key={row.id}
              row={row}
              provider={registry.get(row.provider)}
              t={t}
              canManage={config.canManage}
              busy={links.busy}
              onDelete={handleDelete}
              onRefresh={(id) => void links.refresh(id)}
            />
          ))}
        </ul>
      )}
        </>
      )}
    </div>
  );
}
