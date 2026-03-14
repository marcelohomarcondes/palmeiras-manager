<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();

$pdo->beginTransaction();

$pdo->exec("
CREATE TABLE IF NOT EXISTS players (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  shirt_number INTEGER,
  primary_position TEXT NOT NULL DEFAULT 'A DEFINIR',
  secondary_positions TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS matches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  season TEXT NOT NULL,
  competition TEXT NOT NULL,
  phase TEXT NOT NULL DEFAULT '',
  round TEXT NOT NULL DEFAULT '',
  match_date TEXT NOT NULL,       -- YYYY-MM-DD
  match_time TEXT NOT NULL DEFAULT '', -- HH:MM
  stadium TEXT NOT NULL DEFAULT '',
  referee TEXT NOT NULL DEFAULT '',
  home TEXT NOT NULL,
  away TEXT NOT NULL,
  kit_used TEXT NOT NULL DEFAULT '',
  weather TEXT NOT NULL DEFAULT '',
  home_score INTEGER,
  away_score INTEGER,
  notes TEXT NOT NULL DEFAULT ''
);

-- jogadores relacionados na partida (titulares/ banco) + posição do jogo
CREATE TABLE IF NOT EXISTS match_players (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  club_name TEXT NOT NULL,
  player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
  role TEXT NOT NULL CHECK(role IN ('STARTER','BENCH')),
  position TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  entered INTEGER NOT NULL DEFAULT 0, -- entrou em campo (para reservas)
  UNIQUE(match_id, club_name, player_id)
);

-- estatísticas imputadas ao fim do jogo (por jogador relacionado)
CREATE TABLE IF NOT EXISTS match_player_stats (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  club_name TEXT NOT NULL,
  player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
  goals_for INTEGER NOT NULL DEFAULT 0,
  goals_against INTEGER NOT NULL DEFAULT 0,
  assists INTEGER NOT NULL DEFAULT 0,
  yellow_cards INTEGER NOT NULL DEFAULT 0,
  red_cards INTEGER NOT NULL DEFAULT 0,
  rating REAL,          -- nota (0-10)
  motm INTEGER NOT NULL DEFAULT 0, -- melhor em campo
  UNIQUE(match_id, club_name, player_id)
);

-- substituições (até 5 + 1 extra prorrogação = controle manual livre)
CREATE TABLE IF NOT EXISTS substitutions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  club_name TEXT NOT NULL,
  player_out_id INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  player_in_id INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  minute INTEGER,              -- minuto (opcional)
  is_extra_time INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS trophies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  competition_name TEXT NOT NULL,
  season TEXT NOT NULL,
  achieved_at TEXT,
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS transfers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  season TEXT NOT NULL,
  type TEXT NOT NULL,
  athlete_name TEXT NOT NULL,
  club_origin TEXT NOT NULL DEFAULT '',
  club_destination TEXT NOT NULL DEFAULT '',
  value TEXT NOT NULL DEFAULT '',
  term TEXT NOT NULL DEFAULT '',
  grade TEXT NOT NULL DEFAULT '',
  extra_player_name TEXT NOT NULL DEFAULT '',
  shirt_number_assigned INTEGER,
  transaction_date TEXT NOT NULL, -- YYYY-MM-DD
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS injuries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
  injury_type TEXT NOT NULL,
  injured_part TEXT NOT NULL,
  injury_date TEXT NOT NULL,
  recovery_time TEXT NOT NULL,
  return_date TEXT,
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS lineup_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_name TEXT NOT NULL UNIQUE CHECK(template_name IN ('TITULAR','RESERVA')),
  formation TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS lineup_template_slots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER NOT NULL REFERENCES lineup_templates(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK(role IN ('STARTER','BENCH')),
  sort_order INTEGER NOT NULL,
  player_id INTEGER REFERENCES players(id) ON DELETE SET NULL,
  position TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_match_players_match ON match_players(match_id);
CREATE INDEX IF NOT EXISTS idx_match_stats_match ON match_player_stats(match_id);
CREATE INDEX IF NOT EXISTS idx_transfers_date ON transfers(transaction_date);
CREATE INDEX IF NOT EXISTS idx_injuries_date ON injuries(injury_date);
");

# Seed templates (TITULAR/RESERVA)
$pdo->exec("INSERT OR IGNORE INTO lineup_templates(template_name) VALUES ('TITULAR');");
$pdo->exec("INSERT OR IGNORE INTO lineup_templates(template_name) VALUES ('RESERVA');");

# Seed trophies list is fixed via UI; no need extra tables.

# Views: Palmeiras matches + opponent + gf/ga/result + mando
$pdo->exec("DROP VIEW IF EXISTS v_pal_matches;");
$pdo->exec("
CREATE VIEW v_pal_matches AS
SELECT
  m.id AS match_id,
  m.season,
  m.competition,
  m.phase,
  m.round,
  m.match_date,
  m.match_time,
  m.stadium,
  m.referee,
  m.kit_used,
  m.weather,
  m.home,
  m.away,
  m.home_score,
  m.away_score,
  CASE
    WHEN m.home = '" . app_club() . "' THEN m.away
    WHEN m.away = '" . app_club() . "' THEN m.home
    ELSE NULL
  END AS opponent,
  CASE
    WHEN m.home = '" . app_club() . "' THEN m.home_score
    WHEN m.away = '" . app_club() . "' THEN m.away_score
    ELSE NULL
  END AS gf,
  CASE
    WHEN m.home = '" . app_club() . "' THEN m.away_score
    WHEN m.away = '" . app_club() . "' THEN m.home_score
    ELSE NULL
  END AS ga,
  CASE
    WHEN m.home = '" . app_club() . "' THEN 'HOME'
    WHEN m.away = '" . app_club() . "' THEN 'AWAY'
    ELSE NULL
  END AS venue_side,
  CASE
    WHEN (CASE WHEN m.home = '" . app_club() . "' THEN m.home_score WHEN m.away = '" . app_club() . "' THEN m.away_score ELSE NULL END) IS NULL THEN NULL
    WHEN (CASE WHEN m.home = '" . app_club() . "' THEN m.home_score WHEN m.away = '" . app_club() . "' THEN m.away_score ELSE NULL END) >
         (CASE WHEN m.home = '" . app_club() . "' THEN m.away_score WHEN m.away = '" . app_club() . "' THEN m.home_score ELSE NULL END) THEN 'W'
    WHEN (CASE WHEN m.home = '" . app_club() . "' THEN m.home_score WHEN m.away = '" . app_club() . "' THEN m.away_score ELSE NULL END) =
         (CASE WHEN m.home = '" . app_club() . "' THEN m.away_score WHEN m.away = '" . app_club() . "' THEN m.home_score ELSE NULL END) THEN 'D'
    ELSE 'L'
  END AS result
FROM matches m;
");

# View: player match stats joined with match meta
$pdo->exec("DROP VIEW IF EXISTS v_player_match_stats;");
$pdo->exec("
CREATE VIEW v_player_match_stats AS
SELECT
  m.id AS match_id,
  m.season,
  m.competition,
  m.match_date,
  m.home,
  m.away,
  mp.club_name,
  p.name AS player_name,
  p.shirt_number,
  mp.role,
  mp.entered,
  mp.position,
  COALESCE(s.goals_for,0) AS goals_for,
  COALESCE(s.goals_against,0) AS goals_against,
  COALESCE(s.assists,0) AS assists,
  COALESCE(s.yellow_cards,0) AS yellow_cards,
  COALESCE(s.red_cards,0) AS red_cards,
  s.rating,
  COALESCE(s.motm,0) AS motm
FROM match_players mp
JOIN matches m ON m.id=mp.match_id
JOIN players p ON p.id=mp.player_id
LEFT JOIN match_player_stats s
  ON s.match_id=mp.match_id AND s.club_name=mp.club_name AND s.player_id=mp.player_id;
");

$pdo->commit();

echo "OK: banco/migrations/views criados em: " . db_path() . PHP_EOL;
