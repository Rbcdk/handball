// Types for the engine's event log (mirrors app/Game/Engine/MatchEngine.php output).

export type Position = 'GK' | 'LW' | 'LB' | 'CB' | 'RB' | 'RW' | 'PV';

/** Court slots for field players. X7 = the extra attacker when playing 7-vs-6. */
export type FieldSlot = 'LW' | 'LB' | 'CB' | 'RB' | 'RW' | 'PV' | 'X7';

export interface Attributes {
  // Field skills (1-100)
  shooting: number;
  passing: number;
  technique: number;
  speed: number;
  stamina: number;
  jumping: number;
  defense: number;
  composure: number;
  // Goalkeeper skills (1-100)
  reflexes: number;
  positioning: number;
  sevenMeter: number;
}

export interface Player {
  id: string;
  name: string;
  number: number;
  position: Position;
  age: number;
  attrs: Attributes;
}

export type DefenseSystem = '6-0' | '5-1' | '3-2-1';
export type Tempo = 'slow' | 'normal' | 'fast';
export type AttackFocus = 'balanced' | 'wings' | 'backs' | 'pivot';
export type SevenVsSix = 'never' | 'whenShort' | 'whenBehind' | 'always';
export type Rotation = 'low' | 'normal' | 'high';

export interface Tactics {
  defense: DefenseSystem;
  tempo: Tempo;
  focus: AttackFocus;
  sevenVsSix: SevenVsSix;
  rotation: Rotation;
}

export interface Team {
  id: string;
  name: string;
  short: string;
  color: string;
  players: Player[];
  tactics: Tactics;
}

export type Side = 'home' | 'away';

export type ShotKind =
  | 'wing'
  | 'back9'
  | 'breakthrough'
  | 'pivot'
  | 'fastBreak'
  | 'secondWave'
  | 'sevenMeter'
  | 'rebound'
  | 'emptyGoal';

export type ShotResult = 'goal' | 'save' | 'miss' | 'post' | 'block';

export type TurnoverKind = 'steal' | 'badPass' | 'technical' | 'offensiveFoul' | 'passive';

/** A point in the "attacking frame": d = metres from the goal line being attacked,
 *  y = lateral metres from the centre line (negative = attackers' left side). */
export interface FramePoint {
  d: number;
  y: number;
}

export type Action =
  | { t: 'pass'; from: string; to: string }
  | {
      t: 'shot';
      by: string;
      kind: ShotKind;
      at: FramePoint;
      aim: number; // lateral y where the ball goes (goal is -1.5..1.5)
      result: ShotResult;
      gk: string | null;
      blocker?: string;
    }
  | { t: 'turnover'; by: string; kind: TurnoverKind; stolenBy?: string }
  | { t: 'foul7m'; on: string; by: string }
  | { t: 'suspension'; player: string; side: Side; red: boolean }
  | { t: 'sub'; side: Side; out: string; in: string }
  | { t: 'gkChange'; side: Side; out: string; in: string }
  | { t: 'timeout'; side: Side }
  | { t: 'extraAttacker'; side: Side; player: string };

export interface OnCourt {
  slot: FieldSlot;
  id: string;
}

export type PossessionKind = 'positional' | 'fastBreak' | 'secondWave' | 'emptyGoal';

export interface Possession {
  n: number;
  half: 1 | 2;
  side: Side; // attacking side
  start: number; // game clock seconds
  end: number;
  kind: PossessionKind;
  attackers: OnCourt[];
  attGk: string | null; // null when the attacking team plays 7-vs-6
  defenders: string[];
  /** Defending field players with their slots, as they lined up at the start. */
  defLineup: OnCourt[];
  defGk: string | null; // null when shooting at an empty goal
  defense: DefenseSystem;
  sevenVsSix: boolean;
  pre: Action[]; // timeouts, substitutions, keeper changes before the possession
  actions: Action[];
  score: [number, number]; // home, away after the possession
}

export interface PlayerStats {
  goals: number;
  shots: number;
  assists: number;
  sevenMGoals: number;
  sevenMShots: number;
  turnovers: number;
  steals: number;
  blocks: number;
  suspensions: number;
  saves: number; // goalkeepers
  shotsFaced: number; // goalkeepers
  secondsPlayed: number;
}

export interface TeamStats {
  goals: number;
  shots: number;
  sevenMGoals: number;
  sevenMShots: number;
  fastBreakGoals: number;
  saves: number;
  shotsFaced: number;
  turnovers: number;
  steals: number;
  suspensions: number;
  possessions: number;
  timeouts: number;
}

export interface MatchResult {
  seed: number;
  home: Team;
  away: Team;
  possessions: Possession[];
  score: [number, number];
  halftime: [number, number];
  teamStats: Record<Side, TeamStats>;
  playerStats: Record<string, PlayerStats>;
  /** Which side attacks the right-hand goal in the first half. */
  rightFirstHalf: Side;
}
