import { z } from 'zod';

/**
 * Wire contract for the customer picker (Phase 3b). Candidates come from the
 * customers project search endpoint; parsed through Zod like every other
 * response so a drifted/tampered payload fails here, not in the UI.
 */
export const CustomerCandidateSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  ico: z.string().default(''),
});
export type CustomerCandidate = z.infer<typeof CustomerCandidateSchema>;

export const CustomerSearchResponseSchema = z.object({
  customers: z.array(CustomerCandidateSchema),
});
