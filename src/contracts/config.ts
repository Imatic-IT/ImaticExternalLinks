import { z } from 'zod';

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
});
export type RuntimeConfig = z.infer<typeof RuntimeConfigSchema>;

/** Look up a translated string, falling back to the key itself. */
export function makeTranslator(config: RuntimeConfig): (key: string) => string {
  return (key: string) => config.lang[key] ?? key;
}
