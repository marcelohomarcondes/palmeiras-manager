<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$club = app_club();

echo "== Rebuild de VIEWS (Palmeiras-only) para schema atual de matches ==\n";

$pdo->exec("BEGIN");
try {
  // 1) Derruba views antigas (se existirem)
  $views = [
    'v_pm_vs_opponents',
    'v_pm_top_blowouts',
    'v_pm_matches',
    'v_player_match_stats',
    'v_pal_matches',
  ];

  foreach ($views as $v) {
    $pdo->exec("DROP VIEW IF EXISTS $v");
  }

  // 2) View base: partidas do Palmeiras com perspectiva GF/GA, mando, resultado
  $pdo->exec("
    CREATE VIEW v_pal_matches AS
    SELECT
      m.id,
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

      CASE WHEN m.home = '$club' THEN m.away ELSE m.home END AS opponent,
      CASE WHEN m.home = '$club' THEN 'Casa' ELSE 'Fora' END AS venue,

      CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END AS gf,
      CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END AS ga,

      (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END)
      -
      (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) AS gd,

      CASE
        WHEN m.home_score IS NULL OR m.away_score IS NULL THEN NULL
        WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END)
           > (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'W'
        WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END)
           = (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'D'
        ELSE 'L'
      END AS result
    FROM matches m
    WHERE m.home = '$club' OR m.away = '$club'
  ");

  // 3) Consolidado vs adversários
  $pdo->exec("
    CREATE VIEW v_pm_vs_opponents AS
    SELECT
      opponent,
      COUNT(*) AS games,
      SUM(CASE WHEN result='W' THEN 1 ELSE 0 END) AS wins,
      SUM(CASE WHEN result='D' THEN 1 ELSE 0 END) AS draws,
      SUM(CASE WHEN result='L' THEN 1 ELSE 0 END) AS losses,
      SUM(COALESCE(gf,0)) AS goals_for,
      SUM(COALESCE(ga,0)) AS goals_against,
      SUM(COALESCE(gd,0)) AS goal_diff,
      ROUND(
        CASE WHEN COUNT(*)=0 THEN 0
             ELSE (SUM(CASE WHEN result='W' THEN 3 WHEN result='D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*) * 3.0)
        END
      , 2) AS pct
    FROM v_pal_matches
    WHERE result IS NOT NULL
    GROUP BY opponent
  ");

  // 4) Top goleadas por saldo (gd)
  $pdo->exec("
    CREATE VIEW v_pm_top_blowouts AS
    SELECT
      match_date AS date,
      season,
      competition,
      opponent,
      venue,
      gf,
      ga,
      gd,
      result
    FROM v_pal_matches
    WHERE result IS NOT NULL
    ORDER BY gd DESC, gf DESC, match_date DESC
    LIMIT 10
  ");

  // 5) View auxiliar de matches (se você usa em relatórios)
  $pdo->exec("
    CREATE VIEW v_pm_matches AS
    SELECT
      id,
      season,
      competition,
      match_date,
      opponent,
      venue,
      gf,
      ga,
      gd,
      result
    FROM v_pal_matches
    WHERE result IS NOT NULL
  ");

  // 6) View opcional: estatísticas por jogador (se estiver usando no futuro)
  // (Mantida “neutra” para não quebrar nada; depende de match_player_stats)
  $pdo->exec("
    CREATE VIEW v_player_match_stats AS
    SELECT
      s.match_id,
      s.player_id,
      s.is_starter,
      s.played,
      s.goals_for,
      s.goals_against,
      s.assists,
      s.yellow_cards,
      s.red_cards
    FROM match_player_stats s
  ");

  $pdo->exec("COMMIT");
  echo "OK: views recriadas.\n";
} catch (Throwable $e) {
  $pdo->exec("ROLLBACK");
  throw $e;
}
