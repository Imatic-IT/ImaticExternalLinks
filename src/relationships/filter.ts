import { makeTranslator, RuntimeConfig } from '../contracts/config';

/**
 * Client-side enhancement of the core "Relationships" section. On issues with
 * many linked bugs the list gets long and cluttered with already resolved/closed
 * items; here we add a status filter above the core table and, by default, hide
 * the closed/resolved rows (with a one-click "show all" escape hatch).
 *
 * This is a pure DOM enhancement against the stable `#relationships` markup — no
 * core template changes. The status text rendered by the core table
 * (`.issue-status`) is matched against the injected status enum (label + closed
 * flag) so the whole thing works without an extra request.
 *
 * Uses select2 when it is present on the page (as it is elsewhere in the Imatic
 * stack) for a searchable multi-tag picker; otherwise it degrades to a plain
 * multi-select. Fails silently on any missing piece — a filter must never break
 * the issue page.
 */

// Below this many relationship rows the list is short enough that a filter is
// just noise, so we leave the section untouched.
const MIN_ROWS = 4;

interface RowInfo {
  tr: HTMLTableRowElement;
  status: string | null;
}

export function enhanceRelationships(config: RuntimeConfig): void {
  try {
    run(config);
  } catch {
    // A filter is a nicety; never let it disrupt the issue page.
  }
}

function run(config: RuntimeConfig): void {
  const rel = document.getElementById('relationships');
  if (!rel || rel.querySelector('.imatic-rel-filter')) {
    return; // absent, or already enhanced (idempotent)
  }

  const table = findRelationshipTable(rel);
  if (!table) {
    return;
  }

  const rows: RowInfo[] = Array.from(table.querySelectorAll<HTMLTableRowElement>('tbody > tr')).map(
    (tr) => ({ tr, status: statusOf(tr) }),
  );
  const withStatus = rows.filter((r) => r.status !== null);
  if (withStatus.length < MIN_ROWS) {
    return;
  }

  // Distinct statuses actually present, in first-seen order.
  const present: string[] = [];
  for (const r of withStatus) {
    if (r.status && !present.includes(r.status)) {
      present.push(r.status);
    }
  }

  const closedLabels = new Set(
    config.relStatuses.filter((s) => s.closed).map((s) => s.label),
  );
  const hasClosed = present.some((s) => closedLabels.has(s));
  // Nothing worth filtering: a single status and nothing to hide.
  if (present.length <= 1 && !hasClosed) {
    return;
  }

  // Order options by the configured enum order, then any present-but-unknown.
  const order = config.relStatuses.map((s) => s.label);
  const ordered = [
    ...order.filter((l) => present.includes(l)),
    ...present.filter((l) => !order.includes(l)),
  ];

  const t = makeTranslator(config);

  // Default selection: active (non-closed) statuses. If everything present is
  // closed, default to showing all so the list is never mysteriously empty.
  const active = present.filter((s) => !closedLabels.has(s));
  const initial = new Set(active.length > 0 ? active : present);

  const ui = buildUi(t, ordered, initial);
  table.parentNode?.insertBefore(ui.wrapper, table);

  const apply = (): void => {
    const selected = ui.selected();
    let shown = 0;
    for (const r of withStatus) {
      const visible = r.status !== null && selected.has(r.status);
      r.tr.style.display = visible ? '' : 'none';
      if (visible) {
        shown++;
      }
    }
    ui.setCount(
      t('imatic_rel_count')
        .replace('%shown%', String(shown))
        .replace('%total%', String(withStatus.length)),
    );
    const showingAll = selected.size >= present.length;
    ui.setToggle(showingAll ? t('imatic_rel_only_active') : t('imatic_rel_show_all'), showingAll);
  };

  ui.onChange(apply);
  ui.onToggle((showingAll) => {
    ui.setSelected(showingAll ? active : present);
    apply();
  });

  apply();
}

/** The core relationships table is the one whose rows carry a status cell. */
function findRelationshipTable(rel: HTMLElement): HTMLTableElement | null {
  for (const table of Array.from(rel.querySelectorAll<HTMLTableElement>('table'))) {
    if (table.querySelector('.issue-status')) {
      return table;
    }
  }
  return null;
}

function statusOf(tr: HTMLTableRowElement): string | null {
  const el = tr.querySelector('.issue-status');
  const text = el?.textContent?.trim();
  return text ? text : null;
}

interface Ui {
  wrapper: HTMLElement;
  selected(): Set<string>;
  setSelected(values: string[]): void;
  onChange(cb: () => void): void;
  onToggle(cb: (showingAll: boolean) => void): void;
  setCount(text: string): void;
  setToggle(text: string, showingAll: boolean): void;
}

function buildUi(
  t: (key: string) => string,
  options: string[],
  initial: Set<string>,
): Ui {
  const wrapper = document.createElement('div');
  wrapper.className = 'imatic-rel-filter';

  const icon = document.createElement('i');
  icon.className = 'ace-icon fa fa-filter imatic-rel-filter-icon';
  icon.setAttribute('aria-hidden', 'true');
  wrapper.appendChild(icon);

  const select = document.createElement('select');
  select.className = 'imatic-rel-status';
  select.multiple = true;
  for (const label of options) {
    const opt = document.createElement('option');
    opt.value = label;
    opt.textContent = label;
    opt.selected = initial.has(label);
    select.appendChild(opt);
  }

  const meta = document.createElement('div');
  meta.className = 'imatic-rel-filter-meta';
  const count = document.createElement('span');
  count.className = 'imatic-rel-count';
  const toggle = document.createElement('a');
  toggle.className = 'imatic-rel-toggle';
  toggle.href = '#';
  meta.appendChild(count);
  meta.appendChild(toggle);

  wrapper.appendChild(select);
  wrapper.appendChild(meta);

  // Prefer select2 when available (searchable tag picker consistent with the
  // rest of the Imatic UI); fall back to the plain multi-select otherwise.
  const jq = (window as unknown as { jQuery?: JQueryLike }).jQuery;
  const useSelect2 = !!(jq && jq.fn && jq.fn.select2);
  let $select: JQueryInstance | null = null;
  if (useSelect2 && jq) {
    $select = jq(select);
    $select.select2({
      width: '100%',
      placeholder: t('imatic_rel_filter_placeholder'),
      closeOnSelect: false,
      dropdownParent: jq(wrapper),
    });
  }

  const selected = (): Set<string> =>
    new Set(Array.from(select.selectedOptions).map((o) => o.value));

  return {
    wrapper,
    selected,
    setSelected(values: string[]): void {
      const want = new Set(values);
      for (const opt of Array.from(select.options)) {
        opt.selected = want.has(opt.value);
      }
      if ($select) {
        $select.trigger('change.select2');
      }
    },
    onChange(cb: () => void): void {
      if ($select) {
        $select.on('change', cb);
      } else {
        select.addEventListener('change', cb);
      }
    },
    onToggle(cb: (showingAll: boolean) => void): void {
      toggle.addEventListener('click', (e) => {
        e.preventDefault();
        cb(toggle.dataset.showingAll === '1');
      });
    },
    setCount(text: string): void {
      count.textContent = text;
    },
    setToggle(text: string, showingAll: boolean): void {
      toggle.textContent = text;
      toggle.dataset.showingAll = showingAll ? '1' : '0';
    },
  };
}

// Minimal structural typing for the globally-loaded jQuery + select2, so we
// don't pull jQuery type packages into the build just for this optional path.
interface JQueryInstance {
  select2(options: Record<string, unknown>): JQueryInstance;
  on(event: string, cb: () => void): JQueryInstance;
  trigger(event: string): JQueryInstance;
}
interface JQueryLike {
  (el: Element): JQueryInstance;
  fn?: { select2?: unknown };
}
