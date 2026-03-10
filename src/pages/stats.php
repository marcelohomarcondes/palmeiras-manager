<?php
// src/pages/stats.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
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

function fmt_int($v): int {
  return (int)($v ?? 0);
}

function fmt_pct(float $points, int $played): string {
  if ($played <= 0) return '0,00%';
  return number_format(($points / ($played * 3)) * 100, 2, ',', '.') . '%';
}

function fmt_ppj(int $points, int $played): string {
  if ($played <= 0) return '0,00';
  return number_format($points / $played, 2, ',', '.');
}

function fmt_date_br(?string $date): string {
  $date = trim((string)$date);
  if ($date === '') return '-';
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    return $m[3] . '/' . $m[2] . '/' . $m[1];
  }
  return $date;
}

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function table_columns(PDO $pdo, string $table): array {
  $cols = [];
  $st = $pdo->query("PRAGMA table_info($table)");
  foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
    $cols[] = (string)$r['name'];
  }
  return $cols;
}

function pick_existing_column(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (in_array($c, $cols, true)) return $c;
  }
  return null;
}

function render_table(array $headers, array $rows, array $keys, int $colspanIfEmpty): void {
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm mb-0 align-middle">';
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

function render_table_scroll(array $headers, array $rows, array $keys, int $colspanIfEmpty, int $maxHeightPx = 360): void {
  echo '<div class="table-responsive pm-scroll-table" style="max-height: '.(int)$maxHeightPx.'px;">';
  echo '<table class="table table-sm mb-0 align-middle">';
  echo '<thead><tr>';
  foreach ($headers as $hh) {
    echo '<th>'.h((string)$hh).'</th>';
  }
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
    $gf   = fmt_int($m['gf'] ?? 0);
    $ga   = fmt_int($m['ga'] ?? 0);
    $date = (string)($m['match_date'] ?? '');

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

function sort_label_played(array $a, array $b): int {
  return ($b['played'] <=> $a['played']) ?: strcmp((string)$a['label'], (string)$b['label']);
}

// -----------------------------------------------------------------------------
// Filtros (listas suspensas)
// -----------------------------------------------------------------------------
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));

$st = $pdo->query("SELECT DISTINCT TRIM(COALESCE(season, '')) AS season FROM matches WHERE TRIM(COALESCE(season, '')) <> '' ORDER BY season DESC");
$seasonOptions = array_map(fn($r) => (string)$r['season'], $st ? $st->fetchAll(PDO::FETCH_ASSOC) : []);

if ($season !== '') {
  $st = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE(competition, '')) AS competition FROM matches WHERE TRIM(COALESCE(competition, '')) <> '' AND UPPER(TRIM(COALESCE(season, ''))) = UPPER(TRIM(?)) ORDER BY competition ASC");
  $st->execute([$season]);
} else {
  $st = $pdo->query("SELECT DISTINCT TRIM(COALESCE(competition, '')) AS competition FROM matches WHERE TRIM(COALESCE(competition, '')) <> '' ORDER BY competition ASC");
}
$competitionOptions = array_map(fn($r) => (string)$r['competition'], $st ? $st->fetchAll(PDO::FETCH_ASSOC) : []);

$bind = [':club' => $club];
$where = [];
if ($season !== '') {
  $where[] = "UPPER(TRIM(m.season)) = UPPER(TRIM(:season))";
  $bind[':season'] = $season;
}
if ($competition !== '') {
  $where[] = "UPPER(TRIM(m.competition)) = UPPER(TRIM(:competition))";
  $bind[':competition'] = $competition;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$clubNorm = "UPPER(TRIM(:club))";
$homeNorm = "UPPER(TRIM(m.home))";
$awayNorm = "UPPER(TRIM(m.away))";
$isClubInMatch = "($homeNorm = $clubNorm OR $awayNorm = $clubNorm)";
$clubGF = "CASE WHEN $homeNorm = $clubNorm THEN COALESCE(m.home_score,0) ELSE COALESCE(m.away_score,0) END";
$clubGA = "CASE WHEN $homeNorm = $clubNorm THEN COALESCE(m.away_score,0) ELSE COALESCE(m.home_score,0) END";
$opponentExpr = "CASE WHEN $homeNorm = $clubNorm THEN m.away ELSE m.home END";
$isHomeExpr = "CASE WHEN $homeNorm = $clubNorm THEN 1 ELSE 0 END";

// -----------------------------------------------------------------------------
// Partidas do clube
// -----------------------------------------------------------------------------
$sqlMatches = "
  SELECT
    m.id,
    m.match_date,
    COALESCE(m.season,'')      AS season,
    COALESCE(m.competition,'') AS competition,
    COALESCE(m.phase,'')       AS phase,
    COALESCE(m.round,'')       AS round,
    COALESCE(m.stadium,'')     AS stadium,
    COALESCE(m.referee,'')     AS referee,
    COALESCE(m.kit_used,'')    AS kit_used,
    COALESCE(m.weather,'')     AS weather,
    COALESCE(m.home,'')        AS home,
    COALESCE(m.away,'')        AS away,
    COALESCE(m.home_score,0)   AS home_score,
    COALESCE(m.away_score,0)   AS away_score,
    $opponentExpr AS opponent,
    $isHomeExpr   AS is_home,
    $clubGF       AS gf,
    $clubGA       AS ga
  FROM matches m
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
  ORDER BY m.match_date ASC, m.id ASC
";
$st = $pdo->prepare($sqlMatches);
$st->execute($bind);
$matches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -----------------------------------------------------------------------------
// Resumo / rankings base
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
  $gf = fmt_int($m['gf']);
  $ga = fmt_int($m['ga']);
  $summary['played']++;
  $summary['gf'] += $gf;
  $summary['ga'] += $ga;

  if ($gf > $ga) {
    $summary['wins']++;
    $summary['points'] += 3;
  } elseif ($gf < $ga) {
    $summary['losses']++;
  } else {
    $summary['draws']++;
    $summary['points'] += 1;
  }

  $bucket = ((int)$m['is_home'] === 1) ? 'home' : 'away';
  $homeAway[$bucket]['played']++;
  $homeAway[$bucket]['gf'] += $gf;
  $homeAway[$bucket]['ga'] += $ga;
  if ($gf > $ga) {
    $homeAway[$bucket]['wins']++;
    $homeAway[$bucket]['points'] += 3;
  } elseif ($gf < $ga) {
    $homeAway[$bucket]['losses']++;
  } else {
    $homeAway[$bucket]['draws']++;
    $homeAway[$bucket]['points'] += 1;
  }

  $scoreline = $gf . ' x ' . $ga;
  $scorelines[$scoreline] = ($scorelines[$scoreline] ?? 0) + 1;

  $label = trim((string)$m['opponent']);
  $label = $label !== '' ? $label : '(SEM ADVERSÁRIO)';
  $key = normKey($label);
  if (!isset($opponents[$key])) {
    $opponents[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'points'=>0];
  }
  $opponents[$key]['played']++;
  $opponents[$key]['gf'] += $gf;
  $opponents[$key]['ga'] += $ga;
  if ($gf > $ga) {
    $opponents[$key]['wins']++;
    $opponents[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $opponents[$key]['losses']++;
  } else {
    $opponents[$key]['draws']++;
    $opponents[$key]['points'] += 1;
  }

  $label = trim((string)$m['stadium']);
  $label = $label !== '' ? $label : '(SEM ESTÁDIO)';
  $key = normKey($label);
  if (!isset($stadiums[$key])) {
    $stadiums[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'points'=>0];
  }
  $stadiums[$key]['played']++;
  $stadiums[$key]['gf'] += $gf;
  $stadiums[$key]['ga'] += $ga;
  if ($gf > $ga) {
    $stadiums[$key]['wins']++;
    $stadiums[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $stadiums[$key]['losses']++;
  } else {
    $stadiums[$key]['draws']++;
    $stadiums[$key]['points'] += 1;
  }

  $label = trim((string)$m['referee']);
  $label = $label !== '' ? $label : '(SEM ÁRBITRO)';
  $key = normKey($label);
  if (!isset($refs[$key])) {
    $refs[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0,'yellow'=>0,'red'=>0];
  }
  $refs[$key]['played']++;
  if ($gf > $ga) {
    $refs[$key]['wins']++;
    $refs[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $refs[$key]['losses']++;
  } else {
    $refs[$key]['draws']++;
    $refs[$key]['points'] += 1;
  }

  $label = trim((string)$m['kit_used']);
  $label = $label !== '' ? $label : '(SEM UNIFORME)';
  $key = normKey($label);
  if (!isset($kits[$key])) {
    $kits[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0];
  }
  $kits[$key]['played']++;
  if ($gf > $ga) {
    $kits[$key]['wins']++;
    $kits[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $kits[$key]['losses']++;
  } else {
    $kits[$key]['draws']++;
    $kits[$key]['points'] += 1;
  }

  $label = trim((string)$m['phase']);
  $label = $label !== '' ? $label : '(SEM FASE)';
  $key = normKey($label);
  if (!isset($phases[$key])) {
    $phases[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'points'=>0];
  }
  $phases[$key]['played']++;
  $phases[$key]['gf'] += $gf;
  $phases[$key]['ga'] += $ga;
  if ($gf > $ga) {
    $phases[$key]['wins']++;
    $phases[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $phases[$key]['losses']++;
  } else {
    $phases[$key]['draws']++;
    $phases[$key]['points'] += 1;
  }

  $label = trim((string)$m['weather']);
  $label = $label !== '' ? $label : '(SEM CLIMA)';
  $key = normKey($label);
  if (!isset($weathers[$key])) {
    $weathers[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0];
  }
  $weathers[$key]['played']++;
  if ($gf > $ga) {
    $weathers[$key]['wins']++;
    $weathers[$key]['points'] += 3;
  } elseif ($gf < $ga) {
    $weathers[$key]['losses']++;
  } else {
    $weathers[$key]['draws']++;
    $weathers[$key]['points'] += 1;
  }
}

$summary['gd'] = $summary['gf'] - $summary['ga'];
$summary['pct'] = ($summary['played'] > 0) ? round(($summary['points'] / ($summary['played'] * 3)) * 100, 2) : 0.0;
foreach (['home', 'away'] as $b) {
  $homeAway[$b]['gd'] = $homeAway[$b]['gf'] - $homeAway[$b]['ga'];
  $homeAway[$b]['pct'] = ($homeAway[$b]['played'] > 0) ? round(($homeAway[$b]['points'] / ($homeAway[$b]['played'] * 3)) * 100, 2) : 0.0;
}

$streaks = compute_streaks($matches);

// -----------------------------------------------------------------------------
// Cartões por árbitro
// -----------------------------------------------------------------------------
$mpStatsAvailable = false;
$goalsCol = null;
$assistsCol = null;
$yellowCol = null;
$redCol = null;

if (table_exists($pdo, 'match_player_stats')) {
  $mpsCols = table_columns($pdo, 'match_player_stats');
  $mpStatsAvailable = true;
  $goalsCol   = pick_existing_column($mpsCols, ['goals_for', 'goals']);
  $assistsCol = pick_existing_column($mpsCols, ['assists']);
  $yellowCol  = pick_existing_column($mpsCols, ['yellow_cards', 'yellows', 'yellow', 'amarelos', 'cartoes_amarelos']);
  $redCol     = pick_existing_column($mpsCols, ['red_cards', 'reds', 'red', 'vermelhos', 'cartoes_vermelhos']);

  if ($yellowCol || $redCol) {
    $selects = [];
    if ($yellowCol) $selects[] = 'SUM(COALESCE(mps.'.$yellowCol.',0)) AS yellow'; else $selects[] = '0 AS yellow';
    if ($redCol) $selects[] = 'SUM(COALESCE(mps.'.$redCol.',0)) AS red'; else $selects[] = '0 AS red';

    $sqlRefCards = "
      SELECT COALESCE(m.referee,'') AS referee, " . implode(', ', $selects) . "
      FROM match_player_stats mps
      JOIN matches m ON m.id = mps.match_id
      $whereSql
      " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
        AND UPPER(TRIM(mps.club_name)) = $clubNorm
      GROUP BY COALESCE(m.referee,'')
    ";
    $st = $pdo->prepare($sqlRefCards);
    $st->execute($bind);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $label = trim((string)$r['referee']);
      $label = $label !== '' ? $label : '(SEM ÁRBITRO)';
      $key = normKey($label);
      if (!isset($refs[$key])) {
        $refs[$key] = ['label'=>$label,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'points'=>0,'yellow'=>0,'red'=>0];
      }
      $refs[$key]['yellow'] = fmt_int($r['yellow']);
      $refs[$key]['red']    = fmt_int($r['red']);
    }
  }
}

// -----------------------------------------------------------------------------
// Tops de partidas
// -----------------------------------------------------------------------------
$topBlowouts = [];
$topMostGoals = [];
$topWinsByGF = [];
foreach ($matches as $m) {
  $gf = fmt_int($m['gf']);
  $ga = fmt_int($m['ga']);

  if ($gf > $ga) {
    $topBlowouts[] = [
      'date'        => fmt_date_br((string)$m['match_date']),
      'opponent'    => (string)$m['opponent'],
      'score'       => $gf . ' x ' . $ga,
      'diff'        => $gf - $ga,
      'competition' => (string)$m['competition'],
      'phase'       => (string)$m['phase'],
      'round'       => (string)$m['round'],
      '_date'       => (string)$m['match_date'],
    ];
    $topWinsByGF[] = [
      'date'     => fmt_date_br((string)$m['match_date']),
      'opponent' => (string)$m['opponent'],
      'score'    => $gf . ' x ' . $ga,
      'gf'       => $gf,
      'diff'     => $gf - $ga,
      '_date'    => (string)$m['match_date'],
    ];
  }

  $topMostGoals[] = [
    'date'        => fmt_date_br((string)$m['match_date']),
    'opponent'    => (string)$m['opponent'],
    'score'       => $gf . ' x ' . $ga,
    'total'       => $gf + $ga,
    'competition' => (string)$m['competition'],
    '_date'       => (string)$m['match_date'],
  ];
}
usort($topBlowouts, fn($a,$b) => ($b['diff'] <=> $a['diff']) ?: strcmp((string)$b['_date'], (string)$a['_date']));
$topBlowouts = array_slice($topBlowouts, 0, 10);
foreach ($topBlowouts as &$r) unset($r['_date']); unset($r);

usort($topMostGoals, fn($a,$b) => ($b['total'] <=> $a['total']) ?: strcmp((string)$b['_date'], (string)$a['_date']));
$topMostGoals = array_slice($topMostGoals, 0, 10);
foreach ($topMostGoals as &$r) unset($r['_date']); unset($r);

usort($topWinsByGF, fn($a,$b) => ($b['gf'] <=> $a['gf']) ?: ($b['diff'] <=> $a['diff']) ?: strcmp((string)$b['_date'], (string)$a['_date']));
$topWinsByGF = array_slice($topWinsByGF, 0, 10);
foreach ($topWinsByGF as &$r) unset($r['_date']); unset($r);

$commonScorelines = [];
foreach ($scorelines as $k => $v) {
  $commonScorelines[] = ['scoreline'=>$k, 'count'=>$v];
}
usort($commonScorelines, fn($a,$b) => ($b['count'] <=> $a['count']) ?: strcmp((string)$a['scoreline'], (string)$b['scoreline']));
$commonScorelines = array_slice($commonScorelines, 0, 10);

$oppMostPlayed = array_values($opponents);
foreach ($oppMostPlayed as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($oppMostPlayed, 'sort_label_played');
$oppMostPlayed = array_slice($oppMostPlayed, 0, 10);

$stadMostPlayed = array_values($stadiums);
foreach ($stadMostPlayed as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($stadMostPlayed, 'sort_label_played');
$stadMostPlayed = array_slice($stadMostPlayed, 0, 10);

$kitRank = array_values($kits);
foreach ($kitRank as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($kitRank, 'sort_label_played');
$kitRank = array_slice($kitRank, 0, 15);

$refRank = array_values($refs);
foreach ($refRank as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($refRank, 'sort_label_played');
$refRank = array_slice($refRank, 0, 15);

$phaseRank = array_values($phases);
foreach ($phaseRank as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($phaseRank, 'sort_label_played');
$phaseRank = array_slice($phaseRank, 0, 15);

$weatherRank = array_values($weathers);
foreach ($weatherRank as &$r) {
  $r['pct'] = fmt_pct((float)$r['points'], (int)$r['played']);
}
unset($r);
usort($weatherRank, 'sort_label_played');
$weatherRank = array_slice($weatherRank, 0, 15);

// -----------------------------------------------------------------------------
// Participação real dos atletas: titular + quem entrou
// -----------------------------------------------------------------------------
$topGames100 = [];
$topCleanSheets = [];
$topConsecutiveGames = [];
$topScorers = [];
$topAssists = [];

$playerParticipationSubquery = "
  (
    SELECT mp.match_id, mp.player_id
    FROM match_players mp
    WHERE mp.player_id IS NOT NULL
      AND UPPER(TRIM(mp.club_name)) = $clubNorm
      AND (
        UPPER(TRIM(COALESCE(mp.role,''))) = 'STARTER'
        OR COALESCE(mp.entered, 0) = 1
      )

    UNION

    SELECT s.match_id, s.player_in_id AS player_id
    FROM substitutions s
    WHERE s.player_in_id IS NOT NULL
      AND UPPER(TRIM(s.club_name)) = $clubNorm

    UNION

    SELECT mps.match_id, mps.player_id
    FROM match_player_stats mps
    WHERE mps.player_id IS NOT NULL
      AND UPPER(TRIM(mps.club_name)) = $clubNorm
  )
";

$sqlTopGames = "
  SELECT p.name AS player_name, COUNT(DISTINCT part.match_id) AS games
  FROM $playerParticipationSubquery part
  JOIN players p ON p.id = part.player_id
  JOIN matches m ON m.id = part.match_id
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
  GROUP BY part.player_id
  ORDER BY games DESC, player_name ASC
  LIMIT 100
";
$st = $pdo->prepare($sqlTopGames);
$st->execute($bind);
$topGames100 = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlCleanSheets = "
  SELECT p.name AS player_name, COUNT(DISTINCT part.match_id) AS clean_sheets
  FROM (
    SELECT pp.match_id, pp.player_id
    FROM $playerParticipationSubquery pp
    JOIN players p2 ON p2.id = pp.player_id
    LEFT JOIN match_players mp
      ON mp.match_id = pp.match_id
     AND mp.player_id = pp.player_id
     AND UPPER(TRIM(mp.club_name)) = $clubNorm
    WHERE UPPER(TRIM(COALESCE(NULLIF(mp.position,''), p2.primary_position, ''))) IN ('GK','GOL','GOLEIRO')
  ) part
  JOIN players p ON p.id = part.player_id
  JOIN matches m ON m.id = part.match_id
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " $isClubInMatch
    AND ($clubGA) = 0
  GROUP BY part.player_id
  ORDER BY clean_sheets DESC, player_name ASC
  LIMIT 10
";
$st = $pdo->prepare($sqlCleanSheets);
$st->execute($bind);
$topCleanSheets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($matches) {
  $matchIds = array_map(fn($r) => (int)$r['id'], $matches);
  $in = implode(',', array_fill(0, count($matchIds), '?'));

  $sqlPlays = "
    SELECT part.match_id, part.player_id
    FROM (
      SELECT mp.match_id, mp.player_id
      FROM match_players mp
      WHERE mp.match_id IN ($in)
        AND mp.player_id IS NOT NULL
        AND UPPER(TRIM(mp.club_name)) = UPPER(TRIM(?))
        AND (
          UPPER(TRIM(COALESCE(mp.role,''))) = 'STARTER'
          OR COALESCE(mp.entered, 0) = 1
        )

      UNION

      SELECT s.match_id, s.player_in_id AS player_id
      FROM substitutions s
      WHERE s.match_id IN ($in)
        AND s.player_in_id IS NOT NULL
        AND UPPER(TRIM(s.club_name)) = UPPER(TRIM(?))

      UNION

      SELECT mps.match_id, mps.player_id
      FROM match_player_stats mps
      WHERE mps.match_id IN ($in)
        AND mps.player_id IS NOT NULL
        AND UPPER(TRIM(mps.club_name)) = UPPER(TRIM(?))
    ) part
  ";

  $st = $pdo->prepare($sqlPlays);
  $params = array_merge($matchIds, [$club], $matchIds, [$club], $matchIds, [$club]);
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

    foreach (array_keys($cur) as $pid) {
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

    foreach ($best as $pid => $len) {
      $topConsecutiveGames[] = ['player_name' => $names[(int)$pid] ?? ('ID #'.(int)$pid), 'streak' => (int)$len];
    }
    usort($topConsecutiveGames, fn($a,$b) => ($b['streak'] <=> $a['streak']) ?: strcmp((string)$a['player_name'], (string)$b['player_name']));
    $topConsecutiveGames = array_slice($topConsecutiveGames, 0, 100);
  }
}

// -----------------------------------------------------------------------------
// Artilheiros e assistências (somente com match_player_stats)
// -----------------------------------------------------------------------------
if ($mpStatsAvailable && ($goalsCol || $assistsCol)) {
  $playedSubquery = $playerParticipationSubquery;

  if ($goalsCol) {
    $sql = "
      SELECT p.name AS player_name, SUM(COALESCE(mps.$goalsCol,0)) AS goals
      FROM match_player_stats mps
      JOIN $playedSubquery part
        ON part.match_id = mps.match_id
       AND part.player_id = mps.player_id
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

  if ($assistsCol) {
    $sql = "
      SELECT p.name AS player_name, SUM(COALESCE(mps.$assistsCol,0)) AS assists
      FROM match_player_stats mps
      JOIN $playedSubquery part
        ON part.match_id = mps.match_id
       AND part.player_id = mps.player_id
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

// -----------------------------------------------------------------------------
// Gráficos
// -----------------------------------------------------------------------------
$chart = [
  'meta' => [
    'club' => $club,
    'season' => $season,
    'competition' => $competition,
    'count' => count($matches),
  ],
  'wdl' => [
    'labels' => ['Vitórias', 'Empates', 'Derrotas'],
    'values' => [(int)$summary['wins'], (int)$summary['draws'], (int)$summary['losses']],
  ],
  'timeline' => [
    'labels' => [],
    'points_cum' => [],
    'gd_cum' => [],
    'gf' => [],
    'ga' => [],
  ],
  'homeaway' => [
    'labels' => ['Mandante', 'Visitante'],
    'ppj' => [],
    'gf' => [],
    'ga' => [],
    'gd' => [],
  ],
  'opponents' => ['labels' => [], 'played' => [], 'ppj' => []],
  'kits' => ['labels' => [], 'played' => [], 'ppj' => []],
  'phases' => ['labels' => [], 'ppj' => []],
  'weathers' => ['labels' => [], 'ppj' => []],
  'players' => [
    'games_top10'   => ['labels'=>[],'values'=>[]],
    'consec_top10'  => ['labels'=>[],'values'=>[]],
    'cleans_top10'  => ['labels'=>[],'values'=>[]],
    'scorers_top10' => ['labels'=>[],'values'=>[]],
    'assists_top10' => ['labels'=>[],'values'=>[]],
    'has_player_stats' => ($mpStatsAvailable && ($goalsCol || $assistsCol)),
  ],
  'score_bubble' => [
    'points' => [],
    'max_gf' => 0,
    'max_ga' => 0,
  ],
];

$pCum = 0;
$gdCum = 0;
foreach ($matches as $m) {
  $gf = fmt_int($m['gf']);
  $ga = fmt_int($m['ga']);
  $pts = ($gf > $ga) ? 3 : (($gf === $ga) ? 1 : 0);
  $pCum += $pts;
  $gdCum += ($gf - $ga);

  $label = fmt_date_br((string)$m['match_date']);
  $opp = trim((string)$m['opponent']);
  if ($opp !== '') $label .= ' • ' . $opp;

  $chart['timeline']['labels'][] = $label;
  $chart['timeline']['points_cum'][] = $pCum;
  $chart['timeline']['gd_cum'][] = $gdCum;
  $chart['timeline']['gf'][] = $gf;
  $chart['timeline']['ga'][] = $ga;
}

$chart['homeaway']['ppj'] = [
  $homeAway['home']['played'] > 0 ? round($homeAway['home']['points'] / $homeAway['home']['played'], 2) : 0.0,
  $homeAway['away']['played'] > 0 ? round($homeAway['away']['points'] / $homeAway['away']['played'], 2) : 0.0,
];
$chart['homeaway']['gf'] = [(int)$homeAway['home']['gf'], (int)$homeAway['away']['gf']];
$chart['homeaway']['ga'] = [(int)$homeAway['home']['ga'], (int)$homeAway['away']['ga']];
$chart['homeaway']['gd'] = [(int)$homeAway['home']['gd'], (int)$homeAway['away']['gd']];

foreach (array_slice($oppMostPlayed, 0, 10) as $r) {
  $chart['opponents']['labels'][] = (string)$r['label'];
  $chart['opponents']['played'][] = (int)$r['played'];
  $chart['opponents']['ppj'][] = (float)str_replace(',', '.', fmt_ppj((int)$r['points'], (int)$r['played']));
}
foreach ($kitRank as $r) {
  $chart['kits']['labels'][] = (string)$r['label'];
  $chart['kits']['played'][] = (int)$r['played'];
  $chart['kits']['ppj'][] = (float)str_replace(',', '.', fmt_ppj((int)$r['points'], (int)$r['played']));
}
foreach ($phaseRank as $r) {
  $chart['phases']['labels'][] = (string)$r['label'];
  $chart['phases']['ppj'][] = (float)str_replace(',', '.', fmt_ppj((int)$r['points'], (int)$r['played']));
}
foreach ($weatherRank as $r) {
  $chart['weathers']['labels'][] = (string)$r['label'];
  $chart['weathers']['ppj'][] = (float)str_replace(',', '.', fmt_ppj((int)$r['points'], (int)$r['played']));
}

foreach (array_slice($topGames100, 0, 10) as $r) {
  $chart['players']['games_top10']['labels'][] = (string)$r['player_name'];
  $chart['players']['games_top10']['values'][] = (int)$r['games'];
}
foreach (array_slice($topConsecutiveGames, 0, 10) as $r) {
  $chart['players']['consec_top10']['labels'][] = (string)$r['player_name'];
  $chart['players']['consec_top10']['values'][] = (int)$r['streak'];
}
foreach ($topCleanSheets as $r) {
  $chart['players']['cleans_top10']['labels'][] = (string)$r['player_name'];
  $chart['players']['cleans_top10']['values'][] = (int)$r['clean_sheets'];
}
foreach ($topScorers as $r) {
  $chart['players']['scorers_top10']['labels'][] = (string)$r['player_name'];
  $chart['players']['scorers_top10']['values'][] = (int)$r['goals'];
}
foreach ($topAssists as $r) {
  $chart['players']['assists_top10']['labels'][] = (string)$r['player_name'];
  $chart['players']['assists_top10']['values'][] = (int)$r['assists'];
}

$maxGF = 0;
$maxGA = 0;
foreach ($scorelines as $label => $count) {
  if (!preg_match('/^(\d+) x (\d+)$/', $label, $m)) continue;
  $gf = (int)$m[1];
  $ga = (int)$m[2];
  $maxGF = max($maxGF, $gf);
  $maxGA = max($maxGA, $ga);
  $chart['score_bubble']['points'][] = [
    'x' => $gf,
    'y' => $ga,
    'r' => 4 + min(18, (int)$count * 3),
    'v' => (int)$count,
    'label' => $label,
  ];
}
$chart['score_bubble']['max_gf'] = $maxGF;
$chart['score_bubble']['max_ga'] = $maxGA;

// -----------------------------------------------------------------------------
// Render
// -----------------------------------------------------------------------------
render_header('Relatórios');

echo '<style>
.pm-scroll-table{overflow:auto;position:relative;}
.pm-scroll-table table{border-collapse:separate;border-spacing:0;margin-bottom:0;}
.pm-scroll-table thead,
.pm-scroll-table thead tr,
.pm-scroll-table thead th{background:var(--bs-body-bg, #0f172a)!important;background-clip:padding-box;}
.pm-scroll-table thead th{position:sticky;top:0;z-index:10;box-shadow:0 1px 0 rgba(255,255,255,.08);}
.pm-scroll-table tbody td{background:var(--bs-body-bg, transparent);}
</style>';

// Filtros

echo '<div class="card-soft mb-3">';
echo '  <form method="get" class="p-3">';
echo '    <input type="hidden" name="page" value="stats">';
echo '    <div class="row g-2 align-items-end">';
echo '      <div class="col-12 col-md-4">';
echo '        <label class="form-label">Temporada</label>';
echo '        <select class="form-select" name="season">';
echo '          <option value="">Todas</option>';
foreach ($seasonOptions as $opt) {
  echo '<option value="'.h($opt).'"'.($season === $opt ? ' selected' : '').'>'.h($opt).'</option>';
}
echo '        </select>';
echo '      </div>';
echo '      <div class="col-12 col-md-4">';
echo '        <label class="form-label">Campeonato</label>';
echo '        <select class="form-select" name="competition">';
echo '          <option value="">Todos</option>';
foreach ($competitionOptions as $opt) {
  echo '<option value="'.h($opt).'"'.($competition === $opt ? ' selected' : '').'>'.h($opt).'</option>';
}
echo '        </select>';
echo '      </div>';
echo '      <div class="col-6 col-md-2 d-grid">';
echo '        <button class="btn btn-success" type="submit">Aplicar</button>';
echo '      </div>';
echo '      <div class="col-6 col-md-2 d-grid">';
echo '        <a class="btn btn-outline-secondary" href="?page=stats">Limpar</a>';
echo '      </div>';
echo '    </div>';
echo '    <div class="text-muted mt-2">Clube: <b>'.h($club).'</b> • Partidas consideradas: <b>'.(int)count($matches).'</b></div>';
echo '  </form>';
echo '</div>';

// Cards resumo
echo '<div class="row g-3 mb-3">';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Aproveitamento</h5>';
echo '    <div><b>Jogos:</b> '.(int)$summary['played'].'</div>';
echo '    <div><b>V / E / D:</b> '.(int)$summary['wins'].' / '.(int)$summary['draws'].' / '.(int)$summary['losses'].'</div>';
echo '    <div><b>Pontos:</b> '.(int)$summary['points'].' <span class="text-muted">('.number_format((float)$summary['pct'], 2, ',', '.').'%)</span></div>';
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Gols</h5>';
echo '    <div><b>Gols Pró:</b> '.(int)$summary['gf'].'</div>';
echo '    <div><b>Gols Contra:</b> '.(int)$summary['ga'].'</div>';
echo '    <div><b>Saldo:</b> '.(int)$summary['gd'].'</div>';
echo '  </div>';
echo '</div>';

echo '<div class="col-12 col-lg-4">';
echo '  <div class="card-soft p-3">';
echo '    <h5 class="mb-2">Sequências</h5>';
echo '    <div><b>Invicta:</b> '.(int)$streaks['unbeaten']['len'].' <span class="text-muted">('.fmt_date_br($streaks['unbeaten']['start']).' → '.fmt_date_br($streaks['unbeaten']['end']).')</span></div>';
echo '    <div><b>Sem vitórias:</b> '.(int)$streaks['nowins']['len'].' <span class="text-muted">('.fmt_date_br($streaks['nowins']['start']).' → '.fmt_date_br($streaks['nowins']['end']).')</span></div>';
echo '    <div><b>Vitórias:</b> '.(int)$streaks['wins']['len'].' <span class="text-muted">('.fmt_date_br($streaks['wins']['start']).' → '.fmt_date_br($streaks['wins']['end']).')</span></div>';
echo '    <div><b>Derrotas:</b> '.(int)$streaks['losses']['len'].' <span class="text-muted">('.fmt_date_br($streaks['losses']['start']).' → '.fmt_date_br($streaks['losses']['end']).')</span></div>';
echo '    <div><b>Sem sofrer gols:</b> '.(int)$streaks['clean']['len'].' <span class="text-muted">('.fmt_date_br($streaks['clean']['start']).' → '.fmt_date_br($streaks['clean']['end']).')</span></div>';
echo '  </div>';
echo '</div>';

echo '</div>';

// Gráficos
echo '<div class="card-soft mb-3 p-3">';
echo '  <div class="d-flex justify-content-between align-items-center mb-2">';
echo '    <h5 class="mb-0">Gráficos</h5>';
echo '    <div class="text-muted small">Chart.js • baseado no filtro aplicado</div>';
echo '  </div>';
echo '  <div class="accordion" id="accStatsCharts">';

// 5.1
echo '    <div class="accordion-item">';
echo '      <h2 class="accordion-header" id="accHead1">';
echo '        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#accCol1" aria-expanded="true" aria-controls="accCol1">Visão geral</button>';
echo '      </h2>';
echo '      <div id="accCol1" class="accordion-collapse collapse show" aria-labelledby="accHead1" data-bs-parent="#accStatsCharts">';
echo '        <div class="accordion-body">';
echo '          <div class="row g-3">';
echo '            <div class="col-12 col-xl-8"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Pontos acumulados</b><span class="text-muted small">por partida</span></div><div style="height:280px"><canvas id="ch_points"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-4"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Vitórias / Empates / Derrotas</b><span class="text-muted small">distribuição</span></div><div style="height:280px"><canvas id="ch_wdl"></canvas></div></div></div>';
echo '          </div>';
echo '          <div class="row g-3 mt-0">';
echo '            <div class="col-12"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Saldo acumulado</b><span class="text-muted small">Gols Pró - Gols Contra</span></div><div style="height:260px"><canvas id="ch_gd"></canvas></div></div></div>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

// 5.2
echo '    <div class="accordion-item">';
echo '      <h2 class="accordion-header" id="accHead2">';
echo '        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCol2" aria-expanded="false" aria-controls="accCol2">Ataque &amp; Defesa</button>';
echo '      </h2>';
echo '      <div id="accCol2" class="accordion-collapse collapse" aria-labelledby="accHead2" data-bs-parent="#accStatsCharts">';
echo '        <div class="accordion-body">';
echo '          <div class="row g-3">';
echo '            <div class="col-12"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Gols Pró e Gols Contra por jogo</b><span class="text-muted small">barras</span></div><div style="height:320px"><canvas id="ch_gfga"></canvas></div></div></div>';
echo '          </div>';
echo '          <div class="row g-3 mt-0">';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Placar mais comum</b><span class="text-muted small">Gols Pró x Gols Contra</span></div><div style="height:300px"><canvas id="ch_scorebubble"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Mandante x Visitante</b><span class="text-muted small">PPJ / Gols Pró / Gols Contra</span></div><div style="height:300px"><canvas id="ch_homeaway"></canvas></div></div></div>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

// 5.3
echo '    <div class="accordion-item">';
echo '      <h2 class="accordion-header" id="accHead3">';
echo '        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCol3" aria-expanded="false" aria-controls="accCol3">Contexto</button>';
echo '      </h2>';
echo '      <div id="accCol3" class="accordion-collapse collapse" aria-labelledby="accHead3" data-bs-parent="#accStatsCharts">';
echo '        <div class="accordion-body">';
echo '          <div class="row g-3">';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top adversários</b><span class="text-muted small">Jogos + PPJ</span></div><div style="height:320px"><canvas id="ch_opponents"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Uniformes</b><span class="text-muted small">Jogos + PPJ</span></div><div style="height:320px"><canvas id="ch_kits"></canvas></div></div></div>';
echo '          </div>';
echo '          <div class="row g-3 mt-0">';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Fase</b><span class="text-muted small">PPJ</span></div><div style="height:280px"><canvas id="ch_phases"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Clima</b><span class="text-muted small">PPJ</span></div><div style="height:280px"><canvas id="ch_weather"></canvas></div></div></div>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

// 5.4
echo '    <div class="accordion-item">';
echo '      <h2 class="accordion-header" id="accHead4">';
echo '        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCol4" aria-expanded="false" aria-controls="accCol4">Elenco</button>';
echo '      </h2>';
echo '      <div id="accCol4" class="accordion-collapse collapse" aria-labelledby="accHead4" data-bs-parent="#accStatsCharts">';
echo '        <div class="accordion-body">';
echo '          <div class="row g-3">';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top 10 mais jogos</b><span class="text-muted small">participação real</span></div><div style="height:320px"><canvas id="ch_players_games"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-6"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top 10 jogos consecutivos</b><span class="text-muted small">streak</span></div><div style="height:320px"><canvas id="ch_players_consec"></canvas></div></div></div>';
echo '          </div>';
echo '          <div class="row g-3 mt-0">';
echo '            <div class="col-12 col-xl-4"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top 10 clean sheets</b><span class="text-muted small">goleiros</span></div><div style="height:300px"><canvas id="ch_players_cleans"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-4"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top 10 gols</b><span class="text-muted small">se disponível</span></div><div style="height:300px"><canvas id="ch_players_goals"></canvas></div></div></div>';
echo '            <div class="col-12 col-xl-4"><div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center mb-2"><b>Top 10 assistências</b><span class="text-muted small">se disponível</span></div><div style="height:300px"><canvas id="ch_players_assists"></canvas></div></div></div>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '  </div>';
echo '</div>';

// 6
echo '<div class="card-soft mb-3 p-3">';
echo '  <h5 class="mb-2">Mandante x Visitante</h5>';
$rowsHA = [
  ['cond'=>'Mandante',  'played'=>$homeAway['home']['played'], 'wins'=>$homeAway['home']['wins'], 'draws'=>$homeAway['home']['draws'], 'losses'=>$homeAway['home']['losses'], 'gf'=>$homeAway['home']['gf'], 'ga'=>$homeAway['home']['ga'], 'gd'=>$homeAway['home']['gd'], 'points'=>$homeAway['home']['points'], 'pct'=>number_format((float)$homeAway['home']['pct'], 2, ',', '.').'%'],
  ['cond'=>'Visitante', 'played'=>$homeAway['away']['played'], 'wins'=>$homeAway['away']['wins'], 'draws'=>$homeAway['away']['draws'], 'losses'=>$homeAway['away']['losses'], 'gf'=>$homeAway['away']['gf'], 'ga'=>$homeAway['away']['ga'], 'gd'=>$homeAway['away']['gd'], 'points'=>$homeAway['away']['points'], 'pct'=>number_format((float)$homeAway['away']['pct'], 2, ',', '.').'%'],
];
render_table(['Condição','J','V','E','D','Gols Pró','Gols Contra','Saldo','Pts','Aproveitamento'], $rowsHA, ['cond','played','wins','draws','losses','gf','ga','gd','points','pct'], 10);
echo '</div>';

// 7 e 8
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 goleadas</h5>';
render_table(['Data','Adversário','Placar','Saldo','Competição','Fase','Rodada'], $topBlowouts, ['date','opponent','score','diff','competition','phase','round'], 7);
echo '</div></div>';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Placar mais comum (Gols Pró x Gols Contra)</h5>';
render_table(['Placar','Quantidade'], $commonScorelines, ['scoreline','count'], 2);
echo '</div></div>';
echo '</div>';

// 9 e 10
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 artilheiros</h5>';
if ($goalsCol) {
  render_table(['Jogador','Gols'], $topScorers, ['player_name','goals'], 2);
} else {
  echo '<div class="text-muted">Sem dados: coluna de gols não encontrada em <code>match_player_stats</code>.</div>';
}
echo '</div></div>';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 líderes em assistência</h5>';
if ($assistsCol) {
  render_table(['Jogador','Assistências'], $topAssists, ['player_name','assists'], 2);
} else {
  echo '<div class="text-muted">Sem dados: coluna de assistências não encontrada em <code>match_player_stats</code>.</div>';
}
echo '</div></div>';
echo '</div>';

// 11 e 12
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-4"><div class="card-soft p-3"><h5 class="mb-2">Top 10 goleiros com clean sheet</h5>';
render_table(['Goleiro','Clean sheets'], $topCleanSheets, ['player_name','clean_sheets'], 2);
echo '</div></div>';
echo '<div class="col-12 col-lg-8"><div class="card-soft p-3"><h5 class="mb-2">Top 100 jogadores com mais jogos</h5>';
render_table_scroll(['Jogador','Jogos'], $topGames100, ['player_name','games'], 2, 360);
echo '</div></div>';
echo '</div>';

// 13
echo '<div class="card-soft mb-3 p-3">';
echo '  <h5 class="mb-2">Atletas com mais jogos consecutivos</h5>';
render_table_scroll(['Jogador','Sequência'], $topConsecutiveGames, ['player_name','streak'], 2, 360);
echo '</div>';

// 14 e 15
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 jogos com mais gols (Gols Pró + Gols Contra)</h5>';
render_table(['Data','Adversário','Placar','Total','Competição'], $topMostGoals, ['date','opponent','score','total','competition'], 5);
echo '</div></div>';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 vitórias com mais gols marcados</h5>';
render_table(['Data','Adversário','Placar','Gols Pró','Saldo'], $topWinsByGF, ['date','opponent','score','gf','diff'], 5);
echo '</div></div>';
echo '</div>';

// 16 e 17
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 adversários mais enfrentados</h5>';
render_table(['Adversário','J','V','E','D','Gols Pró','Gols Contra','Aproveitamento'], $oppMostPlayed, ['label','played','wins','draws','losses','gf','ga','pct'], 8);
echo '</div></div>';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Top 10 estádios com mais jogos</h5>';
render_table(['Estádio','J','V','E','D','Aproveitamento'], $stadMostPlayed, ['label','played','wins','draws','losses','pct'], 6);
echo '</div></div>';
echo '</div>';

// 18 e 19
echo '<div class="row g-3 mb-3">';
echo '<div class="col-12 col-lg-5"><div class="card-soft p-3"><h5 class="mb-2">Uniformes mais usados</h5>';
render_table(['Uniforme','J','V','E','D','Pts','Aproveitamento'], $kitRank, ['label','played','wins','draws','losses','points','pct'], 7);
echo '</div></div>';
echo '<div class="col-12 col-lg-7"><div class="card-soft p-3"><h5 class="mb-2">Árbitros</h5>';
render_table(['Árbitro','J','V','E','D','Aproveitamento','CA','CV'], $refRank, ['label','played','wins','draws','losses','pct','yellow','red'], 8);
echo '</div></div>';
echo '</div>';

// 20 e 21
echo '<div class="row g-3 mb-4">';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Fase dos campeonatos</h5>';
render_table(['Fase','J','V','E','D','Gols Pró','Gols Contra','Pts'], $phaseRank, ['label','played','wins','draws','losses','gf','ga','points'], 8);
echo '</div></div>';
echo '<div class="col-12 col-lg-6"><div class="card-soft p-3"><h5 class="mb-2">Clima</h5>';
render_table(['Clima','J','V','E','D','Pts','Aproveitamento'], $weatherRank, ['label','played','wins','draws','losses','points','pct'], 7);
echo '</div></div>';
echo '</div>';

$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
echo '<script>'; 
echo 'window.PM_STATS = '.$chartJson.';';
?>
document.addEventListener('DOMContentLoaded', () => {
  if (!window.Chart || !window.PM_STATS) return;

  const S = window.PM_STATS;
  const bodyStyle = getComputedStyle(document.body);
  const bodyColor = (bodyStyle.getPropertyValue('--bs-body-color') || bodyStyle.color || '').trim();
  if (bodyColor) Chart.defaults.color = bodyColor;
  Chart.defaults.responsive = true;
  Chart.defaults.maintainAspectRatio = false;

  const el = (id) => document.getElementById(id);

  function mkLine(id, labels, datasets, extraOpts = {}) {
    const c = el(id); if (!c) return;
    return new Chart(c, {
      type: 'line',
      data: { labels, datasets },
      options: Object.assign({
        plugins: { legend: { display: true }, tooltip: { mode: 'index', intersect: false } },
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: { x: { ticks: { maxRotation: 0, autoSkip: true } } },
      }, extraOpts),
    });
  }

  function mkBar(id, labels, datasets, extraOpts = {}) {
    const c = el(id); if (!c) return;
    return new Chart(c, {
      type: 'bar',
      data: { labels, datasets },
      options: Object.assign({
        plugins: { legend: { display: true } },
        scales: { x: { ticks: { autoSkip: true } }, y: { beginAtZero: true } },
      }, extraOpts),
    });
  }

  function mkHBar(id, labels, datasets, extraOpts = {}) {
    const c = el(id); if (!c) return;
    return new Chart(c, {
      type: 'bar',
      data: { labels, datasets },
      options: Object.assign({
        indexAxis: 'y',
        plugins: { legend: { display: true } },
        scales: { x: { beginAtZero: true } },
      }, extraOpts),
    });
  }

  function mkDoughnut(id, labels, data) {
    const c = el(id); if (!c) return;
    return new Chart(c, {
      type: 'doughnut',
      data: { labels, datasets: [{ label: 'Qtd', data }] },
      options: { plugins: { legend: { position: 'bottom' } } },
    });
  }

  mkLine('ch_points', S.timeline.labels, [
    { label: 'Pontos acumulados', data: S.timeline.points_cum, tension: 0.25 },
  ], { scales: { y: { beginAtZero: true } } });

  mkDoughnut('ch_wdl', S.wdl.labels, S.wdl.values);

  mkLine('ch_gd', S.timeline.labels, [
    { label: 'Saldo acumulado', data: S.timeline.gd_cum, tension: 0.25 },
  ]);

  mkBar('ch_gfga', S.timeline.labels, [
    { label: 'Gols Pró', data: S.timeline.gf },
    { label: 'Gols Contra', data: S.timeline.ga },
  ]);

  (() => {
    const c = el('ch_scorebubble'); if (!c) return;
    const points = (S.score_bubble.points || []).map(p => ({ x: p.x, y: p.y, r: p.r, _v: p.v, _label: p.label }));
    new Chart(c, {
      type: 'bubble',
      data: { datasets: [{ label: 'Placar', data: points }] },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const raw = ctx.raw || {};
                return `${raw._label || ''} • Qtd: ${raw._v ?? ''}`;
              }
            }
          }
        },
        scales: {
          x: { title: { display: true, text: 'Gols Pró' }, beginAtZero: true, suggestedMax: (S.score_bubble.max_gf || 5) + 1, ticks: { stepSize: 1 } },
          y: { title: { display: true, text: 'Gols Contra' }, beginAtZero: true, suggestedMax: (S.score_bubble.max_ga || 5) + 1, ticks: { stepSize: 1 } },
        }
      }
    });
  })();

  mkBar('ch_homeaway', S.homeaway.labels, [
    { label: 'PPJ', data: S.homeaway.ppj },
    { label: 'Gols Pró', data: S.homeaway.gf },
    { label: 'Gols Contra', data: S.homeaway.ga },
  ]);

  mkHBar('ch_opponents', S.opponents.labels, [
    { label: 'Jogos', data: S.opponents.played },
    { label: 'PPJ', data: S.opponents.ppj },
  ]);

  mkHBar('ch_kits', S.kits.labels, [
    { label: 'Jogos', data: S.kits.played },
    { label: 'PPJ', data: S.kits.ppj },
  ]);

  mkHBar('ch_phases', S.phases.labels, [
    { label: 'PPJ', data: S.phases.ppj },
  ], { scales: { x: { beginAtZero: true, suggestedMax: 3 } } });

  mkHBar('ch_weather', S.weathers.labels, [
    { label: 'PPJ', data: S.weathers.ppj },
  ], { scales: { x: { beginAtZero: true, suggestedMax: 3 } } });

  mkHBar('ch_players_games', S.players.games_top10.labels, [
    { label: 'Jogos', data: S.players.games_top10.values },
  ]);

  mkHBar('ch_players_consec', S.players.consec_top10.labels, [
    { label: 'Jogos consecutivos', data: S.players.consec_top10.values },
  ]);

  mkHBar('ch_players_cleans', S.players.cleans_top10.labels, [
    { label: 'Clean sheets', data: S.players.cleans_top10.values },
  ]);

  if (S.players.has_player_stats) {
    mkHBar('ch_players_goals', S.players.scorers_top10.labels, [
      { label: 'Gols', data: S.players.scorers_top10.values },
    ]);
    mkHBar('ch_players_assists', S.players.assists_top10.labels, [
      { label: 'Assistências', data: S.players.assists_top10.values },
    ]);
  } else {
    mkHBar('ch_players_goals', ['Sem dados'], [{ label: 'Gols', data: [0] }]);
    mkHBar('ch_players_assists', ['Sem dados'], [{ label: 'Assistências', data: [0] }]);
  }
});
<?php

echo '</script>';
render_footer();
