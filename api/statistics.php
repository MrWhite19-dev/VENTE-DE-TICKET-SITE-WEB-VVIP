<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

$stats['tickets_remaining'] = max(
    0,
    (int) $stats['tickets_capacity'] - (int) $stats['tickets_confirmed'] - (int) $stats['tickets_pending']
);

echo json_encode($stats);
