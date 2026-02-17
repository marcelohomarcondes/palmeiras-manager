<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club(); // PALMEIRAS

function int0(mixed $v): int {
  $v = trim((string)$v);
  return ($v === '') ? 0 : (int)$v;
}

/**
 * ✅ Aceita nota com vírgula (6,8) e converte para float (6.8)
 * Use APENAS para rating/nota.
 */
function num0(mixed $v): float {
  $v = trim((string)$v);
  if ($v === '') return 0.0;
  $v = str_replace(',', '.', $v);
  return (float)$v;
}

function fval(string $key, array $fallbackRow = []): string {
  if (isset($_POST[$key])) return trim((string)$_POST[$key]);
  return isset($fallbackRow[$key]) ? trim((string)$fallbackRow[$key]) : '';
}

$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];
$kits      = ['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'];
$weathers  = ['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'];

$competitions = [
  'PAULISTÃO CASAS BAHIA',
  'BRASILEIRÃO BETANO',
  'COPA BETANO DO BRASIL',
  'SUPERCOPA REI SUPERBET',
  'CONMEBOL LIBERTADORES',
  'CONMEBOL SULAMERICANA',
  'CONMEBOL RECOPA',
  'COPA INTERCONTINENTAL DA FIFA',
  'COPA DO MUNDO DE CLUBES DA FIFA',
];

$seasons = [];
for ($y = 2026; $y <= 2040; $y++) $seasons[] = (string)$y;

$MAX_STARTERS = 11;
$MAX_BENCH    = 9;

$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

// =========================
// Helpers de render
// =========================
function select_options(array $items, string $selected): string {
  $out = '';
  foreach ($items as $it) {
    $sel = ($selected === (string)$it) ? ' selected' : '';
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

  echo '<tr class="pm-roster">';
  echo '<td><select name="'.$pidKey.'"><option value="">-- atleta --</option>';
  foreach ($palPlayers as $p) {
    $label = $p['name'];
    if (!empty($p['shirt_number'])) $label = $p['shirt_number'].' - '.$label;
    $sel = (fval($pidKey) === (string)$p['id']) ? ' selected' : '';
    echo '<option value="'.h((string)$p['id']).'"'.$sel.'>'.h($label).'</option>';
  }
  echo '</select></td>';

  echo '<td><select name="'.$posKey.'"><option value="">POS</option>'.select_options($positions, fval($posKey)).'</select></td>';
  echo '<td><input name="'.$ratingKey.'" value="'.h(fval($ratingKey)).'" placeholder="N" data-small="1"></td>';
  echo '<td><input name="'.$gKey.'" value="'.h(fval($gKey)).'" placeholder="G" data-small="1"></td>';
  echo '<td><input name="'.$aKey.'" value="'.h(fval($aKey)).'" placeholder="A" data-small="1"></td>';
  echo '<td><input name="'.$ogKey.'" value="'.h(fval($ogKey)).'" placeholder="GC" data-small="1"></td>';
  echo '<td><input name="'.$yKey.'" value="'.h(fval($yKey)).'" placeholder="CA" data-small="1"></td>';
  echo '<td><input name="'.$rKey.'" value="'.h(fval($rKey)).'" placeholder="CV" data-small="1"></td>';
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

  echo '<tr class="pm-roster">';
  echo '<td><input name="'.$nameKey.'" value="'.h(fval($nameKey)).'" placeholder="Nome do atleta"></td>';
  echo '<td><select name="'.$posKey.'"><option value="">POS</option>'.select_options($positions, fval($posKey)).'</select></td>';
  echo '<td><input name="'.$ratingKey.'" value="'.h(fval($ratingKey)).'" placeholder="N" data-small="1"></td>';
  echo '<td><input name="'.$gKey.'" value="'.h(fval($gKey)).'" placeholder="G" data-small="1"></td>';
  echo '<td><input name="'.$aKey.'" value="'.h(fval($aKey)).'" placeholder="A" data-small="1"></td>';
  echo '<td><input name="'.$ogKey.'" value="'.h(fval($ogKey)).'" placeholder="GC" data-small="1"></td>';
  echo '<td><input name="'.$yKey.'" value="'.h(fval($yKey)).'" placeholder="CA" data-small="1"></td>';
  echo '<td><input name="'.$rKey.'" value="'.h(fval($rKey)).'" placeholder="CV" data-small="1"></td>';
  echo '</tr>';
}

// =========================
// POST handler
// =========================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

  $season      = trim((string)($_POST['season'] ?? ''));
  $competition = trim((string)($_POST['competition'] ?? ''));
  $date        = trim((string)($_POST['match_date'] ?? ''));
  $match_time  = trim((string)($_POST['match_time'] ?? ''));
  $phase       = trim((string)($_POST['phase'] ?? ''));
  $round       = trim((string)($_POST['round'] ?? ''));
  $stadium     = trim((string)($_POST['stadium'] ?? ''));
  $referee     = trim((string)($_POST['referee'] ?? ''));
  $kit_used    = trim((string)($_POST['kit_used'] ?? ''));
  $weather     = trim((string)($_POST['weather'] ?? ''));
  $home        = trim((string)($_POST['home'] ?? ''));
  $away        = trim((string)($_POST['away'] ?? ''));

  $hsRaw = trim((string)($_POST['home_score'] ?? ''));
  $asRaw = trim((string)($_POST['away_score'] ?? ''));
  $home_score = ($hsRaw === '') ? null : (int)$hsRaw;
  $away_score = ($asRaw === '') ? null : (int)$asRaw;

  $isHomePal = (strcasecmp($home, $club) === 0);
  $isAwayPal = (strcasecmp($away, $club) === 0);
  if (!$isHomePal && !$isAwayPal) {
    redirect('/?page=create_match&err=palmeiras_only');
  }

  if ($season === '' || $competition === '' || $date === '' || $home === '' || $away === '' || strcasecmp($home, $away) === 0) {
    redirect('/?page=create_match&err=invalid');
  }
  if ($kit_used === '' || $weather === '') {
    redirect('/?page=create_match&err=invalid');
  }

  $year = substr($date, 0, 4);
  if ($year !== $season) {
    redirect('/?page=create_match&err=season_mismatch');
  }

  $oppClub = $isHomePal ? $away : $home;

  $hasPal = false;
  $hasOpp = false;

  for ($i=0; $i<$MAX_STARTERS; $i++) if (trim((string)($_POST["pal_pid_starter_$i"] ?? '')) !== '') $hasPal = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if (trim((string)($_POST["pal_pid_bench_$i"] ?? '')) !== '') $hasPal = true;

  for ($i=0; $i<$MAX_STARTERS; $i++) if (trim((string)($_POST["opp_name_starter_$i"] ?? '')) !== '') $hasOpp = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if (trim((string)($_POST["opp_name_bench_$i"] ?? '')) !== '') $hasOpp = true;

  if (!$hasPal || !$hasOpp) {
    redirect('/?page=create_match&err=roster_required');
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

    $insMatchPlayer = $pdo->prepare("INSERT INTO match_players(match_id, club_name, player_id, role, position, sort_order, entered)
      VALUES (?,?,?,?,?,?,?)");
    $insStats = $pdo->prepare("INSERT INTO match_player_stats(match_id, club_name, player_id, goals_for, goals_against, assists, yellow_cards, red_cards, rating, motm)
      VALUES (?,?,?,?,?,?,?,?,?,?)");

    // Palmeiras players (já existem)
    $savePal = function(string $role, int $max) use ($club, $matchId, $insMatchPlayer, $insStats, $positions): void {
      for ($i=0; $i<$max; $i++) {
        $pid = (int)($_POST["pal_pid_{$role}_{$i}"] ?? 0);
        if ($pid <= 0) continue;

        $pos = trim((string)($_POST["pal_pos_{$role}_{$i}"] ?? ''));
        if ($pos !== '' && !in_array($pos, $positions, true)) $pos = '';

        // ✅ rating com vírgula
        $rating = num0($_POST["pal_rating_{$role}_{$i}"] ?? '');

        $g  = int0($_POST["pal_g_{$role}_{$i}"] ?? '');
        $a  = int0($_POST["pal_a_{$role}_{$i}"] ?? '');
        $og = int0($_POST["pal_og_{$role}_{$i}"] ?? '');
        $y  = int0($_POST["pal_y_{$role}_{$i}"] ?? '');
        $r  = int0($_POST["pal_r_{$role}_{$i}"] ?? '');

        $entered = ($role === 'starter') ? 1 : 0;

        // ✅ CHECK do banco exige STARTER/BENCH
        $dbRole = ($role === 'starter') ? 'STARTER' : 'BENCH';

        $insMatchPlayer->execute([$matchId, $club, $pid, $dbRole, $pos, $i+1, $entered]);
        $insStats->execute([$matchId, $club, $pid, $g, $og, $a, $y, $r, $rating, 0]);
      }
    };

    // Opponent players (cria no players se não existir)
    $findOppPlayer = $pdo->prepare("SELECT id FROM players WHERE club_name = ? AND name = ? LIMIT 1");
    $insOppPlayer  = $pdo->prepare("
      INSERT INTO players(name, shirt_number, primary_position, secondary_positions, is_active, club_name, created_at, updated_at)
      VALUES (?,?,?,?,?,?,datetime('now'),datetime('now'))
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

        // ✅ rating com vírgula
        $rating = num0($_POST["opp_rating_{$role}_{$i}"] ?? '');

        $g  = int0($_POST["opp_g_{$role}_{$i}"] ?? '');
        $a  = int0($_POST["opp_a_{$role}_{$i}"] ?? '');
        $og = int0($_POST["opp_og_{$role}_{$i}"] ?? '');
        $y  = int0($_POST["opp_y_{$role}_{$i}"] ?? '');
        $r  = int0($_POST["opp_r_{$role}_{$i}"] ?? '');

        $entered = ($role === 'starter') ? 1 : 0;

        // ✅ CHECK do banco exige STARTER/BENCH
        $dbRole = ($role === 'starter') ? 'STARTER' : 'BENCH';

        $insMatchPlayer->execute([$matchId, $oppClub, $pid, $dbRole, $pos, $i+1, $entered]);
        $insStats->execute([$matchId, $oppClub, $pid, $g, $og, $a, $y, $r, $rating, 0]);
      }
    };

    $savePal('starter', $MAX_STARTERS);
    $savePal('bench',   $MAX_BENCH);
    $saveOpp('starter', $MAX_STARTERS);
    $saveOpp('bench',   $MAX_BENCH);

    $pdo->commit();
    redirect('/?page=matches&msg=saved');
  } catch (Throwable $e) {
    $pdo->rollBack();
    redirect('/?page=create_match&err=invalid');
  }
}

// =========================
// GET (render)
// =========================
$players = q($pdo, "
  SELECT id, name, shirt_number
  FROM players
  WHERE is_active = 1
  ORDER BY name
")->fetchAll();

render_header('Cadastrar partida');

if ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Preencha os campos obrigatórios e confira os dados.</div>';
} elseif ($err === 'season_mismatch') {
  echo '<div class="alert alert-warning card-soft">A DATA deve estar no mesmo ano da TEMPORADA.</div>';
} elseif ($err === 'palmeiras_only') {
  echo '<div class="alert alert-warning card-soft">Este sistema aceita apenas jogos onde o <b>' . h($club) . '</b> participa.</div>';
} elseif ($err === 'roster_required') {
  echo '<div class="alert alert-warning card-soft">Informe ao menos 1 atleta no Palmeiras e 1 no adversário (titular ou reserva).</div>';
} elseif ($msg === 'saved') {
  echo '<div class="alert alert-success card-soft">Partida cadastrada com sucesso.</div>';
}

echo <<<HTML
<style>
/* ===== Create Match: alinhamento de colunas + responsivo (FullHD/4K -> Mobile) ===== */

/* Importante: NÃO force cores globais de <select> aqui.
   O layout.php já controla tema/contraste. Aqui ajustamos só a tabela do roster. */

/* Wrapper */
.pm-roster-table { width: 100%; table-layout: fixed; }
.pm-roster-table th, .pm-roster-table td { vertical-align: middle; }

/* Colunas: definimos larguras fixas para as “pequenas”
   e deixamos “Atleta” ocupar o resto (melhor para 16:9/4K). */
.pm-roster-table col.pm-col-pos   { width: clamp(74px, 5.5vw, 92px); }
.pm-roster-table col.pm-col-nota  { width: clamp(86px, 6.5vw, 120px); }
.pm-roster-table col.pm-col-mini  { width: clamp(56px, 4.2vw, 76px); }

/* Espaçamento e alinhamento */
.pm-roster-table th { padding: .55rem .65rem; }
.pm-roster-table td { padding: .45rem .55rem; }
.pm-roster-table th, .pm-roster-table td { white-space: nowrap; }

/* Inputs/selects ocupam 100% da célula */
.pm-roster-table select,
.pm-roster-table input{
  width: 100%;
  height: 40px;
  border-radius: 14px;
  padding: 0 12px;
}

/* Campo “Atleta” pode ficar mais “alto” visualmente (melhor leitura) */
.pm-roster-table td:first-child select,
.pm-roster-table td:first-child input{
  padding-left: 14px;
  padding-right: 34px; /* espaço seta do select */
}

/* Colunas numéricas centralizadas */
.pm-roster-table td:nth-child(n+3) input{ text-align: center; padding: 0 8px; }

/* Evita que o header “suma” com tabela muito estreita */
.pm-roster-table thead th{ position: sticky; top: 0; z-index: 1; }

/* Mobile/9:16: rolagem horizontal ao invés de esmagar */
@media (max-width: 900px){
  .table-responsive{ overflow-x:auto; }
  .pm-roster-table{ min-width: 980px; }
}
</style>
HTML;

// Lista de atletas do Palmeiras carregada do banco (sempre array)
$palPlayers = is_array($players) ? $players : [];

echo '<div class="row g-4">';
echo '<div class="col-12">';
echo '<div class="card-soft p-3">';

echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<form method="post" autocomplete="off">';

echo '<div class="row g-3">';

echo '<div class="col-lg-2 col-md-4 col-6">';
echo '<label class="form-label">Temporada</label>';
echo '<select name="season" class="form-select" required><option value="">-- selecione --</option>';
echo select_options($seasons, fval('season'));
echo '</select></div>';

echo '<div class="col-lg-4 col-md-8 col-6">';
echo '<label class="form-label">Campeonato</label>';
echo '<select name="competition" class="form-select" required><option value="">-- selecione --</option>';
echo select_options($competitions, fval('competition'));
echo '</select></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Data</label>';
echo '<input type="date" name="match_date" class="form-control" value="'.h(fval('match_date')).'" required></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Horário</label>';
echo '<input type="time" name="match_time" class="form-control" value="'.h(fval('match_time')).'"></div>';

echo '<div class="col-lg-3 col-md-6 col-12">';
echo '<label class="form-label">Fase</label>';
echo '<input name="phase" class="form-control" value="'.h(fval('phase')).'" placeholder="Fase de grupos / Quartas / Final"></div>';

echo '<div class="col-lg-2 col-md-6 col-12">';
echo '<label class="form-label">Rodada</label>';
echo '<input name="round" class="form-control" value="'.h(fval('round')).'" placeholder="1ª rodada"></div>';

echo '<div class="col-lg-4 col-md-6 col-12">';
echo '<label class="form-label">Estádio</label>';
echo '<input name="stadium" class="form-control" value="'.h(fval('stadium')).'" placeholder="Allianz Parque"></div>';

echo '<div class="col-lg-3 col-md-6 col-12">';
echo '<label class="form-label">Árbitro</label>';
echo '<input name="referee" class="form-control" value="'.h(fval('referee')).'" placeholder="Nome do árbitro"></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Uniforme</label>';
echo '<select name="kit_used" class="form-select" required><option value="">-- selecione --</option>';
echo select_options($kits, fval('kit_used'));
echo '</select></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Clima</label>';
echo '<select name="weather" class="form-select" required><option value="">-- selecione --</option>';
echo select_options($weathers, fval('weather'));
echo '</select></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Mandante</label>';
echo '<input name="home" class="form-control" value="'.h(fval('home', ['home'=>$club])).'" required></div>';

echo '<div class="col-lg-3 col-md-6 col-6">';
echo '<label class="form-label">Visitante</label>';
echo '<input name="away" class="form-control" value="'.h(fval('away')).'" placeholder="Adversário" required></div>';

echo '<div class="col-lg-1 col-md-3 col-6">';
echo '<label class="form-label">GF</label>';
echo '<input name="home_score" class="form-control" value="'.h(fval('home_score')).'" inputmode="numeric"></div>';

echo '<div class="col-lg-1 col-md-3 col-6">';
echo '<label class="form-label">GA</label>';
echo '<input name="away_score" class="form-control" value="'.h(fval('away_score')).'" inputmode="numeric"></div>';

echo '</div>'; // row g-3

echo '<hr class="my-4" style="opacity:.25;">';

echo '<h4 class="mb-1">Relacionados e desempenho</h4>';
echo '<div class="text-muted small mb-3">Cadastre titulares e reservas do <b>'.h($club).'</b> e do adversário. Para o adversário, você pode digitar o nome e nós salvaremos como atleta do clube informado.</div>';

$cg = '<colgroup><col><col class="pm-col-pos"><col class="pm-col-nota"><col class="pm-col-mini"><col class="pm-col-mini"><col class="pm-col-mini"><col class="pm-col-mini"><col class="pm-col-mini"></colgroup>';

echo '<div class="col-12 mt-2">';
echo '<div class="row g-3">';

echo '<div class="col-lg-6">';
echo '<div class="card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">'.h($club).'</h6><span class="text-muted small">Titulares</span></div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm mb-0 pm-roster-table">'.$cg.'<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_STARTERS; $i++) row_player_pal($i, 'starter', $positions, $palPlayers);
echo '</tbody></table></div>';

echo '<div class="mt-3 d-flex justify-content-between align-items-center"><span class="text-muted small">Reservas (máx. '.$MAX_BENCH.')</span></div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm mb-0 pm-roster-table">'.$cg.'<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_BENCH; $i++) row_player_pal($i, 'bench', $positions, $palPlayers);
echo '</tbody></table></div>';

echo '</div></div>';

echo '<div class="col-lg-6">';
echo '<div class="card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Adversário</h6><span class="text-muted small">Titulares</span></div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm mb-0 pm-roster-table">'.$cg.'<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_STARTERS; $i++) row_player_opp($i, 'starter', $positions);
echo '</tbody></table></div>';

echo '<div class="mt-3 d-flex justify-content-between align-items-center"><span class="text-muted small">Reservas (máx. '.$MAX_BENCH.')</span></div>';
echo '<div class="table-responsive">';
echo '<table class="table table-sm mb-0 pm-roster-table">'.$cg.'<thead><tr><th>Atleta</th><th>POS</th><th>Nota</th><th>G</th><th>A</th><th>GC</th><th>CA</th><th>CV</th></tr></thead><tbody>';
for ($i=0; $i<$MAX_BENCH; $i++) row_player_opp($i, 'bench', $positions);
echo '</tbody></table></div>';

echo '</div></div>';

echo '</div></div>'; // row / col-12

echo '<div class="d-flex gap-2 mt-3">';
echo '<button class="btn btn-success">Salvar partida completa</button>';
echo '<a class="btn btn-outline-secondary" href="/?page=matches">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>'; // card-soft
echo '</div>'; // col-12
echo '</div>'; // row g-4

render_footer();
