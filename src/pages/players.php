<?php
declare(strict_types=1);

$pdo = db();

/**
 * Opções fixas de posição (conforme requisito)
 */
$POSITIONS = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

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
  q($pdo, "DELETE FROM players WHERE id=?", [(int)$_GET['del']]);
  redirect('/?page=players');
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
               WHERE id=?",
        [$name, $shirt, $prim, $sec, $active, $id]
      );
    } else {
      q($pdo, "INSERT INTO players(name, shirt_number, primary_position, secondary_positions, is_active)
               VALUES (?,?,?,?,?)",
        [$name, $shirt, $prim, $sec, $active]
      );
    }
    redirect('/?page=players');
  }
}

$rows = q($pdo, "SELECT * FROM players ORDER BY is_active DESC, primary_position ASC, shirt_number ASC, name ASC")->fetchAll();

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

echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
echo '<thead><tr><th>#</th><th>Atleta</th><th>Posição</th><th>Sec.</th><th>Status</th><th></th></tr></thead><tbody>';

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

echo '</tbody></table></div>';
echo '</div></div>';
echo '</div>';

render_footer();
