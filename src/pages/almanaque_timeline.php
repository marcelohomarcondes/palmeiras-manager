<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo    = db();
$userId = require_user_id();
$club   = function_exists('app_club') ? (string)app_club() : 'PALMEIRAS';

render_header('Almanaque • Linha do Tempo');

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

function alm_tl_build_url(array $overrides = []): string {
  $params = array_merge($_GET, ['page' => 'almanaque_timeline'], $overrides);
  foreach ($params as $k => $v) {
    if ($v === null || $v === '') unset($params[$k]);
  }
  return 'index.php?' . http_build_query($params);
}

function alm_tl_fmt_date_br(?string $date): string {
  if (!$date) return '-';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : h($date);
}

function alm_tl_pct(int $wins, int $draws, int $games): string {
  if ($games <= 0) return '0,00%';
  $points = ($wins * 3) + $draws;
  return number_format(($points * 100) / ($games * 3), 2, ',', '.') . '%';
}

function alm_tl_year_from_date(?string $date): string {
  $date = trim((string)$date);
  if ($date === '') return '';
  $ts = strtotime($date);
  if ($ts !== false) return date('Y', $ts);
  return substr($date, 0, 4);
}

function alm_tl_decade_label(string|int|null $year): string {
  if ($year === null || $year === '') {
    return '';
  }

  $y = (int)$year;
  if ($y <= 0) {
    return '';
  }

  $d = (int)(floor($y / 10) * 10);
  return (string)$d;
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

$baseWhere = [$isClubInMatch];
$params = [':club' => $club];

if ($matchesHasUserId) {
  $baseWhere[] = "m.user_id = :user_id";
  $params[':user_id'] = $userId;
}

$sql = "
  SELECT
    m.id,
    TRIM(COALESCE(m.season, '')) AS season,
    TRIM(COALESCE(m.competition, '')) AS competition,
    TRIM(COALESCE(m.phase, '')) AS phase,
    TRIM(COALESCE(m.round, '')) AS round,
    m.match_date,
    m.home,
    m.away,
    COALESCE(m.home_score, 0) AS home_score,
    COALESCE(m.away_score, 0) AS away_score,
    $gfExpr AS gf,
    $gaExpr AS ga
  FROM matches m
  WHERE " . implode(' AND ', $baseWhere) . "
  ORDER BY date(m.match_date) ASC, m.id ASC
";

$rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
echo '  <div>';
echo '    <div class="muted">Linha do tempo histórica</div>';
echo '    <h3 class="mb-0">Décadas e anos</h3>';
echo '  </div>';
echo '  <div>';
echo '    <a class="btn btn-secondary" href="index.php?page=almanaque">Voltar ao almanaque</a>';
echo '  </div>';
echo '</div>';

if (!$rows) {
  echo '<div class="card-soft p-3 muted">Nenhuma partida encontrada para este save.</div>';
  render_footer();
  exit;
}

/*
|--------------------------------------------------------------------------
| Agrupar por ano e década
|--------------------------------------------------------------------------
*/
$years = [];
foreach ($rows as $r) {
  $year = alm_tl_year_from_date((string)$r['match_date']);
  if ($year === '') continue;

  if (!isset($years[$year])) {
    $years[$year] = [
      'year'        => $year,
      'games'       => 0,
      'wins'        => 0,
      'draws'       => 0,
      'losses'      => 0,
      'goals_for'   => 0,
      'goals_against'=> 0,
      'matches'     => [],
    ];
  }

  $gf = (int)$r['gf'];
  $ga = (int)$r['ga'];

  $years[$year]['games']++;
  $years[$year]['goals_for'] += $gf;
  $years[$year]['goals_against'] += $ga;

  if ($gf > $ga) $years[$year]['wins']++;
  elseif ($gf < $ga) $years[$year]['losses']++;
  else $years[$year]['draws']++;

  $years[$year]['matches'][] = $r;
}

if (!$years) {
  echo '<div class="card-soft p-3 muted">Não foi possível montar a linha do tempo.</div>';
  render_footer();
  exit;
}

uksort($years, static fn(string $a, string $b): int => (int)$b <=> (int)$a);

$decades = [];
foreach ($years as $year => $data) {
  $decade = alm_tl_decade_label($year);
  if (!isset($decades[$decade])) {
    $decades[$decade] = [];
  }
  $decades[$decade][$year] = $data;
}

uksort($decades, static fn(string $a, string $b): int => (int)$b <=> (int)$a);

?>
<style>
  .timeline-decade-card { overflow: hidden; }
  .timeline-year-link {
    display: block;
    padding: .5rem .75rem;
    border-radius: .5rem;
    text-decoration: none;
  }
  .timeline-year-link.active {
    font-weight: 700;
  }
</style>
<?php

$selectedYear = trim((string)($_GET['year'] ?? ''));
if ($selectedYear === '' || !isset($years[$selectedYear])) {
  $selectedYear = array_key_first($years);
}
$selected = $years[$selectedYear];

echo '<div class="row g-3">';

echo '<div class="col-12 col-xl-3">';
foreach ($decades as $decade => $decadeYears) {
  echo '<div class="card-soft p-3 mb-3 timeline-decade-card">';
  echo '  <div class="fw-bold mb-2">Década de ' . h((string)$decade) . 's</div>';
  echo '  <div class="vstack gap-1">';
  foreach ($decadeYears as $year => $yData) {
    $url = alm_tl_build_url(['year' => $year]);
    $active = ((string)$selectedYear === (string)$year) ? ' active' : '';
    echo '<a class="timeline-year-link' . $active . '" href="' . h($url) . '">';
    echo '  <div class="d-flex justify-content-between align-items-center">';
    echo '    <span>' . h((string)$year) . '</span>';
    echo '    <span class="text-muted small">' . (int)$yData['games'] . ' J</span>';
    echo '  </div>';
    echo '</a>';
  }
  echo '  </div>';
  echo '</div>';
}
echo '</div>';

echo '<div class="col-12 col-xl-9">';

echo '<div class="card-soft mb-3">';
echo '  <div class="p-3">';
echo '    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">';
echo '      <div>';
echo '        <div class="muted">Resumo da temporada</div>';
echo '        <h4 class="mb-0">' . h((string)$selected['year']) . '</h4>';
echo '      </div>';
echo '      <div class="text-muted">' . (int)$selected['games'] . ' partida(s)</div>';
echo '    </div>';
echo '  </div>';
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
echo '        <td class="text-center">' . (int)$selected['games'] . '</td>';
echo '        <td class="text-center">' . (int)$selected['wins'] . '</td>';
echo '        <td class="text-center">' . (int)$selected['draws'] . '</td>';
echo '        <td class="text-center">' . (int)$selected['losses'] . '</td>';
echo '        <td class="text-center">' . (int)$selected['goals_for'] . '</td>';
echo '        <td class="text-center">' . (int)$selected['goals_against'] . '</td>';
echo '        <td class="text-center">' . ((int)$selected['goals_for'] - (int)$selected['goals_against']) . '</td>';
echo '        <td class="text-center">' . alm_tl_pct((int)$selected['wins'], (int)$selected['draws'], (int)$selected['games']) . '</td>';
echo '      </tr></tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

$selectedMatches = $selected['matches'];
usort($selectedMatches, static function (array $a, array $b): int {
  $da = strtotime((string)$a['match_date']) ?: 0;
  $db = strtotime((string)$b['match_date']) ?: 0;
  if ($da === $db) {
    return ((int)$b['id']) <=> ((int)$a['id']);
  }
  return $db <=> $da;
});

echo '<div class="card-soft">';
echo '  <div class="p-3">';
echo '    <div class="muted mb-2">Lista de jogos de ' . h($selected['year']) . ' (ordenada por data).</div>';
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

foreach ($selectedMatches as $m) {
  $gf = (int)$m['gf'];
  $ga = (int)$m['ga'];
  $resultado = $gf > $ga ? 'Vitória' : ($gf < $ga ? 'Derrota' : 'Empate');

  echo '      <tr>';
  echo '        <td>' . alm_tl_fmt_date_br((string)$m['match_date']) . '</td>';
  echo '        <td>' . h((string)$m['season']) . '</td>';
  echo '        <td>' . h((string)$m['competition']) . '</td>';
  echo '        <td>' . h((string)($m['phase'] ?? '-')) . '</td>';
  echo '        <td>' . h((string)($m['round'] ?? '-')) . '</td>';
  echo '        <td>' . h((string)$m['home']) . ' x ' . h((string)$m['away']) . '</td>';
  echo '        <td class="text-end">' . $gf . '</td>';
  echo '        <td class="text-end">' . $ga . '</td>';
  echo '        <td class="text-center">' . $resultado . '</td>';
  echo '        <td class="text-end"><a class="btn btn-sm btn-primary" href="index.php?page=match&id=' . (int)$m['id'] . '">Abrir</a></td>';
  echo '      </tr>';
}

echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

echo '</div>';
echo '</div>';

render_footer();