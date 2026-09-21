import { LinkRow } from '../contracts/link';

/**
 * Client-side counterpart of a backend provider. Phase 2 only needs per-provider
 * presentation (row icon); Phase 3 adds an optional `pick()` for the Nextcloud
 * native picker. Keeping the interface small avoids speculative coupling.
 */
/** A labelled property to display under a row's title (label is a lang key). */
export interface RowProperty {
  labelKey: string;
  value: string;
}

export interface ClientProvider {
  readonly key: string;

  /** Font-Awesome class (without the leading `fa `) for a row of this provider. */
  rowIcon(row: LinkRow): string;

  /**
   * Optional extra properties to render under the title (e.g. a customer's
   * invoicing identifiers). Absent ⇒ nothing extra is shown.
   */
  properties?(row: LinkRow): RowProperty[];

  /**
   * Optional http(s) target for the title link when the canonical URL uses a
   * synthetic scheme (e.g. `customer://<id>`) that can't be linked directly.
   * Absent ⇒ the title links to the sanitised canonical URL, or renders as text.
   */
  titleHref?(row: LinkRow): string | null;
}
