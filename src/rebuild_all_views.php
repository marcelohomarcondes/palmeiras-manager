<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Recriando views ==\n";

$pdo->exec("BEGIN");

try {

  // Remove antigas
  $pdo->exec("DROP VIEW IF EXISTS v_pal_matches");
  $pdo->exec("DROP VIEW IF EXISTS v_player_match_stats");

  // View de partidas do Palmeiras
  $pdo->exec("
    CREATE VIEW v_pal_matches AS
    SELECT *
    FROM matches
    WHERE home = '" . app_club() . "'
       OR away = '" . app_club() . "'
  ");

  // View estatísticas de jogadores (SEM is_starter)
  $pdo->exec("
    CREATE VIEW v_player_match_stats AS
    SELECT
      s.id,
      s.match_id,
      s.club_name,
      s.player_id,
      s.goals_for,
      s.goals_against,
      s.assists,
      s.yellow_cards,
      s.red_cards,
      s.rating,
      s.motm
    FROM match_player_stats s
  ");

  $pdo->exec("COMMIT");

  echo "OK: views recriadas com sucesso.\n";

} catch (Throwable $e) {
  $pdo->exec("ROLLBACK");
  throw $e;
}
