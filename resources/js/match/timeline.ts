// Turns a MatchResult (the engine's event log) into an animation timeline:
// keyframes with every player's position, plus commentary lines, pop-ups,
// overlays and suspension timers. The viewer only interpolates keyframes.

import type {
  Action, DefenseSystem, FieldSlot, FramePoint, MatchResult, OnCourt, Player, Possession,
  ShotKind, Side,
} from './types';

export interface P {
  x: number;
  y: number;
}
export interface KF {
  t: number;
  clock: number;
  pos: Record<string, P>;
  ball: P;
  /** Attacking side and direction on screen (1 = towards the right goal), null between halves. */
  att: Side | null;
  dir: 1 | -1;
}
export interface Popup {
  t0: number;
  t1: number;
  text: string;
  side: Side | null;
  at: P;
  big: boolean;
}
export interface Overlay {
  t0: number;
  t1: number;
  kind: 'kickoff' | 'half' | 'full';
}
export interface LogEntry {
  t: number;
  clock: number;
  side: Side | null;
  kind: 'goal' | 'save' | 'miss' | 'turnover' | 'foul' | 'suspension' | 'timeout' | 'info';
  text: string;
  score: [number, number];
}
export interface Susp {
  side: Side;
  id: string;
  from: number;
  to: number;
  red: boolean;
}
export interface Timeline {
  kfs: KF[];
  popups: Popup[];
  overlays: Overlay[];
  logs: LogEntry[];
  susp: Susp[];
  possEndT: number[];
  duration: number;
}

export const COURT_W = 40;
export const COURT_H = 20;

// Base attacking positions, attacking frame (d from goal line, y lateral; - = attackers' left).
const ATT: Record<FieldSlot, FramePoint> = {
  LW: { d: 1.2, y: -8.6 },
  LB: { d: 9.6, y: -5.4 },
  CB: { d: 10.2, y: 0 },
  RB: { d: 9.6, y: 5.4 },
  RW: { d: 1.2, y: 8.6 },
  PV: { d: 6.6, y: -0.6 },
  X7: { d: 6.7, y: 2.4 },
};

/** Distance of the 6 m line from the goal line at lateral position y. */
function line6(y: number): number {
  const off = Math.abs(y) - 1.5;
  if (off <= 0) return 6;
  if (off >= 6) return 0.4;
  return Math.sqrt(36 - off * off);
}

interface DefSlot {
  p: FramePoint;
  rank: number; // 0 = on the 6 m line, 1 = middle row, 2 = top
}

/** Small deterministic offset in [-amp, amp], so the same match always replays the same way. */
function jitter(id: string, step: number, amp: number): number {
  let h = 2166136261 ^ Math.imul(step + 1, 374761393);
  for (let i = 0; i < id.length; i++) h = Math.imul(h ^ id.charCodeAt(i), 16777619);
  h ^= h >>> 15;
  h = Math.imul(h, 2246822519);
  h ^= h >>> 13;
  return (((h >>> 0) / 4294967296) * 2 - 1) * amp;
}

function defenseShape(sys: DefenseSystem, n: number): DefSlot[] {
  const onLine = (y: number): DefSlot => ({ p: { d: Math.max(1.2, line6(y) + 0.7), y }, rank: 0 });
  if (n < 6) {
    const out: DefSlot[] = [];
    for (let i = 0; i < n; i++) out.push(onLine(-7 + (14 * i) / Math.max(1, n - 1)));
    return out;
  }
  if (sys === '5-1') {
    return [...[-7.6, -3.8, 0, 3.8, 7.6].map(onLine), { p: { d: 9.4, y: 0 }, rank: 2 }];
  }
  if (sys === '3-2-1') {
    return [
      ...[-6.6, 0, 6.6].map(onLine),
      { p: { d: 8.4, y: -4 }, rank: 1 },
      { p: { d: 8.4, y: 4 }, rank: 1 },
      { p: { d: 10.6, y: 0 }, rank: 2 },
    ];
  }
  return [-8, -4.6, -1.5, 1.5, 4.6, 8].map(onLine);
}

/** Map defenders to the shape: mobile backs to advanced spots, the rest along the line. */
function assignDefense(lineup: OnCourt[], shape: DefSlot[]): Map<string, FramePoint> {
  const out = new Map<string, FramePoint>();
  const left = [...lineup];
  const takeBy = (pref: FieldSlot[]) => {
    for (const s of pref) {
      const i = left.findIndex((o) => o.slot === s);
      if (i >= 0) return left.splice(i, 1)[0];
    }
    return left.shift()!;
  };
  const advanced = shape.filter((s) => s.rank > 0).sort((a, b) => b.rank - a.rank);
  for (const s of advanced) out.set(takeBy(s.rank === 2 ? ['CB', 'LB', 'RB'] : ['LB', 'RB', 'CB']).id, s.p);
  const lineSlots = shape.filter((s) => s.rank === 0).sort((a, b) => a.p.y - b.p.y);
  // Defenders face the other way: their right wing covers the attackers' left side.
  const order: FieldSlot[] = ['RW', 'RB', 'PV', 'CB', 'LB', 'LW'];
  left.sort((a, b) => order.indexOf(a.slot) - order.indexOf(b.slot));
  lineSlots.forEach((s, i) => {
    if (left[i]) out.set(left[i].id, s.p);
  });
  return out;
}

const SHOT_TEXT: Record<ShotKind, string> = {
  wing: 'from the wing',
  back9: 'from 9 metres',
  breakthrough: 'on a breakthrough',
  pivot: 'from the pivot position',
  fastBreak: 'on the fast break',
  secondWave: 'on the second wave',
  sevenMeter: 'from the 7-metre line',
  rebound: 'on the rebound',
  emptyGoal: 'into the empty goal',
};

export function buildTimeline(m: MatchResult): Timeline {
  const people = new Map<string, Player & { side: Side }>();
  for (const p of m.home.players) people.set(p.id, { ...p, side: 'home' });
  for (const p of m.away.players) people.set(p.id, { ...p, side: 'away' });
  const nm = (id: string) => {
    const p = people.get(id);
    if (!p) return '?';
    const parts = p.name.split(' ');
    return `${parts[0][0]}. ${parts.slice(1).join(' ')}`;
  };
  const teamName = (s: Side) => (s === 'home' ? m.home.name : m.away.name);
  const other = (s: Side): Side => (s === 'home' ? 'away' : 'home');

  const kfs: KF[] = [];
  const popups: Popup[] = [];
  const overlays: Overlay[] = [];
  const logs: LogEntry[] = [];
  const susp: Susp[] = [];
  const possEndT: number[] = [];
  let t = 0;
  let cur: Record<string, P> = {};
  let ball: P = { x: COURT_W / 2, y: COURT_H / 2 };
  let score: [number, number] = [0, 0];
  let pendingClock: (KF | LogEntry)[] = [];
  let step = 0;
  let curAtt: Side | null = null;
  let curDir: 1 | -1 = 1;

  const push = (dt: number) => {
    t += dt;
    const kf: KF = { t, clock: NaN, pos: { ...cur }, ball: { ...ball }, att: curAtt, dir: curDir };
    kfs.push(kf);
    pendingClock.push(kf);
  };
  const log = (side: Side | null, kind: LogEntry['kind'], text: string) => {
    const e: LogEntry = { t, clock: NaN, side, kind, text, score: [...score] as [number, number] };
    logs.push(e);
    pendingClock.push(e);
  };
  const popup = (text: string, side: Side | null, at: P, dur = 1.1, big = false) =>
    popups.push({ t0: t, t1: t + dur, text, side, at: { ...at }, big });
  const fixClocks = (t0: number, c0: number, c1: number) => {
    const span = Math.max(0.001, t - t0);
    for (const e of pendingClock) e.clock = c0 + ((e.t - t0) / span) * (c1 - c0);
    pendingClock = [];
  };

  const rightward = (side: Side, half: 1 | 2) => (half === 1) === (m.rightFirstHalf === side);
  const toWorld = (side: Side, half: 1 | 2, f: FramePoint): P =>
    rightward(side, half)
      ? { x: COURT_W - f.d, y: COURT_H / 2 + f.y }
      : { x: f.d, y: COURT_H / 2 - f.y };
  const benchSpot = (side: Side): P => ({ x: COURT_W / 2 + (side === 'home' ? -4 : 4), y: COURT_H + 0.9 });

  // ----- kick-off: both teams in their own half, tactics overlay -----
  {
    const first = m.possessions[0];
    const att = first.side;
    for (const o of first.attackers) {
      const f = ATT[o.slot];
      cur[o.id] = toWorld(att, 1, { d: 21 + f.d * 0.3, y: f.y * 0.85 });
    }
    const shape = assignDefense(first.defLineup, defenseShape(first.defense, first.defLineup.length));
    for (const [id, f] of shape) cur[id] = toWorld(att, 1, f);
    if (first.attGk) cur[first.attGk] = toWorld(att, 1, { d: 39.3, y: 0 });
    if (first.defGk) cur[first.defGk] = toWorld(att, 1, { d: 0.7, y: 0 });
    const cb = first.attackers.find((o) => o.slot === 'CB') ?? first.attackers[0];
    cur[cb.id] = toWorld(att, 1, { d: 20.3, y: 0 });
    ball = { ...cur[cb.id] };
    curAtt = att;
    curDir = rightward(att, 1) ? 1 : -1;
    kfs.push({ t: 0, clock: 0, pos: { ...cur }, ball: { ...ball }, att: curAtt, dir: curDir });
    overlays.push({ t0: 0, t1: 3.6, kind: 'kickoff' });
    push(3.6);
    fixClocks(0, 0, 0);
  }

  let halfShown = false;

  for (const poss of m.possessions) {
    if (poss.half === 2 && !halfShown) {
      halfShown = true;
      const t0 = t;
      overlays.push({ t0: t, t1: t + 3.2, kind: 'half' });
      curAtt = null;
      log(null, 'info', `Half-time: ${m.home.short} ${score[0]}–${score[1]} ${m.away.short}`);
      push(3.2);
      fixClocks(t0, 1800, 1800);
    }

    const side = poss.side;
    curAtt = side;
    curDir = rightward(side, poss.half) ? 1 : -1;
    const dside = other(side);
    const W = (f: FramePoint) => toWorld(side, poss.half, f);
    const t0 = t;

    // ---- pre-possession actions ----
    for (const a of poss.pre) {
      if (a.t === 'timeout') {
        log(a.side, 'timeout', `Team timeout, ${teamName(a.side)}`);
        popup('TIMEOUT', a.side, { x: COURT_W / 2, y: COURT_H / 2 }, 1.4, true);
        push(1.4);
      } else if (a.t === 'gkChange') {
        log(a.side, 'info', `Goalkeeper change: ${nm(a.in)} replaces ${nm(a.out)}`);
      } else if (a.t === 'extraAttacker') {
        const prevPoss = m.possessions[poss.n - 2];
        if (!prevPoss || !prevPoss.sevenVsSix || prevPoss.side !== side) {
          log(a.side, 'info', `${teamName(a.side)} pull the goalkeeper: 7 vs 6`);
        }
      }
    }

    // ---- who is on court: add newcomers at the bench, remove the rest ----
    const attIds = poss.attackers.map((o) => o.id);
    const defIds = poss.defLineup.map((o) => o.id);
    const on = new Set<string>([...attIds, ...defIds]);
    if (poss.attGk) on.add(poss.attGk);
    if (poss.defGk) on.add(poss.defGk);
    for (const id of Object.keys(cur)) if (!on.has(id)) delete cur[id];
    for (const id of on) if (!cur[id]) cur[id] = benchSpot(people.get(id)!.side);

    const attSlot = new Map(poss.attackers.map((o) => [o.id, o.slot]));
    let activeDef = [...poss.defLineup];
    const gkHome = (b: P) => {
      if (poss.defGk) {
        const by = rightward(side, poss.half) ? b.y - COURT_H / 2 : COURT_H / 2 - b.y;
        cur[poss.defGk] = W({ d: 0.7, y: Math.max(-1.2, Math.min(1.2, by * 0.15)) });
      }
      if (poss.attGk) cur[poss.attGk] = W({ d: 39.2, y: 0 });
    };
    const frameOf = (p: P): FramePoint =>
      rightward(side, poss.half)
        ? { d: COURT_W - p.x, y: p.y - COURT_H / 2 }
        : { d: p.x, y: COURT_H / 2 - p.y };

    /** Set formation with the ball at `holder`. */
    const setFormation = (holder: string) => {
      step++;
      const hSlot = attSlot.get(holder);
      for (const o of poss.attackers) {
        const f = ATT[o.slot];
        const isHolder = o.id === holder;
        const forward = isHolder ? (o.slot === 'LW' || o.slot === 'RW' ? 0.4 : 1.1) : 0;
        // Off-ball attackers keep moving a little so the attack doesn't look frozen.
        const jd = isHolder ? 0 : jitter(o.id, step, 0.5);
        const jy = isHolder ? 0 : jitter(o.id + 'y', step, 0.6);
        cur[o.id] = W({ d: Math.max(0.8, f.d - forward + jd), y: f.y + jy });
      }
      const hf = hSlot ? ATT[hSlot] : { d: 9, y: 0 };
      const shape = assignDefense(activeDef, defenseShape(poss.defense, activeDef.length));

      // Each defender holds his own zone. On a pass only the defender facing the ball
      // steps out and his two neighbours on the line close the gap; the others stay put
      // and only drift now and then, each on his own rhythm, so the line never moves as one.
      const entries = [...shape.entries()];
      const primary = entries
        .map(([id, f]) => ({ id, f, dist: Math.hypot(f.d - hf.d, f.y - hf.y) }))
        .sort((a, b) => a.dist - b.dist)[0];
      const line = entries.filter(([, f]) => f.d < 8).sort((a, b) => a[1].y - b[1].y).map(([id]) => id);
      const pIdx = primary ? line.indexOf(primary.id) : -1;
      const onWing = hf.d < 3;
      for (const [id, f] of entries) {
        const onLine = f.d < 8;
        // Idle drift changes every 3-5 passes, at a different pass for each player.
        const phase = Math.floor((step + Math.floor(Math.abs(jitter(id + 'p', 0, 1)) * 4)) / 4);
        let y = f.y + jitter(id, phase, 0.35);
        let d = onLine ? Math.max(1.2, line6(y) + 0.7) : f.d + jitter(id + 'd', phase, 0.4);
        if (primary && id === primary.id) {
          if (onWing) {
            y += (hf.y - y) * 0.5;
            d = Math.max(1.2, line6(y) + 0.4);
          } else if (hf.d > 7) {
            d = Math.min(hf.d - 1.1, d + 2.2);
            y += (hf.y - y) * 0.65;
          } else {
            // Pivot: the defender stays glued to him on the line.
            y += (hf.y - y) * 0.7;
          }
        } else if (onLine && pIdx >= 0 && Math.abs(line.indexOf(id) - pIdx) === 1 && hf.d > 7) {
          // Neighbours close the gap the stepping-out defender leaves behind.
          y += (primary.f.y - y) * 0.3;
          d = Math.max(1.2, line6(y) + 0.7);
        }
        cur[id] = W({ d, y });
      }
      ball = holder in cur ? { ...cur[holder] } : ball;
      gkHome(ball);
    };

    const sevenMeterSetup = (taker: string) => {
      poss.attackers.forEach((o, i) => {
        cur[o.id] = W({ d: 10.4, y: -6 + (12 * i) / Math.max(1, poss.attackers.length - 1) });
      });
      activeDef.forEach((o, i) => {
        cur[o.id] = W({ d: 9.3, y: -5 + (10 * i) / Math.max(1, activeDef.length - 1) });
      });
      cur[taker] = W({ d: 7.3, y: 0 });
      if (poss.defGk) cur[poss.defGk] = W({ d: 1.6, y: 0 });
      ball = { ...cur[taker] };
    };

    let open = poss.kind === 'fastBreak' || poss.kind === 'emptyGoal';
    let actions = poss.actions;

    if (poss.kind === 'fastBreak') {
      const firstPass = poss.actions[0]?.t === 'pass' ? (poss.actions[0] as Extract<Action, { t: 'pass' }>) : null;
      const runner = firstPass ? firstPass.to : actorOf(poss.actions[0]);
      const passerPos = firstPass && cur[firstPass.from] ? { ...cur[firstPass.from] } : { ...cur[runner] };
      ball = passerPos;
      push(0.25);
      for (const id of attIds) {
        const f = frameOf(cur[id]);
        cur[id] = W({ d: 20 + (Math.abs(f.y) % 5) + 2, y: f.y * 0.7 });
      }
      for (const id of defIds) {
        const f = frameOf(cur[id]);
        cur[id] = W({ d: 13 + Math.abs(f.y), y: f.y * 0.6 });
      }
      const rf = frameOf(cur[runner]);
      cur[runner] = W({ d: 13, y: rf.y > 0 ? 3 : -3 });
      ball = { ...cur[runner] };
      gkHome(ball);
      if (poss.attGk) cur[poss.attGk] = W({ d: 36, y: 0 });
      if (firstPass) log(side, 'info', `${nm(runner)} breaks away`);
      push(1.1);
      // Skip the first pass when processing actions below.
      if (firstPass) actions = actions.slice(1);
    } else if (poss.kind === 'emptyGoal') {
      // Shot comes straight from the defensive half.
    } else {
      const firstHolder = poss.actions[0] ? actorOf(poss.actions[0]) : attIds[0];
      setFormation(firstHolder);
      push(poss.kind === 'secondWave' ? 0.75 : 1.05);
    }

    // ---- process actions ----
    for (const a of actions) {
      switch (a.t) {
        case 'pass': {
          if (open) {
            const f = frameOf(cur[a.to] ?? ball);
            cur[a.to] = W({ d: Math.max(6.8, f.d - 4), y: f.y });
            ball = { ...cur[a.to] };
            for (const id of defIds) {
              if (!cur[id]) continue;
              const df = frameOf(cur[id]);
              cur[id] = W({ d: Math.max(7, df.d - 3), y: df.y });
            }
            push(0.45);
          } else {
            setFormation(a.to);
            push(0.42);
          }
          break;
        }
        case 'suspension': {
          const s = people.get(a.player)!.side;
          susp.push({ side: s, id: a.player, from: poss.start, to: poss.start + 120, red: a.red });
          log(s, 'suspension', a.red
            ? `Red card for ${nm(a.player)} after a third suspension`
            : `2 minutes for ${nm(a.player)} (${teamName(s)})`);
          popup(a.red ? 'RED CARD' : '2 MIN', s, cur[a.player] ?? ball, 1.2);
          activeDef = activeDef.filter((o) => o.id !== a.player);
          if (cur[a.player]) cur[a.player] = benchSpot(s);
          push(0.6);
          delete cur[a.player];
          break;
        }
        case 'foul7m': {
          log(side, 'foul', `7-metre to ${teamName(side)}: ${nm(a.by)} fouls ${nm(a.on)}`);
          popup('7 M', side, cur[a.on] ?? ball, 1.0);
          push(0.6);
          break;
        }
        case 'turnover': {
          const label =
            a.kind === 'steal' ? 'STEAL' : a.kind === 'passive' ? 'PASSIVE PLAY' : a.kind === 'offensiveFoul' ? 'OFFENSIVE FOUL' : a.kind === 'badPass' ? 'BAD PASS' : 'TECHNICAL FAULT';
          const text =
            a.kind === 'steal' && a.stolenBy
              ? `${nm(a.stolenBy)} steals the ball from ${nm(a.by)}`
              : a.kind === 'passive'
                ? `Passive play, ${teamName(side)} lose the ball`
                : a.kind === 'offensiveFoul'
                  ? `Offensive foul by ${nm(a.by)}`
                  : a.kind === 'badPass'
                    ? `Bad pass by ${nm(a.by)}`
                    : `Technical fault by ${nm(a.by)}`;
          log(side, 'turnover', text);
          if (a.stolenBy && cur[a.stolenBy] && cur[a.by]) {
            const mid = { x: (cur[a.stolenBy].x + cur[a.by].x) / 2, y: (cur[a.stolenBy].y + cur[a.by].y) / 2 };
            cur[a.stolenBy] = mid;
            ball = { ...mid };
          } else {
            ball = cur[a.by] ? { ...cur[a.by] } : ball;
          }
          popup(label, dside, ball, 1.0);
          push(0.5);
          push(0.35);
          break;
        }
        case 'shot': {
          const shooterSide = people.get(a.by)!.side;
          if (a.kind === 'sevenMeter') {
            sevenMeterSetup(a.by);
            push(0.9);
          } else if (a.kind === 'emptyGoal') {
            cur[a.by] = W(a.at);
            ball = { ...cur[a.by] };
            for (const o of activeDef) {
              if (!cur[o.id]) continue;
              const f = frameOf(cur[o.id]);
              cur[o.id] = W({ d: Math.max(4, f.d - 9), y: f.y * 0.8 });
            }
            push(0.7);
          } else {
            if (cur[a.by]) {
              cur[a.by] = W(a.at);
              ball = { ...cur[a.by] };
            }
            push(0.32);
          }
          // Ball flight
          let target: FramePoint;
          if (a.result === 'goal') target = { d: -0.5, y: a.aim };
          else if (a.result === 'miss') target = { d: -1.2, y: a.aim };
          else if (a.result === 'post') target = { d: 0, y: a.aim };
          else if (a.result === 'save') target = { d: 0.8, y: a.aim * 0.7 };
          else target = { d: a.at.d - 0.9, y: a.at.y };
          if (a.result === 'block' && a.blocker && cur[a.blocker]) cur[a.blocker] = W({ d: a.at.d - 0.9, y: a.at.y });
          if (a.result === 'save' && a.gk && cur[a.gk]) cur[a.gk] = W({ d: 0.8, y: a.aim * 0.7 });
          ball = W(target);
          push(a.kind === 'emptyGoal' ? 0.95 : 0.28);

          const who = nm(a.by);
          if (a.result === 'goal') {
            if (shooterSide === 'home') score[0] += 1;
            else score[1] += 1;
            log(shooterSide, 'goal', `${who} scores ${SHOT_TEXT[a.kind]}`);
            popup('GOAL', shooterSide, W({ d: 3.5, y: 0 }), 1.3, true);
          } else if (a.result === 'save') {
            log(other(shooterSide), 'save', `${a.gk ? nm(a.gk) : 'Keeper'} saves ${who}'s shot ${SHOT_TEXT[a.kind]}`);
            popup('SAVE', other(shooterSide), ball, 1.0);
          } else if (a.result === 'block') {
            log(other(shooterSide), 'save', `${a.blocker ? nm(a.blocker) : 'The defense'} blocks ${who}'s shot`);
            popup('BLOCK', other(shooterSide), ball, 1.0);
          } else if (a.result === 'post') {
            log(shooterSide, 'miss', `${who} hits the post ${SHOT_TEXT[a.kind]}`);
            popup('POST', shooterSide, ball, 1.0);
            ball = W({ d: 2.6, y: a.aim * 2 });
            push(0.25);
          } else {
            log(shooterSide, 'miss', `${who} shoots wide ${SHOT_TEXT[a.kind]}`);
            popup('WIDE', shooterSide, ball, 1.0);
          }
          push(a.result === 'goal' ? 0.85 : 0.5);
          if (a.result === 'goal') {
            // after a goal the keeper takes the ball for the restart
            ball = W({ d: 20, y: 0 });
          }
          open = true;
          break;
        }
        default:
          break;
      }
    }

    fixClocks(t0, poss.start, poss.end);
    possEndT.push(t);
  }

  // Full time
  const tEnd = t;
  overlays.push({ t0: t + 0.3, t1: Infinity, kind: 'full' });
  curAtt = null;
  log(null, 'info', `Full time: ${m.home.short} ${score[0]}–${score[1]} ${m.away.short}`);
  push(1.5);
  fixClocks(tEnd, 3600, 3600);

  return { kfs, popups, overlays, logs, susp, possEndT, duration: t };
}

function actorOf(a: Action | undefined): string {
  if (!a) return '';
  switch (a.t) {
    case 'pass':
      return a.from;
    case 'shot':
      return a.by;
    case 'turnover':
      return a.by;
    case 'foul7m':
      return a.on;
    default:
      return '';
  }
}

/** Live stats up to and including possession index k. */
export function statsUpTo(m: MatchResult, k: number) {
  const team = {
    home: { goals: 0, shots: 0, sevenG: 0, sevenS: 0, fbGoals: 0, saves: 0, faced: 0, to: 0, steals: 0, susp: 0, timeouts: 0 },
    away: { goals: 0, shots: 0, sevenG: 0, sevenS: 0, fbGoals: 0, saves: 0, faced: 0, to: 0, steals: 0, susp: 0, timeouts: 0 },
  };
  const pl: Record<string, { goals: number; shots: number; saves: number; faced: number }> = {};
  const P = (id: string) => (pl[id] ??= { goals: 0, shots: 0, saves: 0, faced: 0 });
  for (let i = 0; i <= k && i < m.possessions.length; i++) {
    const poss: Possession = m.possessions[i];
    const att = team[poss.side];
    const def = team[poss.side === 'home' ? 'away' : 'home'];
    for (const a of poss.pre) if (a.t === 'timeout') team[a.side].timeouts++;
    for (const a of poss.actions) {
      if (a.t === 'shot') {
        att.shots++;
        P(a.by).shots++;
        if (a.kind === 'sevenMeter') att.sevenS++;
        if (a.result === 'goal') {
          att.goals++;
          P(a.by).goals++;
          if (a.kind === 'sevenMeter') att.sevenG++;
          if (a.kind === 'fastBreak' || a.kind === 'secondWave') att.fbGoals++;
        }
        if (a.gk && (a.result === 'goal' || a.result === 'save')) {
          def.faced++;
          P(a.gk).faced++;
          if (a.result === 'save') {
            def.saves++;
            P(a.gk).saves++;
          }
        }
      } else if (a.t === 'turnover') {
        att.to++;
        if (a.stolenBy) def.steals++;
      } else if (a.t === 'suspension') {
        team[a.side].susp++;
      }
    }
  }
  return { team, pl };
}
