import { createRoot } from 'react-dom/client';
import { LinksApi } from './api/links';
import { RuntimeConfigSchema } from './contracts/config';
import { LinkListSchema } from './contracts/link';
import { enhanceRelationships } from './relationships/filter';
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
  enhanceRelationships(configResult.data);
}

/**
 * Nest the "External links" widget inside the core "Relationships" box, so a
 * customer link (which is really a relation) reads as a sub-panel of that group
 * rather than a separate box at the bottom of the page. Pure DOM move against
 * the stable `#relationships` id — no core template changes. If the
 * relationships box is absent (e.g. hidden), the section stays in its original
 * spot. The now-empty original column wrapper is dropped so no gap is left.
 */
function positionUnderRelationships(mount: HTMLElement): void {
  const move = (): void => {
    const ourWrapper = mount.closest<HTMLElement>('.col-md-12');
    const ourBox = mount.closest<HTMLElement>('.widget-box');
    const relBody = document
      .getElementById('relationships')
      ?.querySelector<HTMLElement>(':scope > .widget-body');
    if (!ourWrapper || !ourBox || !relBody || relBody.contains(ourBox)) {
      return; // missing pieces, or already nested (idempotent)
    }
    ourBox.classList.add('imatic-el-nested');
    relBody.appendChild(ourBox);
    ourWrapper.remove();
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
