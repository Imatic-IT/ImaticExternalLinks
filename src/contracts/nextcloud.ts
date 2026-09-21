import { z } from 'zod';

/**
 * One Nextcloud directory entry returned by the picker's browse endpoint.
 * Folders are navigated into; files are attached. Shapes mirror the server DTO
 * (WebDavNextcloudGateway), validated at the transport boundary.
 */
export const NcEntrySchema = z.object({
  fileid: z.string().default(''),
  name: z.string(),
  path: z.string(),
  isDir: z.boolean().default(false),
  mime: z.string().default(''),
  size: z.number().default(0),
});
export type NcEntry = z.infer<typeof NcEntrySchema>;

/** Response of a browse call: the resolved path, the allowed roots, and entries. */
export const NcBrowseResponseSchema = z.object({
  path: z.string().default('/'),
  roots: z.array(z.string()).default([]),
  entries: z.array(NcEntrySchema).default([]),
});
export type NcBrowseResponse = z.infer<typeof NcBrowseResponseSchema>;
