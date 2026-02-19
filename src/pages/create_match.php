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
   EDIT MODE (pré-preenchimento ao abrir com ?page=create_match&id=XX)
   - Não altera front-end: apenas injeta valores em $_POST/$_GET para o form já existente
   ========================================================= */
$editId = (int)($_GET['id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && $editId > 0) {

  // Partida
  $mrow = q($pdo, "SELECT * FROM matches WHERE id = ? LIMIT 1", [$editId])->fetch(PDO::FETCH_ASSOC);
  if (!$mrow) {
    redirect('/?page=matches&err=not_found');
  }

  // Descobre lado do Palmeiras
  $homeName = (string)($mrow['home'] ?? ($mrow['home_team'] ?? ''));
  $awayName = (string)($mrow['away'] ?? ($mrow['away_team'] ?? ''));
  $isHomePal = (strcasecmp($homeName, $club) === 0);
  $palType   = $isHomePal ? 'HOME' : 'AWAY';
  $oppType   = $isHomePal ? 'AWAY' : 'HOME';
  $oppClub   = $isHomePal ? $awayName : $homeName;

  // Campos simples do form (se existirem na tabela matches)
  foreach ($mrow as $k => $v) {
    $val = ($v === null) ? '' : (string)$v;
    $_GET[$k]  = $val;   // para fval()
    $_POST[$k] = $val;  // para postv()
  }

  // Match id para salvar como UPDATE
  $_POST['match_id'] = (string)$editId;

  // Zera slots
  for ($i=0;$i<11;$i++){
    $_POST["pal_pid_starter_$i"] = $_POST["pal_pid_starter_$i"] ?? '0';
    $_POST["pal_pos_starter_$i"] = $_POST["pal_pos_starter_$i"] ?? '';
    $_POST["opp_name_starter_$i"] = $_POST["opp_name_starter_$i"] ?? '';
    $_POST["opp_pos_starter_$i"]  = $_POST["opp_pos_starter_$i"] ?? '';
  }
  for ($i=0;$i<9;$i++){
    $_POST["pal_pid_bench_$i"] = $_POST["pal_pid_bench_$i"] ?? '0';
    $_POST["pal_pos_bench_$i"] = $_POST["pal_pos_bench_$i"] ?? '';
    $_POST["opp_name_bench_$i"] = $_POST["opp_name_bench_$i"] ?? '';
    $_POST["opp_pos_bench_$i"]  = $_POST["opp_pos_bench_$i"] ?? '';
  }

  // Slots da partida
  $mpr = q($pdo, "
    SELECT role, sort_order, position, player_id, opponent_player_id
    FROM match_players
    WHERE match_id = ?
    ORDER BY role ASC, sort_order ASC
  ", [$editId])->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $needOppNames = [];
  foreach ($mpr as $r) {
    $roleU = strtoupper(trim((string)($r['role'] ?? '')));
    $roleL = ($roleU === 'STARTER') ? 'starter' : (($roleU === 'BENCH') ? 'bench' : '');
    $i = (int)($r['sort_order'] ?? -1);
    if ($roleL === '' || $i < 0) continue;

    $pos = (string)($r['position'] ?? '');

    if (!empty($r['player_id'])) {
      $_POST["pal_pid_{$roleL}_{$i}"] = (string)((int)$r['player_id']);
      $_POST["pal_pos_{$roleL}_{$i}"] = $pos;
    } elseif (!empty($r['opponent_player_id'])) {
      $oid = (int)$r['opponent_player_id'];
      $needOppNames[$oid] = true;
      $_POST["opp_pos_{$roleL}_{$i}"] = $pos;
      $_POST["_tmp_opp_id_{$roleL}_{$i}"] = (string)$oid;
    }
  }

  // Resolve nomes do adversário por ID
  if ($needOppNames) {
    $ids = array_keys($needOppNames);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $opRows = q($pdo, "SELECT id, name FROM opponent_players WHERE id IN ($in)", $ids)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($opRows as $o) $map[(int)$o['id']] = (string)$o['name'];

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $k = "_tmp_opp_id_{$roleL}_{$i}";
        if (!isset($_POST[$k])) continue;
        $oid = (int)$_POST[$k];
        $_POST["opp_name_{$roleL}_{$i}"] = $map[$oid] ?? '';
        unset($_POST[$k]);
      }
    }
  }

  // Stats + MVP (Palmeiras)
  if (table_exists($pdo, 'match_player_stats')) {
    $cols = table_info($pdo, 'match_player_stats');
    $rows = q($pdo, "SELECT * FROM match_player_stats WHERE match_id = ?", [$editId])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byPid = [];
    foreach ($rows as $s) {
      $pid = (int)($s['player_id'] ?? 0);
      if ($pid > 0) $byPid[$pid] = $s;
    }

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $pid = (int)($_POST["pal_pid_{$roleL}_{$i}"] ?? 0);
        if ($pid <= 0) continue;
        $s = $byPid[$pid] ?? null;
        if (!$s) continue;

        $gf = isset($cols['goals_for']) ? (int)($s['goals_for'] ?? 0) : (int)($s['goals'] ?? 0);
        $as = (int)($s['assists'] ?? 0);
        $gc = isset($cols['goals_against']) ? (int)($s['goals_against'] ?? 0) : (isset($cols['own_goals']) ? (int)($s['own_goals'] ?? 0) : 0);
        $yc = isset($cols['yellow_cards']) ? (int)($s['yellow_cards'] ?? 0) : (int)($s['yellow'] ?? 0);
        $rc = isset($cols['red_cards']) ? (int)($s['red_cards'] ?? 0) : (int)($s['red'] ?? 0);
        $rt = (string)($s['rating'] ?? '');
        $mvp = isset($cols['is_mvp']) ? (int)($s['is_mvp'] ?? 0) : (int)($s['motm'] ?? 0);

        $_POST["pal_g_{$roleL}_{$i}"] = (string)$gf;
        $_POST["pal_a_{$roleL}_{$i}"] = (string)$as;
        $_POST["pal_og_{$roleL}_{$i}"] = (string)$gc;
        $_POST["pal_y_{$roleL}_{$i}"] = (string)$yc;
        $_POST["pal_r_{$roleL}_{$i}"] = (string)$rc;
        $_POST["pal_rating_{$roleL}_{$i}"] = $rt;

        if ($mvp === 1) $_POST['mvp'] = "pal_{$roleL}_{$i}";
      }
    }
  }

  // Stats + MVP (Adversário)
  if (table_exists($pdo, 'opponent_match_player_stats')) {
    $cols = table_info($pdo, 'opponent_match_player_stats');
    $rows = q($pdo, "SELECT * FROM opponent_match_player_stats WHERE match_id = ?", [$editId])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byOid = [];
    foreach ($rows as $s) {
      $oid = (int)($s['opponent_player_id'] ?? 0);
      if ($oid > 0) $byOid[$oid] = $s;
    }

    // oid por slot (derivado dos match_players)
    $oidBySlot = ['starter'=>[], 'bench'=>[]];
    foreach ($mpr as $r) {
      if (empty($r['opponent_player_id'])) continue;
      $roleU = strtoupper(trim((string)($r['role'] ?? '')));
      $roleL = ($roleU === 'STARTER') ? 'starter' : (($roleU === 'BENCH') ? 'bench' : '');
      $i = (int)($r['sort_order'] ?? -1);
      if ($roleL === '' || $i < 0) continue;
      $oidBySlot[$roleL][$i] = (int)$r['opponent_player_id'];
    }

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $oid = (int)($oidBySlot[$roleL][$i] ?? 0);
        if ($oid <= 0) continue;
        $s = $byOid[$oid] ?? null;
        if (!$s) continue;

        $gf = isset($cols['goals_for']) ? (int)($s['goals_for'] ?? 0) : (int)($s['goals'] ?? 0);
        $as = (int)($s['assists'] ?? 0);
        $gc = isset($cols['goals_against']) ? (int)($s['goals_against'] ?? 0) : (isset($cols['own_goals']) ? (int)($s['own_goals'] ?? 0) : 0);
        $yc = isset($cols['yellow_cards']) ? (int)($s['yellow_cards'] ?? 0) : (int)($s['yellow'] ?? 0);
        $rc = isset($cols['red_cards']) ? (int)($s['red_cards'] ?? 0) : (int)($s['red'] ?? 0);
        $rt = (string)($s['rating'] ?? '');
        $mvp = isset($cols['is_mvp']) ? (int)($s['is_mvp'] ?? 0) : (int)($s['motm'] ?? 0);

        $_POST["opp_g_{$roleL}_{$i}"] = (string)$gf;
        $_POST["opp_a_{$roleL}_{$i}"] = (string)$as;
        $_POST["opp_og_{$roleL}_{$i}"] = (string)$gc;
        $_POST["opp_y_{$roleL}_{$i}"] = (string)$yc;
        $_POST["opp_r_{$roleL}_{$i}"] = (string)$rc;
        $_POST["opp_rating_{$roleL}_{$i}"] = $rt;

        if ($mvp === 1) $_POST['mvp'] = "opp_{$roleL}_{$i}";
      }
    }
  }

  // Substituições (pal = ids / opp = nomes)
  if (table_exists($pdo, 'match_substitutions')) {
    $subs = q($pdo, "SELECT * FROM match_substitutions WHERE match_id = ? ORDER BY sort_order ASC, id ASC", [$editId])->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Mapa id->nome do adversário
    $oppNameById = [];
    $oppAll = q($pdo, "SELECT id, name FROM opponent_players WHERE club_name=? COLLATE NOCASE", [$oppClub])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($oppAll as $o) $oppNameById[(int)$o['id']] = (string)$o['name'];

    $p = 0; $o = 0;
    foreach ($subs as $s) {
      $side = strtoupper((string)($s['side'] ?? ''));
      $min  = (string)($s['minute'] ?? '');

      if ($side === $palType && $p < 5) {
        $_POST["pal_sub_min_$p"] = $min;
        $_POST["pal_sub_out_$p"] = (string)((int)($s['player_out_id'] ?? 0));
        $_POST["pal_sub_in_$p"]  = (string)((int)($s['player_in_id'] ?? 0));
        $p++;
      } elseif ($side === $oppType && $o < 5) {
        $_POST["opp_sub_min_$o"] = $min;
        $outId = (int)($s['opponent_out_id'] ?? 0);
        $inId  = (int)($s['opponent_in_id'] ?? 0);
        $_POST["opp_sub_out_$o"] = $oppNameById[$outId] ?? '';
        $_POST["opp_sub_in_$o"]  = $oppNameById[$inId] ?? '';
        $o++;
      }
    }
  }

}


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
   Templates (auto-detect schema)
   - lineup_templates / lineup_template_slots
   - templates / template_players
   ========================================================= */

function detect_templates_schema(PDO $pdo): array {
  $tplTable = null;
  if (table_exists($pdo, 'lineup_templates')) $tplTable = 'lineup_templates';
  elseif (table_exists($pdo, 'templates'))    $tplTable = 'templates';

  $slotTable = null;
  if (table_exists($pdo, 'lineup_template_slots')) $slotTable = 'lineup_template_slots';
  elseif (table_exists($pdo, 'template_players'))  $slotTable = 'template_players';

  $nameCol = null;
  $clubCol = null;

  if ($tplTable) {
    $info   = table_info($pdo, $tplTable);
    $nameCol = pick_col($info, ['template_name','name','title']);
    $clubCol = pick_col($info, ['club_name','club']);
  }

  return [
    'tplTable'  => $tplTable,
    'slotTable' => $slotTable,
    'nameCol'   => $nameCol,
    'clubCol'   => $clubCol,
  ];
}

$tplSchema = detect_templates_schema($pdo);

$templates = [];
try {
  if ($tplSchema['tplTable'] && $tplSchema['nameCol']) {
    $tTable = $tplSchema['tplTable'];
    $nCol   = $tplSchema['nameCol'];
    $cCol   = $tplSchema['clubCol'];

    if ($cCol) {
      $templates = q($pdo,
        "SELECT id, $nCol AS template_name FROM $tTable WHERE $cCol=? COLLATE NOCASE ORDER BY $nCol ASC",
        [$club]
      )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $templates = q($pdo,
        "SELECT id, $nCol AS template_name FROM $tTable ORDER BY $nCol ASC"
      )->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
      echo '<option value="0"></option>';
      foreach ($palPlayers as $p) {
        $label2 = trim((string)$p['shirt_number']) !== '' ? '#'.$p['shirt_number'].' - '.$p['name'] : $p['name'];
        $sel = ((int)$p['id'] === $cur) ? ' selected' : '';
        echo '<option value="'.h((string)$p['id']).'"'.$sel.'>'.h($label2).'</option>';
      }
      echo '</select>';
    } else {
      echo '<input class="form-control form-control-sm" name="'.h($pidKey).'" value="'.h(postv($pidKey)).'" placeholder="Nome">';
    }
    echo '</td>';

    echo '<td><input '.$small.' type="number" min="0" name="'.h($gKey).'" value="'.h(postv($gKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($aKey).'" value="'.h(postv($aKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($gcKey).'" value="'.h(postv($gcKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($caKey).'" value="'.h(postv($caKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($cvKey).'" value="'.h(postv($cvKey)).'"></td>';
    echo '<td><input '.$small.' type="text" name="'.h($rtKey).'" value="'.h(postv($rtKey)).'"></td>';

    echo '<td><input type="radio" name="mvp" value="'.h($mvpVal).'" '.$checkedMvp.'></td>';

    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* =========================================================
   Substituições (até 5 por time)
   ========================================================= */

function ensure_match_substitutions_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS match_substitutions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      match_id INTEGER NOT NULL,
      side TEXT NOT NULL,                 -- HOME | AWAY
      minute INTEGER NULL,
      player_out_id INTEGER NULL,
      player_in_id  INTEGER NULL,
      opponent_out_id INTEGER NULL,
      opponent_in_id  INTEGER NULL,
      sort_order INTEGER NOT NULL DEFAULT 0
    )
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_subs_match ON match_substitutions(match_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_subs_side  ON match_substitutions(match_id, side)");
}

function render_subs_block(string $prefix, string $title): void {
  echo '<h6 class="mt-3">'.h($title).'</h6>';
  echo '<div class="table-responsive">';
  echo '<table class="table table-dark table-sm align-middle mb-0">';
  echo '<thead><tr class="text-center">
    <th style="width:90px;">MIN</th>
    <th>SAI</th>
    <th>ENTRA</th>
  </tr></thead><tbody>';

  for ($i=0; $i<5; $i++) {
    $kMin = $prefix.'_sub_min_'.$i;
    $kOut = $prefix.'_sub_out_'.$i;
    $kIn  = $prefix.'_sub_in_'.$i;

    echo '<tr class="text-center">';
    echo '<td><input type="number" min="0" max="130" step="1" class="form-control form-control-sm text-center px-1" style="max-width:90px;margin:0 auto;" name="'.h($kMin).'" value="'.h(postv($kMin)).'"></td>';

    // opções preenchidas via JS (sem quebrar padrão visual)
    echo '<td><select class="form-select form-select-sm w-100 sub-out" data-prefix="'.h($prefix).'" data-selected="'.h(postv($kOut)).'" name="'.h($kOut).'"><option value=""></option></select></td>';
    echo '<td><select class="form-select form-select-sm w-100 sub-in"  data-prefix="'.h($prefix).'" data-selected="'.h(postv($kIn)).'" name="'.h($kIn).'"><option value=""></option></select></td>';
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

      $tplSchema = detect_templates_schema($pdo);
      if (!$tplSchema['slotTable']) { redirect('?page=create_match&err=tpl_slots'); }
      $slotTable = $tplSchema['slotTable'];

      $rows = q($pdo, "
        SELECT role, sort_order, player_id, position
        FROM $slotTable
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

  $home        = postv('home');
  $away        = postv('away');

  $home_score_raw = postv('home_score');
  $away_score_raw = postv('away_score');

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

    $g  = to_int(postv("pal_g_starter_$i"), 0);
    $a  = to_int(postv("pal_a_starter_$i"), 0);
    $gc = to_int(postv("pal_og_starter_$i"), 0); // GC = gols contra (own goals)
    $ca = to_int(postv("pal_y_starter_$i"), 0);
    $cv = to_int(postv("pal_r_starter_$i"), 0);
    $rt = to_float(postv("pal_rating_starter_$i"), 0.0);

    if ($pid>0 && $pos!=='') {
      $palRows[] = [
        'role'=>'STARTER','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="pal_starter_$i")?1:0
      ];
    }
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $pid = to_int(postv("pal_pid_bench_$i"), 0);
    $pos = postv("pal_pos_bench_$i");

    $g  = to_int(postv("pal_g_bench_$i"), 0);
    $a  = to_int(postv("pal_a_bench_$i"), 0);
    $gc = to_int(postv("pal_og_bench_$i"), 0); // GC = gols contra (own goals)
    $ca = to_int(postv("pal_y_bench_$i"), 0);
    $cv = to_int(postv("pal_r_bench_$i"), 0);
    $rt = to_float(postv("pal_rating_bench_$i"), 0.0);

    if ($pid>0 && $pos!=='') {
      $palRows[] = [
        'role'=>'BENCH','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="pal_bench_$i")?1:0
      ];
    }
  }

  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $name = postv("opp_name_starter_$i");
    $pos  = postv("opp_pos_starter_$i");

    $g  = to_int(postv("opp_g_starter_$i"), 0);
    $a  = to_int(postv("opp_a_starter_$i"), 0);
    $gc = to_int(postv("opp_og_starter_$i"), 0); // GC = gols contra (own goals)
    $ca = to_int(postv("opp_y_starter_$i"), 0);
    $cv = to_int(postv("opp_r_starter_$i"), 0);
    $rt = to_float(postv("opp_rating_starter_$i"), 0.0);

    if ($name!=='' && $pos!=='') {
      $oppRows[] = [
        'role'=>'STARTER','sort_order'=>$i,'name'=>$name,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="opp_starter_$i")?1:0
      ];
    }
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $name = postv("opp_name_bench_$i");
    $pos  = postv("opp_pos_bench_$i");

    $g  = to_int(postv("opp_g_bench_$i"), 0);
    $a  = to_int(postv("opp_a_bench_$i"), 0);
    $gc = to_int(postv("opp_og_bench_$i"), 0); // GC = gols contra (own goals)
    $ca = to_int(postv("opp_y_bench_$i"), 0);
    $cv = to_int(postv("opp_r_bench_$i"), 0);
    $rt = to_float(postv("opp_rating_bench_$i"), 0.0);

    if ($name!=='' && $pos!=='') {
      $oppRows[] = [
        'role'=>'BENCH','sort_order'=>$i,'name'=>$name,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="opp_bench_$i")?1:0
      ];
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

  // Substituições (até 5 por time)
  $subsPal = [];
  $subsOpp = [];

  // Palmeiras: OUT apenas titulares, IN apenas reservas
  $palStarters = [];
  $palBench    = [];
  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $pid = to_int(postv("pal_pid_starter_$i"), 0);
    if ($pid > 0) $palStarters[$pid] = true;
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $pid = to_int(postv("pal_pid_bench_$i"), 0);
    if ($pid > 0) $palBench[$pid] = true;
  }

  for ($i=0; $i<5; $i++) {
    $minRaw = trim(postv("pal_sub_min_$i"));
    $outId  = to_int(postv("pal_sub_out_$i"), 0);
    $inId   = to_int(postv("pal_sub_in_$i"), 0);

    if ($minRaw === '' && $outId === 0 && $inId === 0) continue; // linha vazia
    if ($outId === 0 || $inId === 0) redirect('?page=create_match&err=subs_incomplete');
    if ($outId === $inId) redirect('?page=create_match&err=subs_same');

    if (!isset($palStarters[$outId])) redirect('?page=create_match&err=subs_out_not_starter');
    if (!isset($palBench[$inId]))     redirect('?page=create_match&err=subs_in_not_bench');

    $subsPal[] = [
      'minute' => ($minRaw === '' ? null : (int)$minRaw),
      'out'    => $outId,
      'in'     => $inId,
      'sort'   => $i,
    ];
  }

  // Adversário: OUT apenas titulares (nome), IN apenas reservas (nome)
  $oppStarters = [];
  $oppBench    = [];
  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $nm = trim(postv("opp_name_starter_$i"));
    if ($nm !== '') $oppStarters[strtolower($nm)] = $nm;
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $nm = trim(postv("opp_name_bench_$i"));
    if ($nm !== '') $oppBench[strtolower($nm)] = $nm;
  }

  for ($i=0; $i<5; $i++) {
    $minRaw = trim(postv("opp_sub_min_$i"));
    $outNm  = trim(postv("opp_sub_out_$i"));
    $inNm   = trim(postv("opp_sub_in_$i"));

    if ($minRaw === '' && $outNm === '' && $inNm === '') continue;
    if ($outNm === '' || $inNm === '') redirect('?page=create_match&err=subs_incomplete');
    if (strcasecmp($outNm, $inNm) === 0) redirect('?page=create_match&err=subs_same');

    $kOut = strtolower($outNm);
    $kIn  = strtolower($inNm);
    if (!isset($oppStarters[$kOut])) redirect('?page=create_match&err=subs_out_not_starter');
    if (!isset($oppBench[$kIn]))     redirect('?page=create_match&err=subs_in_not_bench');

    $subsOpp[] = [
      'minute' => ($minRaw === '' ? null : (int)$minRaw),
      'out_name' => $oppStarters[$kOut],
      'in_name'  => $oppBench[$kIn],
      'sort'   => $i,
    ];
  }

  // Schema tables
  $matchesInfo = table_info($pdo, 'matches');
  $matchPlayersInfo = table_info($pdo, 'match_players');
  $oppPlayersInfo   = table_info($pdo, 'opponent_players');

  $hasPalStats      = table_exists($pdo, 'match_player_stats');
  $palStatsInfo     = $hasPalStats ? table_info($pdo, 'match_player_stats') : [];

  $hasOppStats      = table_exists($pdo, 'opponent_match_player_stats');
  $oppStatsInfo     = $hasOppStats ? table_info($pdo, 'opponent_match_player_stats') : [];

  // matches: home/away ou fallback home_team/away_team
  $colHome = pick_col($matchesInfo, ['home','home_team']);
  $colAway = pick_col($matchesInfo, ['away','away_team']);
  $colHomeScore = pick_col($matchesInfo, ['home_score','home_goals']);
  $colAwayScore = pick_col($matchesInfo, ['away_score','away_goals']);

  if (!$colHome || !$colAway) redirect('?page=create_match&err=exception');

  try {
    $pdo->beginTransaction();

    // Monta matchData com o que existir no schema (mantém compatibilidade)
    $matchData = [];

    if (isset($matchesInfo['season'])) $matchData['season'] = $season;
    if (isset($matchesInfo['competition'])) $matchData['competition'] = $competition;

    if (isset($matchesInfo['match_date'])) $matchData['match_date'] = $date;
    elseif (isset($matchesInfo['date'])) $matchData['date'] = $date;

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

    // Modo edição: se vier match_id, faz UPDATE e regrava dependências
    $editMatchId = to_int(postv('match_id'), 0);
    $isEdit = ($editMatchId > 0);

    if ($isEdit) {
      // created_at não deve ser alterado ao editar
      if (isset($matchesInfo['updated_at'])) $matchData['updated_at'] = $now;

      $mCols = array_keys($matchData);
      $set = implode(',', array_map(fn($c) => $c.'=?', $mCols));
      q($pdo, "UPDATE matches SET $set WHERE id = ?", array_merge(array_values($matchData), [$editMatchId]));
      $matchId = $editMatchId;

      // remove dados antigos para regravar exatamente o que está no formulário
      q($pdo, "DELETE FROM match_players WHERE match_id = ?", [$matchId]);
      if (table_exists($pdo, 'match_player_stats')) q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?", [$matchId]);
      if (table_exists($pdo, 'opponent_match_player_stats')) q($pdo, "DELETE FROM opponent_match_player_stats WHERE match_id = ?", [$matchId]);
      if (table_exists($pdo, 'match_substitutions')) q($pdo, "DELETE FROM match_substitutions WHERE match_id = ?", [$matchId]);

    } else {
      if (isset($matchesInfo['created_at'])) $matchData['created_at'] = $now;
      if (isset($matchesInfo['updated_at'])) $matchData['updated_at'] = $now;

      $mCols = array_keys($matchData);
      $mPh   = array_fill(0, count($mCols), '?');
      q($pdo, "INSERT INTO matches(".implode(',', $mCols).") VALUES(".implode(',', $mPh).")", array_values($matchData));
      $matchId = (int)$pdo->lastInsertId();
    }


    // Prepared statements
    $insMatchPlayers = $pdo->prepare("
      INSERT INTO match_players(match_id, club_name, player_id, opponent_player_id, role, position, sort_order, entered, player_type)
      VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $selOpp = $pdo->prepare("SELECT id FROM opponent_players WHERE club_name=? COLLATE NOCASE AND name=? COLLATE NOCASE LIMIT 1");
    $insOpp = $pdo->prepare("INSERT INTO opponent_players(club_name, name, is_active, primary_position) VALUES(?, ?, 1, ?)");

    // Palmeiras roster
    foreach ($palRows as $r) {
      $insMatchPlayers->execute([$matchId, $club, (int)$r['player_id'], null, $r['role'], $r['position'], (int)$r['sort_order'], ($r['role']==='STARTER'?1:0), $palType]);

      // match_player_stats (criar linha default)
      if ($hasPalStats && isset($palStatsInfo['match_id']) && isset($palStatsInfo['player_id'])) {

        $data = [
          'match_id'  => $matchId,
          'player_id' => (int)$r['player_id'],
        ];

        if (isset($palStatsInfo['club_name'])) $data['club_name'] = $club;

        // valores vindos do formulário (GC = gols contra do jogador)
        $gf = (int)($r['goals_for'] ?? 0);
        $ga = (int)($r['goals_against'] ?? 0);
        $as = (int)($r['assists'] ?? 0);
        $yc = (int)($r['yellow_cards'] ?? 0);
        $rc = (int)($r['red_cards'] ?? 0);
        $rt = (float)($r['rating'] ?? 0.0);
        $motm = (int)($r['is_mvp'] ?? 0);

        if (isset($palStatsInfo['goals_for']))     $data['goals_for'] = $gf;
        if (isset($palStatsInfo['goals_against'])) $data['goals_against'] = $ga;
        if (isset($palStatsInfo['assists']))       $data['assists'] = $as;
        if (isset($palStatsInfo['yellow_cards']))  $data['yellow_cards'] = $yc;
        if (isset($palStatsInfo['red_cards']))     $data['red_cards'] = $rc;
        if (isset($palStatsInfo['rating']))        $data['rating'] = $rt;   // NOT NULL em alguns schemas
        if (isset($palStatsInfo['motm']))          $data['motm'] = $motm;

        // preencher NOT NULL sem default (fallback seguro)
        foreach ($palStatsInfo as $col => $meta) {
          if ($col === 'id') continue;
          if ((int)$meta['notnull'] === 1 && !array_key_exists($col, $data)) {
            if ($meta['dflt'] !== null) continue;
            $t = strtoupper((string)$meta['type']);
            if (str_contains($t, 'INT')) $data[$col] = 0;
            else $data[$col] = '';
          }
        }

        $cols = array_keys($data);
        $ph   = array_fill(0, count($cols), '?');
        $pdo->prepare("INSERT INTO match_player_stats(".implode(',', $cols).") VALUES(".implode(',', $ph).")")
            ->execute(array_values($data));
      }
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

      $insMatchPlayers->execute([$matchId, $oppClub, null, $oppId, $r['role'], $r['position'], (int)$r['sort_order'], ($r['role']==='STARTER'?1:0), $oppType]);

      // opponent_match_player_stats (corrigido: club_name NOT NULL)
      if ($hasOppStats && isset($oppStatsInfo['match_id']) && isset($oppStatsInfo['opponent_player_id'])) {

        $data = [
          'match_id' => $matchId,
          'opponent_player_id' => $oppId,
        ];

        // ✅ seu erro: club_name é NOT NULL
        if (isset($oppStatsInfo['club_name'])) $data['club_name'] = $oppClub;

        // valores vindos do formulário (GC = gols contra do jogador)
        $gf = (int)($r['goals_for'] ?? 0);
        $ga = (int)($r['goals_against'] ?? 0);
        $as = (int)($r['assists'] ?? 0);
        $yc = (int)($r['yellow_cards'] ?? 0);
        $rc = (int)($r['red_cards'] ?? 0);
        $rt = (float)($r['rating'] ?? 0.0);
        $motm = (int)($r['is_mvp'] ?? 0);

        if (isset($oppStatsInfo['goals_for']))     $data['goals_for'] = $gf;
        if (isset($oppStatsInfo['goals_against'])) $data['goals_against'] = $ga;
        if (isset($oppStatsInfo['assists']))       $data['assists'] = $as;
        if (isset($oppStatsInfo['yellow_cards']))  $data['yellow_cards'] = $yc;
        if (isset($oppStatsInfo['red_cards']))     $data['red_cards'] = $rc;
        if (isset($oppStatsInfo['rating']))        $data['rating'] = $rt;   // NOT NULL em alguns schemas
        if (isset($oppStatsInfo['motm']))          $data['motm'] = $motm;

        // campos comuns que podem existir / ser NOT NULL
        if (isset($oppStatsInfo['role'])) $data['role'] = $r['role'];
        if (isset($oppStatsInfo['position'])) $data['position'] = $r['position'];
        if (isset($oppStatsInfo['sort_order'])) $data['sort_order'] = (int)$r['sort_order'];
        if (isset($oppStatsInfo['entered'])) $data['entered'] = 1;

        // preencher NOT NULL sem default (fallback seguro)
        foreach ($oppStatsInfo as $col => $meta) {
          if ($col === 'id') continue;
          if ((int)$meta['notnull'] === 1 && !array_key_exists($col, $data)) {
            if ($meta['dflt'] !== null) continue;
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

    // Substituições (persistência)
    ensure_match_substitutions_table($pdo);
    q($pdo, "DELETE FROM match_substitutions WHERE match_id=?", [$matchId]);

    $insSub = $pdo->prepare("
      INSERT INTO match_substitutions(
        match_id, side, minute,
        player_out_id, player_in_id,
        opponent_out_id, opponent_in_id,
        sort_order
      ) VALUES(?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Palmeiras (ids)
    foreach ($subsPal as $s) {
      $insSub->execute([
        $matchId,
        $palType,
        $s['minute'],
        (int)$s['out'],
        (int)$s['in'],
        null,
        null,
        (int)$s['sort'],
      ]);
    }

    // Mapa nome->id do adversário (já criado/garantido acima)
    $oppIdByName = [];
    $oppRowsAll = q($pdo, "SELECT id, name FROM opponent_players WHERE club_name=? COLLATE NOCASE", [$oppClub])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($oppRowsAll as $o) {
      $nm = strtolower(trim((string)($o['name'] ?? '')));
      if ($nm !== '') $oppIdByName[$nm] = (int)($o['id'] ?? 0);
    }

    foreach ($subsOpp as $s) {
      $outKey = strtolower(trim((string)$s['out_name']));
      $inKey  = strtolower(trim((string)$s['in_name']));
      $outId  = (int)($oppIdByName[$outKey] ?? 0);
      $inId   = (int)($oppIdByName[$inKey] ?? 0);

      if ($outId <= 0 || $inId <= 0) {
        // fallback: tenta resolver na hora
        $selOpp->execute([$oppClub, (string)$s['out_name']]);
        $outId = (int)($selOpp->fetchColumn() ?: 0);
        $selOpp->execute([$oppClub, (string)$s['in_name']]);
        $inId  = (int)($selOpp->fetchColumn() ?: 0);
      }

      $insSub->execute([
        $matchId,
        $oppType,
        $s['minute'],
        null,
        null,
        $outId ?: null,
        $inId ?: null,
        (int)$s['sort'],
      ]);
    }

    $pdo->commit();
    redirect('/?page=match&id='.$matchId);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pm_log('ERROR', 'create_match FAIL: '.$e->getMessage());
    redirect('?page=create_match&err=exception');
  }
}

RENDER_PAGE:

render_header('Criar Partida');

if ($err !== '') {
  echo '<div class="alert alert-danger card-soft">'.h($err).'</div>';
}

if ($msg === 'tpl') {
  echo '<div class="alert alert-success card-soft">Template aplicado.</div>';
}

$mvpSelected = postv('mvp');

echo '<form method="post" autocomplete="off">';
echo '<input type="hidden" name="match_id" value="'.h((string)((postv('match_id') !== '') ? postv('match_id') : (string)($_GET['id'] ?? ''))).'">';

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
  <input class="form-control" name="home" value="'.h(fval('home',$club)).'" required>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Visitante</label>
  <input class="form-control" name="away" value="'.h(fval('away','ADVERSÁRIO')).'" required>
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
render_subs_block('pal', 'Substituições (até 5)');

echo '</div></div>';

// Adversário
$homeVal = fval('home',$club);
$awayVal = fval('away','ADVERSÁRIO');
$oppTitle = (strcasecmp($homeVal, $club) === 0) ? $awayVal : $homeVal;

echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-3">'.h($oppTitle).'</h5>';
render_table_create(false, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(false, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
render_subs_block('opp', 'Substituições (até 5)');

echo '</div></div>';

echo '</div>';

echo '<div class="text-end mt-3">
  <button type="submit" class="btn btn-success">Salvar</button>
</div>';

echo '</form>';

// Preenche as listas de substituições com base nos titulares/reservas (sem alterar o padrão visual)
echo '<script>
(function(){
  function clearAndKeepEmpty(sel){
    sel.innerHTML = "";
    var o = document.createElement("option");
    o.value = "";
    o.textContent = "";
    sel.appendChild(o);
  }

  function setOptions(sel, options){
    var cur = sel.getAttribute("data-selected") || sel.value || "";
    clearAndKeepEmpty(sel);
    options.forEach(function(x){
      var o = document.createElement("option");
      o.value = x.value;
      o.textContent = x.label;
      sel.appendChild(o);
    });

    if(cur){
      sel.value = cur;
      if(!sel.value){
        var curL = String(cur).toLowerCase();
        for (var i=0;i<sel.options.length;i++){
          if (String(sel.options[i].value).toLowerCase() === curL) { sel.value = sel.options[i].value; break; }
        }
      }
    }
  }

  function uniqByKey(list, keyFn){
    var seen = {};
    var out = [];
    list.forEach(function(x){
      var k = keyFn(x);
      if(seen[k]) return;
      seen[k] = true;
      out.push(x);
    });
    return out;
  }

  function buildFromSelect(names){
    var out = [];
    names.forEach(function(n){
      var s = document.querySelector("select[name=\\"" + n + "\\"]");
      if(!s) return;
      var v = s.value;
      if(!v || v === "0") return;
      var label = (s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : "").trim();
      if(!label) return;
      out.push({value: v, label: label});
    });
    return uniqByKey(out, function(x){ return String(x.value); });
  }

  function buildFromInputs(names){
    var out = [];
    names.forEach(function(n){
      var i = document.querySelector("input[name=\\"" + n + "\\"]");
      if(!i) return;
      var v = (i.value || "").trim();
      if(!v) return;
      out.push({value: v, label: v});
    });
    return uniqByKey(out, function(x){ return String(x.value).toLowerCase(); });
  }

  function refresh(prefix){
    var outOpts = [];
    var inOpts  = [];

    if(prefix === "pal"){
      var starters = [];
      var bench = [];
      for (var i=0;i<11;i++) starters.push("pal_pid_starter_" + i);
      for (var j=0;j<9;j++)  bench.push("pal_pid_bench_" + j);
      outOpts = buildFromSelect(starters);
      inOpts  = buildFromSelect(bench);
    } else {
      var starters2 = [];
      var bench2 = [];
      for (var i2=0;i2<11;i2++) starters2.push("opp_name_starter_" + i2);
      for (var j2=0;j2<9;j2++)  bench2.push("opp_name_bench_" + j2);
      outOpts = buildFromInputs(starters2);
      inOpts  = buildFromInputs(bench2);
    }

    document.querySelectorAll("select.sub-out[data-prefix=\\"" + prefix + "\\"]").forEach(function(sel){
      setOptions(sel, outOpts);
    });
    document.querySelectorAll("select.sub-in[data-prefix=\\"" + prefix + "\\"]").forEach(function(sel){
      setOptions(sel, inOpts);
    });
  }

  function bind(){
    // Palmeiras selects
    for (var i=0;i<11;i++){
      var s = document.querySelector("select[name=\\"pal_pid_starter_" + i + "\\"]");
      if(s) s.addEventListener("change", function(){ refresh("pal"); });
    }
    for (var j=0;j<9;j++){
      var b = document.querySelector("select[name=\\"pal_pid_bench_" + j + "\\"]");
      if(b) b.addEventListener("change", function(){ refresh("pal"); });
    }

    // Adversário inputs
    for (var i2=0;i2<11;i2++){
      var is = document.querySelector("input[name=\\"opp_name_starter_" + i2 + "\\"]");
      if(is) is.addEventListener("input", function(){ refresh("opp"); });
    }
    for (var j2=0;j2<9;j2++){
      var ib = document.querySelector("input[name=\\"opp_name_bench_" + j2 + "\\"]");
      if(ib) ib.addEventListener("input", function(){ refresh("opp"); });
    }
  }

  refresh("pal");
  refresh("opp");
  bind();
})();
</script>';

render_footer();
