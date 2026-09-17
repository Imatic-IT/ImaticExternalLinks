import { z } from 'zod';
import { ApiErrorSchema } from '../contracts/errors';

/** A typed error carrying the server's machine code + HTTP status. */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;

  constructor(message: string, code: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

/**
 * Perform a request and validate the response against a schema. This is the one
 * place the wire is trusted → typed: a non-2xx body is parsed as an ApiError, a
 * 2xx body must satisfy `schema` or the call rejects. UI code never sees raw
 * JSON.
 */
export async function request<S extends z.ZodTypeAny>(
  url: string,
  schema: S,
  init?: RequestInit,
): Promise<z.infer<S>> {
  let res: Response;
  try {
    res = await fetch(url, { credentials: 'same-origin', ...init });
  } catch {
    throw new ApiError('Network error', 'network', 0);
  }

  let json: unknown = null;
  try {
    json = await res.json();
  } catch {
    if (!res.ok) {
      throw new ApiError('Request failed', `http_${res.status}`, res.status);
    }
    throw new ApiError('Invalid server response', 'bad_json', res.status);
  }

  if (!res.ok) {
    const parsed = ApiErrorSchema.safeParse(json);
    throw new ApiError(
      parsed.success ? parsed.data.error : 'Request failed',
      parsed.success ? parsed.data.code : `http_${res.status}`,
      res.status,
    );
  }

  return schema.parse(json);
}
