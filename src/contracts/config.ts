import { z } from 'zod';

/**
 * One issue that links to the current (customer) issue — the reverse of the
 * "connect customer" flow. Server-resolved and already access-filtered; shown
 * as a read-only tab inside the section.
 */
export const BacklinkSchema = z.object({
  id: z.number().int().positive(),
  url: z.string(),
  label: z.string(),
  status: z.string().default(''),
});
export type Backlink = z.infer<typeof BacklinkSchema>;

/**
 * One status enum value, used by the relationships-filter enhancement to decide
 * which of the core "Relationships" rows are closed/resolved (hidden by default)
 * versus active. `label` matches the text the core table renders in
 * `.issue-status`, so filtering can be done purely client-side.
 */
export const RelStatusSchema = z.object({
  label: z.string(),
  closed: z.boolean().default(false),
});
export type RelStatus = z.infer<typeof RelStatusSchema>;

/**
 * Runtime configuration injected by the PHP layer into the
 * <script id="imatic-external-links" data-config='…'> tag. Parsed once at
 * bootstrap; a malformed config aborts mounting rather than half-initialising.
 */
export const RuntimeConfigSchema = z.object({
  ajaxUrl: z.string(),
  /** Endpoint for the customer picker search (Phase 3b). */
  customerSearchUrl: z.string().default(''),
  /** Whether the customers project is configured (shows the "add customer" flow). */
  customersEnabled: z.boolean().default(false),
  /** Whether the "add link" flow is offered on the current project. */
  linksEnabled: z.boolean().default(true),
  bugId: z.number().int().positive(),
  canManage: z.boolean(),
  csrfToken: z.string(),
  csrfField: z.string(),
  /** UI strings keyed by lang key; source of truth is the PHP lang files. */
  lang: z.record(z.string()).default({}),
  /** Issues that link to this one (only populated on a customer issue). */
  backlinks: z.array(BacklinkSchema).default([]),
  /** Status enum for the core relationships filter (label + closed flag). */
  relStatuses: z.array(RelStatusSchema).default([]),
});
export type RuntimeConfig = z.infer<typeof RuntimeConfigSchema>;

/** Look up a translated string, falling back to the key itself. */
export function makeTranslator(config: RuntimeConfig): (key: string) => string {
  return (key: string) => config.lang[key] ?? key;
}
