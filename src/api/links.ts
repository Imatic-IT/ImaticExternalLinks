import { z } from 'zod';
import { RuntimeConfig } from '../contracts/config';
import { CustomerCandidate, CustomerSearchResponseSchema } from '../contracts/customer';
import {
  AddLinkResponseSchema,
  DeleteResponseSchema,
  LinkListSchema,
  LinkRow,
} from '../contracts/link';
import { NcBrowseResponse, NcBrowseResponseSchema } from '../contracts/nextcloud';
import { request } from './client';

/**
 * Transport for the links AJAX endpoint. Bound to the runtime config so it
 * always sends the bug id + CSRF token; the UI depends on this class, not on
 * fetch details. State-changing calls are form-encoded POSTs (Mantis reads
 * gpc_* from POST); `list` is read-only.
 */
export class LinksApi {
  private readonly config: RuntimeConfig;

  constructor(config: RuntimeConfig) {
    this.config = config;
  }

  async list(): Promise<LinkRow[]> {
    const res = await this.post({ action: 'list' }, LinkListSchema);
    return res.links;
  }

  async add(url: string, description: string): Promise<LinkRow> {
    const res = await this.post({ action: 'add', url, description }, AddLinkResponseSchema);
    return res.link;
  }

  async remove(linkId: number): Promise<void> {
    await this.post({ action: 'delete', link_id: String(linkId) }, DeleteResponseSchema);
  }

  async refresh(linkId: number): Promise<LinkRow> {
    const res = await this.post(
      { action: 'refresh', link_id: String(linkId) },
      AddLinkResponseSchema,
    );
    return res.link;
  }

  /** Search customers for the picker (Phase 3b). Hits the dedicated endpoint. */
  async searchCustomers(query: string): Promise<CustomerCandidate[]> {
    const res = await this.post(
      { action: 'customer_search', q: query },
      CustomerSearchResponseSchema,
      this.config.customerSearchUrl,
    );
    return res.customers;
  }

  /** List a Nextcloud folder within the project's allowed scope. */
  async browseNextcloud(path: string): Promise<NcBrowseResponse> {
    return this.post(
      { action: 'browse', path },
      NcBrowseResponseSchema,
      this.config.nextcloudBrowseUrl,
    );
  }

  /** Attach a picked Nextcloud file (by its in-scope path); returns the new row. */
  async attachNextcloud(path: string): Promise<LinkRow> {
    const res = await this.post(
      { action: 'attach', path },
      AddLinkResponseSchema,
      this.config.nextcloudBrowseUrl,
    );
    return res.link;
  }

  private post<S extends z.ZodTypeAny>(
    fields: Record<string, string>,
    schema: S,
    url: string = this.config.ajaxUrl,
  ): Promise<z.infer<S>> {
    const body = new URLSearchParams();
    body.set('bug_id', String(this.config.bugId));
    body.set(this.config.csrfField, this.config.csrfToken);
    for (const [key, value] of Object.entries(fields)) {
      body.set(key, value);
    }

    return request(url, schema, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
  }
}
