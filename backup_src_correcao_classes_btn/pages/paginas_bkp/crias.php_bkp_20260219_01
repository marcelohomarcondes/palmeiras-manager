<?php
declare(strict_types=1);

$pdo = db();

/**
 * Opções fixas de posição
 */
$POSITIONS = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

/**
 * Ordem customizada para ordenar por POS (GOL, ALE, LE, ZAG, LD, ALD, VOL, ME, MC, MD, MEI, PE, PD, SA, ATA)
 */
$PRIMARY_POS_ORDER_CASE = "CASE UPPER(primary_position) WHEN 'GOL' THEN 1 WHEN 'ALE' THEN 2 WHEN 'LE' THEN 3 WHEN 'ZAG' THEN 4 WHEN 'LD' THEN 5 WHEN 'ALD' THEN 6 WHEN 'VOL' THEN 7 WHEN 'ME' THEN 8 WHEN 'MC' THEN 9 WHEN 'MD' THEN 10 WHEN 'MEI' THEN 11 WHEN 'PE' THEN 12 WHEN 'PD' THEN 13 WHEN 'SA' THEN 14 WHEN 'ATA' THEN 15 ELSE 999 END";

function parse_positions(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  $raw = str_replace(';', ',', $raw);
  $parts = array_map('trim', explode(',', $raw));
  $parts = array_values(array_unique(array_filter($parts, fn($v) => $v !== '')));
  return $parts;
}

/**
 * Tabelas (se não existirem)
 * NOTE: mantive shirt_number no CREATE por compatibilidade com quem já criou antes,
 * mas a página não usa mais número na BASE.
 */
q($pdo, "
  CREATE TABLE IF NOT EXISTS academy_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    primary_position TEXT NOT NULL,
    secondary_positions TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    club_name TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
  )
");

q($pdo, "
  CREATE TABLE IF NOT EXISTS academy_dismissed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    club_name TEXT NOT NULL,
    dismissed_at TEXT DEFAULT (datetime('now'))
  )
");

$err = '';
$edit = null;

/**
 * Edit (GET)
 */
if (isset($_GET['edit'])) {
  $edit = q($pdo, "SELECT * FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [(int)$_GET['edit'], app_club()])->fetch() ?: null;
}

/**
 * Delete (GET) - remove da base definitivamente
 */
if (isset($_GET['del'])) {
  q($pdo, "DELETE FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [(int)$_GET['del'], app_club()]);
  redirect('/?page=crias');
}

/**
 * Dispensar (GET) - move para lista de dispensados (só nome)
 */
if (isset($_GET['dismiss'])) {
  $id = (int)$_GET['dismiss'];
  $r = q($pdo, "SELECT * FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [$id, app_club()])->fetch();
  if ($r) {
    q($pdo, "INSERT INTO academy_dismissed(name, club_name) VALUES(?,?)", [(string)$r['name'], app_club()]);
    q($pdo, "DELETE FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [$id, app_club()]);
  }
  redirect('/?page=crias');
}

/**
 * PROMOVER (POST - via modal)
 * - Nome vem preenchido
 * - Usuário preenche: número (agora SIM), posição primária, secundárias
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_submit'])) {
  $academyId = (int)($_POST['academy_id'] ?? 0);

  $name = trim((string)($_POST['promote_name'] ?? ''));
  $shirt = ($_POST['promote_shirt_number'] ?? '') === '' ? null : (int)$_POST['promote_shirt_number'];
  $prim = trim((string)($_POST['promote_primary_position'] ?? ''));

  $secArr = $_POST['promote_secondary_positions'] ?? [];
  if (!is_array($secArr)) $secArr = [];
  $secArr = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $secArr), fn($v) => $v !== '')));
  $secArr = array_values(array_filter($secArr, fn($v) => in_array($v, $POSITIONS, true)));
  $sec = implode(',', $secArr);

  if ($academyId <= 0) {
    $err = 'Atleta inválido para promoção.';
  } elseif ($name === '') {
    $err = 'Informe o nome do atleta.';
  } elseif ($shirt === null) {
    $err = 'Informe o número de camisa para o elenco profissional.';
  } elseif (!in_array($prim, $POSITIONS, true)) {
    $err = 'Selecione uma posição primária válida.';
  }

  if ($err === '') {
    $r = q($pdo, "SELECT * FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [$academyId, app_club()])->fetch();
    if (!$r) {
      $err = 'Atleta não encontrado na base.';
    } else {
      // 1) Insere no elenco profissional (players)
      q($pdo, "INSERT INTO players(name, shirt_number, primary_position, secondary_positions, is_active, club_name)
               VALUES (?,?,?,?,?,?)",
        [$name, $shirt, $prim, $sec, 1, app_club()]
      );

      // 2) Remove da base
      q($pdo, "DELETE FROM academy_players WHERE id=? AND club_name = ? COLLATE NOCASE", [$academyId, app_club()]);

      // 3) Tenta registrar em Transferências como "PROMOVIDO DA BASE" (não quebra se schema diferente)
      try {
        q($pdo, "INSERT INTO transfers(player_name, transfer_type, club_name, created_at)
                 VALUES (?,?,?,datetime('now'))",
          [$name, 'PROMOVIDO DA BASE', app_club()]
        );
      } catch (\Throwable $e) {}

      redirect('/?page=crias');
    }
  }
}

/**
 * Save (POST) - Cadastro/edição na BASE (SEM número)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['promote_submit'])) {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));
  $prim = trim((string)($_POST['primary_position'] ?? ''));

  $secArr = $_POST['secondary_positions'] ?? [];
  if (!is_array($secArr)) $secArr = [];
  $secArr = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $secArr), fn($v) => $v !== '')));
  $secArr = array_values(array_filter($secArr, fn($v) => in_array($v, $POSITIONS, true)));
  $sec = implode(',', $secArr);

  $active = isset($_POST['is_active']) ? 1 : 0;

  if ($name === '') {
    $err = 'Informe o nome do atleta.';
  } elseif (!in_array($prim, $POSITIONS, true)) {
    $err = 'Selecione uma posição primária válida.';
  }

  if ($err !== '') {
    $edit = [
      'id' => $id,
      'name' => $name,
      'primary_position' => $prim,
      'secondary_positions' => $sec,
      'is_active' => $active
    ];
  } else {
    if ($id > 0) {
      q($pdo, "UPDATE academy_players
              SET name=?, primary_position=?, secondary_positions=?, is_active=?, updated_at=datetime('now')
              WHERE id=? AND club_name = ? COLLATE NOCASE",
        [$name, $prim, $sec, $active, $id, app_club()]
      );
    } else {
      q($pdo, "INSERT INTO academy_players(name, primary_position, secondary_positions, is_active, club_name)
              VALUES (?,?,?,?,?)",
        [$name, $prim, $sec, $active, app_club()]
      );
    }
    redirect('/?page=crias');
  }
}

/**
 * SORT POR COLUNA (sem coluna de número agora)
 */
$allowedDirs = ['ASC', 'DESC'];
$sortKey = null;
$sortDir = null;

$sortSelectMap = [
  'sort_name'      => 'name',
  'sort_primary'   => 'primary',
  'sort_secondary' => 'secondary',
  'sort_status'    => 'status',
];

foreach ($sortSelectMap as $param => $key) {
  if (isset($_GET[$param])) {
    $v = strtoupper(trim((string)$_GET[$param]));
    if (in_array($v, $allowedDirs, true)) {
      $sortKey = $key;
      $sortDir = $v;
      break;
    }
  }
}

#$defaultOrderBy = "is_active DESC, primary_position ASC, name ASC";
$defaultOrderBy = "is_active DESC, {$PRIMARY_POS_ORDER_CASE} ASC, name ASC";

$orderBy = $defaultOrderBy;

if ($sortKey !== null && $sortDir !== null) {
  if ($sortKey === 'name') {
    $orderBy = "name {$sortDir}";
  } elseif ($sortKey === 'primary') {
    $orderBy = "{$PRIMARY_POS_ORDER_CASE} {$sortDir}, name ASC";
  } elseif ($sortKey === 'secondary') {
    $orderBy = "secondary_positions {$sortDir}, name ASC";
  } elseif ($sortKey === 'status') {
    $orderBy = "is_active {$sortDir}, name ASC";
  }
}

$rows = q($pdo, "
  SELECT *
  FROM academy_players
  WHERE club_name = ? COLLATE NOCASE
  ORDER BY {$orderBy}
", [app_club()])->fetchAll();

$dismissed = q($pdo, "
  SELECT name
  FROM academy_dismissed
  WHERE club_name = ? COLLATE NOCASE
  ORDER BY dismissed_at DESC, name ASC
", [app_club()])->fetchAll();

render_header('CRIAS DA ACADEMIA');

echo '<div class="row g-3">';

/**
 * COLUNA ESQUERDA: Form de novo/editar + Dispensados
 */
echo '<div class="col-lg-4"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">' . ($edit ? 'Editar atleta' : 'Novo atleta') . '</div>';

if ($err !== '') {
  echo '<div class="alert alert-danger py-2 mb-2">' . h($err) . '</div>';
}

echo '<form method="post" class="vstack gap-2">';
if ($edit && isset($edit['id']) && (int)$edit['id'] > 0) {
  echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';
}

echo '<div><label class="form-label">Nome</label><input class="form-control" name="name" required value="' . h($edit['name'] ?? '') . '"></div>';

$primVal = (string)($edit['primary_position'] ?? '');
echo '<div>';
echo '<label class="form-label">Posição primária</label>';
echo '<select class="form-select" name="primary_position" required>';
echo '<option value="">-- selecione --</option>';
foreach ($POSITIONS as $p) {
  $sel = ($p === $primVal) ? ' selected' : '';
  echo '<option value="' . h($p) . '"' . $sel . '>' . h($p) . '</option>';
}
echo '</select>';
echo '</div>';

$secSelected = parse_positions((string)($edit['secondary_positions'] ?? ''));

echo '<div>';
echo '<label class="form-label">Posições secundárias</label>';
echo '<div class="card-soft p-2" style="box-shadow:none;">';
echo '<div class="row g-2">';
foreach ($POSITIONS as $p) {
  $checked = in_array($p, $secSelected, true) ? ' checked' : '';
  $idcb = 'sec_' . $p;
  echo '<div class="col-6">';
  echo '<div class="form-check">';
  echo '<input class="form-check-input" type="checkbox" name="secondary_positions[]" id="' . h($idcb) . '" value="' . h($p) . '"' . $checked . '>';
  echo '<label class="form-check-label" for="' . h($idcb) . '">' . h($p) . '</label>';
  echo '</div>';
  echo '</div>';
}
echo '</div>';
echo '</div>';
echo '<div class="form-text">Marque 0 ou mais posições secundárias.</div>';
echo '</div>';

$checked = (!$edit || (int)($edit['is_active'] ?? 1) === 1) ? 'checked' : '';
echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="act" ' . $checked . '><label class="form-check-label" for="act">Ativo na base</label></div>';

echo '<button class="btn btn-success">Salvar</button>';
if ($edit) echo '<a class="btn btn-outline-secondary" href="/?page=crias">Cancelar</a>';
echo '</form>';

echo '<div class="text-muted small mt-3">Dica: ao promover, o atleta sai da base e entra no elenco profissional.</div>';

// Dispensados
echo '<hr class="my-3">';
echo '<div class="fw-bold mb-2">Dispensados da Base</div>';

if (!$dismissed) {
  echo '<div class="text-muted small">Nenhum atleta dispensado.</div>';
} else {
  echo '<div class="vstack gap-1">';
  foreach ($dismissed as $d) {
    echo '<div class="small">• ' . h((string)$d['name']) . '</div>';
  }
  echo '</div>';
}

echo '</div></div>';

/**
 * COLUNA DIREITA: Lista (sem coluna # agora) + botões
 */
echo '<div class="col-lg-8"><div class="card card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-bold">Lista</div>';
echo '<div class="text-muted small">Total: ' . count($rows) . '</div></div>';

echo '<div class="table-responsive">';
echo '<form method="get" id="sortFormAcademy">';
echo '<input type="hidden" name="page" value="crias">';

echo '<table class="table table-sm align-middle mb-0">';
echo '<thead><tr>';

function sortSelect(string $name, ?string $activeKey, ?string $activeDir, string $thisKey): string {
  $isActive = ($activeKey === $thisKey) ? ($activeDir ?? '') : '';
  $selNone = ($isActive === '') ? ' selected' : '';
  $selAsc  = ($isActive === 'ASC') ? ' selected' : '';
  $selDesc = ($isActive === 'DESC') ? ' selected' : '';
  return '<select class="form-select form-select-sm js-sortcol" name="' . h($name) . '" data-key="' . h($thisKey) . '">' .
           '<option value=""' . $selNone . '>—</option>' .
           '<option value="ASC"' . $selAsc . '>↑</option>' .
           '<option value="DESC"' . $selDesc . '>↓</option>' .
         '</select>';
}

echo '<th>Atleta' . sortSelect('sort_name', $sortKey, $sortDir, 'name') . '</th>';
echo '<th>Posição' . sortSelect('sort_primary', $sortKey, $sortDir, 'primary') . '</th>';
echo '<th>Pos. Sec.' . sortSelect('sort_secondary', $sortKey, $sortDir, 'secondary') . '</th>';
echo '<th>Status' . sortSelect('sort_status', $sortKey, $sortDir, 'status') . '</th>';
echo '<th></th>';

echo '</tr></thead><tbody>';

foreach ($rows as $r) {
  $rid = (int)$r['id'];
  $rname = (string)$r['name'];

  echo '<tr>';
  echo '<td>' . h($rname) . '</td>';
  echo '<td>' . h((string)$r['primary_position']) . '</td>';
  echo '<td class="text-muted">' . h((string)$r['secondary_positions']) . '</td>';
  echo '<td>' . ((int)$r['is_active'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>') . '</td>';

  echo '<td class="text-end">';
  echo '<a class="btn btn-sm btn-outline-primary" href="/?page=crias&edit=' . $rid . '">Editar</a> ';
  echo '<a class="btn btn-sm btn-outline-danger" href="/?page=crias&del=' . $rid . '" onclick="return confirm(\'Excluir atleta da base?\')">Excluir</a> ';

  echo '<button type="button" class="btn btn-sm btn-outline-success js-promote"
              data-id="' . $rid . '"
              data-name="' . h($rname) . '">
          Promover
        </button> ';

  echo '<a class="btn btn-sm btn-outline-warning" href="/?page=crias&dismiss=' . $rid . '" onclick="return confirm(\'Dispensar este atleta da base? Ele irá para a lista de dispensados.\')">Dispensar</a>';

  echo '</td>';
  echo '</tr>';
}

echo '</tbody></table>';
echo '</form>';
echo '</div>';

/**
 * MODAL PROMOVER (continua com Número, pois agora vira profissional)
 */
echo '
<div class="modal fade" id="promoteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card card-soft p-0">
      <div class="modal-header">
        <div class="fw-bold">Promover atleta</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="promoteForm" class="vstack gap-2">
          <input type="hidden" name="academy_id" id="promote_academy_id" value="">
          <input type="hidden" name="promote_submit" value="1">

          <div>
            <label class="form-label">Nome</label>
            <input class="form-control" name="promote_name" id="promote_name" required readonly>
          </div>

          <div>
            <label class="form-label">Número</label>
            <input class="form-control" type="number" name="promote_shirt_number" id="promote_shirt_number" required>
          </div>

          <div>
            <label class="form-label">Posição primária</label>
            <select class="form-select" name="promote_primary_position" id="promote_primary_position" required>
              <option value="">-- selecione --</option>';

foreach ($POSITIONS as $p) {
  echo '<option value="' . h($p) . '">' . h($p) . '</option>';
}

echo '      </select>
          </div>

          <div>
            <label class="form-label">Posições secundárias</label>
            <div class="card-soft p-2" style="box-shadow:none;">
              <div class="row g-2">';

foreach ($POSITIONS as $p) {
  $idcb = 'prom_sec_' . $p;
  echo '<div class="col-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="promote_secondary_positions[]" id="' . h($idcb) . '" value="' . h($p) . '">
            <label class="form-check-label" for="' . h($idcb) . '">' . h($p) . '</label>
          </div>
        </div>';
}

echo '        </div>
            </div>
            <div class="form-text">Marque 0 ou mais posições secundárias.</div>
          </div>

          <button class="btn btn-success">Salvar</button>
        </form>
      </div>
    </div>
  </div>
</div>
';

echo '<script>
(function () {
  // SORT
  var form = document.getElementById("sortFormAcademy");
  if (form) {
    var selects = form.querySelectorAll(".js-sortcol");
    function clearOthers(active) {
      selects.forEach(function (s) { if (s !== active) s.value = ""; });
    }
    selects.forEach(function (sel) {
      sel.addEventListener("change", function () {
        clearOthers(sel);
        form.submit();
      });
    });
  }

  // PROMOVER MODAL
  var modalEl = document.getElementById("promoteModal");
  if (!modalEl) return;

  var idEl = document.getElementById("promote_academy_id");
  var nameEl = document.getElementById("promote_name");
  var numEl = document.getElementById("promote_shirt_number");
  var primEl = document.getElementById("promote_primary_position");

  function clearPromoteForm() {
    numEl.value = "";
    primEl.value = "";
    var cbs = modalEl.querySelectorAll("input[type=checkbox][name=\'promote_secondary_positions[]\']");
    cbs.forEach(function(cb){ cb.checked = false; });
  }

  document.querySelectorAll(".js-promote").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-id") || "";
      var name = btn.getAttribute("data-name") || "";

      idEl.value = id;
      nameEl.value = name;

      clearPromoteForm();

      var m = bootstrap.Modal.getOrCreateInstance(modalEl);
      m.show();

      setTimeout(function(){ numEl.focus(); }, 150);
    });
  });
})();
</script>';

echo '</div></div>'; // col direita
echo '</div>'; // row

render_footer();
