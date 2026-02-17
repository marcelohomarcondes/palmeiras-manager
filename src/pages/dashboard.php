<?php
declare(strict_types=1);
$pdo = db();

$games = (int)scalar($pdo, "SELECT COUNT(*) FROM matches");
#$players = (int)scalar($pdo, "SELECT COUNT(*) FROM players WHERE is_active=1");
$players = (int) q(
  $pdo,
  "SELECT COUNT(*) FROM players WHERE is_active=1 AND club_name = ? COLLATE NOCASE",
  [app_club()]
)->fetchColumn();
$inj = (int)scalar($pdo, "SELECT COUNT(*) FROM injuries");
$tr = (int)scalar($pdo, "SELECT COUNT(*) FROM transfers");
$troph = (int)scalar($pdo, "SELECT COUNT(*) FROM trophies");

render_header('Dashboard');

echo '<div class="row g-3">';
$cards = [
  ['Partidas', $games, 'bi-calendar3', '/?page=matches'],
  ['Elenco ativo', $players, 'bi-people', '/?page=players'],
  ['Transferências', $tr, 'bi-arrow-left-right', '/?page=transfers'],
  ['Lesões', $inj, 'bi-bandaid', '/?page=injuries'],
  ['Troféus', $troph, 'bi-trophy', '/?page=trophies'],
];

foreach ($cards as [$label,$val,$icon,$href]) {
  echo '<div class="col-md-4 col-lg-3">';
  echo '<a class="text-decoration-none" href="' . h($href) . '">';
  echo '<div class="card card-soft p-3 h-100">';
  echo '<div class="d-flex align-items-center justify-content-between">';
  echo '<div><div class="text-muted small">' . h($label) . '</div><div class="fs-3 fw-bold">' . (int)$val . '</div></div>';
  echo '<i class="bi ' . h($icon) . ' fs-2 text-primary"></i>';
  echo '</div></div></a></div>';
}
echo '</div>';

echo '<div class="row g-3 mt-1">';
echo '<div class="col-lg-7">';
echo '<div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Últimas partidas</div>';

$rows = q($pdo, "SELECT id, match_date, competition, home, away, home_score, away_score FROM matches ORDER BY match_date DESC, id DESC LIMIT 8")->fetchAll();
if (!$rows) {
  echo '<div class="text-muted">Sem partidas cadastradas.</div>';
} else {
  echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
  echo '<thead><tr><th>Data</th><th>Competição</th><th>Jogo</th><th class="text-end">Placar</th><th></th></tr></thead><tbody>';
  foreach ($rows as $r) {
    $pl = ($r['home_score'] === null || $r['away_score'] === null) ? '-' : ((int)$r['home_score'] . ' - ' . (int)$r['away_score']);
    echo '<tr>';
    echo '<td>' . h($r['match_date']) . '</td>';
    echo '<td>' . h($r['competition']) . '</td>';
    echo '<td>' . h($r['home']) . ' x ' . h($r['away']) . '</td>';
    echo '<td class="text-end fw-bold">' . h($pl) . '</td>';
    echo '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/?page=match&id=' . (int)$r['id'] . '">Abrir</a></td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
}
echo '</div></div>';

echo '<div class="col-lg-5">';
echo '<div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Atalhos</div>';
echo '<div class="d-grid gap-2">';
echo '<a class="btn btn-success" href="index.php?page=create_match">Cadastrar partida</a>';
echo '<a class="btn btn-outline-primary" href="/?page=players">Gerenciar elenco</a>';
echo '<a class="btn btn-outline-secondary" href="/?page=stats">Relatórios estatísticos</a>';
echo '<a class="btn btn-outline-secondary" href="/?page=opponents">Consolidado vs adversários</a>';
echo '</div>';
echo '<div class="text-muted small mt-3">Se algo “sumir”, rode novamente: <span class="mono">php src/migrate.php</span></div>';
echo '</div></div>';

echo '</div>';

render_footer();

