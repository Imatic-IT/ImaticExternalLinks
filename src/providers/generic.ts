import { ClientProvider } from './types';

/** Catch-all provider: any http(s) link. */
export const genericProvider: ClientProvider = {
  key: 'generic',
  rowIcon: () => 'fa-external-link',
};
