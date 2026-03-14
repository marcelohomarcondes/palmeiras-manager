<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Almanaque • Estádios');

$q           = trim((string)($_GET['q'] ?? ''));
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));
$stadium     = trim((string)($_GET['stadium'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? 'games'));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

$clubName = 'Palmeiras';

function alm_build_url(array $overrides = []): string {
  $params = array_merge($_GET, ['page' => 'almanaque_stadiums'], $overrides);
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

$seasonOptions = q(
  $pdo,
  "SELECT DISTINCT season
   FROM matches
   WHERE COALESCE(TRIM(season), '') <> ''
     AND COALESCE(TRIM(stadium), '') <> ''
   ORDER BY season DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$competitionOptions = q(
  $pdo,
  "SELECT DISTINCT competition
   FROM matches
   WHERE COALESCE(TRIM(competition), '') <> ''
     AND COALESCE(TRIM(stadium), '') <> ''
   ORDER BY competition ASC"
)->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| DETALHE DO ESTÁDIO
|--------------------------------------------------------------------------
*/
if ($stadium !== '') {
  $where = ["TRIM(COALESCE(stadium, '')) = ?"];
  $params = [$stadium];

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
      TRIM(COALESCE(stadium, '')) AS stadium,
      COUNT(*) AS games,
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score > away_score THEN 1
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score > home_score THEN 1
          ELSE 0
        END
      ) AS wins,
      SUM(
        CASE
          WHEN home_score = away_score THEN 1
          ELSE 0
        END
      ) AS draws,
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score < away_score THEN 1
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score < home_score THEN 1
          ELSE 0
        END
      ) AS losses,
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN home_score
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN away_score
          ELSE 0
        END
      ) AS goals_for,
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN away_score
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN home_score
          ELSE 0
        END
      ) AS goals_against,
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN home_score
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN away_score
          ELSE 0
        END
      ) -
      SUM(
        CASE
          WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN away_score
          WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN home_score
          ELSE 0
        END
      ) AS goal_diff,
      ROUND(
        (
          SUM(
            CASE
              WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score > away_score THEN 3
              WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score > home_score THEN 3
              WHEN home_score = away_score THEN 1
              ELSE 0
            END
          ) * 100.0
        ) / (COUNT(*) * 3),
        2
      ) AS pct
    FROM matches
    WHERE " . implode(' AND ', $where) . "
    GROUP BY TRIM(COALESCE(stadium, ''))
  ";

  $summaryParams = [
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
  ];
  $summaryParams = array_merge($summaryParams, $params);

  $summary = q($pdo, $sqlSummary, $summaryParams)->fetch();

  echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
  echo '  <div>';
  echo '    <div class="muted">Resumo detalhado do estádio</div>';
  echo '    <h3 class="mb-0">' . h($stadium) . '</h3>';
  echo '  </div>';
  echo '  <div>';
  echo '    <a class="btn btn-secondary" href="' . h(alm_build_url([
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
  echo '        <a class="btn btn-secondary" href="' . h(alm_build_url([
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
      phase,
      round,
      match_date,
      home,
      away,
      home_score,
      away_score,
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN home_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN away_score
        ELSE 0
      END AS gf,
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN away_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN home_score
        ELSE 0
      END AS ga,
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score > away_score THEN 'W'
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score > home_score THEN 'W'
        WHEN home_score = away_score THEN 'D'
        ELSE 'L'
      END AS result
    FROM matches
    WHERE " . implode(' AND ', $where) . "
    ORDER BY date(match_date) DESC, id DESC
  ";

  $matchParams = [
    $clubName, $clubName,
    $clubName, $clubName,
    $clubName, $clubName,
  ];
  $matchParams = array_merge($matchParams, $params);

  $matches = q($pdo, $sqlMatches, $matchParams)->fetchAll();

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
    echo '        <td>' . alm_fmt_date_br((string)$m['match_date']) . '</td>';
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
$where = ["COALESCE(TRIM(stadium), '') <> ''"];
$params = [];

if ($q !== '') {
  $where[] = "stadium LIKE ?";
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
echo '        <a class="btn btn-secondary" href="' . h(alm_build_url([
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
    TRIM(COALESCE(stadium, '')) AS stadium,
    COUNT(*) AS games,
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score > away_score THEN 1
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score > home_score THEN 1
        ELSE 0
      END
    ) AS wins,
    SUM(
      CASE
        WHEN home_score = away_score THEN 1
        ELSE 0
      END
    ) AS draws,
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score < away_score THEN 1
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score < home_score THEN 1
        ELSE 0
      END
    ) AS losses,
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN home_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN away_score
        ELSE 0
      END
    ) AS goals_for,
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN away_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN home_score
        ELSE 0
      END
    ) AS goals_against,
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN home_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN away_score
        ELSE 0
      END
    ) -
    SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) THEN away_score
        WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) THEN home_score
        ELSE 0
      END
    ) AS goal_diff,
    ROUND(
      (
        SUM(
          CASE
            WHEN UPPER(TRIM(COALESCE(home, ''))) = UPPER(TRIM(?)) AND home_score > away_score THEN 3
            WHEN UPPER(TRIM(COALESCE(away, ''))) = UPPER(TRIM(?)) AND away_score > home_score THEN 3
            WHEN home_score = away_score THEN 1
            ELSE 0
          END
        ) * 100.0
      ) / (COUNT(*) * 3),
      2
    ) AS pct
  FROM matches
";

if ($where) {
  $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= "
  GROUP BY TRIM(COALESCE(stadium, ''))
  ORDER BY {$orderCol} {$orderDir}, stadium ASC
";

$queryParams = [
  $clubName, $clubName,
  $clubName, $clubName,
  $clubName, $clubName,
  $clubName, $clubName,
  $clubName, $clubName,
  $clubName, $clubName,
  $clubName, $clubName,
];
$queryParams = array_merge($queryParams, $params);

$rows = q($pdo, $sql, $queryParams)->fetchAll();

if (!$rows) {
  echo '  <div class="px-3 pb-3 muted">Sem resultados para os filtros informados.</div>';
  echo '</div>';
  render_footer();
  exit;
}

echo '  <div class="table-responsive">';
echo '    <table class="table align-middle mb-0">';
echo '      <thead><tr>
          <th>' . alm_sort_link('stadium', 'Estádio', $sort, $dir) . '</th>
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
  echo '        <td><a href="' . h(alm_build_url(['stadium' => (string)$r['stadium']])) . '">' . h((string)$r['stadium']) . '</a></td>';
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




