<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = (string) ($_POST['status'] ?? '');
    if (!$reservationId || !in_array($newStatus, ['en_attente', 'confirmee', 'annulee'], true)) {
        $error = 'Action de réservation invalide.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT r.id, r.statut_reservation, r.seance_id, r.type_ticket_id, r.nombre_tickets,
                        s.statut AS session_status, s.capacite_totale, t.capacite AS ticket_capacity,
                        f.statut AS film_status
                 FROM reservations r JOIN seances s ON s.id=r.seance_id
                 JOIN types_tickets t ON t.id=r.type_ticket_id
                 JOIN films f ON f.id=s.film_id
                 WHERE r.id = ? FOR UPDATE'
            );
            $lock->execute([$reservationId]);
            $reservation = $lock->fetch();
            if (!$reservation) {
                throw new RuntimeException('Réservation introuvable.');
            }
            if ($newStatus !== 'annulee' && ($reservation['session_status'] !== 'ouverte' || $reservation['film_status'] !== 'publie')) {
                throw new RuntimeException('Une réservation d’une séance indisponible ne peut pas être activée.');
            }
            if (
                $reservation['statut_reservation'] === 'annulee'
                && in_array($newStatus, ['en_attente', 'confirmee'], true)
            ) {
                $usage = $pdo->prepare(
                    "SELECT COALESCE(SUM(nombre_tickets),0) AS session_usage,
                            COALESCE(SUM(CASE WHEN type_ticket_id=? THEN nombre_tickets ELSE 0 END),0) AS type_usage
                     FROM reservations
                     WHERE seance_id=? AND statut_reservation IN ('en_attente','confirmee')"
                );
                $usage->execute([$reservation['type_ticket_id'], $reservation['seance_id']]);
                $occupied = $usage->fetch();
                if (
                    (int) $occupied['session_usage'] + (int) $reservation['nombre_tickets'] > (int) $reservation['capacite_totale']
                    || (int) $occupied['type_usage'] + (int) $reservation['nombre_tickets'] > (int) $reservation['ticket_capacity']
                ) {
                    throw new RuntimeException('Les places ont été attribuées depuis l’annulation; cette réservation ne peut pas être réactivée.');
                }
            }
            $pdo->prepare('UPDATE reservations SET statut_reservation = ? WHERE id = ?')
                ->execute([$newStatus, $reservationId]);
            $pdo->commit();
            admin_flash('success', 'Le statut de la réservation a été mis à jour.');
            redirect('reservations.php');
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Reservation status update failed: ' . $exception->getMessage());
            $error = 'Impossible de modifier le statut.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 50;
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(r.reference_reservation LIKE ? OR r.nom_client LIKE ? OR r.email_client LIKE ? OR r.telephone_client LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($statusFilter, ['en_attente', 'confirmee', 'annulee'], true)) {
    $where[] = 'r.statut_reservation = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM reservations r' . $whereSql);
$countQuery->execute($params);
$totalRows = (int) $countQuery->fetchColumn();
$pageCount = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $pageCount);
$statement = $pdo->prepare(
    'SELECT r.*, f.titre, s.date_seance, s.heure_debut, t.nom AS ticket
     FROM reservations r JOIN seances s ON s.id=r.seance_id
     JOIN films f ON f.id=s.film_id JOIN types_tickets t ON t.id=r.type_ticket_id' .
     $whereSql . ' ORDER BY r.date_reservation DESC LIMIT ? OFFSET ?'
);
foreach ($params as $index => $value) {
    $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
}
$statement->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
$statement->bindValue(count($params) + 2, ($page - 1) * $pageSize, PDO::PARAM_INT);
$statement->execute();
$reservations = $statement->fetchAll();

$counts = $pdo->query(
    "SELECT statut_reservation, COUNT(*) AS requests, COALESCE(SUM(nombre_tickets),0) AS tickets
     FROM reservations GROUP BY statut_reservation"
)->fetchAll();
$summary = ['en_attente' => ['requests' => 0, 'tickets' => 0], 'confirmee' => ['requests' => 0, 'tickets' => 0], 'annulee' => ['requests' => 0, 'tickets' => 0]];
foreach ($counts as $row) {
    $summary[$row['statut_reservation']] = ['requests' => (int) $row['requests'], 'tickets' => (int) $row['tickets']];
}

admin_header('Réservations', 'reservations');
?>
<?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
<section class="admin-grid">
  <?php foreach (['en_attente' => 'En attente', 'confirmee' => 'Confirmées', 'annulee' => 'Annulées'] as $key => $label): ?>
    <article class="stat-card"><span><?= h($label) ?></span><strong><?= $summary[$key]['requests'] ?> demandes</strong><small><?= $summary[$key]['tickets'] ?> tickets concernés</small></article>
  <?php endforeach; ?>
  <article class="stat-card"><span>Tickets confirmés</span><strong><?= $summary['confirmee']['tickets'] ?></strong><small>Les demandes en attente ne sont pas des ventes</small></article>
</section>
<section class="admin-panel">
  <form class="admin-search" method="get">
    <input name="q" value="<?= h($search) ?>" placeholder="Nom, e-mail, téléphone ou référence">
    <select name="status"><option value="">Tous les statuts</option><?php foreach (['en_attente', 'confirmee', 'annulee'] as $status): ?><option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select>
    <button class="admin-button" type="submit">Rechercher</button>
  </form>
  <p><?= number_format($totalRows, 0, ',', ' ') ?> résultat(s) • Page <?= $page ?> / <?= $pageCount ?></p>
  <table class="admin-table"><thead><tr><th>Référence / date</th><th>Client</th><th>Film / séance</th><th>Pass / quantité</th><th>Montant</th><th>Statut / action</th></tr></thead><tbody>
  <?php foreach ($reservations as $reservation):
    $statusClass = ['en_attente' => 'pending', 'confirmee' => 'confirmed', 'annulee' => 'cancelled'][$reservation['statut_reservation']];
  ?>
    <tr>
      <td><?= h($reservation['reference_reservation']) ?><br><span class="muted"><?= h($reservation['date_reservation']) ?></span></td>
      <td class="wrap"><?= h($reservation['nom_client']) ?><br><a href="mailto:<?= h($reservation['email_client']) ?>"><?= h($reservation['email_client']) ?></a><br><?= h($reservation['telephone_client']) ?></td>
      <td class="wrap"><?= h($reservation['titre']) ?><br><span class="muted"><?= h($reservation['date_seance']) ?> • <?= h(substr($reservation['heure_debut'], 0, 5)) ?></span></td>
      <td><?= h($reservation['ticket']) ?> × <?= (int) $reservation['nombre_tickets'] ?></td>
      <td><?= number_format((float) $reservation['montant_total'], 0, ',', ' ') ?> FCFA</td>
      <td><span class="badge <?= $statusClass ?>"><?= h(str_replace('_', ' ', $reservation['statut_reservation'])) ?></span>
        <form method="post" class="admin-actions" style="margin-top:7px"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
          <select name="status" aria-label="Nouveau statut"><?php foreach (['en_attente', 'confirmee', 'annulee'] as $status): ?><option value="<?= $status ?>" <?= $reservation['statut_reservation'] === $status ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select>
          <button class="admin-button secondary" type="submit">Mettre à jour</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$reservations): ?><tr><td colspan="6">Aucune réservation ne correspond à la recherche.</td></tr><?php endif; ?>
  </tbody></table>
  <div class="admin-actions" style="margin-top:16px"><?php if ($page > 1): ?><a class="admin-button secondary" href="?q=<?= rawurlencode($search) ?>&amp;status=<?= rawurlencode($statusFilter) ?>&amp;page=<?= $page - 1 ?>">← Précédent</a><?php endif; ?><?php if ($page < $pageCount): ?><a class="admin-button secondary" href="?q=<?= rawurlencode($search) ?>&amp;status=<?= rawurlencode($statusFilter) ?>&amp;page=<?= $page + 1 ?>">Suivant →</a><?php endif; ?></div>
</section>
<?php admin_footer(); ?>
