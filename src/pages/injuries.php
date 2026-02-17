<?php
declare(strict_types=1);
$pdo = db();

$players = q($pdo, "SELECT id, name, shirt_number, primary_position FROM players ORDER BY is_active DESC, primary_position, shirt_number, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $playerId = (int)($_POST['player_id'] ?? 0);
  $injuryType = trim((string)($_POST['injury_type'] ?? ''));
  $part = trim((string)($_POST['injured_part'] ?? ''));
  $injuryDate = trim((string)($_POST['injury_date'] ?? ''));
  $recovery = trim((string)($_POST['recovery_time'] ?? ''));
  $returnDate = trim((string)($_POST['return_date'] ?? ''));
  $returnDate = $returnDate === '' ? null : $returnDate;
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($playerId > 0 && $injuryType !== '' && $part !== '' && $injuryDate !== '' && $recovery !== '') {
    if ($id > 0) {
      q($pdo, "UPDATE injuries SET player_id=?, injury_type=?, injured_part=?, injury_date=?, recovery_time=?, return_date=?, notes=? WHERE id=?",
        [$playerId,$injuryType,$part,$injuryDate,$recovery,$returnDate,$notes,$id]);
    } else {
      q($pdo, "INSERT INTO injuries(player_id, injury_type, injured_part, injury_date, recovery_time, return_date, notes) VALUES (?,?,?,?,?,?,?)",
        [$playerId,$injuryType,$part,$injuryDate,$recovery,$returnDate,$notes]);
    }
  }
  redirect('/?page=injuries');
}

$edit = null;
if (isset($_GET['edit'])) $edit = q($pdo, "SELECT * FROM injuries WHERE id=?", [(int)$_GET['edit']])->fetch() ?: null;
if (isset($_GET['del'])) { q($pdo, "DELETE FROM injuries WHERE id=?", [(int)$_GET['del']]); redirect('/?page=injuries'); }

$rows = q($pdo, "SELECT i.*, p.name AS player_name, p.shirt_number, p.primary_position
                FROM injuries i JOIN players p ON p.id=i.player_id
                ORDER BY i.injury_date DESC, i.id DESC")->fetchAll();

render_header('Lesões');

echo '<div class="row g-3">';
echo '<div class="col-lg-4"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">' . ($edit ? 'Editar lesão' : 'Nova lesão') . '</div>';

echo '<form method="post" class="vstack gap-2">';
if ($edit) echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';

echo '<div><label class="form-label">Atleta</label><select class="form-select" name="player_id" required>';
echo '<option value="">-- selecione --</option>';
foreach ($players as $p) {
  $sel = ($edit && (int)$edit['player_id'] === (int)$p['id']) ? 'selected' : '';
  $lbl = trim((string)($p['shirt_number'] ?? '')) . ' - ' . $p['name'] . ' (' . $p['primary_position'] . ')';
  echo '<option value="' . (int)$p['id'] . '" ' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></div>';

echo '<div><label class="form-label">Tipo de lesão</label><input class="form-control" name="injury_type" required value="' . h($edit['injury_type'] ?? '') . '"></div>';
echo '<div><label class="form-label">Membro lesionado</label><input class="form-control" name="injured_part" required value="' . h($edit['injured_part'] ?? '') . '"></div>';
echo '<div><label class="form-label">Data da lesão</label><input class="form-control" type="date" name="injury_date" required value="' . h($edit['injury_date'] ?? '') . '"></div>';
echo '<div><label class="form-label">Tempo de recuperação</label><input class="form-control" name="recovery_time" required value="' . h($edit['recovery_time'] ?? '') . '"></div>';
echo '<div><label class="form-label">Data de retorno (opcional)</label><input class="form-control" type="date" name="return_date" value="' . h((string)($edit['return_date'] ?? '')) . '"></div>';
echo '<div><label class="form-label">Notas</label><textarea class="form-control" rows="3" name="notes">' . h($edit['notes'] ?? '') . '</textarea></div>';

echo '<button class="btn btn-success">Salvar</button>';
if ($edit) echo '<a class="btn btn-outline-secondary" href="/?page=injuries">Cancelar</a>';
echo '</form>';
echo '</div></div>';

echo '<div class="col-lg-8"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Histórico</div>';
echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
echo '<thead><tr><th>Data</th><th>Atleta</th><th>Tipo</th><th>Membro</th><th>Recuperação</th><th>Retorno</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
  $ath = trim((string)($r['shirt_number'] ?? '')) . ' - ' . $r['player_name'];
  echo '<tr>';
  echo '<td>' . h($r['injury_date']) . '</td>';
  echo '<td>' . h($ath) . '</td>';
  echo '<td>' . h($r['injury_type']) . '</td>';
  echo '<td>' . h($r['injured_part']) . '</td>';
  echo '<td>' . h($r['recovery_time']) . '</td>';
  echo '<td>' . h((string)($r['return_date'] ?? '')) . '</td>';
  echo '<td class="text-end">';
  echo '<a class="btn btn-sm btn-outline-primary" href="/?page=injuries&edit=' . (int)$r['id'] . '">Editar</a> ';
  echo '<a class="btn btn-sm btn-outline-danger" href="/?page=injuries&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir?\')">Excluir</a>';
  echo '</td>';
  echo '</tr>';
}
echo '</tbody></table></div>';
echo '</div></div>';
echo '</div>';

render_footer();

