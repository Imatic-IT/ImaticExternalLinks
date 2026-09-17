import { ClientProvider } from './types';

/**
 * Nextcloud provider (presentation only in Phase 2). The native file picker and
 * enrichment-driven decoration land in Phase 3; for now rows just carry the
 * cloud icon and use the generic open/editor actions supplied by the backend.
 */
export const nextcloudProvider: ClientProvider = {
  key: 'nextcloud',
  rowIcon: () => 'fa-cloud',
};
