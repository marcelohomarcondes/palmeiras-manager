<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = db();
$club = app_club();

function exec_sql(PDO $pdo, string $sql): void {
  $pdo->exec($sql);
}

/**
 * View base: transforma qualquer partida em “Palmeiras vs Adversário”
 * e já calcula gols pró/contra + resultado (W/D/L).
 */
exec_sql($pdo, "DROP VIEW IF EXISTS v_pm_matches");
exec_sql($pdo, "
CREATE VIEW v_pm_matches AS
SELECT
  m.id,
  m.season,
  m.competition,
  m.match_date,

  CASE WHEN m.home = '$club' THEN m.away ELSE m.home END AS opponent,
  CASE WHEN m.home = '$club' THEN 'HOME' ELSE 'AWAY' END AS venue_side,

  CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END AS gf,
  CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END AS ga,

  (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END) -
  (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) AS gd,

  CASE
    WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END) >
         (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'W'
    WHEN (CASE WHEN m.home = '$club' THEN m.home_score ELSE m.away_score END) <
         (CASE WHEN m.home = '$club' THEN m.away_score ELSE m.home_score END) THEN 'L'
    ELSE 'D'
  END AS result
FROM matches m
WHERE m.home = '$club' OR m.away = '$club';
");

/**
 * Consolidado vs adversários
 */
exec_sql($pdo, "DROP VIEW IF EXISTS v_pm_vs_opponents");
exec_sql($pdo, "
CREATE VIEW v_pm_vs_opponents AS
SELECT
  opponent,
  COUNT(*) AS games,
  SUM(CASE WHEN result='W' THEN 1 ELSE 0 END) AS wins,
  SUM(CASE WHEN result='D' THEN 1 ELSE 0 END) AS draws,
  SUM(CASE WHEN result='L' THEN 1 ELSE 0 END) AS losses,
  SUM(gf) AS goals_for,
  SUM(ga) AS goals_against,
  SUM(gd) AS goal_diff,
  ROUND(
    CASE WHEN COUNT(*)=0 THEN 0
         ELSE (SUM(CASE WHEN result='W' THEN 3 WHEN result='D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*)*3)
    END
  , 2) AS pct
FROM v_pm_matches
GROUP BY opponent;
");

/**
 * Top goleadas (por saldo)
 */
exec_sql($pdo, "DROP VIEW IF EXISTS v_pm_top_blowouts");
exec_sql($pdo, "
CREATE VIEW v_pm_top_blowouts AS
SELECT
  match_date,
  season,
  competition,
  opponent,
  venue_side,
  gf, ga, gd,
  result
FROM v_pm_matches
ORDER BY gd DESC, gf DESC, match_date DESC;
");

echo "OK: views criadas/atualizadas.\n";
