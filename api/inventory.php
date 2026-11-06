<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Séance invalide.']);
    exit;
}

$sessionQuery = $pdo->prepare(
    "SELECT s.id, s.date_seance, s.heure_debut, s.salle, s.statut,
            f.id AS film_id, f.titre
     FROM seances s JOIN films f ON f.id=s.film_id
     WHERE s.id = ? AND s.statut='ouverte' AND f.statut='publie'
       AND s.date_seance >= CURRENT_DATE"
);
$sessionQuery->execute([$sessionId]);
$session = $sessionQuery->fetch();
if (!$session) {
    http_response_code(404);
    echo json_encode(['error' => 'Séance indisponible.']);
    exit;
}

$query = $pdo->prepare(
    "SELECT t.id, t.nom, t.capacite, t.prix_ticket, t.statut,
            GREATEST(0, t.capacite - COALESCE(SUM(
                CASE WHEN r.statut_reservation IN ('en_attente', 'confirmee')
                THEN r.nombre_tickets ELSE 0 END
            ), 0)) AS places_restantes,
            COALESCE(SUM(CASE WHEN r.statut_reservation = 'confirmee'
                THEN r.nombre_tickets ELSE 0 END), 0) AS tickets_confirmes
     FROM types_tickets t
     LEFT JOIN reservations r ON r.type_ticket_id = t.id
     WHERE t.seance_id = ?
     GROUP BY t.id, t.nom, t.capacite, t.prix_ticket, t.statut
     ORDER BY FIELD(t.nom, 'GP', 'VIP', 'VVIP'), t.nom"
);
$query->execute([$sessionId]);
echo json_encode(['session' => $session, 'tickets' => $query->fetchAll()]);
