import { createRoot } from 'react-dom/client';
import { LinksApi } from './api/links';
import { RuntimeConfigSchema } from './contracts/config';
import { LinkListSchema } from './contracts/link';
import { LinksSection } from './ui/LinksSection';

/**
 * Entry point. Reads + Zod-parses the runtime config and the server-seeded
 * initial link list, then mounts the section. Every external input crosses a
 * schema boundary here, so a malformed payload fails loudly at startup instead
 * of corrupting the UI. Any failure leaves the PHP fallback markup in place.
 */
function boot(): void {
  const script = document.getElementById('imatic-external-links');
  const mount = document.getElementById('imatic-el-body');
  if (!script || !mount) {
    return;
  }

  const configResult = RuntimeConfigSchema.safeParse(readJson(script.getAttribute('data-config')));
  if (!configResult.success) {
    // Misconfigured injection — leave the static fallback and log for the dev.
    console.error('[ImaticExternalLinks] invalid runtime config', configResult.error);
    return;
  }

  const initialResult = LinkListSchema.safeParse(readJson(mount.getAttribute('data-initial')));
  const initial = initialResult.success ? initialResult.data.links : [];

  const api = new LinksApi(configResult.data);

  createRoot(mount).render(
    <LinksSection api={api} config={configResult.data} initial={initial} />,
  );

  positionUnderRelationships(mount);
}

/**
 * Relocate the whole "External links" widget to sit directly below the core
 * "Relationships" box, so a customer link (which is really a relation) reads as
 * part of that group. Pure DOM move against the stable `#relationships` id — no
 * core template changes. If the relationships box is absent (e.g. hidden), the
 * section is left in its original spot.
 */
function positionUnderRelationships(mount: HTMLElement): void {
  const move = (): void => {
    const ourBox = mount.closest<HTMLElement>('.col-md-12');
    const relBox = document.getElementById('relationships')?.closest<HTMLElement>('.col-md-12');
    if (
      ourBox &&
      relBox &&
      relBox.parentNode &&
      ourBox !== relBox &&
      ourBox.previousElementSibling !== relBox // idempotent: already in place
    ) {
      relBox.parentNode.insertBefore(ourBox, relBox.nextSibling);
    }
  };
  // Run now and once more after the next frame, so late body-end scripts from
  // other plugins can't leave the box stranded in its original (bottom) slot.
  move();
  requestAnimationFrame(move);
}

function readJson(raw: string | null): unknown {
  if (!raw) {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

// The bundle is injected as a classic (blocking) script; depending on cache vs
// network timing it may execute mid-parse. Defer to DOMContentLoaded when the
// document is still parsing so the mount + relationships nodes always exist.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
