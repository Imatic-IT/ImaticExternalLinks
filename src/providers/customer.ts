import { LinkRow } from '../contracts/link';
import { ClientProvider, RowProperty } from './types';

/**
 * Customer provider (Phase 3b): a link to an internal Mantis customer issue.
 * Rows carry a building icon and surface invoicing identifiers pulled from the
 * customer's custom fields (present in `meta` after enrichment). The label keys
 * resolve through the injected translator (PHP lang files).
 */

/** meta key → lang key, in display order. Only present values are shown. */
const CUSTOMER_FIELDS: ReadonlyArray<{ metaKey: string; labelKey: string }> = [
  { metaKey: 'ico', labelKey: 'imatic_el_customer_ico' },
  { metaKey: 'dic', labelKey: 'imatic_el_customer_dic' },
  { metaKey: 'invoice_email', labelKey: 'imatic_el_customer_invoice_email' },
  { metaKey: 'pohoda_id', labelKey: 'imatic_el_customer_pohoda_id' },
];

/**
 * Customer id of a `customer://<id>` row, or null for any other provider's row.
 * Used to hide already-attached customers from the picker.
 */
export function customerIdOf(row: LinkRow): number | null {
  const match = /^customer:\/\/(\d+)$/.exec(row.url.trim());
  return match ? Number(match[1]) : null;
}

export const customerProvider: ClientProvider = {
  key: 'customer',
  rowIcon: () => 'fa-building-o',
  properties(row: LinkRow): RowProperty[] {
    const out: RowProperty[] = [];
    for (const field of CUSTOMER_FIELDS) {
      const raw = row.meta[field.metaKey];
      if (typeof raw === 'string' && raw.trim() !== '') {
        out.push({ labelKey: field.labelKey, value: raw });
      }
    }
    return out;
  },
};
