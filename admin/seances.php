<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$error = '';
$ticketNames = ['GP', 'VIP', 'VVIP'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    if ($action === 'save') {
        $filmId = filter_var($_POST['film_id'] ?? null, FILTER_VALIDATE_INT);
        $date = (string) ($_POST['date_seance'] ?? '');
        $time = (string) ($_POST['heure_debut'] ?? '');
        $endTime = trim((string) ($_POST['heure_fin'] ?? ''));
        $room = trim((string) ($_POST['salle'] ?? ''));
        $capacity = filter_var($_POST['capacite_totale'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['statut'] ?? 'ouverte');
        $dateValid = DateTime::createFromFormat('!Y-m-d', $date);
        if (
            !$filmId || !$capacity || $capacity < 1 || $capacity > 100000 ||
            !$dateValid || $dateValid->format('Y-m-d') !== $date ||
            !preg_match('/^\d{2}:\d{2}$/', $time) ||
            ($endTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $endTime)) ||
            mb_strlen($room) < 2 || mb_strlen($room) > 120 ||
            !in_array($status, ['ouverte', 'annulee', 'terminee'], true)
        ) {
            $error = 'Vérifiez le film, la date, les horaires, la salle et la capacité.';
        } else {
            $ticketData = [];
            $allocated = 0;
            foreach ($ticketNames as $ticketName) {
                $price = filter_var($_POST['price_' . $ticketName] ?? null, FILTER_VALIDATE_INT);
                $ticketCapacity = filter_var($_POST['capacity_' . $ticketName] ?? null, FILTER_VALIDATE_INT);
                if (
                    $price === false || $price < 0 || $price > 1000000000 ||
                    $ticketCapacity === false || $ticketCapacity < 0 || $ticketCapacity > 100000
                ) {
                    $error = 'Les tarifs et capacités des trois catégories doivent être des nombres positifs.';
                    break;
                }
                $ticketData[$ticketName] = ['price' => $price, 'capacity' => $ticketCapacity];
                $allocated += $ticketCapacity;
            }
            if ($error === '' && ($allocated > $capacity || $allocated < 1)) {
                $error = 'La somme des capacités GP/VIP/VVIP doit être positive et ne peut pas dépasser la capacité de la salle.';
            }

            if ($error === '') {
                try {
                    $filmCheck = $pdo->prepare("SELECT COUNT(*) FROM films WHERE id = ? AND statut <> 'archive'");
                    $filmCheck->execute([$filmId]);
                    if ((int) $filmCheck->fetchColumn() !== 1) {
                        throw new RuntimeException('Le film sélectionné n’existe pas ou est archivé.');
                    }
                    $pdo->beginTransaction();
                    if ($id > 0) {
                        $lock = $pdo->prepare('SELECT id, film_id FROM seances WHERE id = ? FOR UPDATE');
                        $lock->execute([$id]);
                        $existingSession = $lock->fetch();
                        if (!$existingSession) {
                            throw new RuntimeException('Séance introuvable.');
                        }
                        if ((int) $existingSession['film_id'] !== (int) $filmId) {
                            $reservationCount = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE seance_id = ?');
                            $reservationCount->execute([$id]);
                            if ((int) $reservationCount->fetchColumn() > 0) {
                                throw new RuntimeException('Le film d’une séance ayant des réservations ne peut pas être remplacé.');
                            }
                        }
                        $occupiedQuery = $pdo->prepare(
                            "SELECT type_ticket_id, COALESCE(SUM(nombre_tickets), 0) AS quantity
                             FROM reservations WHERE seance_id = ? AND statut_reservation IN ('en_attente','confirmee')
                             GROUP BY type_ticket_id"
                        );
                        $occupiedQuery->execute([$id]);
                        $occupiedRows = $occupiedQuery->fetchAll();
                        $occupied = [];
                        $occupiedTotal = 0;
                        foreach ($occupiedRows as $row) {
                            $occupied[(int) $row['type_ticket_id']] = (int) $row['quantity'];
                            $occupiedTotal += (int) $row['quantity'];
                        }
                        if ($capacity < $occupiedTotal) {
                            throw new RuntimeException('La capacité totale ne peut pas être inférieure aux demandes actives et réservations confirmées.');
                        }
                        $pdo->prepare(
                            'UPDATE seances SET film_id=?, date_seance=?, heure_debut=?, heure_fin=?, salle=?, capacite_totale=?, statut=? WHERE id=?'
                        )->execute([$filmId, $date, $time, $endTime !== '' ? $endTime : null, $room, $capacity, $status, $id]);

                        $typesQuery = $pdo->prepare('SELECT id, nom FROM types_tickets WHERE seance_id = ? FOR UPDATE');
                        $typesQuery->execute([$id]);
                        $types = $typesQuery->fetchAll();
                        foreach ($types as $type) {
                            $config = $ticketData[$type['nom']] ?? null;
                            if (!$config) {
                                continue;
                            }
                            if ($config['capacity'] < ($occupied[(int) $type['id']] ?? 0)) {
                                throw new RuntimeException('La capacité de ' . $type['nom'] . ' ne peut pas être inférieure aux tickets déjà réservés.');
                            }
                            $pdo->prepare('UPDATE types_tickets SET prix_ticket=?, capacite=?, statut=? WHERE id=?')
                                ->execute([$config['price'], $config['capacity'], $config['capacity'] > 0 ? 'actif' : 'inactif', $type['id']]);
                            unset($ticketData[$type['nom']]);
                        }
                        foreach ($ticketData as $typeName => $config) {
                            $pdo->prepare('INSERT INTO types_tickets (seance_id, nom, prix_ticket, capacite, statut) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$id, $typeName, $config['price'], $config['capacity'], $config['capacity'] > 0 ? 'actif' : 'inactif']);
                        }
                    } else {
                        $insert = $pdo->prepare(
                            'INSERT INTO seances (film_id, date_seance, heure_debut, heure_fin, salle, capacite_totale, statut) VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $insert->execute([$filmId, $date, $time, $endTime !== '' ? $endTime : null, $room, $capacity, $status]);
                        $newId = (int) $pdo->lastInsertId();
                        foreach ($ticketData as $typeName => $config) {
                            $pdo->prepare('INSERT INTO types_tickets (seance_id, nom, prix_ticket, capacite, statut) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$newId, $typeName, $config['price'], $config['capacity'], $config['capacity'] > 0 ? 'actif' : 'inactif']);
                        }
                    }
                    $pdo->commit();
                    admin_flash('success', $id > 0 ? 'La séance et ses tarifs ont été mis à jour.' : 'La séance a été créée.');
                    redirect('seances.php');
                } catch (RuntimeException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Session save failed: ' . $exception->getMessage());
                    $error = 'Impossible d’enregistrer la séance. Vérifiez le film et les catégories de pass.';
                }
            }
        }
    }

    if ($action === 'delete' && $id > 0) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM seances WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetch()) {
                throw new RuntimeException('Séance introuvable.');
            }
            $hasReservations = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE seance_id = ?');
            $hasReservations->execute([$id]);
            if ((int) $hasReservations->fetchColumn() > 0) {
                $pdo->prepare("UPDATE seances SET statut = 'annulee' WHERE id = ?")->execute([$id]);
                admin_flash('success', 'La séance liée à des réservations a été annulée pour préserver l’historique.');
            } else {
                $pdo->prepare('DELETE FROM seances WHERE id = ?')->execute([$id]);
                admin_flash('success', 'La séance a été supprimée.');
            }
            $pdo->commit();
            redirect('seances.php');
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Session deletion failed: ' . $exception->getMessage());
            $error = 'Impossible de supprimer ou annuler cette séance.';
        }
    }
}

$films = $pdo->query("SELECT id, titre FROM films WHERE statut <> 'archive' ORDER BY titre")->fetchAll();
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editing = null;
$editTickets = [];
if ($editId) {
    $statement = $pdo->prepare('SELECT * FROM seances WHERE id = ?');
    $statement->execute([$editId]);
    $editing = $statement->fetch() ?: null;
    if ($editing) {
        $typeQuery = $pdo->prepare('SELECT nom, prix_ticket, capacite FROM types_tickets WHERE seance_id = ?');
        $typeQuery->execute([$editId]);
        foreach ($typeQuery->fetchAll() as $row) {
            $editTickets[$row['nom']] = $row;
        }
    }
}
$sessions = $pdo->query(
    "SELECT s.*, f.titre,
        COALESCE(SUM(CASE WHEN r.statut_reservation IN ('en_attente','confirmee') THEN r.nombre_tickets ELSE 0 END), 0) AS held,
        GROUP_CONCAT(CONCAT(t.nom, ':', t.prix_ticket, ' FCFA (', t.capacite, ')') ORDER BY FIELD(t.nom,'GP','VIP','VVIP') SEPARATOR ' · ') AS ticket_info
     FROM seances s JOIN films f ON f.id=s.film_id
     LEFT JOIN types_tickets t ON t.seance_id=s.id
     LEFT JOIN reservations r ON r.type_ticket_id=t.id
     GROUP BY s.id, f.titre ORDER BY s.date_seance DESC, s.heure_debut DESC"
)->fetchAll();

admin_header('Séances & tickets', 'seances');
?>
<section class="admin-panel">
  <h2><?= $editing ? 'Modifier une séance' : 'Créer une séance' ?></h2>
  <?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$films): ?><p>Créez d’abord un film non archivé.</p><?php else: ?>
  <form class="admin-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <label>Film<select name="film_id" required><?php foreach ($films as $film): ?><option value="<?= (int) $film['id'] ?>" <?= (int) ($editing['film_id'] ?? 0) === (int) $film['id'] ? 'selected' : '' ?>><?= h($film['titre']) ?></option><?php endforeach; ?></select></label>
    <label>Date<input name="date_seance" type="date" required value="<?= h($editing['date_seance'] ?? '') ?>"></label>
    <label>Heure de début<input name="heure_debut" type="time" required value="<?= h(isset($editing['heure_debut']) ? substr($editing['heure_debut'], 0, 5) : '') ?>"></label>
    <label>Heure de fin<input name="heure_fin" type="time" value="<?= h(isset($editing['heure_fin']) ? substr($editing['heure_fin'], 0, 5) : '') ?>"></label>
    <label>Salle / lieu<input name="salle" maxlength="120" required value="<?= h($editing['salle'] ?? '') ?>"></label>
    <label>Capacité totale de la salle<input name="capacite_totale" type="number" min="1" max="100000" required value="<?= h((string) ($editing['capacite_totale'] ?? '')) ?>"></label>
    <?php foreach ($ticketNames as $name): ?>
      <label>Prix <?= h($name) ?> (FCFA)<input name="price_<?= h($name) ?>" type="number" min="0" step="1" required value="<?= h(isset($editTickets[$name]['prix_ticket']) ? (string) (int) $editTickets[$name]['prix_ticket'] : '') ?>"></label>
      <label>Capacité <?= h($name) ?><input name="capacity_<?= h($name) ?>" type="number" min="0" required value="<?= h((string) ($editTickets[$name]['capacite'] ?? '')) ?>"></label>
    <?php endforeach; ?>
    <label>Statut<select name="statut"><?php foreach (['ouverte', 'annulee', 'terminee'] as $status): ?><option value="<?= $status ?>" <?= ($editing['statut'] ?? 'ouverte') === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
    <div class="full admin-actions"><button class="admin-button" type="submit"><?= $editing ? 'Enregistrer la séance' : 'Créer la séance' ?></button><?php if ($editing): ?><a class="admin-button secondary" href="seances.php">Annuler</a><?php endif; ?></div>
  </form>
  <?php endif; ?>
</section>
<section class="admin-panel">
  <h2>Séances programmées</h2>
  <table class="admin-table"><thead><tr><th>Film / date</th><th>Salle</th><th>Pass (prix / capacité)</th><th>Places occupées</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($sessions as $session): ?><tr>
    <td class="wrap"><?= h($session['titre']) ?><br><span class="muted"><?= h($session['date_seance']) ?> • <?= h(substr($session['heure_debut'], 0, 5)) ?></span></td>
    <td class="wrap"><?= h($session['salle']) ?></td><td class="wrap"><?= h($session['ticket_info'] ?? 'Aucune catégorie') ?></td>
    <td><?= (int) $session['held'] ?> / <?= (int) $session['capacite_totale'] ?></td><td><?= h($session['statut']) ?></td>
    <td><div class="admin-actions"><a class="admin-button secondary" href="seances.php?edit=<?= (int) $session['id'] ?>">Modifier</a>
      <form method="post" onsubmit="return confirm('Supprimer ou annuler cette séance ?')"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $session['id'] ?>"><button class="admin-button danger" type="submit">Annuler</button></form>
    </div></td>
  </tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php admin_footer(); ?>
