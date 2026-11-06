<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$stats = $pdo->query(
    "SELECT
       (SELECT COUNT(DISTINCT identifiant_visiteur) FROM visites) AS visitors_total,
       (SELECT COUNT(DISTINCT identifiant_visiteur) FROM visites WHERE date_visite >= CURDATE()) AS visitors_today,
       COALESCE((SELECT SUM(capacite_totale) FROM seances WHERE statut <> 'annulee'), 0) AS tickets_capacity,
       COALESCE((SELECT SUM(nombre_tickets) FROM reservations WHERE statut_reservation = 'confirmee'), 0) AS tickets_confirmed,
       COALESCE((SELECT SUM(nombre_tickets) FROM reservations WHERE statut_reservation = 'en_attente'), 0) AS tickets_pending,
       (SELECT COUNT(*) FROM reservations) AS reservations_total,
       (SELECT COUNT(*) FROM reservations WHERE statut_reservation = 'en_attente') AS reservations_pending,
       (SELECT COUNT(*) FROM reservations WHERE statut_reservation = 'confirmee') AS reservations_confirmed,
       COALESCE((SELECT SUM(montant_total) FROM reservations WHERE statut_reservation = 'confirmee'), 0) AS revenue_confirmed"
)->fetch();
$stats['tickets_remaining'] = max(0, (int) $stats['tickets_capacity'] - (int) $stats['tickets_confirmed'] - (int) $stats['tickets_pending']);

$visits = $pdo->query(
    "SELECT DATE(date_visite) AS day, COUNT(DISTINCT identifiant_visiteur) AS total
     FROM visites WHERE date_visite >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(date_visite) ORDER BY day"
)->fetchAll();
$visitMax = max(1, ...array_map(static fn(array $row): int => (int) $row['total'], $visits));

$bookings = $pdo->query(
    "SELECT DATE(date_reservation) AS day, COUNT(*) AS total
     FROM reservations WHERE date_reservation >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(date_reservation) ORDER BY day"
)->fetchAll();
$bookingMax = max(1, ...array_map(static fn(array $row): int => (int) $row['total'], $bookings));

$latest = $pdo->query(
    'SELECT r.reference_reservation, r.nom_client, r.nombre_tickets, r.montant_total,
            r.statut_reservation, r.date_reservation, f.titre, t.nom AS ticket
     FROM reservations r
     JOIN seances s ON s.id = r.seance_id
     JOIN films f ON f.id = s.film_id
     JOIN types_tickets t ON t.id = r.type_ticket_id
     ORDER BY r.date_reservation DESC LIMIT 8'
)->fetchAll();
$popular = $pdo->query(
    "SELECT f.titre, COALESCE(SUM(CASE WHEN r.statut_reservation IN ('en_attente','confirmee')
              THEN r.nombre_tickets ELSE 0 END), 0) AS total
     FROM films f
     LEFT JOIN seances s ON s.film_id = f.id
     LEFT JOIN reservations r ON r.seance_id = s.id
     GROUP BY f.id ORDER BY total DESC LIMIT 5"
)->fetchAll();
$upcoming = $pdo->query(
    "SELECT f.titre, s.date_seance, s.heure_debut, s.salle, s.capacite_totale,
            COALESCE(SUM(CASE WHEN r.statut_reservation IN ('en_attente','confirmee')
              THEN r.nombre_tickets ELSE 0 END), 0) AS held
     FROM seances s JOIN films f ON f.id = s.film_id
     LEFT JOIN reservations r ON r.seance_id = s.id
     WHERE s.statut = 'ouverte' AND s.date_seance >= CURRENT_DATE
     GROUP BY s.id, f.titre, s.date_seance, s.heure_debut, s.salle, s.capacite_totale
     ORDER BY s.date_seance, s.heure_debut LIMIT 5"
)->fetchAll();

admin_header('Tableau de bord', 'dashboard');
?>
<section class="admin-grid" id="live-stats">
  <article class="stat-card"><span>Visiteurs au total</span><strong data-stat="visitors_total"><?= number_format((int) $stats['visitors_total'], 0, ',', ' ') ?></strong><small>Sur la période conservée</small></article>
  <article class="stat-card"><span>Visiteurs aujourd’hui</span><strong data-stat="visitors_today"><?= number_format((int) $stats['visitors_today'], 0, ',', ' ') ?></strong><small>Visiteurs uniques estimés</small></article>
  <article class="stat-card"><span>Capacité totale</span><strong data-stat="tickets_capacity"><?= number_format((int) $stats['tickets_capacity'], 0, ',', ' ') ?></strong><small>Places programmées</small></article>
  <article class="stat-card"><span>Demandes en attente</span><strong data-stat="reservations_pending"><?= number_format((int) $stats['reservations_pending'], 0, ',', ' ') ?></strong><small>À traiter</small></article>
  <article class="stat-card"><span>Tickets demandés en attente</span><strong data-stat="tickets_pending"><?= number_format((int) $stats['tickets_pending'], 0, ',', ' ') ?></strong><small>Places bloquées jusqu’à action</small></article>
  <article class="stat-card"><span>Tickets confirmés</span><strong data-stat="tickets_confirmed"><?= number_format((int) $stats['tickets_confirmed'], 0, ',', ' ') ?></strong><small>Ventes confirmées</small></article>
  <article class="stat-card"><span>Tickets restants</span><strong data-stat="tickets_remaining"><?= number_format((int) $stats['tickets_remaining'], 0, ',', ' ') ?></strong><small>En attente + confirmés déduits</small></article>
  <article class="stat-card"><span>Réservations totales</span><strong data-stat="reservations_total"><?= number_format((int) $stats['reservations_total'], 0, ',', ' ') ?></strong><small><span data-stat="reservations_confirmed"><?= (int) $stats['reservations_confirmed'] ?></span> confirmées</small></article>
  <article class="stat-card"><span>Chiffre confirmé</span><strong data-stat="revenue_confirmed"><?= number_format((float) $stats['revenue_confirmed'], 0, ',', ' ') ?></strong><small>FCFA</small></article>
</section>
<div class="admin-flash success" id="new-reservation-notice" hidden role="status"></div>

<section class="admin-columns">
  <div>
    <article class="admin-panel">
      <h2>Visiteurs uniques — 7 derniers jours</h2>
      <div class="admin-bars">
        <?php foreach ($visits as $row): ?>
          <div class="admin-bar"><span><?= h(date('d/m', strtotime($row['day']))) ?></span><div class="admin-bar-track"><i style="width: <?= round(100 * (int) $row['total'] / $visitMax) ?>%"></i></div><strong><?= (int) $row['total'] ?></strong></div>
        <?php endforeach; ?>
        <?php if (!$visits): ?><p>Aucune visite enregistrée sur cette période.</p><?php endif; ?>
      </div>
    </article>
    <article class="admin-panel">
      <h2>Réservations reçues — 7 derniers jours</h2>
      <div class="admin-bars">
        <?php foreach ($bookings as $row): ?>
          <div class="admin-bar"><span><?= h(date('d/m', strtotime($row['day']))) ?></span><div class="admin-bar-track"><i style="width: <?= round(100 * (int) $row['total'] / $bookingMax) ?>%"></i></div><strong><?= (int) $row['total'] ?></strong></div>
        <?php endforeach; ?>
        <?php if (!$bookings): ?><p>Aucune réservation sur cette période.</p><?php endif; ?>
      </div>
    </article>
  </div>
  <div>
    <article class="admin-panel">
      <h2>Prochaines séances</h2>
      <?php if (!$upcoming): ?><p>Aucune séance programmée.</p><?php else: ?>
        <table class="admin-table"><thead><tr><th>Film / date</th><th>Places</th></tr></thead><tbody>
        <?php foreach ($upcoming as $row): $remaining = max(0, (int) $row['capacite_totale'] - (int) $row['held']); ?>
          <tr><td class="wrap"><?= h($row['titre']) ?><br><span class="muted"><?= h($row['date_seance']) ?> • <?= h(substr($row['heure_debut'], 0, 5)) ?> • <?= h($row['salle']) ?></span></td><td><?= $remaining ?> / <?= (int) $row['capacite_totale'] ?><?php if ($remaining <= max(1, (int) floor($row['capacite_totale'] * .1))): ?><br><span class="badge cancelled">Presque complet</span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </article>
    <article class="admin-panel">
      <h2>Films les plus demandés</h2>
      <div class="admin-bars"><?php foreach ($popular as $row): ?><div class="admin-bar"><span class="wrap"><?= h($row['titre']) ?></span><div class="admin-bar-track"><i style="width: <?= min(100, (int) $row['total']) ?>%"></i></div><strong><?= (int) $row['total'] ?></strong></div><?php endforeach; ?></div>
    </article>
  </div>
</section>

<section class="admin-panel">
  <h2>Dernières réservations</h2>
  <?php if (!$latest): ?><p>Aucune réservation enregistrée.</p><?php else: ?>
  <table class="admin-table"><thead><tr><th>Référence</th><th>Client</th><th>Film / pass</th><th>Tickets</th><th>Montant</th><th>Statut</th></tr></thead><tbody>
    <?php foreach ($latest as $row): $statusClass = ['en_attente' => 'pending', 'confirmee' => 'confirmed', 'annulee' => 'cancelled'][$row['statut_reservation']]; ?>
      <tr><td><?= h($row['reference_reservation']) ?></td><td><?= h($row['nom_client']) ?><br><span class="muted"><?= h($row['date_reservation']) ?></span></td><td class="wrap"><?= h($row['titre']) ?> · <?= h($row['ticket']) ?></td><td><?= (int) $row['nombre_tickets'] ?></td><td><?= number_format((float) $row['montant_total'], 0, ',', ' ') ?> FCFA</td><td><span class="badge <?= $statusClass ?>"><?= h(str_replace('_', ' ', $row['statut_reservation'])) ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<script>
let knownPendingRequests = Number(document.querySelector('[data-stat="reservations_pending"]')?.textContent.replace(/\D/g, '') || 0);
window.setInterval(async () => {
  try {
    const response = await fetch('../api/statistics.php', { cache: 'no-store' });
    if (!response.ok) throw new Error('Statistiques indisponibles');
    const stats = await response.json();
    const pending = Number(stats.reservations_pending);
    if (pending > knownPendingRequests) {
      const notice = document.getElementById('new-reservation-notice');
      notice.textContent = (pending - knownPendingRequests) + ' nouvelle(s) demande(s) de réservation reçue(s).';
      notice.hidden = false;
    }
    knownPendingRequests = pending;
    document.querySelectorAll('[data-stat]').forEach((element) => {
      const key = element.dataset.stat;
      if (Object.prototype.hasOwnProperty.call(stats, key)) {
        element.textContent = new Intl.NumberFormat('fr-FR').format(stats[key]);
      }
    });
  } catch (error) {
    console.error(error);
  }
}, 30000);
</script>
<?php admin_footer(); ?>
