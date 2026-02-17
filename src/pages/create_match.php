<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club(); // PALMEIRAS

/**
 * LOG simples do sistema
 * - Sempre grava em D:\Projetos\palmeiras_manager\logs\app.log
 * - Cria o diretório caso não exista
 */
function pm_log(string $level, string $message): void {
  $dir = 'D:\\Projetos\\palmeiras_manager\\logs';
  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }
  $file = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($file, "[$ts] [$level] $message" . PHP_EOL, FILE_APPEND);
}

function int0(mixed $v): int {
  $v = trim((string)$v);
  return ($v === '') ? 0 : (int)$v;
}

/** Aceita nota com vírgula (6,8) e converte para float (6.8) */
function num0(mixed $v): float {
  $v = trim((string)$v);
  if ($v === '') return 0.0;
  $v = str_replace(',', '.', $v);
  return (float)$v;
}

/** Repopula campos após erro */
function fval(string $key, array $fallbackRow = []): string {
  if (isset($_POST[$key])) return trim((string)$_POST[$key]);
  return isset($fallbackRow[$key]) ? trim((string)$fallbackRow[$key]) : '';
}

$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];
$kits      = ['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'];
$weathers  = ['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'];

/**
 * Campeonatos (conforme você definiu)
 */
$competitions = [
  'Paulistão Casas Bahia',
  'Brasileirão Betano',
  'Copa Betano do Brasil',
  'CONMEBOL Libertadores',
  'CONMEBOL Sul-Americana',
  'CONMEBOL Recopa',
  'Supercopa do Brasil',
  'Intercontinental FIFA',
  'Copa do Mundo de Clubes da FIFA',
];

/**
 * Temporadas: 2026 a 2040
 */
$seasons = [];
for ($y = 2026; $y <= 2040; $y++) $seasons[] = (string)$y;

$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

$MAX_STARTERS = 11;
$MAX_BENCH    = 9;

function select_options(array $items, string $selected): string {
  $out = '';
  foreach ($items as $it) {
    $sel = ((string)$it === $selected) ? ' selected' : '';
    $out .= '<option value="'.h((string)$it).'"'.$sel.'>'.h((string)$it).'</option>';
  }
  return $out;
}

function row_player_pal(int $i, string $role, array $positions, array $palPlayers): void {
  $pidKey    = "pal_pid_{$role}_{$i}";
  $posKey    = "pal_pos_{$role}_{$i}";
  $ratingKey = "pal_rating_{$role}_{$i}";
  $gKey      = "pal_g_{$role}_{$i}";
  $aKey      = "pal_a_{$role}_{$i}";
  $ogKey     = "pal_og_{$role}_{$i}";
  $yKey      = "pal_y_{$role}_{$i}";
  $rKey      = "pal_r_{$role}_{$i}";

  echo '<tr>';

  echo '<td>';
  echo '<select class="form-select" name="'.h($pidKey).'">';
  echo '<option value="">-- atleta --</option>';
  foreach ($palPlayers as $p) {
    $label = $p['name'];
    if (!empty($p['shirt_number'])) $label = $p['shirt_number'].' - '.$label;
    $sel = (fval($pidKey) === (string)$p['id']) ? ' selected' : '';
    echo '<option value="'.h((string)$p['id']).'"'.$sel.'>'.h($label).'</option>';
  }
  echo '</select>';
  echo '</td>';

  echo '<td><select class="form-select" name="'.h($posKey).'"><option value="">POS</option>'.select_options($positions, fval($posKey)).'</select></td>';
  echo '<td><input class="form-control" name="'.h($ratingKey).'" value="'.h(fval($ratingKey)).'" placeholder="0,0"></td>';
  echo '<td><input class="form-control" name="'.h($gKey).'" value="'.h(fval($gKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($aKey).'" value="'.h(fval($aKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($ogKey).'" value="'.h(fval($ogKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($yKey).'" value="'.h(fval($yKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($rKey).'" value="'.h(fval($rKey)).'" placeholder="0"></td>';

  echo '</tr>';
}

function row_player_opp(int $i, string $role, array $positions): void {
  $nameKey   = "opp_name_{$role}_{$i}";
  $posKey    = "opp_pos_{$role}_{$i}";
  $ratingKey = "opp_rating_{$role}_{$i}";
  $gKey      = "opp_g_{$role}_{$i}";
  $aKey      = "opp_a_{$role}_{$i}";
  $ogKey     = "opp_og_{$role}_{$i}";
  $yKey      = "opp_y_{$role}_{$i}";
  $rKey      = "opp_r_{$role}_{$i}";

  echo '<tr>';

  echo '<td><input class="form-control" name="'.h($nameKey).'" value="'.h(fval($nameKey)).'" placeholder="Nome do atleta"></td>';
  echo '<td><select class="form-select" name="'.h($posKey).'"><option value="">POS</option>'.select_options($positions, fval($posKey)).'</select></td>';
  echo '<td><input class="form-control" name="'.h($ratingKey).'" value="'.h(fval($ratingKey)).'" placeholder="0,0"></td>';
  echo '<td><input class="form-control" name="'.h($gKey).'" value="'.h(fval($gKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($aKey).'" value="'.h(fval($aKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($ogKey).'" value="'.h(fval($ogKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($yKey).'" value="'.h(fval($yKey)).'" placeholder="0"></td>';
  echo '<td><input class="form-control" name="'.h($rKey).'" value="'.h(fval($rKey)).'" placeholder="0"></td>';

  echo '</tr>';
}

/* ==========================================================
   POST handler
========================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

  $season      = trim((string)($_POST['season'] ?? ''));
  $competition = strtoupper(trim((string)($_POST['competition'] ?? '')));
  $date        = trim((string)($_POST['match_date'] ?? ''));
  $match_time  = trim((string)($_POST['match_time'] ?? ''));
  $phase       = strtoupper(trim((string)($_POST['phase'] ?? '')));
  $round       = trim((string)($_POST['round'] ?? ''));
  $stadium     = strtoupper(trim((string)($_POST['stadium'] ?? '')));
  $referee     = strtoupper(trim((string)($_POST['referee'] ?? '')));
  $kit_used    = trim((string)($_POST['kit_used'] ?? ''));
  $weather     = strtoupper(trim((string)($_POST['weather'] ?? '')));
  $home        = strtoupper(trim((string)($_POST['home'] ?? '')));
  $away        = strtoupper(trim((string)($_POST['away'] ?? '')));

  // Placar (opcional)
  $hsRaw = trim((string)($_POST['home_score'] ?? ''));
  $asRaw = trim((string)($_POST['away_score'] ?? ''));
  $home_score = ($hsRaw === '') ? null : (int)$hsRaw;
  $away_score = ($asRaw === '') ? null : (int)$asRaw;

  $isHomePal = (strcasecmp($home, $club) === 0);
  $isAwayPal = (strcasecmp($away, $club) === 0);

  if (!$isHomePal && !$isAwayPal) {
    redirect('?page=create_match&err=palmeiras_only');
  }

  // valida obrigatórios
  if ($season === '' || $competition === '' || $date === '' || $home === '' || $away === '' || strcasecmp($home, $away) === 0) {
    redirect('?page=create_match&err=invalid');
  }
  if ($kit_used === '' || $weather === '') {
    redirect('?page=create_match&err=invalid');
  }

  // garante que temporada está no range 2026..2040 (defesa extra)
  $sInt = (int)$season;
  if ($sInt < 2026 || $sInt > 2040) {
    redirect('?page=create_match&err=invalid');
  }

  // valida ano da data vs temporada
  $year = substr($date, 0, 4);
  if ($year !== $season) {
    redirect('?page=create_match&err=season_mismatch');
  }

  $oppClub = $isHomePal ? $away : $home;

  // precisa ter pelo menos 1 do Palmeiras e 1 do adversário
  $hasPal = false;
  $hasOpp = false;

  for ($i=0; $i<$MAX_STARTERS; $i++) if (trim((string)($_POST["pal_pid_starter_$i"] ?? '')) !== '') $hasPal = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if (trim((string)($_POST["pal_pid_bench_$i"] ?? '')) !== '') $hasPal = true;

  for ($i=0; $i<$MAX_STARTERS; $i++) if (trim((string)($_POST["opp_name_starter_$i"] ?? '')) !== '') $hasOpp = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if (trim((string)($_POST["opp_name_bench_$i"] ?? '')) !== '') $hasOpp = true;

  if (!$hasPal || !$hasOpp) {
    redirect('?page=create_match&err=roster_required');
  }

  /**
   * ==========================================================
   * VALIDAÇÃO: NÃO PERMITIR MESMO JOGADOR DO PALMEIRAS 2x
   * ==========================================================
   * - Verifica titulares + reservas
   * - NÃO se aplica ao adversário (nome pode repetir)
   */
  $palSeen = [];

  for ($i=0; $i<$MAX_STARTERS; $i++) {
    $pid = (int)($_POST["pal_pid_starter_$i"] ?? 0);
    if ($pid <= 0) continue;
    if (isset($palSeen[$pid])) {
      pm_log('WARN', "Jogador duplicado no Palmeiras (titular). player_id=$pid");
      redirect('?page=create_match&err=dup_player');
    }
    $palSeen[$pid] = true;
  }

  for ($i=0; $i<$MAX_BENCH; $i++) {
    $pid = (int)($_POST["pal_pid_bench_$i"] ?? 0);
    if ($pid <= 0) continue;
    if (isset($palSeen[$pid])) {
      pm_log('WARN', "Jogador duplicado no Palmeiras (reserva). player_id=$pid");
      redirect('?page=create_match&err=dup_player');
    }
    $palSeen[$pid] = true;
  }

  $pdo->beginTransaction();
  try {
    q($pdo, "INSERT INTO matches(
      season, competition, phase, round,
      match_date, match_time,
      stadium, referee,
      home, away,
      kit_used, weather,
      home_score, away_score
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
      $season, $competition, $phase, $round,
      $date, $match_time,
      $stadium, $referee,
      $home, $away,
      $kit_used, $weather,
      $home_score, $away_score
    ]);

    $matchId = (int)$pdo->lastInsertId();

    $insMatchPlayer = $pdo->prepare("
      INSERT INTO match_players(match_id, club_name, player_id, role, position, sort_order, entered)
      VALUES (?,?,?,?,?,?,?)
    ");
    $insStats = $pdo->prepare("
      INSERT INTO match_player_stats(match_id, club_name, player_id, goals_for, goals_against, assists, yellow_cards, red_cards, rating, motm)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    // Palmeiras
    $savePal = function(string $role, int $max) use ($club, $matchId, $insMatchPlayer, $insStats, $positions): void {
      for ($i=0; $i<$max; $i++) {
        $pid = (int)($_POST["pal_pid_{$role}_{$i}"] ?? 0);
        if ($pid <= 0) continue;

        $pos = trim((string)($_POST["pal_pos_{$role}_{$i}"] ?? ''));
        if ($pos !== '' && !in_array($pos, $positions, true)) $pos = '';

        $rating = num0($_POST["pal_rating_{$role}_{$i}"] ?? '');
        $g  = int0($_POST["pal_g_{$role}_{$i}"] ?? '');
        $a  = int0($_POST["pal_a_{$role}_{$i}"] ?? '');
        $og = int0($_POST["pal_og_{$role}_{$i}"] ?? '');
        $y  = int0($_POST["pal_y_{$role}_{$i}"] ?? '');
        $r  = int0($_POST["pal_r_{$role}_{$i}"] ?? '');

        $entered = ($role === 'starter') ? 1 : 0;
        $dbRole  = ($role === 'starter') ? 'STARTER' : 'BENCH';

        $insMatchPlayer->execute([$matchId, $club, $pid, $dbRole, $pos, $i+1, $entered]);
        $insStats->execute([$matchId, $club, $pid, $g, $og, $a, $y, $r, $rating, 0]);
      }
    };

    // Adversário (cria em opponent_players se não existir)
//    $findOppPlayer = $pdo->prepare("SELECT id FROM opponent_players WHERE club_name = ? AND name = ? LIMIT 1");
//    $insOppPlayer  = $pdo->prepare("
//      INSERT INTO opponent_players(name, shirt_number, primary_position, secondary_positions, is_active, club_name, created_at, updated_at)
//      VALUES (?,?,?,?,?,?,datetime('now'),datetime('now'))
//    ");
    $findOppPlayer = $pdo->prepare("SELECT id FROM opponent_players WHERE club_name = ? AND name = ? LIMIT 1");
      $insOppPlayer = $pdo->prepare("
        INSERT INTO opponent_players(name, primary_position, secondary_positions, is_active, club_name, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      ");

    $saveOpp = function(string $role, int $max) use ($oppClub, $matchId, $insMatchPlayer, $insStats, $positions, $findOppPlayer, $insOppPlayer, $pdo): void {
      for ($i=0; $i<$max; $i++) {
        $name = trim((string)($_POST["opp_name_{$role}_{$i}"] ?? ''));
        if ($name === '') continue;

        $pos = trim((string)($_POST["opp_pos_{$role}_{$i}"] ?? ''));
        if ($pos !== '' && !in_array($pos, $positions, true)) $pos = '';

        $findOppPlayer->execute([$oppClub, $name]);
        $row = $findOppPlayer->fetch();

        if ($row) {
          $pid = (int)$row['id'];
        } else {
          $insOppPlayer->execute([$name, null, $pos, '[]', 1, $oppClub]);
          $pid = (int)$pdo->lastInsertId();
        }

        $rating = num0($_POST["opp_rating_{$role}_{$i}"] ?? '');
        $g  = int0($_POST["opp_g_{$role}_{$i}"] ?? '');
        $a  = int0($_POST["opp_a_{$role}_{$i}"] ?? '');
        $og = int0($_POST["opp_og_{$role}_{$i}"] ?? '');
        $y  = int0($_POST["opp_y_{$role}_{$i}"] ?? '');
        $r  = int0($_POST["opp_r_{$role}_{$i}"] ?? '');

        $entered = ($role === 'starter') ? 1 : 0;
        $dbRole  = ($role === 'starter') ? 'STARTER' : 'BENCH';

        $insMatchPlayer->execute([$matchId, $oppClub, $pid, $dbRole, $pos, $i+1, $entered]);
        $insStats->execute([$matchId, $oppClub, $pid, $g, $og, $a, $y, $r, $rating, 0]);
      }
    };

    $savePal('starter', $MAX_STARTERS);
    $savePal('bench',   $MAX_BENCH);
    $saveOpp('starter', $MAX_STARTERS);
    $saveOpp('bench',   $MAX_BENCH);

    $pdo->commit();

    pm_log('INFO', "Partida cadastrada com sucesso. match_id=$matchId home=$home away=$away date=$date season=$season competition=$competition");
    redirect('?page=matches&msg=saved');

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pm_log('ERROR', 'Erro ao cadastrar partida: ' . $e->getMessage());
    redirect('?page=create_match&err=exception');
  }
}

/* ==========================================================
   GET (render)
========================================================== */

$players = q($pdo, "
  SELECT id, name, shirt_number
  FROM players
  WHERE is_active = 1
    AND club_name = ? COLLATE NOCASE
  ORDER BY name
", [$club])->fetchAll();

render_header('Cadastrar partida');

// Descobrir o nome do adversário (para título da coluna direita)
$homeName = fval('home');
$awayName = fval('away');
$oppTitle = 'Adversário';
if ($homeName !== '' && $awayName !== '') {
  if (strcasecmp($homeName, $club) === 0) $oppTitle = $awayName;
  elseif (strcasecmp($awayName, $club) === 0) $oppTitle = $homeName;
}

// Alerts
if ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Preencha os campos obrigatórios e confira os dados.</div>';
} elseif ($err === 'season_mismatch') {
  echo '<div class="alert alert-warning card-soft">A DATA deve estar no mesmo ano da TEMPORADA.</div>';
} elseif ($err === 'palmeiras_only') {
  echo '<div class="alert alert-warning card-soft">Este sistema aceita apenas jogos onde o ' . h($club) . ' participa.</div>';
} elseif ($err === 'roster_required') {
  echo '<div class="alert alert-warning card-soft">Informe ao menos 1 atleta no Palmeiras e 1 no adversário (titular ou reserva).</div>';
} elseif ($err === 'dup_player') {
  echo '<div class="alert alert-warning card-soft">Você selecionou o <b>mesmo jogador do Palmeiras</b> em mais de uma posição. Ajuste a escalação e tente novamente.</div>';
} elseif ($err === 'exception') {
  echo '<div class="alert alert-danger card-soft">Falha ao cadastrar a partida. Verifique o log em <code>D:\Projetos\palmeiras_manager\logs\app.log</code>.</div>';
} elseif ($msg === 'saved') {
  echo '<div class="alert alert-success card-soft">Partida cadastrada com sucesso.</div>';
}

echo <<<HTML
<style>
/* ===== Create Match: alinhamento + responsivo ===== */
.pm-roster-table { width: 100%; table-layout: fixed; }
.pm-roster-table th, .pm-roster-table td { vertical-align: middle; }
.pm-roster-table col.pm-col-pos  { width: clamp(74px, 5.5vw, 92px); }
.pm-roster-table col.pm-col-nota { width: clamp(86px, 6.5vw, 120px); }
.pm-roster-table col.pm-col-mini { width: clamp(56px, 4.2vw, 76px); }
.pm-roster-table th { padding: .55rem .65rem; }
.pm-roster-table td { padding: .45rem .55rem; }
.pm-roster-table th, .pm-roster-table td { white-space: nowrap; }
.pm-roster-table select, .pm-roster-table input{
  width: 100%;
  height: 40px;
  border-radius: 14px;
  padding: 0 12px;
}
.pm-roster-table td:first-child select,
.pm-roster-table td:first-child input{
  padding-left: 14px;
  padding-right: 34px;
}
.pm-roster-table td:nth-child(n+3) input{
  text-align: center;
  padding: 0 8px;
}
.pm-roster-table thead th{ position: sticky; top: 0; z-index: 1; }

/* Em telas grandes, evita esmagar as colunas e mantém scroll interno */
@media (min-width: 1200px){
  .pm-roster-table { min-width: 860px; }
}
@media (max-width: 900px){
  .table-responsive{ overflow-x:auto; }
  .pm-roster-table{ min-width: 980px; }
}
</style>
HTML;

$palPlayers = is_array($players) ? $players : [];

echo '<div class="row g-4">';
echo '<div class="col-12">';
echo '<div class="card card-soft p-3">';

echo '<form method="post" autocomplete="off">';

echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<div class="row g-3">';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Temporada</label>';
echo '<select class="form-select" name="season" required>';
echo '<option value="">-- selecione --</option>';
echo select_options($seasons, fval('season'));
echo '</select>';
echo '</div>';

echo '<div class="col-12 col-md-4">';
echo '<label class="form-label">Campeonato</label>';
echo '<select class="form-select" name="competition" required>';
echo '<option value="">-- selecione --</option>';
echo select_options($competitions, fval('competition'));
echo '</select>';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Data</label>';
echo '<input class="form-control" type="date" name="match_date" value="'.h(fval('match_date')).'" required>';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Horário</label>';
echo '<input class="form-control" type="time" name="match_time" value="'.h(fval('match_time')).'">';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Fase</label>';
echo '<input class="form-control" name="phase" value="'.h(fval('phase')).'" placeholder="Ex: Quartas">';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Rodada</label>';
echo '<input class="form-control" name="round" value="'.h(fval('round')).'" placeholder="Ex: 10">';
echo '</div>';

echo '<div class="col-12 col-md-4">';
echo '<label class="form-label">Estádio</label>';
echo '<input class="form-control" name="stadium" value="'.h(fval('stadium')).'">';
echo '</div>';

echo '<div class="col-12 col-md-4">';
echo '<label class="form-label">Árbitro</label>';
echo '<input class="form-control" name="referee" value="'.h(fval('referee')).'">';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Uniforme</label>';
echo '<select class="form-select" name="kit_used" required>';
echo '<option value="">-- selecione --</option>';
echo select_options($kits, fval('kit_used'));
echo '</select>';
echo '</div>';

echo '<div class="col-12 col-md-2">';
echo '<label class="form-label">Clima</label>';
echo '<select class="form-select" name="weather" required>';
echo '<option value="">-- selecione --</option>';
echo select_options($weathers, fval('weather'));
echo '</select>';
echo '</div>';

echo '<div class="col-12 col-md-4">';
echo '<label class="form-label">Mandante</label>';
echo '<input class="form-control" name="home" value="'.h($homeName).'" required>';
echo '</div>';

echo '<div class="col-12 col-md-4">';
echo '<label class="form-label">Visitante</label>';
echo '<input class="form-control" name="away" value="'.h($awayName).'" required>';
echo '</div>';

echo '<div class="col-6 col-md-2">';
echo '<label class="form-label">GF (Mandante)</label>';
echo '<input class="form-control" name="home_score" value="'.h(fval('home_score')).'" placeholder="0">';
echo '</div>';

echo '<div class="col-6 col-md-2">';
echo '<label class="form-label">GA (Visitante)</label>';
echo '<input class="form-control" name="away_score" value="'.h(fval('away_score')).'" placeholder="0">';
echo '</div>';

echo '</div>'; // row

echo '<hr class="my-4">';

echo '<h5 class="mb-2">Relacionados e desempenho</h5>';
echo '<div class="text-muted mb-3">Preencha titulares e reservas. No adversário, digite o nome do atleta.</div>';

echo '<div class="row g-3">';

/* =========================
   ESQUERDA: PALMEIRAS
========================= */
echo '<div class="col-12 col-xl-6">';
echo '<div class="card card-soft p-3 h-100">';

echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<h6 class="mb-0">'.h($club).'</h6>';
echo '<span class="badge text-bg-success">Meu time</span>';
echo '</div>';

echo '<div class="mb-2 fw-bold">Titulares</div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm pm-roster-table align-middle mb-3">';
echo '<colgroup>
  <col>
  <col class="pm-col-pos">
  <col class="pm-col-nota">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
</colgroup>';
echo '<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_STARTERS; $i++) row_player_pal($i, 'starter', $positions, $palPlayers);
echo '</tbody></table>';
echo '</div>';

echo '<div class="mb-2 fw-bold">Reservas (máx. '.$MAX_BENCH.')</div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm pm-roster-table align-middle mb-0">';
echo '<colgroup>
  <col>
  <col class="pm-col-pos">
  <col class="pm-col-nota">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
</colgroup>';
echo '<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_BENCH; $i++) row_player_pal($i, 'bench', $positions, $palPlayers);
echo '</tbody></table>';
echo '</div>';

echo '</div></div>';

/* =========================
   DIREITA: ADVERSÁRIO
========================= */
echo '<div class="col-12 col-xl-6">';
echo '<div class="card card-soft p-3 h-100">';

echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<h6 class="mb-0">'.h($oppTitle).'</h6>';
echo '<span class="badge text-bg-secondary">Outro time</span>';
echo '</div>';

echo '<div class="mb-2 fw-bold">Titulares</div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm pm-roster-table align-middle mb-3">';
echo '<colgroup>
  <col>
  <col class="pm-col-pos">
  <col class="pm-col-nota">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
</colgroup>';
echo '<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_STARTERS; $i++) row_player_opp($i, 'starter', $positions);
echo '</tbody></table>';
echo '</div>';

echo '<div class="mb-2 fw-bold">Reservas (máx. '.$MAX_BENCH.')</div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm pm-roster-table align-middle mb-0">';
echo '<colgroup>
  <col>
  <col class="pm-col-pos">
  <col class="pm-col-nota">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
  <col class="pm-col-mini">
</colgroup>';
echo '<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_BENCH; $i++) row_player_opp($i, 'bench', $positions);
echo '</tbody></table>';
echo '</div>';

echo '</div></div>'; // col + card

echo '</div>'; // row lado a lado

echo '<div class="d-flex gap-2 mt-3">';
echo '<button class="btn btn-success" type="submit">Salvar partida completa</button>';
echo '<a class="btn btn-outline-secondary" href="?page=matches">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div></div></div>';

render_footer();
