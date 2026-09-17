import { z } from 'zod';

/**
 * Client-side input contract for adding a link. Zod is the single validation
 * layer: it trims, length-caps (matching the DB columns) and enforces http(s),
 * and `safeParse` gives us a typed, normalised value or a field error without
 * hand-rolled checks. The backend re-validates independently — this is UX, not
 * the security boundary.
 */
export const AddLinkInputSchema = z.object({
  url: z
    .string()
    .trim()
    .min(1, { message: 'invalid_url' })
    .max(2000, { message: 'invalid_url' })
    .refine((value) => {
      try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
      } catch {
        return false;
      }
    }, { message: 'invalid_url' }),
  description: z.string().trim().max(2000).default(''),
});

export type AddLinkInput = z.infer<typeof AddLinkInputSchema>;
