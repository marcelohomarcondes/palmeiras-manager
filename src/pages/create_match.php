<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club(); // ex: PALMEIRAS

/* =========================================================
   Helpers (sem redeclarar q() / h() do projeto)
   ========================================================= */

if (!function_exists('pm_log')) {
  function pm_log(string $level, string $message): void {
    $dir = 'D:\\Projetos\\palmeiras_manager\\logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts][$level] $message\n", FILE_APPEND);
  }
}

if (!function_exists('redirect')) {
  function redirect(string $url): void { header('Location: '.$url); exit; }
}

if (!function_exists('postv')) {
  function postv(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    if (is_array($v)) $v = $v[0] ?? $default;
    return trim((string)$v);
  }
}

if (!function_exists('fval')) {
  function fval(string $key, string $default = ''): string {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') return postv($key, $default);
    return trim((string)($_GET[$key] ?? $default));
  }
}

if (!function_exists('to_int')) {
  function to_int(string $v, int $default = 0): int {
    $v = trim($v);
    return ($v === '') ? $default : (int)$v;
  }
}

if (!function_exists('to_float')) {
  function to_float(string $v, float $default = 0.0): float {
    $v = trim((string)$v);
    if ($v === '') return $default;
    $v = str_replace(',', '.', $v);
    return (float)$v;
  }
}

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('table_info')) {
  /**
   * Retorna map: colName => ['notnull'=>0/1,'dflt'=>mixed,'type'=>string]
   */
  function table_info(PDO $pdo, string $table): array {
    $map = [];
    try {
      $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $name = (string)$r['name'];
        $map[$name] = [
          'notnull' => (int)($r['notnull'] ?? 0),
          'dflt'    => $r['dflt_value'] ?? null,
          'type'    => (string)($r['type'] ?? ''),
        ];
      }
    } catch (Throwable $e) {}
    return $map;
  }
}

if (!function_exists('pick_col')) {
  function pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (isset($cols[$c])) return $c;
    return null;
  }
}

if (!function_exists('select_options')) {
  function select_options(array $items, string $selected): string {
    $out = '';
    foreach ($items as $k => $v) {
      $value = is_int($k) ? (string)$v : (string)$k;
      $label = (string)$v;
      $sel = (strcasecmp($value, (string)$selected) === 0) ? ' selected' : '';
      $out .= '<option value="'.h($value).'"'.$sel.'>'.h($label).'</option>';
    }
    return $out;
  }
}

/* =========================================================
   Listas (mantém seu padrão)
   ========================================================= */

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

$kits      = ['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'];
$weathers  = ['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'];
$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

$MAX_STARTERS = 11;
$MAX_BENCH    = 9;

$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

/* =========================================================
   Palmeiras players
   ========================================================= */

$palPlayers = [];
try {
  $palPlayers = q($pdo, "
    SELECT id, name, shirt_number
    FROM players
    WHERE is_active = 1
      AND club_name = ? COLLATE NOCASE
    ORDER BY name
  ", [$club])->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $palPlayers = [];
}

/* =========================================================
   Templates (apenas do clube, se existir club_name)
   ========================================================= */

$templates = [];
try {
  if (table_exists($pdo, 'lineup_templates')) {
    $tplInfo = table_info($pdo, 'lineup_templates');
    if (isset($tplInfo['club_name'])) {
      $templates = q($pdo, "SELECT id, template_name FROM lineup_templates WHERE club_name=? COLLATE NOCASE ORDER BY template_name ASC", [$club])
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $templates = q($pdo, "SELECT id, template_name FROM lineup_templates ORDER BY template_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  }
} catch (Throwable $e) {
  $templates = [];
}

/* =========================================================
   UI tabela (mesmo layout base)
   ========================================================= */

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

  for ($i=0; $i<$maxRows; $i++) {

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
    $small = 'class="form-control form-control-sm text-center px-1" style="max-width:70px;margin:0 auto;"';

    echo '<tr class="text-center">';

    echo '<td>
      <select class="form-select form-select-sm text-center px-1" name="'.h($posKey).'">
        <option value=""></option>'.select_options($positions, postv($posKey)).'
      </select>
    </td>';

    echo '<td class="text-start">';
    if ($isPal) {
      $cur = (int)postv($pidKey, '0');
      echo '<select class="form-select form-select-sm w-100" name="'.h($pidKey).'">';
      echo '<option value="0">--</option>';
      foreach ($palPlayers as $p) {
        $labelP = (string)($p['name'] ?? '');
        $num = (string)($p['shirt_number'] ?? '');
        if ($num !== '') $labelP = $num.' - '.$labelP;
        $sel = ((int)($p['id'] ?? 0) === $cur) ? 'selected' : '';
        echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.h($labelP).'</option>';
      }
      echo '</select>';
    } else {
      echo '<input class="form-control form-control-sm" name="'.h($pidKey).'" value="'.h(postv($pidKey)).'" placeholder="Nome do atleta">';
    }
    echo '</td>';

    echo '<td><input type="number" name="'.h($gKey).'"  value="'.h(postv($gKey,'0')).'"  '.$small.'></td>';
    echo '<td><input type="number" name="'.h($aKey).'"  value="'.h(postv($aKey,'0')).'"  '.$small.'></td>';
    echo '<td><input type="number" name="'.h($gcKey).'" value="'.h(postv($gcKey,'0')).'" '.$small.'></td>';
    echo '<td><input type="number" name="'.h($caKey).'" value="'.h(postv($caKey,'0')).'" '.$small.'></td>';
    echo '<td><input type="number" name="'.h($cvKey).'" value="'.h(postv($cvKey,'0')).'" '.$small.'></td>';
    echo '<td><input type="number" step="0.1" min="0" max="10" name="'.h($rtKey).'" value="'.h(postv($rtKey,'0')).'" '.$small.'></td>';

    echo '<td class="text-center"><input type="radio" name="mvp" value="'.h($mvpVal).'" '.$checkedMvp.'></td>';

    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* =========================================================
   POST
   ========================================================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

  $action = postv('action');

  /* ===== Aplicar template (não salva) ===== */
  if ($action === 'apply_template') {
    $tplId = to_int(postv('pal_template_id'), 0);
    if ($tplId <= 0) { redirect('?page=create_match&err=tpl'); }

    try {
      $rows = q($pdo, "
        SELECT role, sort_order, player_id, position
        FROM lineup_template_slots
        WHERE template_id=?
        ORDER BY role ASC, sort_order ASC
      ", [$tplId])->fetchAll(PDO::FETCH_ASSOC) ?: [];

      for ($i=0;$i<$MAX_STARTERS;$i++){ $_POST["pal_pid_starter_$i"]='0'; $_POST["pal_pos_starter_$i"]=''; }
      for ($i=0;$i<$MAX_BENCH;$i++){ $_POST["pal_pid_bench_$i"]='0'; $_POST["pal_pos_bench_$i"]=''; }

      foreach ($rows as $r) {
        $role = strtoupper(trim((string)($r['role'] ?? '')));
        $i    = (int)($r['sort_order'] ?? -1);
        $pid  = (int)($r['player_id'] ?? 0);
        $pos  = trim((string)($r['position'] ?? ''));

        if ($role === 'STARTER' && $i>=0 && $i<$MAX_STARTERS) { $_POST["pal_pid_starter_$i"]=(string)$pid; $_POST["pal_pos_starter_$i"]=$pos; }
        if ($role === 'BENCH'   && $i>=0 && $i<$MAX_BENCH)    { $_POST["pal_pid_bench_$i"]=(string)$pid;   $_POST["pal_pos_bench_$i"]=$pos; }
      }

      $_POST['pal_template_id'] = (string)$tplId;
      $msg = 'tpl';
      goto RENDER_PAGE;

    } catch (Throwable $e) {
      pm_log('ERROR', 'apply_template FAIL: '.$e->getMessage());
      redirect('?page=create_match&err=tpl');
    }
  }

  /* ===== Salvar partida ===== */

  $season      = postv('season');
  $competition = postv('competition');
  $date        = postv('match_date');
  $match_time  = postv('match_time');
  $phase       = postv('phase');
  $round       = postv('round');
  $stadium     = postv('stadium');
  $referee     = postv('referee');
  $kit_used    = postv('kit_used');
  $weather     = postv('weather');

  $home = strtoupper(trim(postv('home')));
  $away = strtoupper(trim(postv('away')));

  $home_score_raw = postv('home_score');
  $away_score_raw = postv('away_score');

  if ($season==='' || $competition==='' || $date==='' || $home==='' || $away==='') redirect('?page=create_match&err=invalid');
  if (strlen($date) >= 4 && substr($date,0,4) !== $season) redirect('?page=create_match&err=season_mismatch');

  $isHomePal = (strcasecmp($home, $club) === 0);
  $isAwayPal = (strcasecmp($away, $club) === 0);
  if (!$isHomePal && !$isAwayPal) redirect('?page=create_match&err=palmeiras_only');

  $oppClub = $isHomePal ? $away : $home;

  $palType = $isHomePal ? 'HOME' : 'AWAY';
  $oppType = $isHomePal ? 'AWAY' : 'HOME';

  $mvpSelected = postv('mvp');

  // Escalações
  $palRows = [];
  $oppRows = [];

  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $pid = to_int(postv("pal_pid_starter_$i"), 0);
    $pos = postv("pal_pos_starter_$i");
    if ($pid>0 && $pos!=='') {
      $palRows[] = ['role'=>'STARTER','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,'is_mvp'=>($mvpSelected==="pal_starter_$i")?1:0];
    }
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $pid = to_int(postv("pal_pid_bench_$i"), 0);
    $pos = postv("pal_pos_bench_$i");
    if ($pid>0 && $pos!=='') {
      $palRows[] = ['role'=>'BENCH','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,'is_mvp'=>($mvpSelected==="pal_bench_$i")?1:0];
    }
  }

  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $name = postv("opp_name_starter_$i");
    $pos  = postv("opp_pos_starter_$i");
    if ($name!=='' && $pos!=='') {
      $oppRows[] = ['role'=>'STARTER','sort_order'=>$i,'name'=>$name,'position'=>$pos,'is_mvp'=>($mvpSelected==="opp_starter_$i")?1:0];
    }
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $name = postv("opp_name_bench_$i");
    $pos  = postv("opp_pos_bench_$i");
    if ($name!=='' && $pos!=='') {
      $oppRows[] = ['role'=>'BENCH','sort_order'=>$i,'name'=>$name,'position'=>$pos,'is_mvp'=>($mvpSelected==="opp_bench_$i")?1:0];
    }
  }

  if (count($palRows) < 1 || count($oppRows) < 1) redirect('?page=create_match&err=roster_required');

  // evita repetição no Palmeiras
  $seen = [];
  foreach ($palRows as $r) {
    $pid = (int)$r['player_id'];
    if (isset($seen[$pid])) redirect('?page=create_match&err=dup_player');
    $seen[$pid]=true;
  }

  // Schema tables
  $matchesInfo = table_info($pdo, 'matches');
  $matchPlayersInfo = table_info($pdo, 'match_players');
  $oppPlayersInfo   = table_info($pdo, 'opponent_players');
  $hasOppStats      = table_exists($pdo, 'opponent_match_player_stats');
  $oppStatsInfo     = $hasOppStats ? table_info($pdo, 'opponent_match_player_stats') : [];

  // matches: home/away ou fallback home_team/away_team
  $colHome = pick_col($matchesInfo, ['home','home_team']);
  $colAway = pick_col($matchesInfo, ['away','away_team']);
  $colHomeScore = pick_col($matchesInfo, ['home_score','home_goals']);
  $colAwayScore = pick_col($matchesInfo, ['away_score','away_goals']);

  if (!$colHome || !$colAway) {
    pm_log('ERROR', 'matches sem colunas home/away');
    redirect('?page=create_match&err=exception');
  }

  // valida match_players mínimo
  foreach (['match_id','club_name','player_id','opponent_player_id','role','position','sort_order','entered','player_type'] as $need) {
    if (!isset($matchPlayersInfo[$need])) {
      pm_log('ERROR', "match_players sem coluna: $need");
      redirect('?page=create_match&err=exception');
    }
  }

  // valida opponent_players mínimo
  foreach (['club_name','name','is_active','primary_position'] as $need) {
    if (!isset($oppPlayersInfo[$need])) {
      pm_log('ERROR', "opponent_players sem coluna: $need");
      redirect('?page=create_match&err=exception');
    }
  }

  try {
    $pdo->beginTransaction();

    // INSERT matches com colunas existentes
    $matchData = [];
    if (isset($matchesInfo['season'])) $matchData['season'] = $season;
    if (isset($matchesInfo['competition'])) $matchData['competition'] = $competition;
    if (isset($matchesInfo['match_date'])) $matchData['match_date'] = $date;
    if (isset($matchesInfo['match_time'])) $matchData['match_time'] = $match_time;
    if (isset($matchesInfo['phase'])) $matchData['phase'] = $phase;
    if (isset($matchesInfo['round'])) $matchData['round'] = $round;
    if (isset($matchesInfo['stadium'])) $matchData['stadium'] = $stadium;
    if (isset($matchesInfo['referee'])) $matchData['referee'] = $referee;
    if (isset($matchesInfo['kit_used'])) $matchData['kit_used'] = $kit_used;
    if (isset($matchesInfo['weather'])) $matchData['weather'] = $weather;

    $matchData[$colHome] = $home;
    $matchData[$colAway] = $away;

    if ($colHomeScore) $matchData[$colHomeScore] = ($home_score_raw==='' ? null : (int)$home_score_raw);
    if ($colAwayScore) $matchData[$colAwayScore] = ($away_score_raw==='' ? null : (int)$away_score_raw);

    $now = date('Y-m-d H:i:s');
    if (isset($matchesInfo['created_at'])) $matchData['created_at'] = $now;
    if (isset($matchesInfo['updated_at'])) $matchData['updated_at'] = $now;

    $mCols = array_keys($matchData);
    $mPh   = array_fill(0, count($mCols), '?');
    q($pdo, "INSERT INTO matches(".implode(',', $mCols).") VALUES(".implode(',', $mPh).")", array_values($matchData));
    $matchId = (int)$pdo->lastInsertId();

    // Prepared statements
    $insMatchPlayers = $pdo->prepare("
      INSERT INTO match_players(match_id, club_name, player_id, opponent_player_id, role, position, sort_order, entered, player_type)
      VALUES(?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");

    $selOpp = $pdo->prepare("SELECT id FROM opponent_players WHERE club_name=? COLLATE NOCASE AND name=? COLLATE NOCASE LIMIT 1");
    $insOpp = $pdo->prepare("INSERT INTO opponent_players(club_name, name, is_active, primary_position) VALUES(?, ?, 1, ?)");

    // Palmeiras roster
    foreach ($palRows as $r) {
      $insMatchPlayers->execute([$matchId, $club, (int)$r['player_id'], null, $r['role'], $r['position'], (int)$r['sort_order'], $palType]);
    }

    // Adversário roster (+ opponent_players)
    foreach ($oppRows as $r) {
      $name = trim((string)$r['name']);
      if ($name === '') continue;

      $selOpp->execute([$oppClub, $name]);
      $oppId = (int)($selOpp->fetchColumn() ?: 0);

      if ($oppId <= 0) {
        $insOpp->execute([$oppClub, $name, $r['position']]);
        $oppId = (int)$pdo->lastInsertId();
      }

      $insMatchPlayers->execute([$matchId, $oppClub, null, $oppId, $r['role'], $r['position'], (int)$r['sort_order'], $oppType]);

      // opponent_match_player_stats (corrigido: club_name NOT NULL)
      if ($hasOppStats && isset($oppStatsInfo['match_id']) && isset($oppStatsInfo['opponent_player_id'])) {

        $data = [
          'match_id' => $matchId,
          'opponent_player_id' => $oppId,
        ];

        // ✅ seu erro: club_name é NOT NULL
        if (isset($oppStatsInfo['club_name'])) $data['club_name'] = $oppClub;

        // campos comuns que podem existir / ser NOT NULL
        if (isset($oppStatsInfo['role'])) $data['role'] = $r['role'];
        if (isset($oppStatsInfo['position'])) $data['position'] = $r['position'];
        if (isset($oppStatsInfo['sort_order'])) $data['sort_order'] = (int)$r['sort_order'];
        if (isset($oppStatsInfo['entered'])) $data['entered'] = 1;
        if (isset($oppStatsInfo['player_type'])) $data['player_type'] = $oppType;

        // zera stats se existirem
        foreach (['goals','assists','own_goals','yellow_cards','red_cards','rating','is_mvp'] as $k) {
          if (isset($oppStatsInfo[$k])) $data[$k] = ($k === 'rating') ? 0.0 : 0;
        }

        // timestamps se existirem
        if (isset($oppStatsInfo['created_at'])) $data['created_at'] = $now;
        if (isset($oppStatsInfo['updated_at'])) $data['updated_at'] = $now;

        // last safety: garante NOT NULL sem default
        foreach ($oppStatsInfo as $col => $meta) {
          if ($col === 'id') continue;
          if ((int)$meta['notnull'] === 1 && !array_key_exists($col, $data)) {
            // se tiver default, deixa o DB cuidar
            if ($meta['dflt'] !== null) continue;

            // fallback seguro
            $t = strtoupper((string)$meta['type']);
            if (str_contains($t, 'INT')) $data[$col] = 0;
            else $data[$col] = '';
          }
        }

        $cols = array_keys($data);
        $ph   = array_fill(0, count($cols), '?');
        $pdo->prepare("INSERT INTO opponent_match_player_stats(".implode(',', $cols).") VALUES(".implode(',', $ph).")")
            ->execute(array_values($data));
      }
    }

    $pdo->commit();
    redirect('/?page=match&id='.$matchId);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pm_log('ERROR', 'create_match FAIL: '.$e->getMessage());
    redirect('?page=create_match&err=exception');
  }
}

/* =========================================================
   UI
   ========================================================= */

RENDER_PAGE:

render_header('Cadastrar partida');

// adversário no título
$homeName = fval('home');
$awayName = fval('away');
$oppTitle = 'Adversário';
if ($homeName !== '' && $awayName !== '') {
  if (strcasecmp($homeName, $club) === 0) $oppTitle = $awayName;
  elseif (strcasecmp($awayName, $club) === 0) $oppTitle = $homeName;
}

if ($msg === 'tpl') {
  echo '<div class="alert alert-success card-soft">Template aplicado na escalação do '.h($club).'. Você pode editar antes de salvar.</div>';
}

if ($err === 'invalid') {
  echo '<div class="alert alert-danger card-soft">Preencha os campos obrigatórios.</div>';
} elseif ($err === 'season_mismatch') {
  echo '<div class="alert alert-warning card-soft">A <b>DATA</b> deve estar no mesmo ano da <b>TEMPORADA</b>.</div>';
} elseif ($err === 'palmeiras_only') {
  echo '<div class="alert alert-warning card-soft">Este sistema aceita apenas jogos onde o <b>'.h($club).'</b> participa.</div>';
} elseif ($err === 'roster_required') {
  echo '<div class="alert alert-warning card-soft">Informe ao menos <b>1 atleta do '.h($club).'</b> e <b>1 do adversário</b>.</div>';
} elseif ($err === 'dup_player') {
  echo '<div class="alert alert-warning card-soft">Você selecionou o <b>mesmo jogador do '.h($club).'</b> mais de uma vez.</div>';
} elseif ($err === 'tpl') {
  echo '<div class="alert alert-danger card-soft">Não foi possível aplicar o template.</div>';
} elseif ($err === 'exception') {
  echo '<div class="alert alert-danger card-soft">Falha ao cadastrar. Verifique o log em <code>D:\\Projetos\\palmeiras_manager\\logs\\app.log</code>.</div>';
}

$mvpSelected = postv('mvp');

echo '<form method="post" autocomplete="off">';

echo '<div class="card-soft p-3 mb-3">';
echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<div class="row g-3">';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Temporada</label>
  <select class="form-select" name="season" required>
    <option value=""></option>'.select_options($seasons, fval('season')).'
  </select>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Competição</label>
  <select class="form-select" name="competition" required>
    <option value=""></option>'.select_options($competitions, fval('competition')).'
  </select>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Data</label>
  <input type="date" class="form-control" name="match_date" value="'.h(fval('match_date')).'" required>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Hora</label>
  <input type="time" class="form-control" name="match_time" value="'.h(fval('match_time')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Clima</label>
  <select class="form-select" name="weather">
    <option value=""></option>'.select_options($weathers, fval('weather')).'
  </select>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Fase</label>
  <input class="form-control" name="phase" value="'.h(fval('phase')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Rodada</label>
  <input class="form-control" name="round" value="'.h(fval('round')).'">
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Estádio</label>
  <input class="form-control" name="stadium" value="'.h(fval('stadium')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Árbitro</label>
  <input class="form-control" name="referee" value="'.h(fval('referee')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Uniforme</label>
  <select class="form-select" name="kit_used">
    <option value=""></option>'.select_options($kits, fval('kit_used')).'
  </select>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Mandante</label>
  <input class="form-control" name="home" value="'.h(fval('home')).'" required>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Visitante</label>
  <input class="form-control" name="away" value="'.h(fval('away')).'" required>
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">GF</label>
  <input class="form-control text-center" type="number" name="home_score" value="'.h(fval('home_score')).'">
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">GA</label>
  <input class="form-control text-center" type="number" name="away_score" value="'.h(fval('away_score')).'">
</div>';

echo '</div></div>';

echo '<div class="row g-4">';

// Palmeiras
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($club).'</h5>';

echo '<div class="d-flex flex-wrap gap-2 align-items-end mb-2">';
echo '<div style="min-width:260px;max-width:420px;flex:1;">';
echo '<label class="form-label mb-1">Template de escalação</label>';
echo '<select class="form-select form-select-sm" name="pal_template_id">';
echo '<option value="0">-- selecionar --</option>';
$curTpl = to_int(postv('pal_template_id','0'), 0);
foreach ($templates as $t) {
  $tid = (int)($t['id'] ?? 0);
  $tn  = (string)($t['template_name'] ?? '');
  if ($tid <= 0) continue;
  $sel = ($tid === $curTpl) ? 'selected' : '';
  echo '<option value="'.$tid.'" '.$sel.'>'.h($tn).'</option>';
}
echo '</select></div>';
echo '<button type="submit" name="action" value="apply_template" formnovalidate class="btn btn-outline-light btn-sm">Aplicar</button>';
echo '</div>';

render_table_create(true, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(true, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
echo '</div></div>';

// Adversário
echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-3">'.h($oppTitle).'</h5>';
render_table_create(false, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(false, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
echo '</div></div>';

echo '</div>';

echo '<div class="text-end mt-3">
  <button type="submit" class="btn btn-success">Salvar</button>
</div>';

echo '</form>';

render_footer();
