// Line-up on a court: drag players onto positions (mouse and touch), or tap a player then a position.
// Empty positions preview the engine's automatic pick, computed the same way as MatchEngine::startingLineup.

type Slot = 'GK' | 'LW' | 'LB' | 'CB' | 'RB' | 'RW' | 'PV';
const COURT: Exclude<Slot, 'GK'>[] = ['LW', 'LB', 'CB', 'RB', 'RW', 'PV'];
const ALL: Slot[] = ['GK', ...COURT];

interface Card {
  id: number; name: string; short: string; pos: string; number: number; gk: boolean;
  fitness: number; injured: boolean; rating: number;
  ratings: Record<Slot, number>; scores: Record<Slot, number>;
}

type Chosen = Partial<Record<Slot, number | null>>;

export function mountLineup(root = document.querySelector<HTMLFormElement>('[data-lineup]')) {
  if (!root) return;
  const cards: Card[] = JSON.parse(root.dataset.players!);
  const byId = new Map(cards.map((c) => [c.id, c]));
  const raw = JSON.parse(root.dataset.chosen!) as { slots: Record<string, number>; gk: number | null };
  const chosen: Chosen = { GK: raw.gk ?? null };
  for (const s of COURT) chosen[s] = raw.slots?.[s] ?? null;
  const initial = JSON.stringify(chosen);

  const pitch = root.querySelector<HTMLElement>('[data-pitch]')!;
  const bench = root.querySelector<HTMLElement>('[data-bench]')!;
  const ghost = root.querySelector<HTMLElement>('[data-ghost]')!;
  const hint = root.querySelector<HTMLElement>('[data-hint]')!;
  const spotEl = (s: Slot) => root.querySelector<HTMLElement>(`.spot[data-slot="${s}"]`)!;
  const cardEl = (id: number) => root.querySelector<HTMLButtonElement>(`[data-card="${id}"]`)!;
  const face = (id: number) => cardEl(id).querySelector('[data-face] svg')!.outerHTML;

  let selected: number | null = null; // tap-to-place: the player waiting for a position

  // ----- rules -----
  const fits = (c: Card, s: Slot) => !c.injured && (s === 'GK') === c.gk;

  /** The seven who would start: chosen where valid, the rest filled like the engine does. */
  function resolve(): Record<Slot, { id: number | null; auto: boolean }> {
    const used = new Set<number>();
    const out = {} as Record<Slot, { id: number | null; auto: boolean }>;
    const ok = (id: number | null | undefined, s: Slot) => id != null && byId.has(id) && fits(byId.get(id)!, s) && !used.has(id);
    if (ok(chosen.GK, 'GK')) { out.GK = { id: chosen.GK!, auto: false }; used.add(chosen.GK!); }
    for (const s of COURT) if (ok(chosen[s], s)) { out[s] = { id: chosen[s]!, auto: false }; used.add(chosen[s]!); }
    for (const s of COURT) {
      if (out[s]) continue;
      const best = cards.filter((c) => !c.gk && !c.injured && !used.has(c.id)).sort((a, b) => b.scores[s] - a.scores[s])[0];
      out[s] = { id: best?.id ?? null, auto: true };
      if (best) used.add(best.id);
    }
    if (!out.GK) {
      const best = cards.filter((c) => c.gk && !c.injured && !used.has(c.id)).sort((a, b) => b.scores.GK - a.scores.GK)[0];
      out.GK = { id: best?.id ?? null, auto: true };
    }
    return out;
  }

  function place(id: number, target: Slot) {
    const c = byId.get(id);
    if (!c || !fits(c, target)) return flash(target);
    const from = ALL.find((s) => chosen[s] === id);
    if (from === target) return;
    const there = chosen[target] ?? null;
    chosen[target] = id;
    if (from) chosen[from] = there != null && fits(byId.get(there)!, from) ? there : null; // swap
    render();
  }

  function clear(s: Slot) { chosen[s] = null; render(); }

  function flash(s: Slot) {
    const el = spotEl(s);
    el.classList.remove('is-refused'); void el.offsetWidth; el.classList.add('is-refused');
  }

  // ----- drawing -----
  function render() {
    const lineup = resolve();
    const onCourt = new Map<number, { slot: Slot; auto: boolean }>();
    for (const s of ALL) {
      const { id, auto } = lineup[s];
      if (id != null) onCourt.set(id, { slot: s, auto });
      const el = spotEl(s);
      const c = id != null ? byId.get(id)! : null;
      el.classList.toggle('is-auto', auto);
      el.classList.toggle('is-empty', !c);
      el.classList.toggle('is-off', !!c && s !== 'GK' && c.pos !== s);
      el.querySelector('[data-disc]')!.innerHTML = c ? face(c.id) : '';
      el.querySelector('[data-name]')!.textContent = c ? c.short : 'Nobody';
      el.querySelector('[data-meta]')!.innerHTML = c
        ? `<b>${c.ratings[s]}</b>${s !== 'GK' && c.pos !== s ? ` <span class="spot__off" title="Plays out of position">${c.pos}</span>` : ''}${auto ? ' <span class="spot__auto">auto</span>' : ''}`
        : '';
      const clr = el.querySelector<HTMLElement>('[data-clear]')!;
      clr.hidden = auto;
      el.querySelector('[data-spot]')!.setAttribute('aria-label',
        `${s}: ${c ? `${c.name}, rating ${c.ratings[s]} here${auto ? ', automatic' : ''}` : 'nobody'}`);
      const input = root!.querySelector<HTMLInputElement>(`[data-input="${s}"]`)!;
      input.value = chosen[s] != null ? String(chosen[s]) : '';
    }
    for (const c of cards) {
      const el = cardEl(c.id);
      const at = onCourt.get(c.id);
      el.classList.toggle('is-on', !!at);
      el.classList.toggle('is-selected', selected === c.id);
      el.setAttribute('aria-pressed', String(selected === c.id));
      el.querySelector('[data-where]')!.textContent = at ? `${at.slot}${at.auto ? ' auto' : ''}` : '';
    }
    preview(selected);
    root!.querySelector<HTMLElement>('[data-dirty]')!.hidden = JSON.stringify(chosen) === initial;
    hint.textContent = selected != null ? `Now tap a position for ${byId.get(selected)!.short}` : 'Drag onto the court, or tap to pick';
    pitch.classList.toggle('is-choosing', selected != null);
  }

  /** While a player is picked or dragged, every position shows how good he would be there. */
  function preview(id: number | null) {
    const c = id != null ? byId.get(id)! : null;
    for (const s of ALL) {
      const el = spotEl(s);
      const tag = el.querySelector<HTMLElement>('[data-preview]')!;
      const ok = !!c && fits(c, s);
      tag.hidden = !ok;
      if (ok) tag.textContent = String(c!.ratings[s]);
      el.classList.toggle('is-target', ok);
    }
  }

  // ----- tapping and keyboard (buttons, so Enter and Space work) -----
  root.addEventListener('click', (e) => {
    if (suppressClick) { suppressClick = false; return; }
    const t = e.target as HTMLElement;
    const card = t.closest<HTMLElement>('[data-card]');
    const spot = t.closest<HTMLElement>('[data-spot]');
    const clr = t.closest<HTMLElement>('[data-clear]');
    if (clr) { clear(clr.dataset.clear as Slot); return; }
    if (card) {
      const id = Number(card.dataset.card);
      if (byId.get(id)!.injured) return;
      selected = selected === id ? null : id;
      render();
      if (selected != null && window.matchMedia('(max-width: 860px)').matches) pitch.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (spot) {
      const s = spot.dataset.spot as Slot;
      if (selected != null) { place(selected, s); selected = null; render(); return; }
      // Nothing picked: pick the player standing here, to move him somewhere else.
      const id = resolve()[s].id;
      if (id != null) { selected = id; render(); }
    }
  });
  root.querySelector('[data-auto-all]')!.addEventListener('click', () => {
    for (const s of ALL) chosen[s] = null;
    selected = null;
    render();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && selected != null) { selected = null; render(); }
  });

  // ----- dragging with pointer events (works for mouse, pen and touch) -----
  let drag: { id: number; from: Slot | null; x: number; y: number; active: boolean; pointer: number } | null = null;
  let suppressClick = false;

  root.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    const t = e.target as HTMLElement;
    if (t.closest('[data-clear]')) return;
    const card = t.closest<HTMLElement>('[data-card]');
    const spot = t.closest<HTMLElement>('[data-spot]');
    let id: number | null = null;
    let from: Slot | null = null;
    if (card) id = Number(card.dataset.card);
    else if (spot) { from = spot.dataset.spot as Slot; id = resolve()[from].id; }
    if (id == null || byId.get(id)!.injured) return;
    drag = { id, from, x: e.clientX, y: e.clientY, active: false, pointer: e.pointerId };
  });

  window.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pointer) return;
    if (!drag.active) {
      if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 8) return;
      drag.active = true;
      ghost.innerHTML = face(drag.id);
      ghost.hidden = false;
      document.body.classList.add('is-dragging');
      preview(drag.id);
    }
    e.preventDefault();
    const r = pitch.getBoundingClientRect();
    ghost.style.transform = `translate(${e.clientX - r.left}px, ${e.clientY - r.top}px)`;
    const over = dropTarget(e.clientX, e.clientY);
    root!.querySelectorAll('.spot').forEach((el) => el.classList.toggle('is-over', el === over.spot));
    bench.classList.toggle('is-over', over.bench);
  }, { passive: false });

  const end = (e: PointerEvent) => {
    if (!drag || e.pointerId !== drag.pointer) return;
    const d = drag;
    drag = null;
    if (!d.active) return; // a plain click: handled by the click listener
    suppressClick = true;
    setTimeout(() => (suppressClick = false), 0);
    ghost.hidden = true;
    document.body.classList.remove('is-dragging');
    root!.querySelectorAll('.spot').forEach((el) => el.classList.remove('is-over'));
    bench.classList.remove('is-over');
    const over = e.type === 'pointerup' ? dropTarget(e.clientX, e.clientY) : { spot: null, bench: false };
    selected = null;
    if (over.spot) place(d.id, over.spot.dataset.slot as Slot);
    else if (over.bench && d.from) clear(d.from); // dragged off the court: back to automatic
    render();
  };
  window.addEventListener('pointerup', end);
  window.addEventListener('pointercancel', end);

  function dropTarget(x: number, y: number) {
    const el = document.elementFromPoint(x, y) as HTMLElement | null;
    let spot = el?.closest<HTMLElement>('.spot') ?? null;
    if (!spot && el && pitch.contains(el)) {
      // Dropped on open court: snap to the nearest position within reach.
      let best = Infinity;
      root!.querySelectorAll<HTMLElement>('.spot').forEach((s) => {
        const r = s.querySelector('[data-disc]')!.getBoundingClientRect();
        const dist = Math.hypot(r.left + r.width / 2 - x, r.top + r.height / 2 - y);
        if (dist < best && dist < 90) { best = dist; spot = s; }
      });
    }
    return { spot, bench: !spot && !!el && bench.contains(el) };
  }

  // Stop the page from scrolling while a finger drags a player.
  pitch.addEventListener('touchmove', (e) => { if (drag?.active) e.preventDefault(); }, { passive: false });
  bench.addEventListener('touchmove', (e) => { if (drag?.active) e.preventDefault(); }, { passive: false });

  window.addEventListener('beforeunload', (e) => {
    if (JSON.stringify(chosen) !== initial && !submitting) e.preventDefault();
  });
  let submitting = false;
  root.addEventListener('submit', () => (submitting = true));

  render();
}
