<?php
declare(strict_types=1);

$pdo = db();

/**
 * Opções fixas de posição (conforme requisito)
 */
$POSITIONS = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];


/**
 * Ordem customizada para ordenar por POS (GOL, ALE, LE, ZAG, LD, ALD, VOL, ME, MC, MD, MEI, PE, PD, SA, ATA)
 */
$PRIMARY_POS_ORDER_CASE = "CASE UPPER(primary_position) WHEN 'GOL' THEN 1 WHEN 'ALE' THEN 2 WHEN 'LE' THEN 3 WHEN 'ZAG' THEN 4 WHEN 'LD' THEN 5 WHEN 'ALD' THEN 6 WHEN 'VOL' THEN 7 WHEN 'ME' THEN 8 WHEN 'MC' THEN 9 WHEN 'MD' THEN 10 WHEN 'MEI' THEN 11 WHEN 'PE' THEN 12 WHEN 'PD' THEN 13 WHEN 'SA' THEN 14 WHEN 'ATA' THEN 15 ELSE 999 END";
/**
 * Helper: parse de string "PE, PD; MEI" -> ['PE','PD','MEI']
 */
function parse_positions(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  $raw = str_replace(';', ',', $raw);
  $parts = array_map('trim', explode(',', $raw));
  $parts = array_values(array_unique(array_filter($parts, fn($v) => $v !== '')));
  return $parts;
}

$err = '';
$edit = null;

// Edit (GET)
if (isset($_GET['edit'])) {
  $edit = q($pdo, "SELECT * FROM players WHERE id=?", [(int)$_GET['edit']])->fetch() ?: null;
}

// Delete (GET)
if (isset($_GET['del'])) {
#  q($pdo, "DELETE FROM players WHERE id=?", [(int)$_GET['del']]);
#  redirect('/?page=players');
  q($pdo, "DELETE FROM players WHERE id=? AND club_name = ? COLLATE NOCASE", [(int)$_GET['del'], app_club()]);
}

// Save (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));

  $shirt = ($_POST['shirt_number'] ?? '') === '' ? null : (int)$_POST['shirt_number'];

  // Primária (select)
  $prim = trim((string)($_POST['primary_position'] ?? ''));

  // Secundárias (checkboxes => array)
  $secArr = $_POST['secondary_positions'] ?? [];
  if (!is_array($secArr)) $secArr = [];
  $secArr = array_values(array_unique(array_filter(array_map(
    fn($v) => trim((string)$v),
    $secArr
  ), fn($v) => $v !== '')));

  // filtra apenas posições válidas (evita valores injetados)
  global $POSITIONS;
  $secArr = array_values(array_filter($secArr, fn($v) => in_array($v, $POSITIONS, true)));

  $sec = implode(',', $secArr);

  $active = isset($_POST['is_active']) ? 1 : 0;

  // validações
  if ($name === '') {
    $err = 'Informe o nome do atleta.';
  } elseif (!in_array($prim, $POSITIONS, true)) {
    $err = 'Selecione uma posição primária válida.';
  }

  // Se erro, mantém os valores no form (sem perder o que foi imputado)
  if ($err !== '') {
    $edit = [
      'id' => $id,
      'name' => $name,
      'shirt_number' => $shirt,
      'primary_position' => $prim,
      'secondary_positions' => $sec,
      'is_active' => $active
    ];
  } else {
    if ($id > 0) {
      q($pdo, "UPDATE players
              SET name=?, shirt_number=?, primary_position=?, secondary_positions=?, is_active=?, updated_at=datetime('now')
              WHERE id=? AND club_name = ? COLLATE NOCASE",
        [$name, $shirt, $prim, $sec, $active, $id, app_club()]
      );
    } else {
      q($pdo, "INSERT INTO players(name, shirt_number, primary_position, secondary_positions, is_active, club_name)
                VALUES (?,?,?,?,?,?)",
        [$name, $shirt, $prim, $sec, $active, app_club()]
      );
    }
    redirect('/?page=players');
  }
}

/**
 * SORT POR COLUNA (seleção em cada coluna)
 * Cada select envia ASC/DESC para sua coluna; só 1 deve ficar ativo por vez.
 * Mantém o estilo atual (apenas adiciona os selects no cabeçalho).
 */
$allowedDirs = ['ASC', 'DESC'];

$sortKey = null;
$sortDir = null;

// prioridade fixa (se, por algum motivo, vier mais de um select preenchido)
$sortSelectMap = [
  'sort_number'    => 'number',
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

// ORDER BY padrão atual (quando não há sort selecionado)
#$defaultOrderBy = "is_active DESC, primary_position ASC, shirt_number ASC, name ASC";
$defaultOrderBy = "is_active DESC, {$PRIMARY_POS_ORDER_CASE} ASC, shirt_number ASC, name ASC";

// Monta ORDER BY com base no sort selecionado (com tie-breakers estáveis)
$orderBy = $defaultOrderBy;

if ($sortKey !== null && $sortDir !== null) {
  if ($sortKey === 'number') {
    // números nulos sempre no fim, independente do sentido
    $orderBy = "shirt_number IS NULL ASC, shirt_number {$sortDir}, name ASC";
  } elseif ($sortKey === 'name') {
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
  FROM players
  WHERE club_name = ? COLLATE NOCASE
  ORDER BY {$orderBy}
", [app_club()])->fetchAll();

render_header('Elenco');

echo '<div class="row g-3">';
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
echo '<div><label class="form-label">Número</label><input class="form-control" type="number" name="shirt_number" value="' . h((string)($edit['shirt_number'] ?? '')) . '"></div>';

// Posição primária (dropdown)
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

// Posições secundárias (checkboxes)
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

echo '</div>'; // row
echo '</div>'; // card-soft
echo '<div class="form-text">Marque 0 ou mais posições secundárias.</div>';
echo '</div>';

$checked = (!$edit || (int)($edit['is_active'] ?? 1) === 1) ? 'checked' : '';
echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="act" ' . $checked . '><label class="form-check-label" for="act">Ativo no elenco</label></div>';

echo '<button class="btn btn-success">Salvar</button>';
if ($edit) echo '<a class="btn btn-outline-secondary" href="/?page=players">Cancelar</a>';
echo '</form>';

echo '<div class="text-muted small mt-3">Dica: transferências podem ativar/inativar atletas automaticamente.</div>';
echo '</div></div>';

echo '<div class="col-lg-8"><div class="card card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-bold">Lista</div>';
echo '<div class="text-muted small">Total: ' . count($rows) . '</div></div>';

echo '<div class="table-responsive">';

// Form GET para sort por coluna (sem mudar layout geral)
echo '<form method="get" id="sortFormPlayers">';
echo '<input type="hidden" name="page" value="players">';
if (isset($_GET['edit'])) echo '<input type="hidden" name="edit" value="' . (int)$_GET['edit'] . '">';

echo '<table class="table table-sm align-middle mb-0">';

echo '<thead><tr>';

function sortSelect(string $name, ?string $activeKey, ?string $activeDir, string $thisKey): string {
  $isActive = ($activeKey === $thisKey) ? ($activeDir ?? '') : '';
  $selNone = ($isActive === '') ? ' selected' : '';
  $selAsc  = ($isActive === 'ASC') ? ' selected' : '';
  $selDesc = ($isActive === 'DESC') ? ' selected' : '';
  // sem estilos custom; só classes já usadas no projeto (bootstrap)
  return '<select class="form-select form-select-sm js-sortcol" name="' . h($name) . '" data-key="' . h($thisKey) . '">' .
           '<option value=""' . $selNone . '>—</option>' .
           '<option value="ASC"' . $selAsc . '>↑</option>' .
           '<option value="DESC"' . $selDesc . '>↓</option>' .
         '</select>';
}

// th # (numeração)
echo '<th>#' . sortSelect('sort_number', $sortKey, $sortDir, 'number') . '</th>';

// th Nome
echo '<th>Atleta' . sortSelect('sort_name', $sortKey, $sortDir, 'name') . '</th>';

// th Posição primária
echo '<th>Posição' . sortSelect('sort_primary', $sortKey, $sortDir, 'primary') . '</th>';

// th Secundárias
echo '<th>Pos. Sec.' . sortSelect('sort_secondary', $sortKey, $sortDir, 'secondary') . '</th>';

// th Status
echo '<th>Status' . sortSelect('sort_status', $sortKey, $sortDir, 'status') . '</th>';

// th ações
echo '<th></th>';

echo '</tr></thead><tbody>';

foreach ($rows as $r) {
  echo '<tr>';
  echo '<td class="mono">' . h((string)($r['shirt_number'] ?? '')) . '</td>';
  echo '<td>' . h($r['name']) . '</td>';
  echo '<td>' . h($r['primary_position']) . '</td>';
  echo '<td class="text-muted">' . h((string)$r['secondary_positions']) . '</td>';
  echo '<td>' . ((int)$r['is_active'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>') . '</td>';
  echo '<td class="text-end">';
  echo '<a class="btn btn-sm btn-outline-primary" href="/?page=players&edit=' . (int)$r['id'] . '">Editar</a> ';
  echo '<a class="btn btn-sm btn-outline-danger" href="/?page=players&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir atleta?\')">Excluir</a>';
  echo '</td>';
  echo '</tr>';
}

echo '</tbody></table>';
echo '</form>'; // sortFormPlayers
echo '</div>';  // table-responsive

// JS mínimo: ao selecionar uma coluna, limpa as outras e submete (sem mexer no estilo)
echo '<script>
(function () {
  var form = document.getElementById("sortFormPlayers");
  if (!form) return;

  var selects = form.querySelectorAll(".js-sortcol");
  function clearOthers(active) {
    selects.forEach(function (s) {
      if (s !== active) s.value = "";
    });
  }

  selects.forEach(function (sel) {
    sel.addEventListener("change", function () {
      clearOthers(sel);
      form.submit();
    });
  });
})();
</script>';

echo '</div></div>';
echo '</div>';

render_footer();
