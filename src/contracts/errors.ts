import { z } from 'zod';

/** Shape of an error body returned by the plugin's JsonResponder. */
export const ApiErrorSchema = z.object({
  error: z.string(),
  code: z.string().default(''),
});
export type ApiErrorBody = z.infer<typeof ApiErrorSchema>;
