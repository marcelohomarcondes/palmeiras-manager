<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Vs Adversários');

$q = trim((string)($_GET['q'] ?? ''));
$season = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));

// Se não houver filtro, usa a view pronta (mais rápida)
if ($q === '' && $season === '' && $competition === '') {
  $rows = q($pdo, "SELECT opponent, games, wins, draws, losses, goals_for, goals_against, goal_diff, pct
                   FROM v_pm_vs_opponents
                   ORDER BY pct DESC, games DESC, goal_diff DESC, goals_for DESC, opponent ASC")->fetchAll();

  echo '<div class="card-soft">';
  echo '<div class="muted mb-2">Consolidado automático baseado nos jogos do Palmeiras.</div>';

  if (!$rows) {
    echo '<div class="muted">Nenhuma partida cadastrada ainda.</div></div>';
    render_footer(); exit;
  }

  echo '<div class="table-responsive"><table class="table align-middle">';
  echo '<thead><tr>
    <th>Adversário</th>
    <th class="text-end">J</th><th class="text-end">V</th><th class="text-end">E</th><th class="text-end">D</th>
    <th class="text-end">GP</th><th class="text-end">GC</th><th class="text-end">SG</th><th class="text-end">% AP</th>
  </tr></thead><tbody>';

  foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>'.h((string)$r['opponent']).'</td>';
    echo '<td class="text-end">'.(int)$r['games'].'</td>';
    echo '<td class="text-end">'.(int)$r['wins'].'</td>';
    echo '<td class="text-end">'.(int)$r['draws'].'</td>';
    echo '<td class="text-end">'.(int)$r['losses'].'</td>';
    echo '<td class="text-end">'.(int)$r['goals_for'].'</td>';
    echo '<td class="text-end">'.(int)$r['goals_against'].'</td>';
    echo '<td class="text-end">'.(int)$r['goal_diff'].'</td>';
    echo '<td class="text-end">'.number_format((float)$r['pct'], 2, ',', '.').'%</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div></div>';
  render_footer(); exit;
}

// Com filtros: agrega em cima de v_pm_matches
$where = [];
$params = [];

if ($q !== '') { $where[] = "opponent LIKE ?"; $params[] = "%$q%"; }
if ($season !== '') { $where[] = "season = ?"; $params[] = $season; }
if ($competition !== '') { $where[] = "competition = ?"; $params[] = $competition; }

$sql = "
SELECT
  opponent,
  COUNT(*) AS games,
  SUM(CASE WHEN result='W' THEN 1 ELSE 0 END) AS wins,
  SUM(CASE WHEN result='D' THEN 1 ELSE 0 END) AS draws,
  SUM(CASE WHEN result='L' THEN 1 ELSE 0 END) AS losses,
  SUM(gf) AS goals_for,
  SUM(ga) AS goals_against,
  SUM(gd) AS goal_diff,
  ROUND((SUM(CASE WHEN result='W' THEN 3 WHEN result='D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*)*3), 2) AS pct
FROM v_pm_matches
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " GROUP BY opponent
          ORDER BY pct DESC, games DESC, goal_diff DESC, goals_for DESC, opponent ASC";

$rows = q($pdo, $sql, $params)->fetchAll();

echo '<div class="card-soft">';
echo '<form class="row g-2 mb-3" method="get">';
echo '<input type="hidden" name="page" value="opponents">';
echo '<div class="col-md-5"><input class="form-control" name="q" placeholder="Buscar adversário..." value="'.h($q).'"></div>';
echo '<div class="col-md-3"><input class="form-control" name="competition" placeholder="Campeonato (ex: Brasileirão)" value="'.h($competition).'"></div>';
echo '<div class="col-md-2"><input class="form-control" name="season" placeholder="Temporada" value="'.h($season).'"></div>';
echo '<div class="col-md-2 d-grid"><button class="btn btn-primary">Aplicar</button></div>';
echo '</form>';

if (!$rows) {
  echo '<div class="muted">Sem resultados para os filtros informados.</div></div>';
  render_footer(); exit;
}

echo '<div class="table-responsive"><table class="table align-middle">';
echo '<thead><tr>
  <th>Adversário</th>
  <th class="text-end">J</th><th class="text-end">V</th><th class="text-end">E</th><th class="text-end">D</th>
  <th class="text-end">GP</th><th class="text-end">GC</th><th class="text-end">SG</th><th class="text-end">% AP</th>
</tr></thead><tbody>';

foreach ($rows as $r) {
  echo '<tr>';
  echo '<td>'.h((string)$r['opponent']).'</td>';
  echo '<td class="text-end">'.(int)$r['games'].'</td>';
  echo '<td class="text-end">'.(int)$r['wins'].'</td>';
  echo '<td class="text-end">'.(int)$r['draws'].'</td>';
  echo '<td class="text-end">'.(int)$r['losses'].'</td>';
  echo '<td class="text-end">'.(int)$r['goals_for'].'</td>';
  echo '<td class="text-end">'.(int)$r['goals_against'].'</td>';
  echo '<td class="text-end">'.(int)$r['goal_diff'].'</td>';
  echo '<td class="text-end">'.number_format((float)$r['pct'], 2, ',', '.').'%</td>';
  echo '</tr>';
}

echo '</tbody></table></div></div>';
render_footer();

