import { z } from 'zod';

/**
 * Wire contracts for links. These Zod schemas are the single source of truth for
 * the API shape; TypeScript types are inferred from them and every server
 * response is parsed through them before it reaches the UI, so drift or a
 * tampered payload surfaces here rather than deep in a component.
 */

export const LinkActionSchema = z.object({
  label: z.string(),
  // Not .url(): editor/open targets are server-built and already restricted to
  // http(s) on the backend; the UI additionally sanitises hrefs before use.
  url: z.string(),
  icon: z.string(),
  external: z.boolean(),
});
export type LinkAction = z.infer<typeof LinkActionSchema>;

export const LinkRowSchema = z.object({
  id: z.number().int().positive(),
  provider: z.string(),
  url: z.string(),
  title: z.string().nullable(),
  description: z.string().nullable(),
  // PHP json_encode emits an empty map as `[]` (array), not `{}`. Normalise that
  // back to an object so a link with no meta still parses as a record.
  meta: z
    .preprocess((v) => (Array.isArray(v) ? {} : v), z.record(z.unknown()))
    .default({}),
  actions: z.array(LinkActionSchema).default([]),
});
export type LinkRow = z.infer<typeof LinkRowSchema>;

export const LinkListSchema = z.object({
  links: z.array(LinkRowSchema),
});
export type LinkList = z.infer<typeof LinkListSchema>;

export const AddLinkResponseSchema = z.object({
  link: LinkRowSchema,
});

export const DeleteResponseSchema = z.object({
  success: z.boolean(),
});
