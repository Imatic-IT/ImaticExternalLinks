/**
 * Return the URL only if it is a safe http(s) link, otherwise null. Defence in
 * depth: the backend already restricts stored URLs to http(s), but the UI must
 * never render a `javascript:`/`data:` href even if a payload slips through.
 */
export function safeHref(url: string): string | null {
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? url : null;
  } catch {
    return null;
  }
}
