<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club(); // ex: PALMEIRAS

if (!function_exists('redirect')) {
  function redirect(string $url): void { header('Location: '.$url); exit; }
}

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

$matchId = (int)($_GET['id'] ?? 0);
if ($matchId <= 0) {
  render_header('Partida');
  echo '<div class="alert alert-danger card-soft">ID da partida inválido.</div>';
  render_footer();
  exit;
}

/* =========================================================
   Carrega a partida
   ========================================================= */

$match = q($pdo, "SELECT * FROM matches WHERE id = ?", [$matchId])->fetch(PDO::FETCH_ASSOC);
if (!$match) {
  render_header('Partida');
  echo '<div class="alert alert-danger card-soft">Partida não encontrada.</div>';
  render_footer();
  exit;
}

// compatibilidade: home/away ou home_team/away_team
$home = (string)($match['home'] ?? ($match['home_team'] ?? ''));
$away = (string)($match['away'] ?? ($match['away_team'] ?? ''));

/* =========================================================
   Carrega escalações (JOIN em players e opponent_players)
   ========================================================= */

$rows = q($pdo, "
  SELECT
    mp.player_type,
    mp.club_name,
    mp.role,
    mp.sort_order,
    mp.player_id,
    mp.opponent_player_id,
    mp.position,

    p.name AS player_name,
    p.shirt_number AS shirt_number,

    op.name AS opponent_name

  FROM match_players mp
  LEFT JOIN players p ON p.id = mp.player_id
  LEFT JOIN opponent_players op ON op.id = mp.opponent_player_id
  WHERE mp.match_id = ?
  ORDER BY mp.player_type, mp.role, mp.sort_order
", [$matchId])->fetchAll(PDO::FETCH_ASSOC) ?: [];

// separa HOME/AWAY
$homeStarters = [];
$homeBench    = [];
$awayStarters = [];
$awayBench    = [];

foreach ($rows as $r) {
  $type = strtoupper(trim((string)($r['player_type'] ?? '')));
  $role = strtoupper(trim((string)($r['role'] ?? '')));

  if ($type === 'HOME' && $role === 'STARTER') $homeStarters[] = $r;
  if ($type === 'HOME' && $role === 'BENCH')   $homeBench[]    = $r;
  if ($type === 'AWAY' && $role === 'STARTER') $awayStarters[] = $r;
  if ($type === 'AWAY' && $role === 'BENCH')   $awayBench[]    = $r;
}

/* =========================================================
   Render helpers
   ========================================================= */

function render_lineup_table(array $rows): void {
  echo '<div class="table-responsive">';
  echo '<table class="table table-dark table-sm align-middle mb-0">';
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

  // Mostra exatamente o que está no DB (sem inventar)
  foreach ($rows as $r) {
    $pos = (string)($r['position'] ?? '');

    $name = '';
    if (!empty($r['player_id'])) {
      // jogador do seu elenco
      $n = (string)($r['player_name'] ?? '');
      $num = (string)($r['shirt_number'] ?? '');
      $name = ($num !== '' ? ($num.' - '.$n) : $n);
    } else {
      // adversário
      $name = (string)($r['opponent_name'] ?? '');
    }
    if ($name === '') $name = '--';

    // stats: por enquanto zerado (se você quiser, eu integro com suas tabelas de stats depois)
    echo '<tr class="text-center">';
    echo '<td>'.h($pos).'</td>';
    echo '<td class="text-start">'.h($name).'</td>';
    echo '<td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td></td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* =========================================================
   Página
   ========================================================= */

render_header('Partida');

echo '<div class="card-soft p-3 mb-3">';
echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<div class="row g-3">';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Temporada</label>
  <input class="form-control" value="'.h((string)($match['season'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Competição</label>
  <input class="form-control" value="'.h((string)($match['competition'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Data</label>
  <input class="form-control" value="'.h((string)($match['match_date'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Hora</label>
  <input class="form-control" value="'.h((string)($match['match_time'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Clima</label>
  <input class="form-control" value="'.h((string)($match['weather'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Fase</label>
  <input class="form-control" value="'.h((string)($match['phase'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Rodada</label>
  <input class="form-control" value="'.h((string)($match['round'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Estádio</label>
  <input class="form-control" value="'.h((string)($match['stadium'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Árbitro</label>
  <input class="form-control" value="'.h((string)($match['referee'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Uniforme</label>
  <input class="form-control" value="'.h((string)($match['kit_used'] ?? '')).'" disabled>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Mandante</label>
  <input class="form-control" value="'.h($home).'" disabled>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Visitante</label>
  <input class="form-control" value="'.h($away).'" disabled>
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">GF</label>
  <input class="form-control text-center" value="'.h((string)($match['home_score'] ?? ($match['home_goals'] ?? ''))).'" disabled>
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">GA</label>
  <input class="form-control text-center" value="'.h((string)($match['away_score'] ?? ($match['away_goals'] ?? ''))).'" disabled>
</div>';

echo '</div>'; // row
echo '</div>'; // card

echo '<div class="row g-4">';

// HOME
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($home !== '' ? $home : 'Mandante').'</h5>';
echo '<h6 class="mt-3">Titulares (11)</h6>';
render_lineup_table($homeStarters);
echo '<h6 class="mt-3">Reservas (9)</h6>';
render_lineup_table($homeBench);
echo '</div></div>';

// AWAY
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($away !== '' ? $away : 'Visitante').'</h5>';
echo '<h6 class="mt-3">Titulares (11)</h6>';
render_lineup_table($awayStarters);
echo '<h6 class="mt-3">Reservas (9)</h6>';
render_lineup_table($awayBench);
echo '</div></div>';

echo '</div>'; // row

render_footer();
