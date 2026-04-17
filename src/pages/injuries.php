<?php
declare(strict_types=1);

$pdo = db();
$userId = require_user_id();
$club = app_club();

$err = '';

$players = q(
  $pdo,
  "SELECT id, name, shirt_number, primary_position
   FROM players
   WHERE user_id = :user_id
     AND TRIM(club_name) = TRIM(:club) COLLATE NOCASE
   ORDER BY is_active DESC, primary_position, shirt_number, name",
  [
    ':user_id' => $userId,
    ':club'    => $club,
  ]
)->fetchAll();

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

  $playerExists = false;
  if ($playerId > 0) {
    $playerExists = (bool) q(
      $pdo,
      "SELECT id
       FROM players
       WHERE id = :id
         AND user_id = :user_id
         AND TRIM(club_name) = TRIM(:club) COLLATE NOCASE
       LIMIT 1",
      [
        ':id'      => $playerId,
        ':user_id' => $userId,
        ':club'    => $club,
      ]
    )->fetch();
  }

  if (!$playerExists) {
    $err = 'Selecione um atleta válido.';
  } elseif ($injuryType === '' || $part === '' || $injuryDate === '' || $recovery === '') {
    $err = 'Preencha todos os campos obrigatórios.';
  } else {
    if ($id > 0) {
      q(
        $pdo,
        "UPDATE injuries
         SET player_id = :player_id,
             injury_type = :injury_type,
             injured_part = :injured_part,
             injury_date = :injury_date,
             recovery_time = :recovery_time,
             return_date = :return_date,
             notes = :notes
         WHERE id = :id
           AND user_id = :user_id",
        [
          ':player_id'     => $playerId,
          ':injury_type'   => $injuryType,
          ':injured_part'  => $part,
          ':injury_date'   => $injuryDate,
          ':recovery_time' => $recovery,
          ':return_date'   => $returnDate,
          ':notes'         => $notes,
          ':id'            => $id,
          ':user_id'       => $userId,
        ]
      );
    } else {
      q(
        $pdo,
        "INSERT INTO injuries(
          user_id,
          player_id,
          injury_type,
          injured_part,
          injury_date,
          recovery_time,
          return_date,
          notes
        ) VALUES (
          :user_id,
          :player_id,
          :injury_type,
          :injured_part,
          :injury_date,
          :recovery_time,
          :return_date,
          :notes
        )",
        [
          ':user_id'       => $userId,
          ':player_id'     => $playerId,
          ':injury_type'   => $injuryType,
          ':injured_part'  => $part,
          ':injury_date'   => $injuryDate,
          ':recovery_time' => $recovery,
          ':return_date'   => $returnDate,
          ':notes'         => $notes,
        ]
      );
    }

    redirect('/?page=injuries');
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $edit = q(
    $pdo,
    "SELECT *
     FROM injuries
     WHERE id = :id
       AND user_id = :user_id
     LIMIT 1",
    [
      ':id'      => (int)$_GET['edit'],
      ':user_id' => $userId,
    ]
  )->fetch() ?: null;
}

if (isset($_GET['del'])) {
  q(
    $pdo,
    "DELETE FROM injuries
     WHERE id = :id
       AND user_id = :user_id",
    [
      ':id'      => (int)$_GET['del'],
      ':user_id' => $userId,
    ]
  );
  redirect('/?page=injuries');
}

$rows = q(
  $pdo,
  "SELECT
      i.*,
      p.name AS player_name,
      p.shirt_number,
      p.primary_position
   FROM injuries i
   JOIN players p
     ON p.id = i.player_id
    AND p.user_id = i.user_id
   WHERE i.user_id = :user_id
   ORDER BY i.injury_date DESC, i.id DESC",
  [
    ':user_id' => $userId,
  ]
)->fetchAll();

render_header('Lesões');

echo '<div class="row g-3">';

echo '<div class="col-lg-4 col-xl-3"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">' . ($edit ? 'Editar lesão' : 'Nova lesão') . '</div>';

if ($err !== '') {
  echo '<div class="alert alert-danger py-2 mb-2">' . h($err) . '</div>';
}

echo '<form method="post" class="vstack gap-2">';
if ($edit) {
  echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';
}

echo '<div><label class="form-label">Atleta</label><select class="form-select" name="player_id" required>';
echo '<option value="">-- selecione --</option>';
foreach ($players as $p) {
  $sel = ($edit && (int)$edit['player_id'] === (int)$p['id']) ? 'selected' : '';
  $sn = trim((string)($p['shirt_number'] ?? ''));
  $lbl = ($sn !== '' ? $sn . ' - ' : '') . $p['name'] . ' (' . $p['primary_position'] . ')';
  echo '<option value="' . (int)$p['id'] . '" ' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></div>';

echo '<div><label class="form-label">Tipo de lesão</label><input class="form-control" name="injury_type" required value="' . h($edit['injury_type'] ?? '') . '"></div>';
echo '<div><label class="form-label">Membro lesionado</label><input class="form-control" name="injured_part" required value="' . h($edit['injured_part'] ?? '') . '"></div>';
echo '<div><label class="form-label">Data da lesão</label><input class="form-control" type="date" name="injury_date" required value="' . h($edit['injury_date'] ?? '') . '"></div>';
echo '<div><label class="form-label">Tempo de recuperação</label><input class="form-control" name="recovery_time" required value="' . h($edit['recovery_time'] ?? '') . '"></div>';
echo '<div><label class="form-label">Data de retorno (opcional)</label><input class="form-control" type="date" name="return_date" value="' . h((string)($edit['return_date'] ?? '')) . '"></div>';
echo '<div><label class="form-label">Notas</label><textarea class="form-control" rows="3" name="notes">' . h($edit['notes'] ?? '') . '</textarea></div>';

echo '<button class="btn btn-primary">Salvar</button>';
if ($edit) {
  echo '<a class="btn btn-secondary" href="/?page=injuries">Cancelar</a>';
}
echo '</form>';
echo '</div></div>';

echo '<div class="col-lg-8 col-xl-9"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Histórico</div>';
echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
echo '<thead><tr><th>Data</th><th>Atleta</th><th>Tipo</th><th>Membro</th><th>Recuperação</th><th>Retorno</th><th></th></tr></thead><tbody>';

if (!$rows) {
  echo '<tr><td colspan="7" class="text-muted">Nenhuma lesão cadastrada.</td></tr>';
} else {
  foreach ($rows as $r) {
    $sn = trim((string)($r['shirt_number'] ?? ''));
    $ath = ($sn !== '' ? $sn . ' - ' : '') . $r['player_name'];

    echo '<tr>';
    echo '<td>' . h((string)$r['injury_date']) . '</td>';
    echo '<td>' . h($ath) . '</td>';
    echo '<td>' . h((string)$r['injury_type']) . '</td>';
    echo '<td>' . h((string)$r['injured_part']) . '</td>';
    echo '<td>' . h((string)$r['recovery_time']) . '</td>';
    echo '<td>' . h((string)($r['return_date'] ?? '')) . '</td>';
    echo '<td class="text-end">';
    echo '<a class="btn btn-sm btn-primary" href="/?page=injuries&edit=' . (int)$r['id'] . '">Editar</a> ';
    echo '<a class="btn btn-sm btn-danger" href="/?page=injuries&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir?\')">Excluir</a>';
    echo '</td>';
    echo '</tr>';
  }
}

echo '</tbody></table></div>';
echo '</div></div>';
echo '</div>';

render_footer();