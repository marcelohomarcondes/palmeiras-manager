<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Relatórios');

$season = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));

$params = [];
$where = [];

if ($season !== '') { $where[] = "season = ?"; $params[] = $season; }
if ($competition !== '') { $where[] = "competition = ?"; $params[] = $competition; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$total = (int)scalar($pdo, "SELECT COUNT(*) FROM v_pm_matches $whereSql", $params);

echo '<div class="card-soft">';

echo '<form class="row g-2 mb-3" method="get">';
echo '<input type="hidden" name="page" value="stats">';
echo '<div class="col-md-3"><label class="form-label">Temporada</label><input class="form-control" name="season" value="'.h($season).'" placeholder="ex: 2026"></div>';
echo '<div class="col-md-7"><label class="form-label">Campeonato</label><input class="form-control" name="competition" value="'.h($competition).'" placeholder="ex: Brasileirão"></div>';
echo '<div class="col-md-2 d-grid align-items-end"><button class="btn btn-success" style="margin-top: 2rem;">Aplicar</button></div>';
echo '</form>';

if ($total === 0) {
  echo '<div class="muted">Cadastre partidas (Palmeiras vs adversário) para liberar as estatísticas.</div>';
  echo '</div>';
  render_footer();
  exit;
}

// Top 10 goleadas (com filtros)
$top = q($pdo, "
SELECT match_date, season, competition, opponent, venue_side, gf, ga, gd, result
FROM v_pm_matches
$whereSql
ORDER BY gd DESC, gf DESC, match_date DESC, id DESC
LIMIT 10
", $params)->fetchAll();

// Maior sequência invicta (com filtros)
$bestUnbeaten = q($pdo, "
WITH x AS (
  SELECT *, CASE WHEN result='L' THEN 1 ELSE 0 END AS is_loss
  FROM v_pm_matches
  $whereSql
),
g AS (
  SELECT *, SUM(is_loss) OVER (ORDER BY match_date, id) AS grp
  FROM x
),
seq AS (
  SELECT grp,
         MIN(match_date) AS start_date,
         MAX(match_date) AS end_date,
         COUNT(*) AS games,
         SUM(CASE WHEN result='W' THEN 1 ELSE 0 END) AS wins,
         SUM(CASE WHEN result='D' THEN 1 ELSE 0 END) AS draws,
         SUM(gf) AS gf,
         SUM(ga) AS ga,
         SUM(gd) AS gd
  FROM g
  WHERE result <> 'L'
  GROUP BY grp
)
SELECT * FROM seq
ORDER BY games DESC, gd DESC
LIMIT 1
", $params)->fetch(PDO::FETCH_ASSOC) ?: null;

// Maior sequência sem vitória (com filtros)
$worstNoWin = q($pdo, "
WITH x AS (
  SELECT *, CASE WHEN result='W' THEN 1 ELSE 0 END AS is_win
  FROM v_pm_matches
  $whereSql
),
g AS (
  SELECT *, SUM(is_win) OVER (ORDER BY match_date, id) AS grp
  FROM x
),
seq AS (
  SELECT grp,
         MIN(match_date) AS start_date,
         MAX(match_date) AS end_date,
         COUNT(*) AS games,
         SUM(CASE WHEN result='D' THEN 1 ELSE 0 END) AS draws,
         SUM(CASE WHEN result='L' THEN 1 ELSE 0 END) AS losses
  FROM g
  WHERE result <> 'W'
  GROUP BY grp
)
SELECT * FROM seq
ORDER BY games DESC
LIMIT 1
", $params)->fetch(PDO::FETCH_ASSOC) ?: null;

echo '<div class="row g-3">';

echo '<div class="col-lg-6">';
echo '<div class="card-soft"><h5>Maior sequência invicta</h5>';
if ($bestUnbeaten) {
  echo '<div><b>'.(int)$bestUnbeaten['games'].'</b> jogos — '.h((string)$bestUnbeaten['start_date']).' até '.h((string)$bestUnbeaten['end_date']).'</div>';
  echo '<div class="muted">V: '.(int)$bestUnbeaten['wins'].' | E: '.(int)$bestUnbeaten['draws'].' | GP: '.(int)$bestUnbeaten['gf'].' | GC: '.(int)$bestUnbeaten['ga'].' | SG: '.(int)$bestUnbeaten['gd'].'</div>';
} else {
  echo '<div class="muted">Sem dados suficientes.</div>';
}
echo '</div></div>';

echo '<div class="col-lg-6">';
echo '<div class="card-soft"><h5>Maior sequência sem vitória</h5>';
if ($worstNoWin) {
  echo '<div><b>'.(int)$worstNoWin['games'].'</b> jogos — '.h((string)$worstNoWin['start_date']).' até '.h((string)$worstNoWin['end_date']).'</div>';
  echo '<div class="muted">E: '.(int)$worstNoWin['draws'].' | D: '.(int)$worstNoWin['losses'].'</div>';
} else {
  echo '<div class="muted">Sem dados suficientes.</div>';
}
echo '</div></div>';

echo '<div class="col-12">';
echo '<div class="card-soft"><h5>Top 10 goleadas (maior saldo)</h5>';
echo '<div class="table-responsive"><table class="table align-middle">';
echo '<thead><tr>
  <th>Data</th><th>Temporada</th><th>Campeonato</th><th>Adversário</th><th>Mando</th>
  <th class="text-end">GF</th><th class="text-end">GA</th><th class="text-end">SG</th><th>Res</th>
</tr></thead><tbody>';

foreach ($top as $r) {
  echo '<tr>';
  echo '<td>'.h((string)$r['match_date']).'</td>';
  echo '<td>'.h((string)$r['season']).'</td>';
  echo '<td>'.h((string)$r['competition']).'</td>';
  echo '<td>'.h((string)$r['opponent']).'</td>';
  echo '<td>'.(($r['venue_side']==='HOME') ? 'Casa' : 'Fora').'</td>';
  echo '<td class="text-end">'.(int)$r['gf'].'</td>';
  echo '<td class="text-end">'.(int)$r['ga'].'</td>';
  echo '<td class="text-end">'.(int)$r['gd'].'</td>';
  echo '<td>'.h((string)$r['result']).'</td>';
  echo '</tr>';
}

echo '</tbody></table></div></div>';
echo '</div>'; // row

echo '</div>'; // card-soft
render_footer();

