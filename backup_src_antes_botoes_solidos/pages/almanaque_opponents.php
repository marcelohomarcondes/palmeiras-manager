<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Almanaque • Adversários');

$q           = trim((string)($_GET['q'] ?? ''));
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));
$opponent    = trim((string)($_GET['opponent'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? 'games'));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

function alm_build_url(array $overrides = []): string {
  $params = array_merge($_GET, ['page' => 'almanaque_opponents'], $overrides);
  foreach ($params as $k => $v) {
    if ($v === null || $v === '') {
      unset($params[$k]);
    }
  }
  return 'index.php?' . http_build_query($params);
}

function alm_sort_link(string $column, string $label, string $currentSort, string $currentDir): string {
  $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
  $arrow = '';

  if ($currentSort === $column) {
    $arrow = $currentDir === 'asc' ? ' ▼' : ' ▲';
  }

  $url = alm_build_url([
    'sort' => $column,
    'dir'  => $nextDir,
  ]);

  return '<a href="' . h($url) . '">' . h($label) . $arrow . '</a>';
}

function alm_fmt_pct($v): string {
  return number_format((float)$v, 2, ',', '.') . '%';
}

function alm_fmt_date_br(?string $date): string {
  if (!$date) return '-';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : h($date);
}

$sortMap = [
  'opponent'      => 'opponent',
  'name'          => 'opponent',
  'games'         => 'games',
  'wins'          => 'wins',
  'draws'         => 'draws',
  'losses'        => 'losses',
  'goals_for'     => 'goals_for',
  'goals_against' => 'goals_against',
  'goal_diff'     => 'goal_diff',
  'pct'           => 'pct',
  'j'             => 'games',
  'v'             => 'wins',
  'e'             => 'draws',
  'd'             => 'losses',
  'gp'            => 'goals_for',
  'gc'            => 'goals_against',
  'sg'            => 'goal_diff',
  'ap'            => 'pct',
];

$orderCol = $sortMap[$sort] ?? 'games';
$orderDir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

$seasonOptions = q(
  $pdo,
  "SELECT DISTINCT season
   FROM v_pm_matches
   WHERE COALESCE(TRIM(season), '') <> ''
   ORDER BY season DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$competitionOptions = q(
  $pdo,
  "SELECT DISTINCT competition
   FROM v_pm_matches
   WHERE COALESCE(TRIM(competition), '') <> ''
   ORDER BY competition ASC"
)->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| DETALHE DO ADVERSÁRIO
|--------------------------------------------------------------------------
*/
if ($opponent !== '') {
  $where = ["opponent = ?"];
  $params = [$opponent];

  if ($season !== '') {
    $where[] = "season = ?";
    $params[] = $season;
  }

  if ($competition !== '') {
    $where[] = "competition = ?";
    $params[] = $competition;
  }

  $sqlSummary = "
    SELECT
      opponent,
      COUNT(*) AS games,
      SUM(CASE WHEN result = 'W' THEN 1 ELSE 0 END) AS wins,
      SUM(CASE WHEN result = 'D' THEN 1 ELSE 0 END) AS draws,
      SUM(CASE WHEN result = 'L' THEN 1 ELSE 0 END) AS losses,
      SUM(gf) AS goals_for,
      SUM(ga) AS goals_against,
      SUM(gf) - SUM(ga) AS goal_diff,
      ROUND(
        (SUM(CASE WHEN result = 'W' THEN 3 WHEN result = 'D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*) * 3),
        2
      ) AS pct
    FROM v_pm_matches
    WHERE " . implode(' AND ', $where) . "
    GROUP BY opponent
  ";

  $summary = q($pdo, $sqlSummary, $params)->fetch();

  echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
  echo '  <div>';
  echo '    <div class="muted">Resumo detalhado do adversário</div>';
  echo '    <h3 class="mb-0">' . h($opponent) . '</h3>';
  echo '  </div>';
  echo '  <div>';
  echo '    <a class="btn btn-outline-secondary" href="' . h(alm_build_url([
            'opponent' => null,
            'sort'     => $sort,
            'dir'      => $dir,
          ])) . '">Voltar ao consolidado</a>';
  echo '  </div>';
  echo '</div>';

  echo '<div class="card-soft mb-3">';
  echo '  <form method="get" class="p-3">';
  echo '    <input type="hidden" name="page" value="almanaque_opponents">';
  echo '    <input type="hidden" name="opponent" value="' . h($opponent) . '">';
  echo '    <div class="row g-2 align-items-end">';
  echo '      <div class="col-12 col-md-3">';
  echo '        <label class="form-label">Temporada</label>';
  echo '        <select class="form-select" name="season">';
  echo '          <option value="">Todas</option>';
  foreach ($seasonOptions as $opt) {
    $opt = (string)$opt;
    echo '          <option value="' . h($opt) . '"' . ($season === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
  }
  echo '        </select>';
  echo '      </div>';
  echo '      <div class="col-12 col-md-7">';
  echo '        <label class="form-label">Campeonato</label>';
  echo '        <select class="form-select" name="competition">';
  echo '          <option value="">Todos</option>';
  foreach ($competitionOptions as $opt) {
    $opt = (string)$opt;
    echo '          <option value="' . h($opt) . '"' . ($competition === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
  }
  echo '        </select>';
  echo '      </div>';
  echo '      <div class="col-12 col-md-1 d-grid">';
  echo '        <button class="btn btnbtn-primary btn-primary" type="submit">Aplicar</button>';
  echo '      </div>';
  echo '      <div class="col-12 col-md-1 d-grid">';
  echo '        <a class="btn btn-outline-secondary" href="' . h(alm_build_url([
            'competition' => null,
            'season'      => null,
          ])) . '">Limpar</a>';
  echo '      </div>';
  echo '    </div>';
  echo '  </form>';

  if (!$summary) {
    echo '  <div class="px-3 pb-3 muted">Nenhuma partida encontrada para este adversário com os filtros informados.</div>';
    echo '</div>';
    render_footer();
    exit;
  }

  echo '  <div class="table-responsive">';
  echo '    <table class="table align-middle mb-0">';
  echo '      <thead><tr>
            <th class="text-center">J</th>
            <th class="text-center">V</th>
            <th class="text-center">E</th>
            <th class="text-center">D</th>
            <th class="text-center">GP</th>
            <th class="text-center">GC</th>
            <th class="text-center">SG</th>
            <th class="text-center">% AP</th>
          </tr></thead>';
  echo '      <tbody><tr>';
  echo '        <td class="text-center">' . (int)$summary['games'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['wins'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['draws'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['losses'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['goals_for'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['goals_against'] . '</td>';
  echo '        <td class="text-center">' . (int)$summary['goal_diff'] . '</td>';
  echo '        <td class="text-center">' . alm_fmt_pct($summary['pct']) . '</td>';
  echo '      </tr></tbody>';
  echo '    </table>';
  echo '  </div>';
  echo '</div>';

  $sqlMatches = "
    SELECT
      id,
      season,
      competition,
      match_date,
      home,
      away,
      gf,
      ga,
      result
    FROM v_pm_matches
    WHERE " . implode(' AND ', $where) . "
    ORDER BY date(match_date) DESC, id DESC
  ";

  $matches = q($pdo, $sqlMatches, $params)->fetchAll();

  echo '<div class="card-soft">';
  echo '  <div class="p-3">';
  echo '    <div class="muted mb-2">Lista de jogos contra ' . h($opponent) . ' (ordenada por data).</div>';
  echo '  </div>';
  echo '  <div class="table-responsive">';
  echo '    <table class="table align-middle mb-0">';
  echo '      <thead><tr>
            <th>Data</th>
            <th>Temporada</th>
            <th>Campeonato</th>
            <th>Jogo</th>
            <th class="text-end">GP</th>
            <th class="text-end">GC</th>
            <th class="text-center">Resultado</th>
            <th class="text-end">Ações</th>
          </tr></thead>';
  echo '      <tbody>';

  foreach ($matches as $m) {
    $resultado = match ((string)$m['result']) {
      'W' => 'Vitória',
      'D' => 'Empate',
      'L' => 'Derrota',
      default => '-',
    };

    echo '      <tr>';
    echo '        <td>' . alm_fmt_date_br((string)$m['match_date']) . '</td>';
    echo '        <td>' . h((string)$m['season']) . '</td>';
    echo '        <td>' . h((string)$m['competition']) . '</td>';
    echo '        <td>' . h((string)$m['home']) . ' x ' . h((string)$m['away']) . '</td>';
    echo '        <td class="text-end">' . (int)$m['gf'] . '</td>';
    echo '        <td class="text-end">' . (int)$m['ga'] . '</td>';
    echo '        <td class="text-center">' . $resultado . '</td>';
    echo '        <td class="text-end"><a class="btn btn-sm btn-primary" href="index.php?page=match&id=' . (int)$m['id'] . '">Abrir</a></td>';
    echo '      </tr>';
  }

  echo '      </tbody>';
  echo '    </table>';
  echo '  </div>';
  echo '</div>';

  render_footer();
  exit;
}

/*
|--------------------------------------------------------------------------
| CONSOLIDADO
|--------------------------------------------------------------------------
*/
$where = [];
$params = [];

if ($q !== '') {
  $where[] = "opponent LIKE ?";
  $params[] = "%$q%";
}

if ($season !== '') {
  $where[] = "season = ?";
  $params[] = $season;
}

if ($competition !== '') {
  $where[] = "competition = ?";
  $params[] = $competition;
}

echo '<div class="card-soft mb-3">';
echo '  <form method="get" class="p-3">';
echo '    <input type="hidden" name="page" value="almanaque_opponents">';
echo '    <div class="muted mb-2">Consolidado automático baseado nos jogos do Palmeiras.</div>';
echo '    <div class="row g-2 align-items-end">';
echo '      <div class="col-12 col-md-3">';
echo '        <label class="form-label">Buscar adversário</label>';
echo '        <input class="form-control" name="q" placeholder="Buscar adversário..." value="' . h($q) . '">';
echo '      </div>';
echo '      <div class="col-12 col-md-3">';
echo '        <label class="form-label">Temporada</label>';
echo '        <select class="form-select" name="season">';
echo '          <option value="">Todas</option>';
foreach ($seasonOptions as $opt) {
  $opt = (string)$opt;
  echo '          <option value="' . h($opt) . '"' . ($season === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '        </select>';
echo '      </div>';
echo '      <div class="col-12 col-md-4">';
echo '        <label class="form-label">Campeonato</label>';
echo '        <select class="form-select" name="competition">';
echo '          <option value="">Todos</option>';
foreach ($competitionOptions as $opt) {
  $opt = (string)$opt;
  echo '          <option value="' . h($opt) . '"' . ($competition === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '        </select>';
echo '      </div>';
echo '      <div class="col-12 col-md-1 d-grid">';
echo '        <button class="btn btnbtn-primary btn-primary" type="submit">Aplicar</button>';
echo '      </div>';
echo '      <div class="col-12 col-md-1 d-grid">';
echo '        <a class="btn btn-outline-secondary" href="' . h(alm_build_url([
          'q'           => null,
          'competition' => null,
          'season'      => null,
          'sort'        => 'games',
          'dir'         => 'desc',
        ])) . '">Limpar</a>';
echo '      </div>';
echo '    </div>';
echo '  </form>';

if ($q === '' && $season === '' && $competition === '') {
  $viewSortMap = [
    'opponent'      => 'opponent',
    'name'          => 'opponent',
    'games'         => 'games',
    'wins'          => 'wins',
    'draws'         => 'draws',
    'losses'        => 'losses',
    'goals_for'     => 'goals_for',
    'goals_against' => 'goals_against',
    'goal_diff'     => 'goal_diff',
    'pct'           => 'pct',
    'j'             => 'games',
    'v'             => 'wins',
    'e'             => 'draws',
    'd'             => 'losses',
    'gp'            => 'goals_for',
    'gc'            => 'goals_against',
    'sg'            => 'goal_diff',
    'ap'            => 'pct',
  ];

  $viewOrderCol = $viewSortMap[$sort] ?? 'games';
  $viewOrderDir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

  $rows = q(
    $pdo,
    "SELECT opponent, games, wins, draws, losses, goals_for, goals_against, goal_diff, pct
     FROM v_pm_vs_opponents
     ORDER BY {$viewOrderCol} {$viewOrderDir}, opponent ASC"
  )->fetchAll();
} else {
  $sql = "
    SELECT
      opponent,
      COUNT(*) AS games,
      SUM(CASE WHEN result = 'W' THEN 1 ELSE 0 END) AS wins,
      SUM(CASE WHEN result = 'D' THEN 1 ELSE 0 END) AS draws,
      SUM(CASE WHEN result = 'L' THEN 1 ELSE 0 END) AS losses,
      SUM(gf) AS goals_for,
      SUM(ga) AS goals_against,
      SUM(gf) - SUM(ga) AS goal_diff,
      ROUND(
        (SUM(CASE WHEN result = 'W' THEN 3 WHEN result = 'D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*) * 3),
        2
      ) AS pct
    FROM v_pm_matches
  ";

  if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
  }

  $sql .= " GROUP BY opponent
            ORDER BY {$orderCol} {$orderDir}, opponent ASC";

  $rows = q($pdo, $sql, $params)->fetchAll();
}

if (!$rows) {
  echo '  <div class="px-3 pb-3 muted">Sem resultados para os filtros informados.</div>';
  echo '</div>';
  render_footer();
  exit;
}

echo '  <div class="table-responsive">';
echo '    <table class="table align-middle mb-0">';
echo '      <thead><tr>
          <th>' . alm_sort_link('opponent', 'Adversário', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('games', 'J', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('wins', 'V', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('draws', 'E', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('losses', 'D', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('goals_for', 'GP', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('goals_against', 'GC', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('goal_diff', 'SG', $sort, $dir) . '</th>
          <th class="text-end">' . alm_sort_link('pct', '% AP', $sort, $dir) . '</th>
        </tr></thead>';
echo '      <tbody>';

foreach ($rows as $r) {
  echo '      <tr>';
  echo '        <td><a href="' . h(alm_build_url(['opponent' => (string)$r['opponent']])) . '">' . h((string)$r['opponent']) . '</a></td>';
  echo '        <td class="text-end">' . (int)$r['games'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['wins'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['draws'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['losses'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goals_for'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goals_against'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goal_diff'] . '</td>';
  echo '        <td class="text-end">' . alm_fmt_pct($r['pct']) . '</td>';
  echo '      </tr>';
}

echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

render_footer();

