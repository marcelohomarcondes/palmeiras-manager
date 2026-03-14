<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club();

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('table_cols')) {
  function table_cols(PDO $pdo, string $table): array {
    $cols = [];
    try {
      $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) $cols[(string)$r['name']] = true;
    } catch (Throwable $e) {}
    return $cols;
  }
}


// Helpers do bloco "Dados do jogo"
if (!function_exists('fmtv')) {
  function fmtv($v): string {
    return ($v === null || $v === '') ? '-' : (string)$v;
  }
}
if (!function_exists('fmt_date_br')) {
  function fmt_date_br(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '-';
    $ts = strtotime($raw);
    return ($ts !== false) ? date('d/m/Y', $ts) : $raw;
  }
}
if (!function_exists('pick_first_key')) {
  function pick_first_key(array $row, array $keys) {
    foreach ($keys as $k) {
      if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
    }
    return null;
  }
}

function load_stats(PDO $pdo, int $matchId): array {
  $out = [
    'pal' => [], // player_id => stats
    'opp' => [], // opponent_player_id => stats
  ];

  // Palmeiras stats (se existir)
  if (table_exists($pdo, 'match_player_stats')) {
    $cols = table_cols($pdo, 'match_player_stats');
    $select = [
      'player_id',
      (isset($cols['goals']) ? 'goals' : (isset($cols['goals_for']) ? 'goals_for AS goals' : '0 AS goals')),
      (isset($cols['assists']) ? 'assists' : '0 AS assists'),
      (isset($cols['own_goals']) ? 'own_goals' : (isset($cols['goals_against']) ? 'goals_against AS own_goals' : '0 AS own_goals')),
      (isset($cols['yellow_cards']) ? 'yellow_cards' : '0 AS yellow_cards'),
      (isset($cols['red_cards']) ? 'red_cards' : '0 AS red_cards'),
      (isset($cols['rating']) ? 'rating' : 'NULL AS rating'),
      (isset($cols['is_mvp']) ? 'is_mvp' : (isset($cols['motm']) ? 'motm AS is_mvp' : '0 AS is_mvp')),
    ];

    $rows = q($pdo, "SELECT ".implode(',', $select)." FROM match_player_stats WHERE match_id=?", [$matchId])
      ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid > 0) $out['pal'][$pid] = $r;
    }
  }

  // Adversário stats (se existir)
  if (table_exists($pdo, 'opponent_match_player_stats')) {
    $cols = table_cols($pdo, 'opponent_match_player_stats');
    $select = [
      'opponent_player_id',
      (isset($cols['goals']) ? 'goals' : (isset($cols['goals_for']) ? 'goals_for AS goals' : '0 AS goals')),
      (isset($cols['assists']) ? 'assists' : '0 AS assists'),
      (isset($cols['own_goals']) ? 'own_goals' : (isset($cols['goals_against']) ? 'goals_against AS own_goals' : '0 AS own_goals')),
      (isset($cols['yellow_cards']) ? 'yellow_cards' : '0 AS yellow_cards'),
      (isset($cols['red_cards']) ? 'red_cards' : '0 AS red_cards'),
      (isset($cols['rating']) ? 'rating' : 'NULL AS rating'),
      (isset($cols['is_mvp']) ? 'is_mvp' : (isset($cols['motm']) ? 'motm AS is_mvp' : '0 AS is_mvp')),
    ];

    $rows = q($pdo, "SELECT ".implode(',', $select)." FROM opponent_match_player_stats WHERE match_id=?", [$matchId])
      ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $oid = (int)($r['opponent_player_id'] ?? 0);
      if ($oid > 0) $out['opp'][$oid] = $r;
    }
  }

  return $out;
}

function fmt_player_name(array $r): string {
  if (!empty($r['player_id'])) {
    $n = (string)($r['player_name'] ?? '');
    $num = (string)($r['shirt_number'] ?? '');
    $s = ($num !== '' ? ($num.' - '.$n) : $n);
    return $s !== '' ? $s : '--';
  }
  $s = (string)($r['opponent_name'] ?? '');
  return $s !== '' ? $s : '--';
}

function get_stats_for_row(array $r, array $stats): array {
  $zero = ['goals'=>0,'assists'=>0,'own_goals'=>0,'yellow_cards'=>0,'red_cards'=>0,'rating'=>null,'is_mvp'=>0];
  if (!empty($r['player_id'])) {
    $pid = (int)$r['player_id'];
    return $stats['pal'][$pid] ?? $zero;
  }
  $oid = (int)($r['opponent_player_id'] ?? 0);
  return $stats['opp'][$oid] ?? $zero;
}

function render_block(array $slots, array $stats): void {
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm align-middle mb-0">';
  echo '<thead><tr class="text-center">
    <th style="width:80px;">POS</th>
    <th>Atleta</th>
    <th style="width:55px;">G</th>
    <th style="width:55px;">A</th>
    <th style="width:60px;">GC</th>
    <th style="width:60px;">CA</th>
    <th style="width:60px;">CV</th>
    <th style="width:70px;">Nota</th>
    <th style="width:60px;">MVP</th>
  </tr></thead><tbody>';

  foreach ($slots as $r) {
    $pos = (string)($r['position'] ?? '');
    $name = fmt_player_name($r);
    $st = get_stats_for_row($r, $stats);

    $rt = $st['rating'];
    $rtTxt = ($rt === null || $rt === '') ? '' : str_replace('.', ',', (string)$rt);

    echo '<tr class="text-center">';
    echo '<td>'.h($pos).'</td>';
    echo '<td class="text-start">'.h($name).'</td>';
    echo '<td>'.h((string)((int)($st['goals'] ?? 0))).'</td>';
    echo '<td>'.h((string)((int)($st['assists'] ?? 0))).'</td>';
    echo '<td>'.h((string)((int)($st['own_goals'] ?? 0))).'</td>';
    echo '<td>'.h((string)((int)($st['yellow_cards'] ?? 0))).'</td>';
    echo '<td>'.h((string)((int)($st['red_cards'] ?? 0))).'</td>';
    echo '<td>'.h($rtTxt).'</td>';
    echo '<td>'.(((int)($st['is_mvp'] ?? 0) === 1) ? '★' : '').'</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

function render_subs(PDO $pdo, int $matchId, string $side): void {
  if (!table_exists($pdo, 'match_substitutions')) return;

  $subs = q($pdo, "
    SELECT
      ms.minute,
      ms.player_out_id, ms.player_in_id,
      ms.opponent_out_id, ms.opponent_in_id,
      pout.name AS player_out_name, pout.shirt_number AS player_out_num,
      pin.name  AS player_in_name,  pin.shirt_number  AS player_in_num,
      opout.name AS opp_out_name,
      opin.name  AS opp_in_name
    FROM match_substitutions ms
    LEFT JOIN players pout ON pout.id = ms.player_out_id
    LEFT JOIN players pin  ON pin.id  = ms.player_in_id
    LEFT JOIN opponent_players opout ON opout.id = ms.opponent_out_id
    LEFT JOIN opponent_players opin  ON opin.id  = ms.opponent_in_id
    WHERE ms.match_id=? AND UPPER(ms.side)=UPPER(?)
    ORDER BY ms.sort_order
  ", [$matchId, $side])->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$subs) return;

  echo '<h6 class="mt-3">Substituições</h6>';
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm align-middle mb-0">';
  echo '<thead><tr class="text-center">
    <th style="width:90px;">MIN</th>
    <th>SAI</th>
    <th>ENTRA</th>
  </tr></thead><tbody>';

  foreach ($subs as $s) {
    $min = ($s['minute'] === null || $s['minute'] === '') ? '' : (string)$s['minute'];

    $out = '';
    $in  = '';

    if (!empty($s['player_out_id']) || !empty($s['player_in_id'])) {
      $outName = (string)($s['player_out_name'] ?? '');
      $outNum  = (string)($s['player_out_num'] ?? '');
      $inName  = (string)($s['player_in_name'] ?? '');
      $inNum   = (string)($s['player_in_num'] ?? '');
      $out = ($outNum !== '' ? ($outNum.' - '.$outName) : $outName);
      $in  = ($inNum  !== '' ? ($inNum .' - '.$inName ) : $inName);
    } else {
      $out = (string)($s['opp_out_name'] ?? '');
      $in  = (string)($s['opp_in_name'] ?? '');
    }

    if ($out === '') $out = '--';
    if ($in === '')  $in  = '--';

    echo '<tr class="text-center">';
    echo '<td>'.h($min).'</td>';
    echo '<td class="text-start">'.h($out).'</td>';
    echo '<td class="text-start">'.h($in).'</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* ========================================================= */

$matchId = (int)($_GET['id'] ?? 0);
if ($matchId <= 0) {
  render_header('Partida');
  echo '<div class="alert alert-danger card-soft">ID inválido.</div>';
  render_footer();
  exit;
}

$match = q($pdo, "SELECT * FROM matches WHERE id=?", [$matchId])->fetch(PDO::FETCH_ASSOC);
if (!$match) {
  render_header('Partida');
  echo '<div class="alert alert-danger card-soft">Partida não encontrada.</div>';
  render_footer();
  exit;
}

$home = (string)($match['home'] ?? ($match['home_team'] ?? ''));
$away = (string)($match['away'] ?? ($match['away_team'] ?? ''));

// Carrega TODOS os jogadores da partida
$rows = q($pdo, "
  SELECT
    mp.player_type, mp.role, mp.sort_order, mp.position,
    mp.player_id, mp.opponent_player_id,
    p.name AS player_name, p.shirt_number AS shirt_number,
    op.name AS opponent_name
  FROM match_players mp
  LEFT JOIN players p ON p.id = mp.player_id
  LEFT JOIN opponent_players op ON op.id = mp.opponent_player_id
  WHERE mp.match_id=?
", [$matchId])->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Monta slots fixos (11 + 9) por lado
function empty_slot(): array {
  return [
    'position' => '',
    'player_id' => null,
    'opponent_player_id' => null,
    'player_name' => '',
    'shirt_number' => '',
    'opponent_name' => '',
  ];
}

$homeStar = array_fill(0, 11, empty_slot());
$homeBen  = array_fill(0, 9,  empty_slot());
$awayStar = array_fill(0, 11, empty_slot());
$awayBen  = array_fill(0, 9,  empty_slot());

foreach ($rows as $r) {
  $type = strtoupper(trim((string)($r['player_type'] ?? '')));
  $role = strtoupper(trim((string)($r['role'] ?? '')));
  $i = (int)($r['sort_order'] ?? -1);

  $isStarter = ($role === 'STARTER');
  if ($i < 0) continue;

  if ($type === 'HOME') {
    if ($isStarter && $i < 11) $homeStar[$i] = $r;
    elseif (!$isStarter && $i < 9) $homeBen[$i] = $r;
  } elseif ($type === 'AWAY') {
    if ($isStarter && $i < 11) $awayStar[$i] = $r;
    elseif (!$isStarter && $i < 9) $awayBen[$i] = $r;
  }
}

$stats = load_stats($pdo, $matchId);

render_header('Partida');

echo '<div class="text-end mb-3">';
echo '  <a class="btn btn-sm btn-outline-warning" href="/?page=create_match&id='.(int)$matchId.'">Editar</a>';
echo '</div>';


// ============================
// DADOS DO JOGO (LISTA VERTICAL)
// ============================
$season      = (string)($match['season'] ?? '');
$competition = (string)($match['competition'] ?? '');
$dateRaw     = (string)($match['match_date'] ?? ($match['date'] ?? ''));
$timeRaw     = (string)($match['match_time'] ?? '');
$weather     = (string)($match['weather'] ?? '');
$phase       = (string)($match['phase'] ?? '');
$round       = (string)($match['round'] ?? '');
$stadium     = (string)($match['stadium'] ?? '');
$referee     = (string)($match['referee'] ?? '');
$kitUsed     = (string)($match['kit_used'] ?? '');

$homeScore = pick_first_key($match, ['home_score','home_goals','goals_home','score_home','gf','gols_pro']);
$awayScore = pick_first_key($match, ['away_score','away_goals','goals_away','score_away','ga','gols_contra']);

$homeName = ($home !== '' ? $home : 'MANDANTE');
$awayName = ($away !== '' ? $away : 'VISITANTE');

$hs = ($homeScore !== null && $homeScore !== '') ? (string)$homeScore : '-';
$as = ($awayScore !== null && $awayScore !== '') ? (string)$awayScore : '-';

$dateFmt = ($dateRaw !== '') ? fmt_date_br($dateRaw) : '-';
$timeFmt = ($timeRaw !== '') ? substr($timeRaw, 0, 5) : '-';

echo '<div class="card-soft p-3 mb-3">';
echo '  <h5 class="mb-3">Dados do Jogo</h5>';
echo '  <ul class="list-group list-group-flush">';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Temporada</span>
            <strong>'.h(fmtv($season)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Competição</span>
            <strong class="text-end" title="'.h(fmtv($competition)).'">'.h(fmtv($competition)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Data</span>
            <strong>'.h($dateFmt).' '.($timeFmt !== '-' ? 'às '.h($timeFmt) : '').'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Fase</span>
            <strong>'.h(fmtv($phase)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Rodada</span>
            <strong>'.h(fmtv($round)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Estádio</span>
            <strong class="text-end" title="'.h(fmtv($stadium)).'">'.h(fmtv($stadium)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Árbitro</span>
            <strong class="text-end" title="'.h(fmtv($referee)).'">'.h(fmtv($referee)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Clima</span>
            <strong>'.h(fmtv($weather)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body d-flex justify-content-between align-items-start">
            <span class="text-body-secondary">Uniforme</span>
            <strong>'.h(fmtv($kitUsed)).'</strong>
          </li>';

echo '    <li class="list-group-item bg-transparent text-body text-center" style="font-size:1.15rem;">
            <span class="text-body-secondary d-block mb-1">Placar</span>
            <strong>'.h($homeName).' '.h($hs).' x '.h($as).' '.h($awayName).'</strong>
          </li>';

echo '  </ul>';
echo '</div>';

echo '<div class="row g-4">';

// HOME
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($home !== '' ? $home : 'Mandante').'</h5>';
echo '<h6 class="mt-3">Titulares (11)</h6>';
render_block($homeStar, $stats);
echo '<h6 class="mt-3">Reservas (9)</h6>';
render_block($homeBen, $stats);
render_subs($pdo, $matchId, 'HOME');
echo '</div></div>';

// AWAY
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($away !== '' ? $away : 'Visitante').'</h5>';
echo '<h6 class="mt-3">Titulares (11)</h6>';
render_block($awayStar, $stats);
echo '<h6 class="mt-3">Reservas (9)</h6>';
render_block($awayBen, $stats);
render_subs($pdo, $matchId, 'AWAY');
echo '</div></div>';

echo '</div>';

render_footer();


