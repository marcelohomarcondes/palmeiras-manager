<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$PAL  = app_club(); // ex: PALMEIRAS
$club = $PAL;

/* ================= Helpers (não redeclara h()) ================= */
if (!function_exists('pm_log')) {
  function pm_log(string $level, string $message): void {
    $dir = 'D:\\Projetos\\palmeiras_manager\\logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] [$level] $message" . PHP_EOL, FILE_APPEND);
  }
}

function postv(string $key, string $default = ''): string {
  return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

function fval(string $key, string $default = ''): string {
  // usado no GET para repopular campos quando houver redirect com POST não preservado;
  // aqui fica como fallback simples (para o caso de você evoluir para manter POST)
  return postv($key, $default);
}

function int0(mixed $v): int {
  $v = trim((string)$v);
  return ($v === '') ? 0 : (int)$v;
}

function num0(mixed $v): float {
  $v = trim((string)$v);
  if ($v === '') return 0.0;
  $v = str_replace(',', '.', $v);
  return (float)$v;
}

function pick_existing_cols(PDO $pdo, string $table): array {
  $info = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
  $cols = [];
  foreach ($info as $r) $cols[(string)$r['name']] = true;
  return $cols;
}

function select_options(array $items, string $selected): string {
  $out = '';
  foreach ($items as $it) {
    $sel = ((string)$it === $selected) ? ' selected' : '';
    $out .= '<option value="'.h((string)$it).'"'.$sel.'>'.h((string)$it).'</option>';
  }
  return $out;
}

/* ================= Listas ================= */
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

$seasons = [];
for ($y = 2026; $y <= 2040; $y++) $seasons[] = (string)$y;

$kits     = ['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'];
$weathers = ['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'];
$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

$MAX_STARTERS = 11;
$MAX_BENCH    = 9;

$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

/* ================= Jogadores Palmeiras ================= */
$players = q($pdo, "
  SELECT id, name, shirt_number
  FROM players
  WHERE is_active = 1
    AND club_name = ? COLLATE NOCASE
  ORDER BY name
", [$club])->fetchAll(PDO::FETCH_ASSOC);

$palPlayers = is_array($players) ? $players : [];

/* ================= Render tabela (layout match.php) ================= */
function render_table_create(
  bool $isPal,
  string $role, // starter | bench
  int $maxRows,
  array $positions,
  array $palPlayers,
  string $mvpSelected
): void {

  $label = ($role === 'starter') ? 'Titulares (11)' : 'Reservas (9)';

  echo '<h6 class="mt-3">'.h($label).'</h6>';
  echo '<table class="table table-dark table-sm">';
  echo '<thead><tr>
    <th style="width:70px;">POS</th>
    <th>Atleta</th>
    <th style="width:55px;" class="text-center">G</th>
    <th style="width:55px;" class="text-center">A</th>
    <th style="width:60px;" class="text-center">GC</th>
    <th style="width:60px;" class="text-center">CA</th>
    <th style="width:60px;" class="text-center">CV</th>
    <th style="width:65px;" class="text-center">Nota</th>
    <th style="width:60px;" class="text-center">MVP</th>
  </tr></thead><tbody>';

  for ($i = 0; $i < $maxRows; $i++) {

    if ($isPal) {
      $pidKey = "pal_pid_{$role}_{$i}";
      $posKey = "pal_pos_{$role}_{$i}";
      $gKey   = "pal_g_{$role}_{$i}";
      $aKey   = "pal_a_{$role}_{$i}";
      $gcKey  = "pal_og_{$role}_{$i}";
      $caKey  = "pal_y_{$role}_{$i}";
      $cvKey  = "pal_r_{$role}_{$i}";
      $rtKey  = "pal_rating_{$role}_{$i}";
      $mvpVal = "pal_{$role}_{$i}";
    } else {
      $pidKey = "opp_name_{$role}_{$i}";
      $posKey = "opp_pos_{$role}_{$i}";
      $gKey   = "opp_g_{$role}_{$i}";
      $aKey   = "opp_a_{$role}_{$i}";
      $gcKey  = "opp_og_{$role}_{$i}";
      $caKey  = "opp_y_{$role}_{$i}";
      $cvKey  = "opp_r_{$role}_{$i}";
      $rtKey  = "opp_rating_{$role}_{$i}";
      $mvpVal = "opp_{$role}_{$i}";
    }

    $checkedMvp = ($mvpSelected !== '' && $mvpSelected === $mvpVal) ? 'checked' : '';

    echo '<tr>';

    // POS dropdown (como você pediu)
    echo '<td>
      <select class="form-select form-select-sm text-center px-1" style="max-width:70px;" name="'.h($posKey).'">
        <option value=""></option>'.select_options($positions, postv($posKey)).'
      </select>
    </td>';

    // Atleta
    echo '<td>';
    if ($isPal) {
      echo '<select class="form-select form-select-sm w-100" name="'.h($pidKey).'">';
      echo '<option value="0">--</option>';
      $cur = (int)postv($pidKey, '0');
      foreach ($palPlayers as $p) {
        $labelP = (string)$p['name'];
        if (!empty($p['shirt_number'])) $labelP = $p['shirt_number'].' - '.$labelP;
        $sel = ((int)$p['id'] === $cur) ? 'selected' : '';
        echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.h($labelP).'</option>';
      }
      echo '</select>';
    } else {
      echo '<input class="form-control form-control-sm" name="'.h($pidKey).'" value="'.h(postv($pidKey)).'" placeholder="Nome do atleta">';
    }
    echo '</td>';

    $small = 'class="form-control form-control-sm text-center px-1" style="max-width:60px;"';

    echo '<td><input type="number" name="'.h($gKey).'"  value="'.h(postv($gKey, '0')).'"  '.$small.'></td>';
    echo '<td><input type="number" name="'.h($aKey).'"  value="'.h(postv($aKey, '0')).'"  '.$small.'></td>';
    echo '<td><input type="number" name="'.h($gcKey).'" value="'.h(postv($gcKey,'0')).'" '.$small.'></td>';
    echo '<td><input type="number" name="'.h($caKey).'" value="'.h(postv($caKey,'0')).'" '.$small.'></td>';
    echo '<td><input type="number" name="'.h($cvKey).'" value="'.h(postv($cvKey,'0')).'" '.$small.'></td>';

    echo '<td>
      <input type="number" step="0.1" min="0" max="10"
        name="'.h($rtKey).'"
        value="'.h(postv($rtKey)).'"
        class="form-control form-control-sm text-center px-1"
        style="max-width:65px;">
    </td>';

    echo '<td class="text-center">
      <input type="checkbox" class="mvp-checkbox" name="mvp_row" value="'.h($mvpVal).'" '.$checkedMvp.'>
    </td>';

    echo '</tr>';
  }

  echo '</tbody></table>';
}

/* ================= POST (Salvar) ================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

  // Campos (mantidos)
  $season      = trim(postv('season'));
  $competition = trim(postv('competition'));
  $date        = trim(postv('match_date'));  // usa $date para manter padrão do seu arquivo
  $match_time  = trim(postv('match_time'));
  $phase       = trim(postv('phase'));
  $round       = trim(postv('round'));
  $stadium     = trim(postv('stadium'));
  $referee     = trim(postv('referee'));
  $kit_used    = trim(postv('kit_used'));
  $weather     = trim(postv('weather'));
  $home        = strtoupper(trim(postv('home')));
  $away        = strtoupper(trim(postv('away')));

  $home_score_raw = trim(postv('home_score'));
  $away_score_raw = trim(postv('away_score'));
  $home_score = ($home_score_raw === '') ? null : (int)$home_score_raw;
  $away_score = ($away_score_raw === '') ? null : (int)$away_score_raw;

  // MVP (seleção única)
  $mvpRaw = $_POST['mvp_row'] ?? '';
  if (is_array($mvpRaw)) $mvpRaw = $mvpRaw[0] ?? '';
  $mvpRow = (string)$mvpRaw;

  $isHomePal = (strcasecmp($home, $club) === 0);
  $isAwayPal = (strcasecmp($away, $club) === 0);

  if (!$isHomePal && !$isAwayPal) redirect('?page=create_match&err=palmeiras_only');
  if ($season === '' || $competition === '' || $date === '' || $home === '' || $away === '' || strcasecmp($home, $away) === 0) {
    redirect('?page=create_match&err=invalid');
  }
  if ($kit_used === '' || $weather === '') redirect('?page=create_match&err=invalid');

  // valida ano da data vs temporada
  if (substr($date, 0, 4) !== $season) redirect('?page=create_match&err=season_mismatch');

  $oppClub = $isHomePal ? $away : $home;

  // precisa ter pelo menos 1 do Palmeiras e 1 do adversário
  $hasPal = false;
  $hasOpp = false;

  for ($i=0; $i<$MAX_STARTERS; $i++) if ((int)($_POST["pal_pid_starter_$i"] ?? 0) > 0) $hasPal = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if ((int)($_POST["pal_pid_bench_$i"] ?? 0) > 0) $hasPal = true;

  for ($i=0; $i<$MAX_STARTERS; $i++) if (trim((string)($_POST["opp_name_starter_$i"] ?? '')) !== '') $hasOpp = true;
  for ($i=0; $i<$MAX_BENCH; $i++)    if (trim((string)($_POST["opp_name_bench_$i"] ?? '')) !== '') $hasOpp = true;

  if (!$hasPal || !$hasOpp) redirect('?page=create_match&err=roster_required');

  // NÃO permitir mesmo jogador do Palmeiras duplicado
  $palSeen = [];
  for ($i=0; $i<$MAX_STARTERS; $i++) {
    $pid = (int)($_POST["pal_pid_starter_$i"] ?? 0);
    if ($pid <= 0) continue;
    if (isset($palSeen[$pid])) redirect('?page=create_match&err=dup_player');
    $palSeen[$pid] = true;
  }
  for ($i=0; $i<$MAX_BENCH; $i++) {
    $pid = (int)($_POST["pal_pid_bench_$i"] ?? 0);
    if ($pid <= 0) continue;
    if (isset($palSeen[$pid])) redirect('?page=create_match&err=dup_player');
    $palSeen[$pid] = true;
  }

  // Detecta schema
  $mpCols = pick_existing_cols($pdo, 'match_players');
  $mpHasOppId = isset($mpCols['opponent_player_id']);
  $mpHasType  = isset($mpCols['player_type']);

  // Garante tabela stats adversário
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS opponent_match_player_stats (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      match_id INTEGER NOT NULL,
      club_name TEXT NOT NULL,
      opponent_player_id INTEGER NOT NULL,
      goals_for INTEGER NOT NULL DEFAULT 0,
      goals_against INTEGER NOT NULL DEFAULT 0,
      assists INTEGER NOT NULL DEFAULT 0,
      yellow_cards INTEGER NOT NULL DEFAULT 0,
      red_cards INTEGER NOT NULL DEFAULT 0,
      rating REAL NOT NULL DEFAULT 0,
      motm INTEGER NOT NULL DEFAULT 0,
      FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
      FOREIGN KEY(opponent_player_id) REFERENCES opponent_players(id) ON DELETE RESTRICT
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_omps_match ON opponent_match_player_stats(match_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_omps_player ON opponent_match_player_stats(opponent_player_id)");

  try {
    $pdo->beginTransaction();

    /* ---------- INSERT matches (dinâmico, sem created_at/updated_at obrigatórios) ---------- */
    $matchesCols = pick_existing_cols($pdo, 'matches');

    // Mapeia campos possíveis
    $data = [];

    if (isset($matchesCols['season']))      $data['season'] = $season;
    if (isset($matchesCols['competition'])) $data['competition'] = $competition;
    if (isset($matchesCols['phase']))       $data['phase'] = $phase;
    if (isset($matchesCols['round']))       $data['round'] = $round;

    if (isset($matchesCols['match_date']))  $data['match_date'] = $date;
    if (isset($matchesCols['match_time']))  $data['match_time'] = $match_time;

    if (isset($matchesCols['stadium']))     $data['stadium'] = $stadium;
    if (isset($matchesCols['referee']))     $data['referee'] = $referee;

    if (isset($matchesCols['home']))        $data['home'] = $home;
    if (isset($matchesCols['away']))        $data['away'] = $away;

    if (isset($matchesCols['kit_used']))    $data['kit_used'] = $kit_used;
    if (isset($matchesCols['weather']))     $data['weather']  = $weather;

    // placar: home_score/away_score OU home_goals/away_goals
    if (isset($matchesCols['home_score']))      $data['home_score'] = $home_score;
    elseif (isset($matchesCols['home_goals']))  $data['home_goals'] = $home_score;

    if (isset($matchesCols['away_score']))      $data['away_score'] = $away_score;
    elseif (isset($matchesCols['away_goals']))  $data['away_goals'] = $away_score;

    // created_at/updated_at só se existirem
    $now = date('Y-m-d H:i:s');
    if (isset($matchesCols['created_at'])) $data['created_at'] = $now;
    if (isset($matchesCols['updated_at'])) $data['updated_at'] = $now;

    // segurança: precisa ter ao menos os essenciais
    foreach (['season','competition'] as $req) {
      if (!isset($data[$req])) {
        throw new RuntimeException("Schema de matches não contém coluna obrigatória: {$req}");
      }
    }

    $cols = array_keys($data);
    $ph   = array_fill(0, count($cols), '?');
    $vals = array_values($data);

    $sqlMatch = "INSERT INTO matches (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
    $stmtMatch = $pdo->prepare($sqlMatch);
    $stmtMatch->execute($vals);

    $matchId = (int)$pdo->lastInsertId();

    /* ---------- match_players ---------- */
    if ($mpHasOppId && $mpHasType) {
      $insMatchPlayer = $pdo->prepare("
        INSERT INTO match_players(match_id, club_name, player_id, opponent_player_id, role, position, sort_order, entered, player_type)
        VALUES (?,?,?,?,?,?,?,?,?)
      ");
    } elseif ($mpHasOppId) {
      $insMatchPlayer = $pdo->prepare("
        INSERT INTO match_players(match_id, club_name, player_id, opponent_player_id, role, position, sort_order, entered)
        VALUES (?,?,?,?,?,?,?,?)
      ");
    } else {
      $insMatchPlayer = $pdo->prepare("
        INSERT INTO match_players(match_id, club_name, player_id, role, position, sort_order, entered)
        VALUES (?,?,?,?,?,?,?)
      ");
    }

    /* ---------- stats ---------- */
    $insStatsPal = $pdo->prepare("
      INSERT INTO match_player_stats(match_id, club_name, player_id, goals_for, goals_against, assists, yellow_cards, red_cards, rating, motm)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    $insStatsOpp = $pdo->prepare("
      INSERT INTO opponent_match_player_stats(match_id, club_name, opponent_player_id, goals_for, goals_against, assists, yellow_cards, red_cards, rating, motm)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    /* ---------- opponent_players (dinâmico) ---------- */
    $oppCols = pick_existing_cols($pdo, 'opponent_players');

    $findOppPlayer = $pdo->prepare("SELECT id FROM opponent_players WHERE club_name = ? AND name = ? LIMIT 1");

    // monta INSERT dinâmico conforme schema real
    $baseOppCols = ['club_name', 'name'];
    if (isset($oppCols['primary_position'])) $baseOppCols[] = 'primary_position';
    if (isset($oppCols['is_active']))        $baseOppCols[] = 'is_active';
    if (isset($oppCols['created_at']))       $baseOppCols[] = 'created_at';
    if (isset($oppCols['updated_at']))       $baseOppCols[] = 'updated_at';

    $insOppSql = "INSERT INTO opponent_players (".implode(',', $baseOppCols).") VALUES (".implode(',', array_fill(0, count($baseOppCols), '?')).")";
    $insOppPlayer = $pdo->prepare($insOppSql);

    /* ---------- Save Palmeiras ---------- */
    $savePal = function(string $role, int $max) use ($club, $matchId, $insMatchPlayer, $insStatsPal, $mpHasOppId, $mpHasType, $mvpRow): void {
      $dbRole  = ($role === 'starter') ? 'STARTER' : 'BENCH';
      $entered = ($role === 'starter') ? 1 : 0;

      for ($i=0; $i<$max; $i++) {
        $pid = (int)($_POST["pal_pid_{$role}_{$i}"] ?? 0);
        if ($pid <= 0) continue;

        $pos = trim((string)($_POST["pal_pos_{$role}_{$i}"] ?? ''));

        $rating = num0($_POST["pal_rating_{$role}_{$i}"] ?? '');
        $g  = int0($_POST["pal_g_{$role}_{$i}"] ?? 0);
        $a  = int0($_POST["pal_a_{$role}_{$i}"] ?? 0);
        $gc = int0($_POST["pal_og_{$role}_{$i}"] ?? 0);
        $ca = int0($_POST["pal_y_{$role}_{$i}"] ?? 0);
        $cv = int0($_POST["pal_r_{$role}_{$i}"] ?? 0);

        $sortOrder = $i + 1;

        if ($mpHasOppId && $mpHasType) {
          $insMatchPlayer->execute([$matchId, $club, $pid, null, $dbRole, $pos, $sortOrder, $entered, 'HOME']);
        } elseif ($mpHasOppId) {
          $insMatchPlayer->execute([$matchId, $club, $pid, null, $dbRole, $pos, $sortOrder, $entered]);
        } else {
          $insMatchPlayer->execute([$matchId, $club, $pid, $dbRole, $pos, $sortOrder, $entered]);
        }

        $rowId = "pal_{$role}_{$i}";
        $isMvp = ($mvpRow !== '' && $mvpRow === $rowId) ? 1 : 0;

        $insStatsPal->execute([$matchId, $club, $pid, $g, $gc, $a, $ca, $cv, $rating, $isMvp]);
      }
    };

    /* ---------- Save Opponent ---------- */
    $saveOpp = function(string $role, int $max) use (
      $oppClub, $matchId, $insMatchPlayer, $insStatsOpp,
      $mpHasOppId, $mpHasType, $mvpRow,
      $findOppPlayer, $insOppPlayer, $baseOppCols
    ): void {
      $dbRole  = ($role === 'starter') ? 'STARTER' : 'BENCH';
      $entered = ($role === 'starter') ? 1 : 0;

      for ($i=0; $i<$max; $i++) {
        $name = trim((string)($_POST["opp_name_{$role}_{$i}"] ?? ''));
        if ($name === '') continue;

        $pos = trim((string)($_POST["opp_pos_{$role}_{$i}"] ?? ''));

        $findOppPlayer->execute([$oppClub, $name]);
        $row = $findOppPlayer->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['id'])) {
          $oppPid = (int)$row['id'];
        } else {
          $now = date('Y-m-d H:i:s');
          $vals = [];
          foreach ($baseOppCols as $c) {
            if ($c === 'club_name') $vals[] = $oppClub;
            elseif ($c === 'name') $vals[] = $name;
            elseif ($c === 'primary_position') $vals[] = $pos;
            elseif ($c === 'is_active') $vals[] = 1;
            elseif ($c === 'created_at') $vals[] = $now;
            elseif ($c === 'updated_at') $vals[] = $now;
            else $vals[] = null;
          }
          $insOppPlayer->execute($vals);
          $oppPid = (int)$GLOBALS['pdo']->lastInsertId();
        }

        $rating = num0($_POST["opp_rating_{$role}_{$i}"] ?? '');
        $g  = int0($_POST["opp_g_{$role}_{$i}"] ?? 0);
        $a  = int0($_POST["opp_a_{$role}_{$i}"] ?? 0);
        $gc = int0($_POST["opp_og_{$role}_{$i}"] ?? 0);
        $ca = int0($_POST["opp_y_{$role}_{$i}"] ?? 0);
        $cv = int0($_POST["opp_r_{$role}_{$i}"] ?? 0);

        $sortOrder = $i + 1;

        if ($mpHasOppId && $mpHasType) {
          $insMatchPlayer->execute([$matchId, $oppClub, null, $oppPid, $dbRole, $pos, $sortOrder, $entered, 'AWAY']);
        } elseif ($mpHasOppId) {
          $insMatchPlayer->execute([$matchId, $oppClub, null, $oppPid, $dbRole, $pos, $sortOrder, $entered]);
        } else {
          $insMatchPlayer->execute([$matchId, $oppClub, $oppPid, $dbRole, $pos, $sortOrder, $entered]);
        }

        $rowId = "opp_{$role}_{$i}";
        $isMvp = ($mvpRow !== '' && $mvpRow === $rowId) ? 1 : 0;

        $insStatsOpp->execute([$matchId, $oppClub, $oppPid, $g, $gc, $a, $ca, $cv, $rating, $isMvp]);
      }
    };

    $savePal('starter', $MAX_STARTERS);
    $savePal('bench',   $MAX_BENCH);
    $saveOpp('starter', $MAX_STARTERS);
    $saveOpp('bench',   $MAX_BENCH);

    $pdo->commit();

    pm_log('INFO', "create_match OK match_id={$matchId} home={$home} away={$away} oppClub={$oppClub}");
    redirect('/?page=match&id=' . $matchId);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pm_log('ERROR', 'create_match FAIL: '.$e->getMessage());
    redirect('?page=create_match&err=exception');
  }
}

/* ================= UI ================= */
render_header('Cadastrar partida');

// Descobrir título do adversário
$homeName = fval('home');
$awayName = fval('away');
$oppTitle = 'Adversário';
if ($homeName !== '' && $awayName !== '') {
  if (strcasecmp($homeName, $club) === 0) $oppTitle = $awayName;
  elseif (strcasecmp($awayName, $club) === 0) $oppTitle = $homeName;
}

// alerts
if ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Preencha os campos obrigatórios e confira os dados.</div>';
} elseif ($err === 'season_mismatch') {
  echo '<div class="alert alert-warning card-soft">A DATA deve estar no mesmo ano da TEMPORADA.</div>';
} elseif ($err === 'palmeiras_only') {
  echo '<div class="alert alert-warning card-soft">Este sistema aceita apenas jogos onde o '.h($club).' participa.</div>';
} elseif ($err === 'roster_required') {
  echo '<div class="alert alert-warning card-soft">Informe ao menos 1 atleta do Palmeiras e 1 do adversário.</div>';
} elseif ($err === 'dup_player') {
  echo '<div class="alert alert-warning card-soft">Você selecionou o <b>mesmo jogador do Palmeiras</b> mais de uma vez.</div>';
} elseif ($err === 'exception') {
  echo '<div class="alert alert-danger card-soft">Falha ao cadastrar. Verifique o log em <code>D:\\Projetos\\palmeiras_manager\\logs\\app.log</code>.</div>';
} elseif ($msg === 'saved') {
  echo '<div class="alert alert-success card-soft">Partida cadastrada com sucesso.</div>';
}

echo '<style>
table td, table th { vertical-align: middle; }
table input[type="number"] { text-align: center; }
</style>';

echo '<form method="post" autocomplete="off">';

echo '<div class="row g-4">';

echo '<div class="col-12"><div class="card-soft p-3">';
echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<div class="row g-3">';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Temporada</label>
  <select class="form-select" name="season" required>
    <option value="">-- selecione --</option>'.select_options($seasons, fval('season')).'
  </select>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Campeonato</label>
  <select class="form-select" name="competition" required>
    <option value="">-- selecione --</option>'.select_options($competitions, fval('competition')).'
  </select>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Data</label>
  <input class="form-control" type="date" name="match_date" value="'.h(fval('match_date')).'" required>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Horário</label>
  <input class="form-control" type="time" name="match_time" value="'.h(fval('match_time')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Fase</label>
  <input class="form-control" name="phase" value="'.h(fval('phase')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Rodada</label>
  <input class="form-control" name="round" value="'.h(fval('round')).'">
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Estádio</label>
  <input class="form-control" name="stadium" value="'.h(fval('stadium')).'">
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Árbitro</label>
  <input class="form-control" name="referee" value="'.h(fval('referee')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Uniforme</label>
  <select class="form-select" name="kit_used" required>
    <option value="">-- selecione --</option>'.select_options($kits, fval('kit_used')).'
  </select>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Clima</label>
  <select class="form-select" name="weather" required>
    <option value="">-- selecione --</option>'.select_options($weathers, fval('weather')).'
  </select>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Mandante</label>
  <input class="form-control" name="home" value="'.h(fval('home')).'" required>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Visitante</label>
  <input class="form-control" name="away" value="'.h(fval('away')).'" required>
</div>';

echo '<div class="col-6 col-md-2">
  <label class="form-label">GF (Mandante)</label>
  <input class="form-control" name="home_score" value="'.h(fval('home_score')).'" placeholder="0">
</div>';

echo '<div class="col-6 col-md-2">
  <label class="form-label">GA (Visitante)</label>
  <input class="form-control" name="away_score" value="'.h(fval('away_score')).'" placeholder="0">
</div>';

echo '</div>'; // row
echo '</div></div>'; // card dados

// MVP selecionado (repopulação)
$mvpSelected = '';
$mvpRaw = $_POST['mvp_row'] ?? '';
if (is_array($mvpRaw)) $mvpRaw = $mvpRaw[0] ?? '';
$mvpSelected = (string)$mvpRaw;

echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-3">'.h($club).'</h5>';
render_table_create(true,  'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(true,  'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
echo '</div></div>';

echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-3">'.h($oppTitle).'</h5>';
render_table_create(false, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(false, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
echo '</div></div>';

echo '</div>'; // row principal

echo '<div class="text-end mt-3">
  <button class="btn btn-success">Salvar</button>
</div>';

echo '</form>';

render_footer();

echo <<<HTML
<script>
(function () {
  const boxes = Array.from(document.querySelectorAll('.mvp-checkbox'));
  if (!boxes.length) return;

  // garante só 1 marcado
  const checked = boxes.filter(b => b.checked);
  if (checked.length > 1) checked.slice(1).forEach(b => b.checked = false);

  boxes.forEach(box => {
    box.addEventListener('change', function () {
      if (!this.checked) return; // permite ficar sem MVP
      boxes.forEach(b => { if (b !== this) b.checked = false; });
    });
  });
})();
</script>
HTML;
