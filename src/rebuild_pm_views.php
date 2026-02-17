<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$club = app_club();

echo "== Recriando views PM ==\n";

$pdo->exec("BEGIN");
try {
  // Derruba as views que podem estar inconsistentes
  $pdo->exec("DROP VIEW IF EXISTS v_pm_vs_opponents");
  $pdo->exec("DROP VIEW IF EXISTS v_pm_top_blowouts");
  $pdo->exec("DROP VIEW IF EXISTS v_pm_matches");
  $pdo->exec("DROP VIEW IF EXISTS v_pal_matches");
  $pdo->exec("DROP VIEW IF EXISTS v_player_match_stats");

  // Partidas só do Palmeiras (base)
  $pdo->exec("
    CREATE VIEW v_pm_matches AS
    SELECT
      m.*,
      CASE
        WHEN m.home = '$club' THEN m.away
        ELSE m.home
      END AS opponent,
      CASE
        WHEN m.home = '$club' THEN 'Casa'
        ELSE 'Fora'
      END AS venue,
      CASE
        WHEN m.home = '$club' THEN m.home_score
        ELSE m.away_score
      END AS gf,
      CASE
        WHEN m.home = '$club' THEN m.away_score
        ELSE m.home_score
      END AS ga,
      CASE
        WHEN m.home_score IS NULL OR m.away_score IS NULL THEN NULL
        WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END) >
             (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'W'
        WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END) =
             (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'D'
        ELSE 'L'
      END AS result,
      CASE
        WHEN m.home_score IS NULL OR m.away_score IS NULL THEN NULL
        ELSE (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END)
           - (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END)
      END AS goal_diff
    FROM matches m
    WHERE m.home = '$club' OR m.away = '$club'
  ");

  // Consolidado vs adversários (gera coluna opponent corretamente)
  $pdo->exec("
    CREATE VIEW v_pm_vs_opponents AS
    SELECT
      opponent,
      COUNT(*) AS games,
      SUM(CASE WHEN result = 'W' THEN 1 ELSE 0 END) AS wins,
      SUM(CASE WHEN result = 'D' THEN 1 ELSE 0 END) AS draws,
      SUM(CASE WHEN result = 'L' THEN 1 ELSE 0 END) AS losses,
      SUM(gf) AS goals_for,
      SUM(ga) AS goals_against,
      SUM(goal_diff) AS goal_diff,
      ROUND(
        (SUM(CASE WHEN result = 'W' THEN 3 WHEN result = 'D' THEN 1 ELSE 0 END) * 100.0)
        / (COUNT(*) * 3.0),
      2) AS pct
    FROM v_pm_matches
    WHERE gf IS NOT NULL AND ga IS NOT NULL
    GROUP BY opponent
  ");

  // Top goleadas (por saldo)
  $pdo->exec("
    CREATE VIEW v_pm_top_blowouts AS
    SELECT
      match_date,
      season,
      competition,
      opponent,
      venue,
      gf,
      ga,
      goal_diff,
      result
    FROM v_pm_matches
    WHERE gf IS NOT NULL AND ga IS NOT NULL
    ORDER BY goal_diff DESC, gf DESC, match_date DESC
  ");

  // View de stats do jogador (alinhada ao schema que você mostrou)
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

  // Mantém compatibilidade se algo ainda usar v_pal_matches
  $pdo->exec("
    CREATE VIEW v_pal_matches AS
    SELECT * FROM v_pm_matches
  ");

  $pdo->exec("COMMIT");
  echo "OK: views PM recriadas.\n";
} catch (Throwable $e) {
  $pdo->exec("ROLLBACK");
  throw $e;
}
