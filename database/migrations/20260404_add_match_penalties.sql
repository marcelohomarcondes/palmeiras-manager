-- Migration: Add match_penalties table
-- Date: 2026-04-04
-- Description: Adiciona tabela para armazenar disputas de pênaltis

CREATE TABLE IF NOT EXISTS match_penalties (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id),
  match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  team TEXT NOT NULL CHECK(team IN ('HOME', 'AWAY')),
  player_name TEXT NOT NULL,
  order_number INTEGER NOT NULL,
  scored INTEGER NOT NULL CHECK(scored IN (0, 1)),
  UNIQUE(match_id, team, order_number)
);

CREATE INDEX IF NOT EXISTS idx_match_penalties_match ON match_penalties(match_id);
CREATE INDEX IF NOT EXISTS idx_match_penalties_user_id ON match_penalties(user_id);
