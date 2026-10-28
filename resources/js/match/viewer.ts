// 2D match replay. Draws the court on a canvas and replays the server's event log.
// Modes: 'hero' (landing page autoplay), 'full' (match page with controls, feed and stats), 'empty' (court only).

import { buildTimeline, COURT_H, COURT_W, statsUpTo, type KF, type P, type Timeline } from './timeline';
import type { MatchResult, Side, Tactics, Team } from './types';

type Mode = 'hero' | 'full' | 'empty';

const TACTIC_LABELS: { key: keyof Tactics; label: string; opts: Record<string, string> }[] = [
  { key: 'defense', label: 'Defense', opts: { '6-0': '6-0', '5-1': '5-1', '3-2-1': '3-2-1' } },
  { key: 'tempo', label: 'Tempo', opts: { slow: 'Slow', normal: 'Normal', fast: 'Fast' } },
  { key: 'focus', label: 'Attack focus', opts: { balanced: 'Balanced', wings: 'Wings', backs: 'Backs', pivot: 'Pivot' } },
  { key: 'sevenVsSix', label: '7 vs 6', opts: { never: 'Never', whenShort: 'When short', whenBehind: 'When behind', always: 'Always' } },
  { key: 'rotation', label: 'Rotation', opts: { low: 'Low', normal: 'Normal', high: 'High' } },
];

const MX = 2;
const MY = 1.4;
const WW = COURT_W + MX * 2;
const WH = COURT_H + MY + 2.6;

const ease = (u: number) => (u < 0.5 ? 2 * u * u : 1 - Math.pow(-2 * u + 2, 2) / 2);
const lerp = (a: P, b: P, u: number): P => ({ x: a.x + (b.x - a.x) * u, y: a.y + (b.y - a.y) * u });
/** Reaction delay per player and keyframe, 0 to 0.45 of the move, deterministic. */
function stagger(id: string, k: number): number {
  let h = Math.imul(k + 1, 2654435761);
  for (let i = 0; i < id.length; i++) h = Math.imul(h ^ id.charCodeAt(i), 16777619);
  h ^= h >>> 13;
  return ((h >>> 0) / 4294967296) * 0.45;
}

export const fmtClock = (s: number) => {
  const c = Math.max(0, Math.min(3600, s));
  return `${String(Math.floor(c / 60)).padStart(2, '0')}:${String(Math.floor(c % 60)).padStart(2, '0')}`;
};
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

export class MatchViewer {
  private canvas: HTMLCanvasElement;
  private ctx: CanvasRenderingContext2D;
  private overlay: HTMLElement | null;
  private tl: Timeline | null = null;
  private people = new Map<string, { side: Side; number: number; gk: boolean; short: string }>();
  private vt = 0;
  private playing = false;
  private speed = 2;
  private last = 0;
  private overlayKind: string | null = null;
  private shownLogs = 0;
  private lastPoss = -2;
  private tab = 'feed';
  private reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  constructor(private root: HTMLElement, private match: MatchResult | null, private mode: Mode) {
    this.canvas = root.querySelector('canvas')!;
    this.ctx = this.canvas.getContext('2d')!;
    this.overlay = root.querySelector('.court__overlay');
    if (match) {
      this.tl = buildTimeline(match);
      for (const [side, team] of [['home', match.home], ['away', match.away]] as const) {
        for (const p of team.players) {
          this.people.set(p.id, { side, number: p.number, gk: p.position === 'GK', short: p.name.split(' ').slice(1).join(' ') });
        }
      }
    }
    new ResizeObserver(() => {
      this.resize();
      this.draw();
    }).observe(this.canvas);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.draw());
    new MutationObserver(() => this.draw()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    if (document.fonts) document.fonts.ready.then(() => this.draw());

    if (mode === 'full') this.bindControls();
    if (mode === 'hero' && this.tl) {
      this.speed = 3;
      this.canvas.addEventListener('click', () => this.setPlaying(!this.playing));
      this.setPlaying(!this.reduced);
    }
    this.resize();
    this.update();
    requestAnimationFrame((t) => this.loop(t));
  }

  // ---------- playback ----------

  private loop(now: number) {
    const dt = this.last ? Math.min(0.1, (now - this.last) / 1000) : 0;
    this.last = now;
    if (this.playing && this.tl) {
      this.vt = Math.min(this.tl.duration, this.vt + dt * this.speed);
      if (this.vt >= this.tl.duration) {
        if (this.mode === 'hero') {
          this.playing = false;
          setTimeout(() => {
            this.vt = 0;
            this.overlayKind = null;
            this.setPlaying(true);
          }, 5000);
        } else {
          this.setPlaying(false);
        }
      }
      this.update();
    }
    requestAnimationFrame((t) => this.loop(t));
  }

  private setPlaying(p: boolean) {
    if (!this.tl) return;
    this.playing = p && this.vt < this.tl.duration;
    const btn = this.root.querySelector<HTMLButtonElement>('[data-play]');
    if (btn) btn.textContent = this.playing ? 'Pause' : this.vt >= this.tl.duration ? 'Replay' : this.vt > 0 ? 'Resume' : 'Play';
  }

  private bindControls() {
    const q = <T extends Element>(s: string) => this.root.querySelector<T>(s);
    q<HTMLButtonElement>('[data-play]')?.addEventListener('click', () => {
      if (this.tl && this.vt >= this.tl.duration) this.seek(0);
      this.setPlaying(!this.playing);
    });
    q<HTMLButtonElement>('[data-skip]')?.addEventListener('click', () => {
      if (!this.tl) return;
      this.seek(this.tl.duration);
      this.setPlaying(false);
    });
    this.root.querySelectorAll<HTMLButtonElement>('[data-speed]').forEach((b) => {
      b.setAttribute('aria-pressed', String(Number(b.dataset.speed) === this.speed));
      b.addEventListener('click', () => {
        this.speed = Number(b.dataset.speed);
        this.root.querySelectorAll('[data-speed]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      });
    });
    const seek = q<HTMLInputElement>('[data-seek]');
    if (seek && this.tl) {
      seek.max = String(this.tl.duration);
      seek.addEventListener('input', () => this.seek(Number(seek.value)));
    }
    this.root.querySelectorAll<HTMLButtonElement>('[data-tab]').forEach((b) =>
      b.addEventListener('click', () => {
        this.tab = b.dataset.tab!;
        this.root.querySelectorAll('[data-tab]').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
        this.root.querySelectorAll<HTMLElement>('[data-panel]').forEach((p) => (p.hidden = p.dataset.panel !== this.tab));
        this.renderStats(true);
      }),
    );
    document.addEventListener('keydown', (e) => {
      const t = e.target as HTMLElement;
      if (e.code === 'Space' && !['INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'A'].includes(t.tagName)) {
        e.preventDefault();
        q<HTMLButtonElement>('[data-play]')?.click();
      }
    });
  }

  private seek(t: number) {
    this.vt = Math.max(0, Math.min(this.tl?.duration ?? 0, t));
    this.update();
  }

  // ---------- frame lookup ----------

  private frameAt(t: number) {
    const k = this.tl!.kfs;
    let lo = 0;
    let hi = k.length - 1;
    while (lo < hi) {
      const mid = (lo + hi + 1) >> 1;
      if (k[mid].t <= t) lo = mid;
      else hi = mid - 1;
    }
    const a: KF = k[lo];
    const b: KF = k[Math.min(lo + 1, k.length - 1)];
    const span = b.t - a.t;
    const u = span > 0 ? Math.max(0, Math.min(1, (t - a.t) / span)) : 1;
    const e = ease(u);
    const pos: Record<string, { p: P; alpha: number }> = {};
    // Players start moving at slightly different moments, so a group never moves as one.
    // The players at the ball (passer and receiver) keep exact timing so the ball meets them.
    const atBall = (id: string) =>
      Math.hypot(a.pos[id].x - a.ball.x, a.pos[id].y - a.ball.y) < 0.6 ||
      Math.hypot(b.pos[id].x - b.ball.x, b.pos[id].y - b.ball.y) < 0.6;
    for (const id of Object.keys(a.pos)) {
      if (!b.pos[id]) {
        pos[id] = { p: a.pos[id], alpha: 1 - u };
        continue;
      }
      const delay = atBall(id) ? 0 : stagger(id, lo);
      const up = Math.max(0, Math.min(1, (u - delay) / (1 - delay)));
      pos[id] = { p: lerp(a.pos[id], b.pos[id], ease(up)), alpha: 1 };
    }
    for (const id of Object.keys(b.pos)) if (!a.pos[id]) pos[id] = { p: b.pos[id], alpha: u };
    const dist = Math.hypot(b.ball.x - a.ball.x, b.ball.y - a.ball.y);
    // A player who has the ball at both ends of this move carries it: the ball follows him exactly.
    let ballNow = lerp(a.ball, b.ball, u);
    for (const id of Object.keys(a.pos)) {
      if (!b.pos[id]) continue;
      if (Math.hypot(a.pos[id].x - a.ball.x, a.pos[id].y - a.ball.y) < 0.3 && Math.hypot(b.pos[id].x - b.ball.x, b.pos[id].y - b.ball.y) < 0.3) {
        ballNow = pos[id].p;
        break;
      }
    }
    return {
      pos,
      ball: ballNow,
      clock: a.clock + (b.clock - a.clock) * u,
      lift: dist > 2 ? Math.sin(Math.PI * u) * Math.min(1, dist / 12) : 0,
    };
  }

  // ---------- update page ----------

  private update() {
    if (!this.tl) {
      this.draw();
      return;
    }
    const f = this.frameAt(this.vt);
    let score: [number, number] = [0, 0];
    for (const e of this.tl.logs) {
      if (e.t > this.vt) break;
      score = e.score;
    }
    if (this.mode === 'full') {
      const board = document.querySelector('[data-scoreboard]');
      if (board) {
        board.querySelector('[data-score="home"]')!.textContent = String(score[0]);
        board.querySelector('[data-score="away"]')!.textContent = String(score[1]);
        const ended = this.vt >= this.tl.duration - 1.5;
        board.querySelector('[data-clock]')!.textContent = ended ? 'Full time' : `${fmtClock(f.clock)}, ${f.clock < 1800 ? '1st' : '2nd'} half`;
        for (const side of ['home', 'away'] as const) {
          const active = this.tl.susp.filter((x) => x.side === side && f.clock >= x.from && f.clock < x.to && !ended);
          board.querySelector(`[data-susp="${side}"]`)!.innerHTML = active
            .map((x) => `<span class="susp ${x.red ? 'susp--red' : ''}">#${this.people.get(x.id)?.number} ${x.red ? 'red card' : fmtClock(x.to - f.clock).slice(1)}</span>`)
            .join('');
        }
      }
      const seek = this.root.querySelector<HTMLInputElement>('[data-seek]');
      if (seek && document.activeElement !== seek) seek.value = String(this.vt);
      const sc = this.root.querySelector('[data-seek-clock]');
      if (sc) sc.textContent = fmtClock(f.clock);
      this.updateFeed();
      this.updatePlaybar(f.clock);
      this.renderStats();
    }
    this.updateOverlay();
    this.draw();
  }

  private colorOf(side: Side) {
    return side === 'home' ? this.match!.home.color : this.match!.away.color;
  }

  private updateOverlay() {
    if (!this.overlay || !this.tl || !this.match) return;
    const ov = this.tl.overlays.find((o) => this.vt >= o.t0 && this.vt < o.t1);
    const kind = ov ? ov.kind : null;
    if (kind === this.overlayKind) return;
    this.overlayKind = kind;
    this.overlay.hidden = !kind;
    if (!kind) return;
    const H = this.match.home;
    const A = this.match.away;
    if (kind === 'kickoff') {
      const rows = TACTIC_LABELS.map(
        (t) => `<tr><td>${esc(t.opts[H.tactics[t.key]])}</td><td class="mid">${t.label}</td><td>${esc(t.opts[A.tactics[t.key]])}</td></tr>`,
      ).join('');
      this.overlay.innerHTML = `<div><small>Kick-off. Tactics revealed</small><table><tr><td style="color:${H.color}">${esc(H.short)}</td><td></td><td style="color:${A.color}">${esc(A.short)}</td></tr>${rows}</table></div>`;
    } else if (kind === 'half') {
      this.overlay.innerHTML = `<div><small>Half-time</small><div class="big narrow">${this.match.halftime[0]}–${this.match.halftime[1]}</div></div>`;
    } else {
      const { pl } = statsUpTo(this.match, this.match.possessions.length - 1);
      const top = (team: Team) => {
        const best = team.players.filter((p) => pl[p.id]).sort((a, b) => pl[b.id].goals - pl[a.id].goals)[0];
        return best ? `${esc(best.name)} ${pl[best.id].goals}` : '';
      };
      const [h, a] = this.match.score;
      const verdict = h === a ? 'Draw' : `${esc(h > a ? H.name : A.name)} win`;
      this.overlay.innerHTML = `<div><small>Full time</small><div class="big narrow">${h}–${a}</div><h3 class="narrow">${verdict}</h3><small style="margin-top:8px">Top scorers: ${top(H)}, ${top(A)}</small></div>`;
    }
  }

  /** The bar under the court: who attacks, which way, and the latest event. */
  private updatePlaybar(clock: number) {
    const bar = this.root.querySelector<HTMLElement>('[data-playbar]');
    if (!bar || !this.tl || !this.match) return;
    const k = this.tl.kfs;
    let lo = 0;
    for (let i = 0; i < k.length && k[i].t <= this.vt; i++) lo = i;
    const kf = k[lo];
    const who = bar.querySelector<HTMLElement>('[data-attack]')!;
    if (kf.att && this.vt < this.tl.duration - 1.5) {
      const team = kf.att === 'home' ? this.match.home : this.match.away;
      const arrow = kf.dir === 1 ? '→' : '←';
      who.innerHTML = `<span class="playbar__dot" style="background:${team.color}"></span><b>${esc(team.name)}</b> attack ${arrow}`;
    } else {
      who.textContent = this.vt >= this.tl.duration - 1.5 ? 'Full time' : clock >= 1800 && clock < 1801 ? 'Half-time' : 'Kick-off';
    }
    let last = null;
    for (const e of this.tl.logs) {
      if (e.t > this.vt) break;
      last = e;
    }
    const now = bar.querySelector<HTMLElement>('[data-now]')!;
    const text = last ? `${fmtClock(last.clock)}  ${last.text}` : 'The match starts when you press Play.';
    if (now.textContent !== text) {
      now.textContent = text;
      now.className = `playbar__now ${last ? 'is-' + last.kind : ''}`;
    }
  }

  private updateFeed() {
    const list = this.root.querySelector<HTMLOListElement>('[data-feed]');
    if (!list || !this.tl) return;
    const logs = this.tl.logs;
    let n = 0;
    while (n < logs.length && logs[n].t <= this.vt) n++;
    if (n < this.shownLogs) {
      list.innerHTML = '';
      this.shownLogs = 0;
    }
    for (let i = this.shownLogs; i < n; i++) {
      const e = logs[i];
      const li = document.createElement('li');
      li.className = e.kind;
      const color = e.side ? this.colorOf(e.side) : 'var(--rule)';
      li.innerHTML = `<span class="t">${fmtClock(e.clock)}</span><span class="d" style="background:${color}"></span><span class="x"></span><span class="s">${e.kind === 'goal' ? `${e.score[0]}–${e.score[1]}` : ''}</span>`;
      li.querySelector('.x')!.textContent = e.text;
      list.prepend(li);
    }
    this.shownLogs = n;
    const empty = this.root.querySelector<HTMLElement>('[data-feed-empty]');
    if (empty) empty.hidden = n > 0;
  }

  private renderStats(force = false) {
    if (!this.tl || !this.match) return;
    let k = -1;
    while (k + 1 < this.tl.possEndT.length && this.tl.possEndT[k + 1] <= this.vt) k++;
    if (!force && k === this.lastPoss) return;
    this.lastPoss = k;
    const panel = this.root.querySelector<HTMLElement>('[data-panel="stats"]');
    if (!panel || this.tab !== 'stats') return;
    const { team, pl } = statsUpTo(this.match, k);
    const h = team.home;
    const a = team.away;
    const pct = (x: number, y: number) => (y ? `${Math.round((x / y) * 100)}%` : '–');
    const row = (label: string, hv: string | number, av: string | number) => `<tr><td>${hv}</td><td class="l">${label}</td><td>${av}</td></tr>`;
    const scorers = (t: Team) =>
      t.players
        .filter((p) => pl[p.id]?.shots)
        .sort((x, y) => pl[y.id].goals - pl[x.id].goals)
        .slice(0, 5)
        .map((p) => `<tr><td class="l" style="text-align:left;width:auto;font-weight:400">${esc(p.name)}</td><td class="l"></td><td>${pl[p.id].goals}/${pl[p.id].shots}</td></tr>`)
        .join('');
    panel.innerHTML = `
      <table class="statgrid">
        <tr><td style="color:${this.match.home.color}">${esc(this.match.home.short)}</td><td class="l"></td><td style="color:${this.match.away.color}">${esc(this.match.away.short)}</td></tr>
        ${row('Goals', h.goals, a.goals)}
        ${row('Shots', h.shots, a.shots)}
        ${row('Shooting', pct(h.goals, h.shots), pct(a.goals, a.shots))}
        ${row('7 m', `${h.sevenG}/${h.sevenS}`, `${a.sevenG}/${a.sevenS}`)}
        ${row('Fast-break goals', h.fbGoals, a.fbGoals)}
        ${row('Saves', `${h.saves} (${pct(h.saves, h.faced)})`, `${a.saves} (${pct(a.saves, a.faced)})`)}
        ${row('Turnovers', h.to, a.to)}
        ${row('Steals', h.steals, a.steals)}
        ${row('2 min', h.susp, a.susp)}
        ${row('Timeouts', h.timeouts, a.timeouts)}
      </table>
      <h3 class="narrow" style="font-size:var(--s-15);margin:16px 0 4px">${esc(this.match.home.name)}</h3><table class="statgrid">${scorers(this.match.home)}</table>
      <h3 class="narrow" style="font-size:var(--s-15);margin:16px 0 4px">${esc(this.match.away.name)}</h3><table class="statgrid">${scorers(this.match.away)}</table>`;
  }

  // ---------- canvas ----------

  private resize() {
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const w = this.canvas.clientWidth;
    this.canvas.width = Math.round(w * dpr);
    this.canvas.height = Math.round(((w * WH) / WW) * dpr);
  }

  private css(name: string, fallback: string) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
  }

  draw() {
    const ctx = this.ctx;
    const canvas = this.canvas;
    if (!canvas.width) return;
    const s = canvas.width / WW;
    const X = (x: number) => (x + MX) * s;
    const Y = (y: number) => (y + MY) * s;
    const court = this.css('--court', '#1f5a96');
    const area = this.css('--area', '#e8762c');
    const font = this.css('--f', 'Archivo, sans-serif');
    const cy = COURT_H / 2;

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.fillStyle = this.css('--court-deep', '#184b80');
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = court;
    ctx.fillRect(X(0), Y(0), COURT_W * s, COURT_H * s);
    ctx.lineWidth = Math.max(1, 0.09 * s);
    ctx.strokeStyle = '#ffffff';

    // Goal areas (6 m) and dashed free-throw lines (9 m)
    ctx.save();
    ctx.beginPath();
    ctx.rect(X(0), Y(0), COURT_W * s, COURT_H * s);
    ctx.clip();
    for (const [gx, dir] of [[0, 1], [COURT_W, -1]] as const) {
      const arcPath = (r: number) => {
        ctx.beginPath();
        ctx.moveTo(X(gx), Y(cy - 1.5 - r));
        if (dir === 1) {
          ctx.arc(X(gx), Y(cy - 1.5), r * s, -Math.PI / 2, 0);
          ctx.lineTo(X(gx + r), Y(cy + 1.5));
          ctx.arc(X(gx), Y(cy + 1.5), r * s, 0, Math.PI / 2);
        } else {
          ctx.arc(X(gx), Y(cy - 1.5), r * s, -Math.PI / 2, -Math.PI, true);
          ctx.lineTo(X(gx - r), Y(cy + 1.5));
          ctx.arc(X(gx), Y(cy + 1.5), r * s, Math.PI, Math.PI / 2, true);
        }
      };
      arcPath(6);
      ctx.closePath();
      ctx.fillStyle = area;
      ctx.fill();
      ctx.stroke();
      ctx.setLineDash([0.5 * s, 0.35 * s]);
      arcPath(9);
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.beginPath();
      ctx.moveTo(X(gx + dir * 7), Y(cy - 0.5));
      ctx.lineTo(X(gx + dir * 7), Y(cy + 0.5));
      ctx.moveTo(X(gx + dir * 4), Y(cy - 0.075));
      ctx.lineTo(X(gx + dir * 4), Y(cy + 0.075));
      ctx.stroke();
    }
    ctx.restore();

    ctx.strokeRect(X(0), Y(0), COURT_W * s, COURT_H * s);
    ctx.beginPath();
    ctx.moveTo(X(COURT_W / 2), Y(0));
    ctx.lineTo(X(COURT_W / 2), Y(COURT_H));
    for (const dx of [-4.5, 4.5]) {
      ctx.moveTo(X(COURT_W / 2 + dx), Y(COURT_H - 0.15));
      ctx.lineTo(X(COURT_W / 2 + dx), Y(COURT_H + 0.15));
    }
    ctx.stroke();

    // Goals with nets
    for (const [gx, dir] of [[0, -1], [COURT_W, 1]] as const) {
      const x0 = Math.min(X(gx), X(gx + dir));
      ctx.fillStyle = 'rgba(255,255,255,0.18)';
      ctx.fillRect(x0, Y(cy - 1.5), s, 3 * s);
      ctx.strokeStyle = 'rgba(255,255,255,0.35)';
      ctx.lineWidth = Math.max(0.5, 0.03 * s);
      for (let yy = -1.5; yy <= 1.5; yy += 0.3) {
        ctx.beginPath();
        ctx.moveTo(x0, Y(cy + yy));
        ctx.lineTo(x0 + s, Y(cy + yy));
        ctx.stroke();
      }
      ctx.strokeStyle = '#e8483a';
      ctx.lineWidth = Math.max(2, 0.14 * s);
      ctx.beginPath();
      ctx.moveTo(X(gx), Y(cy - 1.5));
      ctx.lineTo(X(gx + dir), Y(cy - 1.5));
      ctx.lineTo(X(gx + dir), Y(cy + 1.5));
      ctx.lineTo(X(gx), Y(cy + 1.5));
      ctx.stroke();
    }

    if (!this.tl || !this.match) return;

    // Benches
    for (const side of ['home', 'away'] as const) {
      const cx = COURT_W / 2 + (side === 'home' ? -8 : 8);
      ctx.fillStyle = this.colorOf(side);
      ctx.globalAlpha = 0.35;
      ctx.fillRect(X(cx - 5), Y(COURT_H + 0.6), 10 * s, 1.1 * s);
      ctx.globalAlpha = 1;
      ctx.fillStyle = 'rgba(255,255,255,0.85)';
      ctx.font = `600 ${Math.max(9, 0.62 * s)}px ${font}`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(`${side === 'home' ? this.match.home.short : this.match.away.short} bench`, X(cx), Y(COURT_H + 1.18));
    }

    // Players
    const f = this.frameAt(this.vt);
    const r = 0.62 * s;
    for (const [id, { p, alpha }] of Object.entries(f.pos).sort((a, b) => a[1].p.y - b[1].p.y)) {
      const info = this.people.get(id);
      if (!info || alpha <= 0.01) continue;
      ctx.globalAlpha = alpha;
      ctx.beginPath();
      ctx.arc(X(p.x), Y(p.y) + 0.12 * s, r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,0,0,0.25)';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(X(p.x), Y(p.y), r, 0, Math.PI * 2);
      ctx.fillStyle = info.gk ? '#15191d' : this.colorOf(info.side);
      ctx.fill();
      ctx.lineWidth = Math.max(1.5, 0.1 * s);
      ctx.strokeStyle = info.gk ? this.colorOf(info.side) : '#ffffff';
      ctx.stroke();
      ctx.fillStyle = '#ffffff';
      ctx.font = `700 ${Math.max(8, 0.6 * s)}px ${font}`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(String(info.number), X(p.x), Y(p.y) + 0.03 * s);
    }
    ctx.globalAlpha = 1;

    // Ball carrier: white ring and his name, so it's clear who has the ball.
    if (f.lift < 0.05) {
      let holder: string | null = null;
      let best = 0.7;
      for (const [id, { p, alpha }] of Object.entries(f.pos)) {
        const dist = Math.hypot(p.x - f.ball.x, p.y - f.ball.y);
        if (alpha > 0.9 && dist < best) {
          best = dist;
          holder = id;
        }
      }
      const info = holder ? this.people.get(holder) : null;
      if (holder && info) {
        const p = f.pos[holder].p;
        ctx.beginPath();
        ctx.arc(X(p.x), Y(p.y), r + 0.22 * s, 0, Math.PI * 2);
        ctx.lineWidth = Math.max(2, 0.13 * s);
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();
        const label = info.short;
        ctx.font = `700 ${Math.max(10, 0.62 * s)}px ${font}`;
        const tw = ctx.measureText(label).width + 0.5 * s;
        const th = 0.85 * s;
        const lx = Math.max(tw / 2 + 2, Math.min(canvas.width - tw / 2 - 2, X(p.x)));
        const below = Y(p.y) + r + 0.35 * s + th > Y(COURT_H) ? -1 : 1;
        const ly = below === 1 ? Y(p.y) + r + 0.35 * s + th / 2 : Y(p.y) - r - 0.35 * s - th / 2;
        ctx.fillStyle = 'rgba(8, 18, 30, 0.82)';
        ctx.beginPath();
        ctx.roundRect(lx - tw / 2, ly - th / 2, tw, th, th / 2);
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, lx, ly + 0.03 * s);
      }
    }

    // Ball
    const br = (0.27 + f.lift * 0.14) * s;
    ctx.beginPath();
    ctx.arc(X(f.ball.x), Y(f.ball.y) + (0.1 + f.lift * 0.45) * s, br * 0.9, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,0.3)';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(X(f.ball.x), Y(f.ball.y), br, 0, Math.PI * 2);
    ctx.fillStyle = '#ffd23f';
    ctx.fill();
    ctx.lineWidth = Math.max(1, 0.06 * s);
    ctx.strokeStyle = '#3a2a00';
    ctx.stroke();

    // Pop-ups (GOAL, SAVE, 2 MIN ...)
    for (const pu of this.tl.popups) {
      if (this.vt < pu.t0 || this.vt > pu.t1) continue;
      const u = (this.vt - pu.t0) / (pu.t1 - pu.t0);
      const a = u < 0.15 ? u / 0.15 : u > 0.75 ? (1 - u) / 0.25 : 1;
      const size = (pu.big ? 1.25 : 0.72) * s;
      ctx.font = `800 ${Math.max(10, size)}px ${font}`;
      const label = pu.text.charAt(0) + pu.text.slice(1).toLowerCase();
      const w = ctx.measureText(label).width + size * 0.9;
      const h = size * 1.35;
      const px = Math.max(w / 2 + 4, Math.min(canvas.width - w / 2 - 4, X(pu.at.x)));
      const py = Math.max(h, Y(pu.at.y) - r * 2.2 - u * 0.6 * s);
      ctx.globalAlpha = a;
      ctx.fillStyle = pu.side ? this.colorOf(pu.side) : '#15191d';
      ctx.beginPath();
      ctx.roundRect(px - w / 2, py - h / 2, w, h, h * 0.25);
      ctx.fill();
      ctx.lineWidth = Math.max(1, 0.06 * s);
      ctx.strokeStyle = 'rgba(255,255,255,0.85)';
      ctx.stroke();
      ctx.fillStyle = '#ffffff';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, px, py + size * 0.05);
      ctx.globalAlpha = 1;
    }
  }
}

export function mountViewers() {
  document.querySelectorAll<HTMLElement>('[data-match-viewer]').forEach((root) => {
    const mode = (root.dataset.matchViewer as Mode) || 'full';
    const data = document.getElementById('match-data');
    const match = mode !== 'empty' && data ? (JSON.parse(data.textContent || 'null') as MatchResult) : null;
    new MatchViewer(root, match, match ? mode : 'empty');
  });
}
