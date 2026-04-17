<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo    = db();
$userId = require_user_id();
$club   = function_exists('app_club') ? (string)app_club() : 'PALMEIRAS';

render_header('Almanaque • Estádios');

$q           = trim((string)($_GET['q'] ?? ''));
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));
$stadium     = trim((string)($_GET['stadium'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? 'games'));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

if (!function_exists('table_columns')) {
  function table_columns(PDO $pdo, string $table): array {
    $cols = [];
    $st = $pdo->query("PRAGMA table_info($table)");
    foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
      $cols[] = (string)($r['name'] ?? '');
    }
    return $cols;
  }
}

if (!function_exists('table_has_user_id')) {
  function table_has_user_id(PDO $pdo, string $table): bool {
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
      $cache[$table] = in_array('user_id', table_columns($pdo, $table), true);
    }
    return $cache[$table];
  }
}

function alm_stad_build_url(array $overrides = []): string {
  $params = array_merge($_GET, ['page' => 'almanaque_stadiums'], $overrides);
  foreach ($params as $k => $v) {
    if ($v === null || $v === '') {
      unset($params[$k]);
    }
  }
  return 'index.php?' . http_build_query($params);
}

function alm_stad_sort_link(string $column, string $label, string $currentSort, string $currentDir): string {
  $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
  $arrow = '';

  if ($currentSort === $column) {
    $arrow = $currentDir === 'asc' ? ' ▼' : ' ▲';
  }

  $url = alm_stad_build_url([
    'sort' => $column,
    'dir'  => $nextDir,
  ]);

  return '<a href="' . h($url) . '">' . h($label) . $arrow . '</a>';
}

function alm_stad_fmt_pct($v): string {
  return number_format((float)$v, 2, ',', '.') . '%';
}

function alm_stad_fmt_date_br(?string $date): string {
  if (!$date) return '-';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : h($date);
}

$matchesHasUserId = table_has_user_id($pdo, 'matches');

$clubNorm = "UPPER(TRIM(:club))";
$homeNorm = "UPPER(TRIM(COALESCE(m.home, '')))";
$awayNorm = "UPPER(TRIM(COALESCE(m.away, '')))";
$isClubInMatch = "($homeNorm = $clubNorm OR $awayNorm = $clubNorm)";

$gfExpr = "CASE
  WHEN $homeNorm = $clubNorm THEN COALESCE(m.home_score, 0)
  ELSE COALESCE(m.away_score, 0)
END";

$gaExpr = "CASE
  WHEN $homeNorm = $clubNorm THEN COALESCE(m.away_score, 0)
  ELSE COALESCE(m.home_score, 0)
END";

$resultExpr = "CASE
  WHEN ($gfExpr) > ($gaExpr) THEN 'W'
  WHEN ($gfExpr) = ($gaExpr) THEN 'D'
  ELSE 'L'
END";

$baseWhere = [
  $isClubInMatch,
  "TRIM(COALESCE(m.stadium, '')) <> ''",
];
$baseParams = [':club' => $club];

if ($matchesHasUserId) {
  $baseWhere[] = "m.user_id = :user_id";
  $baseParams[':user_id'] = $userId;
}

$seasonOptionsSql = "
  SELECT DISTINCT TRIM(COALESCE(m.season, '')) AS season
  FROM matches m
  WHERE " . implode(' AND ', $baseWhere) . "
    AND TRIM(COALESCE(m.season, '')) <> ''
  ORDER BY CAST(TRIM(m.season) AS INTEGER) DESC, TRIM(m.season) DESC
";
$seasonOptions = q($pdo, $seasonOptionsSql, $baseParams)->fetchAll(PDO::FETCH_COLUMN);

$competitionOptionsSql = "
  SELECT DISTINCT TRIM(COALESCE(m.competition, '')) AS competition
  FROM matches m
  WHERE " . implode(' AND ', $baseWhere) . "
    AND TRIM(COALESCE(m.competition, '')) <> ''
  ORDER BY TRIM(m.competition) ASC
";
$competitionOptions = q($pdo, $competitionOptionsSql, $baseParams)->fetchAll(PDO::FETCH_COLUMN);

$sortMap = [
  'stadium'       => 'stadium',
  'name'          => 'stadium',
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

/*
|--------------------------------------------------------------------------
| DETALHE DO ESTÁDIO
|--------------------------------------------------------------------------
*/
if ($stadium !== '') {
  $where = $baseWhere;
  $params = $baseParams;

  $where[] = "UPPER(TRIM(COALESCE(m.stadium, ''))) = UPPER(TRIM(:stadium))";
  $params[':stadium'] = $stadium;

  if ($season !== '') {
    $where[] = "UPPER(TRIM(COALESCE(m.season, ''))) = UPPER(TRIM(:season))";
    $params[':season'] = $season;
  }

  if ($competition !== '') {
    $where[] = "UPPER(TRIM(COALESCE(m.competition, ''))) = UPPER(TRIM(:competition))";
    $params[':competition'] = $competition;
  }

  $sqlSummary = "
    SELECT
      TRIM(COALESCE(m.stadium, '')) AS stadium,
      COUNT(*) AS games,
      SUM(CASE WHEN ($resultExpr) = 'W' THEN 1 ELSE 0 END) AS wins,
      SUM(CASE WHEN ($resultExpr) = 'D' THEN 1 ELSE 0 END) AS draws,
      SUM(CASE WHEN ($resultExpr) = 'L' THEN 1 ELSE 0 END) AS losses,
      SUM($gfExpr) AS goals_for,
      SUM($gaExpr) AS goals_against,
      SUM($gfExpr) - SUM($gaExpr) AS goal_diff,
      ROUND(
        (
          SUM(CASE WHEN ($resultExpr) = 'W' THEN 3 WHEN ($resultExpr) = 'D' THEN 1 ELSE 0 END) * 100.0
        ) / (COUNT(*) * 3),
        2
      ) AS pct
    FROM matches m
    WHERE " . implode(' AND ', $where) . "
    GROUP BY TRIM(COALESCE(m.stadium, ''))
  ";

  $summary = q($pdo, $sqlSummary, $params)->fetch();

  echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
  echo '  <div>';
  echo '    <div class="muted">Resumo detalhado do estádio</div>';
  echo '    <h3 class="mb-0">' . h($stadium) . '</h3>';
  echo '  </div>';
  echo '  <div>';
  echo '    <a class="btn btn-secondary" href="' . h(alm_stad_build_url([
            'stadium' => null,
            'sort'    => $sort,
            'dir'     => $dir,
          ])) . '">Voltar ao consolidado</a>';
  echo '  </div>';
  echo '</div>';

  echo '<div class="card-soft mb-3">';
  echo '  <form method="get" class="p-3">';
  echo '    <input type="hidden" name="page" value="almanaque_stadiums">';
  echo '    <input type="hidden" name="stadium" value="' . h($stadium) . '">';
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
  echo '        <button class="btn btn-primary" type="submit">Aplicar</button>';
  echo '      </div>';
  echo '      <div class="col-12 col-md-1 d-grid">';
  echo '        <a class="btn btn-secondary" href="' . h(alm_stad_build_url([
            'competition' => null,
            'season'      => null,
          ])) . '">Limpar</a>';
  echo '      </div>';
  echo '    </div>';
  echo '  </form>';

  if (!$summary) {
    echo '  <div class="px-3 pb-3 muted">Nenhuma partida encontrada para este estádio com os filtros informados.</div>';
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
  echo '        <td class="text-center">' . alm_stad_fmt_pct($summary['pct']) . '</td>';
  echo '      </tr></tbody>';
  echo '    </table>';
  echo '  </div>';
  echo '</div>';

  $sqlMatches = "
    SELECT
      m.id,
      m.season,
      m.competition,
      m.phase,
      m.round,
      m.match_date,
      m.home,
      m.away,
      ($gfExpr) AS gf,
      ($gaExpr) AS ga,
      ($resultExpr) AS result
    FROM matches m
    WHERE " . implode(' AND ', $where) . "
    ORDER BY date(m.match_date) DESC, m.id DESC
  ";

  $matches = q($pdo, $sqlMatches, $params)->fetchAll();

  echo '<div class="card-soft">';
  echo '  <div class="p-3">';
  echo '    <div class="muted mb-2">Lista de jogos no estádio ' . h($stadium) . ' (ordenada por data).</div>';
  echo '  </div>';
  echo '  <div class="table-responsive">';
  echo '    <table class="table align-middle mb-0">';
  echo '      <thead><tr>
            <th>Data</th>
            <th>Temporada</th>
            <th>Campeonato</th>
            <th>Fase</th>
            <th>Rodada</th>
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
    echo '        <td>' . alm_stad_fmt_date_br((string)$m['match_date']) . '</td>';
    echo '        <td>' . h((string)$m['season']) . '</td>';
    echo '        <td>' . h((string)$m['competition']) . '</td>';
    echo '        <td>' . h((string)($m['phase'] ?? '-')) . '</td>';
    echo '        <td>' . h((string)($m['round'] ?? '-')) . '</td>';
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
$where = $baseWhere;
$params = $baseParams;

if ($q !== '') {
  $where[] = "UPPER(TRIM(COALESCE(m.stadium, ''))) LIKE UPPER(TRIM(:q))";
  $params[':q'] = '%' . $q . '%';
}

if ($season !== '') {
  $where[] = "UPPER(TRIM(COALESCE(m.season, ''))) = UPPER(TRIM(:season))";
  $params[':season'] = $season;
}

if ($competition !== '') {
  $where[] = "UPPER(TRIM(COALESCE(m.competition, ''))) = UPPER(TRIM(:competition))";
  $params[':competition'] = $competition;
}

echo '<div class="card-soft mb-3">';
echo '  <form method="get" class="p-3">';
echo '    <input type="hidden" name="page" value="almanaque_stadiums">';
echo '    <div class="muted mb-2">Consolidado automático baseado nos jogos do Palmeiras por estádio.</div>';
echo '    <div class="row g-2 align-items-end">';
echo '      <div class="col-12 col-md-3">';
echo '        <label class="form-label">Buscar estádio</label>';
echo '        <input class="form-control" name="q" placeholder="Buscar estádio..." value="' . h($q) . '">';
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
echo '        <button class="btn btn-primary" type="submit">Aplicar</button>';
echo '      </div>';
echo '      <div class="col-12 col-md-1 d-grid">';
echo '        <a class="btn btn-secondary" href="' . h(alm_stad_build_url([
          'q'           => null,
          'competition' => null,
          'season'      => null,
          'sort'        => 'games',
          'dir'         => 'desc',
        ])) . '">Limpar</a>';
echo '      </div>';
echo '    </div>';
echo '  </form>';

$sql = "
  SELECT
    TRIM(COALESCE(m.stadium, '')) AS stadium,
    COUNT(*) AS games,
    SUM(CASE WHEN ($resultExpr) = 'W' THEN 1 ELSE 0 END) AS wins,
    SUM(CASE WHEN ($resultExpr) = 'D' THEN 1 ELSE 0 END) AS draws,
    SUM(CASE WHEN ($resultExpr) = 'L' THEN 1 ELSE 0 END) AS losses,
    SUM($gfExpr) AS goals_for,
    SUM($gaExpr) AS goals_against,
    SUM($gfExpr) - SUM($gaExpr) AS goal_diff,
    ROUND(
      (
        SUM(CASE WHEN ($resultExpr) = 'W' THEN 3 WHEN ($resultExpr) = 'D' THEN 1 ELSE 0 END) * 100.0
      ) / (COUNT(*) * 3),
      2
    ) AS pct
  FROM matches m
  WHERE " . implode(' AND ', $where) . "
  GROUP BY TRIM(COALESCE(m.stadium, ''))
  ORDER BY {$orderCol} {$orderDir}, stadium ASC
";

$rows = q($pdo, $sql, $params)->fetchAll();

if (!$rows) {
  echo '  <div class="px-3 pb-3 muted">Sem resultados para os filtros informados.</div>';
  echo '</div>';
  render_footer();
  exit;
}

echo '  <div class="table-responsive">';
echo '    <table class="table align-middle mb-0">';
echo '      <thead><tr>
          <th>' . alm_stad_sort_link('stadium', 'Estádio', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('games', 'J', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('wins', 'V', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('draws', 'E', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('losses', 'D', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('goals_for', 'GP', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('goals_against', 'GC', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('goal_diff', 'SG', $sort, $dir) . '</th>
          <th class="text-end">' . alm_stad_sort_link('pct', '% AP', $sort, $dir) . '</th>
        </tr></thead>';
echo '      <tbody>';

foreach ($rows as $r) {
  echo '      <tr>';
  echo '        <td><a href="' . h(alm_stad_build_url(['stadium' => (string)$r['stadium']])) . '">' . h((string)$r['stadium']) . '</a></td>';
  echo '        <td class="text-end">' . (int)$r['games'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['wins'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['draws'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['losses'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goals_for'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goals_against'] . '</td>';
  echo '        <td class="text-end">' . (int)$r['goal_diff'] . '</td>';
  echo '        <td class="text-end">' . alm_stad_fmt_pct($r['pct']) . '</td>';
  echo '      </tr>';
}

echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

render_footer();