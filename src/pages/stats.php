<?php
// src/pages/stats.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

// PDO padronizado
$pdo = db();

// Clube (sem helper inexistente)
$club = 'PALMEIRAS';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function normKey(string $v): string {
  $v = trim($v);
  if ($v === '') return '';
  return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
}

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function table_columns(PDO $pdo, string $table): array {
  $cols = [];
  $st = $pdo->query("PRAGMA table_info($table)");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols[] = (string)$r['name'];
  }
  return $cols;
}

function render_table(array $headers, array $rows, array $keys, int $colspanIfEmpty): void {
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm mb-0">';
  echo '<thead><tr>';
  foreach ($headers as $hh) echo '<th>'.h((string)$hh).'</th>';
  echo '</tr></thead><tbody>';

  if (!$rows) {
    echo '<tr><td colspan="'.(int)$colspanIfEmpty.'" class="text-muted">Sem dados.</td></tr>';
  } else {
    foreach ($rows as $r) {
      echo '<tr>';
      foreach ($keys as $k) {
        echo '<td>'.h((string)($r[$k] ?? '')).'</td>';
      }
      echo '</tr>';
    }
  }

  echo '</tbody></table></div>';
}

function compute_streaks(array $matches): array {
  $best = [
    'unbeaten' => ['len'=>0,'start'=>null,'end'=>null],
    'nowins'   => ['len'=>0,'start'=>null,'end'=>null],
    'wins'     => ['len'=>0,'start'=>null,'end'=>null],
    'losses'   => ['len'=>0,'start'=>null,'end'=>null],
    'clean'    => ['len'=>0,'start'=>null,'end'=>null],
  ];
  $cur = [
    'unbeaten' => ['len'=>0,'start'=>null],
    'nowins'   => ['len'=>0,'start'=>null],
    'wins'     => ['len'=>0,'start'=>null],
    'losses'   => ['len'=>0,'start'=>null],
    'clean'    => ['len'=>0,'start'=>null],
  ];

  foreach ($matches as $m) {
    $gf   = (int)$m['gf'];
    $ga   = (int)$m['ga'];
    $date = (string)$m['match_date'];

    $isWin  = $gf > $ga;
    $isDraw = $gf === $ga;
    $isLoss = $gf < $ga;

    if ($isWin || $isDraw) {
      if ($cur['unbeaten']['len'] === 0) $cur['unbeaten']['start'] = $date;
      $cur['unbeaten']['len']++;
      if ($cur['unbeaten']['len'] > $best['unbeaten']['len']) {
        $best['unbeaten'] = ['len'=>$cur['unbeaten']['len'], 'start'=>$cur['unbeaten']['start'], 'end'=>$date];
      }
    } else {
      $cur['unbeaten'] = ['len'=>0,'start'=>null];
    }

    if ($isDraw || $isLoss) {
      if ($cur['nowins']['len'] === 0) $cur['nowins']['start'] = $date;
      $cur['nowins']['len']++;
      if ($cur['nowins']['len'] > $best['nowins']['len']) {
        $best['nowins'] = ['len'=>$cur['nowins']['len'], 'start'=>$cur['nowins']['start'], 'end'=>$date];
      }
    } else {
      $cur['nowins'] = ['len'=>0,'start'=>null];
    }

    if ($isWin) {
      if ($cur['wins']['len'] === 0) $cur['wins']['start'] = $date;
      $cur['wins']['len']++;
      if ($cur['wins']['len'] > $best['wins']['len']) {
        $best['wins'] = ['len'=>$cur['wins']['len'], 'start'=>$cur['wins']['start'], 'end'=>$date];
      }
    } else {
      $cur['wins'] = ['len'=>0,'start'=>null];
    }

    if ($isLoss) {
      if ($cur['losses']['len'] === 0) $cur['losses']['start'] = $date;
      $cur['losses']['len']++;
      if ($cur['losses']['len'] > $best['losses']['len']) {
        $best['losses'] = ['len'=>$cur['losses']['len'], 'start'=>$cur['losses']['start'], 'end'=>$date];
      }
    } else {
      $cur['losses'] = ['len'=>0,'start'=>null];
    }

    if ($ga === 0) {
      if ($cur['clean']['len'] === 0) $cur['clean']['start'] = $date;
      $cur['clean']['len']++;
      if ($cur['clean']['len'] > $best['clean']['len']) {
        $best['clean'] = ['len'=>$cur['clean']['len'], 'start'=>$cur['clean']['start'], 'end'=>$date];
      }
    } else {
      $cur['clean'] = ['len'=>0,'start'=>null];
    }
  }

  return $best;
}

// -----------------------------------------------------------------------------
// Filtros
// -----------------------------------------------------------------------------
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));

// SQL base (case-insensitive)
$bind = [':club' => $club];
$where = [];
if ($season !== '') { $where[] = "m.season = :season"; $bind[':season'] = $season; }
if ($competition !== '') { $where[] = "m.competition = :competition"; $bind[':competition'] = $competition; }
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$clubNorm = "UPPER(TRIM(:club))";
$homeNorm = "UPPER(TRIM(m.home))";
$awayNorm = "UPPER(TRIM(m.away))";
$isClubInMatch = "($homeNorm = $clubNorm OR $awayNorm = $clubNorm)";
$clubGF = "CASE WHEN $homeNorm = $clubNorm THEN COALESCE(m.home_score,0) ELSE COALESCE(m.away_score,0) END";
$clubGA = "CASE WHEN $homeNorm = $clubNorm THEN COALESCE(m.away_score,0) ELSE COALESCE(m.home_score,0) END";
$opponent = "CASE WHEN $homeNorm = $clubNorm THEN m.away ELSE m.home END";
$isHome = "CASE WHEN $homeNorm = $clubNorm THEN 1 ELSE 0 END";

// -----------------------------------------------------------------------------
// Carrega partidas do clube
// -----------------------------------------------------------------------------
$sqlMatches = "
  SELECT
    m.id,
    m.match_date,
    m.season,
    m.competition,
    COALESCE(m.phase,'')   AS phase,
    COALESCE(m.round,'')   AS round,
    COALESCE(m.stadium,'') AS stadium,
    COALESCE(m.referee,'') AS referee,
    COALESCE(m.kit_used,'') AS kit_used,
    COALESCE(m.weather,'') AS weather,
    m.home, m.away,
    COALESCE(m.home_score,0) AS home_score,
    COALESCE(m.away_score,0) AS away_score,
    $opponent AS opponent,
    $isHome AS is_home,
    $clubGF AS gf,
    $clubGA AS ga
  FROM matches m
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
  ORDER BY m.match_date ASC, m.id ASC
";
$st = $pdo->prepare($sqlMatches);
$st->execute($bind);
$matches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -----------------------------------------------------------------------------
// Cálculos gerais
// -----------------------------------------------------------------------------
$summary = ['played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'gd'=>0,'points'=>0,'pct'=>0.0];

$homeAway = [
  'home' => ['played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'gd'=>0,'points'=>0,'pct'=>0.0],
  'away' => ['played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'gd'=>0,'points'=>0,'pct'=>0.0],
];

$scorelines = [];
$opponents  = [];
$stadiums   = [];
$refs       = [];
$kits       = [];
$phases     = [];
$weathers   = [];

foreach ($matches as $m) {
  $gf = (int)$m['gf'];
  $ga = (int)$m['ga'];
  $summary['played']++;
  $summary['gf'] += $gf;
  $summary['ga'] += $ga;

  if ($gf > $ga) { $summary['wins']++; $summary['points'] += 3; }
  elseif ($gf < $ga) { $summary['losses']++; }
  else { $summary['draws']++; $summary['points'] += 1; }

  $bucket = ((int)$m['is_home'] === 1) ? 'home' : 'away';
  $homeAway[$bucket]['played']++;
  $homeAway[$bucket]['gf'] += $gf;
  $homeAway[$bucket]['ga'] += $ga;
  if ($gf > $ga) { $homeAway[$bucket]['wins']++; $homeAway[$bucket]['points'] += 3; }
  elseif ($gf < $ga) { $homeAway[$bucket]['losses']++; }
  else { $homeAway[$bucket]['draws']++; $homeAway[$bucket]['points'] += 1; }

  $sk = $gf . "x" . $ga;
  $scorelines[$sk] = ($scorelines[$sk] ?? 0) + 1;

  $label = trim((string)$m['opponent']); $label = $label !== '' ? $label : '(SEM ADVERSÁRIO)';
  $key = normKey($label);
  if (!isset($opponents[$key])) $opponents[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0];
  $opponents[$key]['played']++;
  if ($gf>$ga) $opponents[$key]['wins']++; elseif ($gf<$ga) $opponents[$key]['losses']++; else $opponents[$key]['draws']++;

  $label = trim((string)$m['stadium']); $label = $label !== '' ? $label : '(SEM ESTÁDIO)';
  $key = normKey($label);
  if (!isset($stadiums[$key])) $stadiums[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0];
  $stadiums[$key]['played']++;
  if ($gf>$ga) $stadiums[$key]['wins']++; elseif ($gf<$ga) $stadiums[$key]['losses']++; else $stadiums[$key]['draws']++;

  $label = trim((string)$m['referee']); $label = $label !== '' ? $label : '(SEM ÁRBITRO)';
  $key = normKey($label);
  if (!isset($refs[$key])) $refs[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0];
  $refs[$key]['played']++;
  if ($gf>$ga) $refs[$key]['wins']++; elseif ($gf<$ga) $refs[$key]['losses']++; else $refs[$key]['draws']++;

  $label = trim((string)$m['kit_used']); $label = $label !== '' ? $label : '(SEM UNIFORME)';
  $key = normKey($label);
  if (!isset($kits[$key])) $kits[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0];
  $kits[$key]['played']++;
  if ($gf>$ga) { $kits[$key]['wins']++; $kits[$key]['points'] += 3; }
  elseif ($gf<$ga) { $kits[$key]['losses']++; }
  else { $kits[$key]['draws']++; $kits[$key]['points'] += 1; }

  $label = trim((string)$m['phase']); $label = $label !== '' ? $label : '(SEM FASE)';
  $key = normKey($label);
  if (!isset($phases[$key])) $phases[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'points'=>0];
  $phases[$key]['played']++;
  $phases[$key]['gf'] += $gf;
  $phases[$key]['ga'] += $ga;
  if ($gf>$ga) { $phases[$key]['wins']++; $phases[$key]['points'] += 3; }
  elseif ($gf<$ga) { $phases[$key]['losses']++; }
  else { $phases[$key]['draws']++; $phases[$key]['points'] += 1; }

  $label = trim((string)$m['weather']); $label = $label !== '' ? $label : '(SEM CLIMA)';
  $key = normKey($label);
  if (!isset($weathers[$key])) $weathers[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0];
  $weathers[$key]['played']++;
  if ($gf>$ga) { $weathers[$key]['wins']++; $weathers[$key]['points'] += 3; }
  elseif ($gf<$ga) { $weathers[$key]['losses']++; }
  else { $weathers[$key]['draws']++; $weathers[$key]['points'] += 1; }
}

$summary['gd'] = $summary['gf'] - $summary['ga'];
$maxPoints = $summary['played'] * 3;
$summary['pct'] = $maxPoints > 0 ? round(($summary['points'] / $maxPoints) * 100, 2) : 0.0;

foreach (['home','away'] as $b) {
  $homeAway[$b]['gd'] = $homeAway[$b]['gf'] - $homeAway[$b]['ga'];
  $mp = $homeAway[$b]['played'] * 3;
  $homeAway[$b]['pct'] = $mp > 0 ? round(($homeAway[$b]['points'] / $mp) * 100, 2) : 0.0;
}

$streaks = compute_streaks($matches);

// -----------------------------------------------------------------------------
// Top 10 goleadas
// -----------------------------------------------------------------------------
$tmp = [];
foreach ($matches as $m) {
  $gf = (int)$m['gf']; $ga = (int)$m['ga'];
  if ($gf > $ga) {
    $tmp[] = [
      'date'        => (string)$m['match_date'],
      'opponent'    => (string)$m['opponent'],
      'score'       => $gf . " x " . $ga,
      'diff'        => $gf - $ga,
      'competition' => (string)$m['competition'],
      'phase'       => (string)$m['phase'],
      'round'       => (string)$m['round'],
    ];
  }
}
usort($tmp, fn($a,$b)=> ($b['diff'] <=> $a['diff']) ?: strcmp($b['date'],$a['date']));
$topBlowouts = array_slice($tmp, 0, 10);

// Placar mais comum
$commonScorelines = [];
foreach ($scorelines as $k=>$v) $commonScorelines[] = ['scoreline'=>$k,'count'=>$v];
usort($commonScorelines, fn($a,$b)=> ($b['count'] <=> $a['count']) ?: strcmp($a['scoreline'],$b['scoreline']));
$commonScorelines = array_slice($commonScorelines, 0, 10);

// Rankings simples
$oppMostPlayed = array_values($opponents);
usort($oppMostPlayed, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$oppMostPlayed = array_slice($oppMostPlayed, 0, 10);

$stadMostPlayed = array_values($stadiums);
usort($stadMostPlayed, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$stadMostPlayed = array_slice($stadMostPlayed, 0, 10);

$kitRank = array_values($kits);
usort($kitRank, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$kitRank = array_slice($kitRank, 0, 15);

$refRank = array_values($refs);
usort($refRank, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$refRank = array_slice($refRank, 0, 15);

$phaseRank = array_values($phases);
usort($phaseRank, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$phaseRank = array_slice($phaseRank, 0, 15);

$weatherRank = array_values($weathers);
usort($weatherRank, fn($a,$b)=> ($b['played'] <=> $a['played']) ?: strcmp($a['label'],$b['label']));
$weatherRank = array_slice($weatherRank, 0, 15);

// -----------------------------------------------------------------------------
// Player stats
// -----------------------------------------------------------------------------
$sqlTopGames = "
  SELECT p.name AS player_name, COUNT(*) AS games
  FROM match_players mp
  JOIN players p ON p.id = mp.player_id
  JOIN matches m ON m.id = mp.match_id
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
    AND UPPER(TRIM(mp.club_name)) = $clubNorm
    AND COALESCE(mp.entered,0) = 1
  GROUP BY mp.player_id
  ORDER BY games DESC, player_name ASC
  LIMIT 100
";
$st = $pdo->prepare($sqlTopGames);
$st->execute($bind);
$topGames100 = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlCleanSheets = "
  SELECT p.name AS player_name, COUNT(*) AS clean_sheets
  FROM match_players mp
  JOIN players p ON p.id = mp.player_id
  JOIN matches m ON m.id = mp.match_id
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
    AND UPPER(TRIM(mp.club_name)) = $clubNorm
    AND COALESCE(mp.entered,0) = 1
    AND UPPER(TRIM(mp.position)) IN ('GK','GOL','GOLEIRO')
    AND ($clubGA) = 0
  GROUP BY mp.player_id
  ORDER BY clean_sheets DESC, player_name ASC
  LIMIT 10
";
$st = $pdo->prepare($sqlCleanSheets);
$st->execute($bind);
$topCleanSheets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Consecutivos
$topConsecutiveGames = [];
if ($matches) {
  $matchIds = array_map(fn($r)=> (int)$r['id'], $matches);
  $in = implode(',', array_fill(0, count($matchIds), '?'));

  $sqlPlays = "
    SELECT mp.match_id, mp.player_id
    FROM match_players mp
    WHERE mp.match_id IN ($in)
      AND UPPER(TRIM(mp.club_name)) = UPPER(TRIM(?))
      AND COALESCE(mp.entered,0) = 1
  ";
  $st = $pdo->prepare($sqlPlays);
  $params = array_merge($matchIds, [$club]);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $playedByMatch = [];
  foreach ($rows as $r) {
    $mid = (int)$r['match_id'];
    $pid = (int)$r['player_id'];
    $playedByMatch[$mid][$pid] = true;
  }

  $cur = [];
  $best = [];
  foreach ($matchIds as $mid) {
    $set = $playedByMatch[$mid] ?? [];

    foreach ($cur as $pid => $_len) {
      if (!isset($set[$pid])) $cur[$pid] = 0;
    }
    foreach ($set as $pid => $_) {
      $cur[$pid] = ($cur[$pid] ?? 0) + 1;
      if (!isset($best[$pid]) || $cur[$pid] > $best[$pid]) $best[$pid] = $cur[$pid];
    }
  }

  if ($best) {
    $pids = array_keys($best);
    $in2 = implode(',', array_fill(0, count($pids), '?'));
    $st = $pdo->prepare("SELECT id, name FROM players WHERE id IN ($in2)");
    $st->execute($pids);
    $nameRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $names = [];
    foreach ($nameRows as $nr) $names[(int)$nr['id']] = (string)$nr['name'];

    $tmp = [];
    foreach ($best as $pid => $len) {
      $tmp[] = ['player_name' => $names[(int)$pid] ?? ("ID #".(int)$pid), 'streak' => (int)$len];
    }
    usort($tmp, fn($a,$b)=> ($b['streak'] <=> $a['streak']) ?: strcmp($a['player_name'],$b['player_name']));
    $topConsecutiveGames = array_slice($tmp, 0, 100);
  }
}

// -----------------------------------------------------------------------------
// Artilheiros / Assistências (se existir match_player_stats com colunas)
// -----------------------------------------------------------------------------
$topScorers = [];
$topAssists = [];
$mpStatsAvailable = false;

if (table_exists($pdo, 'match_player_stats')) {
  $cols = table_columns($pdo, 'match_player_stats');
  $hasGoals   = in_array('goals', $cols, true);
  $hasAssists = in_array('assists', $cols, true);

  if ($hasGoals || $hasAssists) {
    $mpStatsAvailable = true;

    if ($hasGoals) {
      $sql = "
        SELECT p.name AS player_name, SUM(COALESCE(mps.goals,0)) AS goals
        FROM match_player_stats mps
        JOIN players p ON p.id = mps.player_id
        JOIN matches m ON m.id = mps.match_id
        $whereSql
        " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
          AND UPPER(TRIM(mps.club_name)) = $clubNorm
        GROUP BY mps.player_id
        HAVING goals > 0
        ORDER BY goals DESC, player_name ASC
        LIMIT 10
      ";
      $st = $pdo->prepare($sql);
      $st->execute($bind);
      $topScorers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($hasAssists) {
      $sql = "
        SELECT p.name AS player_name, SUM(COALESCE(mps.assists,0)) AS assists
        FROM match_player_stats mps
        JOIN players p ON p.id = mps.player_id
        JOIN matches m ON m.id = mps.match_id
        $whereSql
        " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
          AND UPPER(TRIM(mps.club_name)) = $clubNorm
        GROUP BY mps.player_id
        HAVING assists > 0
        ORDER BY assists DESC, player_name ASC
        LIMIT 10
      ";
      $st = $pdo->prepare($sql);
      $st->execute($bind);
      $topAssists = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  }
}

// -----------------------------------------------------------------------------
// Extras aplicadas
// -----------------------------------------------------------------------------
$topMostGoals = [];
$tmp = [];
foreach ($matches as $m) {
  $gf = (int)$m['gf']; $ga = (int)$m['ga'];
  $tmp[] = [
    'date'        => (string)$m['match_date'],
    'opponent'    => (string)$m['opponent'],
    'score'       => $gf . " x " . $ga,
    'total'       => $gf + $ga,
    'competition' => (string)$m['competition'],
  ];
}
usort($tmp, fn($a,$b)=> ($b['total'] <=> $a['total']) ?: strcmp($b['date'],$a['date']));
$topMostGoals = array_slice($tmp, 0, 10);

$topWinsByGF = [];
$tmp = [];
foreach ($matches as $m) {
  $gf = (int)$m['gf']; $ga = (int)$m['ga'];
  if ($gf > $ga) {
    $tmp[] = [
      'date'     => (string)$m['match_date'],
      'opponent' => (string)$m['opponent'],
      'score'    => $gf . " x " . $ga,
      'gf'       => $gf,
      'diff'     => $gf - $ga,
    ];
  }
}
usort($tmp, fn($a,$b)=> ($b['gf'] <=> $a['gf']) ?: ($b['diff'] <=> $a['diff']) ?: strcmp($b['date'],$a['date']));
$topWinsByGF = array_slice($tmp, 0, 10);

// -----------------------------------------------------------------------------
// Render (padrão matches.php)
// -----------------------------------------------------------------------------
render_header('Relatórios');

// Filtro
echo '<div class="card-soft mb-3">';
echo '  <form method="get" class="p-3">';
echo '    <input type="hidden" name="page" value="stats">';
echo '    <div class="row g-2 align-items-end">';
echo '      <div class="col-12 col-md-3">';
echo '        <label class="form-label">Temporada</label>';
echo '        <input class="form-control" name="season" value="'.h($season).'" placeholder="ex: 2026">';
echo '      </div>';
echo '      <div class="col-12 col-md-7">';
echo '        <label class="form-label">Campeonato</label>';
echo '        <input class="form-control" name="competition" value="'.h($competition).'" placeholder="ex: Brasileirão">';
echo '      </div>';
echo '      <div class="col-12 col-md-2 d-grid">';
echo '        <button class="btn btn-success" type="submit">Aplicar</button>';
echo '      </div>';
echo '    </div>';
echo '    <div class="text-muted mt-2">Clube (case-insensitive): <b>'.h($club).'</b> • Partidas consideradas: <b>'.(int)count($matches).'</b></div>';
echo '  </form>';
echo '</div>';

// Cards resumo
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Aproveitamento</h5>';
echo '    <div><b>Jogos:</b> '.(int)$summary['played'].'</div>';
echo '    <div><b>V/E/D:</b> '.(int)$summary['wins'].' / '.(int)$summary['draws'].' / '.(int)$summary['losses'].'</div>';
echo '    <div><b>Pontos:</b> '.(int)$summary['points'].' <span class="text-muted">('.h((string)$summary['pct']).'%)</span></div>';
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Gols</h5>';
echo '    <div><b>GF:</b> '.(int)$summary['gf'].'</div>';
echo '    <div><b>GA:</b> '.(int)$summary['ga'].'</div>';
echo '    <div><b>Saldo:</b> '.(int)$summary['gd'].'</div>';
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Sequências</h5>';
echo '    <div><b>Invicta:</b> '.(int)$streaks['unbeaten']['len'].' <span class="text-muted">('.h((string)($streaks['unbeaten']['start'] ?? '-')).' → '.h((string)($streaks['unbeaten']['end'] ?? '-')).')</span></div>';
echo '    <div><b>Sem vitórias:</b> '.(int)$streaks['nowins']['len'].' <span class="text-muted">('.h((string)($streaks['nowins']['start'] ?? '-')).' → '.h((string)($streaks['nowins']['end'] ?? '-')).')</span></div>';
echo '    <div><b>Vitórias:</b> '.(int)$streaks['wins']['len'].' <span class="text-muted">('.h((string)($streaks['wins']['start'] ?? '-')).' → '.h((string)($streaks['wins']['end'] ?? '-')).')</span></div>';
echo '    <div><b>Derrotas:</b> '.(int)$streaks['losses']['len'].' <span class="text-muted">('.h((string)($streaks['losses']['start'] ?? '-')).' → '.h((string)($streaks['losses']['end'] ?? '-')).')</span></div>';
echo '    <div><b>Sem sofrer gols:</b> '.(int)$streaks['clean']['len'].' <span class="text-muted">('.h((string)($streaks['clean']['start'] ?? '-')).' → '.h((string)($streaks['clean']['end'] ?? '-')).')</span></div>';
echo '  </div>';
echo '</div>';

echo '</div>';

// Mandante x Visitante
echo '<div class="card-soft mb-3 p-3">';
echo '  <div class="d-flex justify-content-between align-items-center mb-2">';
echo '    <h5 class="mb-0">Mandante x Visitante</h5>';
echo '  </div>';
$rowsHA = [
  [
    'cond'=>'Mandante',
    'played'=>$homeAway['home']['played'],'wins'=>$homeAway['home']['wins'],'draws'=>$homeAway['home']['draws'],'losses'=>$homeAway['home']['losses'],
    'gf'=>$homeAway['home']['gf'],'ga'=>$homeAway['home']['ga'],'gd'=>$homeAway['home']['gd'],
    'points'=>$homeAway['home']['points'],'pct'=>$homeAway['home']['pct'].'%'
  ],
  [
    'cond'=>'Visitante',
    'played'=>$homeAway['away']['played'],'wins'=>$homeAway['away']['wins'],'draws'=>$homeAway['away']['draws'],'losses'=>$homeAway['away']['losses'],
    'gf'=>$homeAway['away']['gf'],'ga'=>$homeAway['away']['ga'],'gd'=>$homeAway['away']['gd'],
    'points'=>$homeAway['away']['points'],'pct'=>$homeAway['away']['pct'].'%'
  ],
];
render_table(['Condição','J','V','E','D','GF','GA','SG','Pts','%'], $rowsHA, ['cond','played','wins','draws','losses','gf','ga','gd','points','pct'], 10);
echo '</div>';

// Seções principais
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 goleadas</h5>';
render_table(['Data','Adversário','Placar','Saldo','Competição','Fase','Rodada'], $topBlowouts, ['date','opponent','score','diff','competition','phase','round'], 7);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Placar mais comum (GF x GA)</h5>';
render_table(['Placar','Qtd'], $commonScorelines, ['scoreline','count'], 2);
echo '  </div>';
echo '</div>';

echo '</div>';

// Artilheiros / Assistências
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 artilheiros</h5>';
if ($mpStatsAvailable) {
  render_table(['Jogador','Gols'], $topScorers, ['player_name','goals'], 2);
} else {
  echo '<div class="text-muted">Sem dados: tabela <code>match_player_stats</code> não encontrada (ou sem colunas <code>goals</code>).</div>';
}
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 líderes em assistência</h5>';
if ($mpStatsAvailable) {
  render_table(['Jogador','Assistências'], $topAssists, ['player_name','assists'], 2);
} else {
  echo '<div class="text-muted">Sem dados: tabela <code>match_player_stats</code> não encontrada (ou sem colunas <code>assists</code>).</div>';
}
echo '  </div>';
echo '</div>';

echo '</div>';

// Goleiros / Jogos
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 goleiros com clean sheet</h5>';
render_table(['Goleiro','Clean sheets'], $topCleanSheets, ['player_name','clean_sheets'], 2);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-8">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 100 jogadores com mais jogos</h5>';
render_table(['Jogador','Jogos'], $topGames100, ['player_name','games'], 2);
echo '  </div>';
echo '</div>';

echo '</div>';

// Consecutivos
echo '<div class="card-soft mb-3 p-3">';
echo '  <h5 class="mb-2">Atletas com mais jogos consecutivos</h5>';
render_table(['Jogador','Streak'], $topConsecutiveGames, ['player_name','streak'], 2);
echo '  <div class="text-muted mt-2">Streak baseado em <code>entered=1</code> em partidas consecutivas dentro do filtro aplicado.</div>';
echo '</div>';

// Extras
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 jogos com mais gols (GF+GA)</h5>';
render_table(['Data','Adversário','Placar','Total','Competição'], $topMostGoals, ['date','opponent','score','total','competition'], 5);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 vitórias com mais gols marcados (GF)</h5>';
render_table(['Data','Adversário','Placar','GF','Saldo'], $topWinsByGF, ['date','opponent','score','gf','diff'], 5);
echo '  </div>';
echo '</div>';

echo '</div>';

// Rankings gerais
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 adversários mais enfrentados</h5>';
render_table(['Adversário','J','V','E','D'], $oppMostPlayed, ['label','played','wins','draws','losses'], 5);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Top 10 estádios com mais jogos</h5>';
render_table(['Estádio','J','V','E','D'], $stadMostPlayed, ['label','played','wins','draws','losses'], 5);
echo '  </div>';
echo '</div>';

echo '</div>';

echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Uniformes (mais usados)</h5>';
render_table(['Uniforme','J','V','E','D','Pts'], $kitRank, ['label','played','wins','draws','losses','points'], 6);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Árbitros (mais jogos)</h5>';
render_table(['Árbitro','J','V','E','D'], $refRank, ['label','played','wins','draws','losses'], 5);
echo '  </div>';
echo '</div>';

echo '</div>';

echo '<div class="row g-3 mb-4">';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Fases (phase)</h5>';
$phaseRows = [];
foreach ($phaseRank as $r) {
  $phaseRows[] = [
    'label'=>$r['label'],
    'played'=>$r['played'],'wins'=>$r['wins'],'draws'=>$r['draws'],'losses'=>$r['losses'],
    'gf'=>$r['gf'],'ga'=>$r['ga'],'points'=>$r['points']
  ];
}
render_table(['Fase','J','V','E','D','GF','GA','Pts'], $phaseRows, ['label','played','wins','draws','losses','gf','ga','points'], 8);
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-6">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Clima (weather)</h5>';
render_table(['Clima','J','V','E','D','Pts'], $weatherRank, ['label','played','wins','draws','losses','points'], 6);
echo '  </div>';
echo '</div>';

echo '</div>';

render_footer();
