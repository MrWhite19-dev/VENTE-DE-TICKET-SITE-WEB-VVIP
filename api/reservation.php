<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !verify_csrf($payload['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Votre formulaire a expiré. Rechargez la page puis réessayez.']);
    exit;
}

$nameValue = $payload['name'] ?? null;
$emailValue = $payload['email'] ?? null;
$phoneValue = $payload['phone'] ?? null;
$name = is_string($nameValue) ? trim($nameValue) : '';
$email = is_string($emailValue) ? trim($emailValue) : '';
$phone = is_string($phoneValue) ? trim($phoneValue) : '';
$typeId = filter_var($payload['ticket_type_id'] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);

if (
    mb_strlen($name) < 2 || mb_strlen($name) > 160 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
    !preg_match('/^[0-9+().\-\s]{6,40}$/', $phone) ||
    !$typeId || !$quantity || $quantity < 1 || $quantity > 10
) {
    http_response_code(422);
    echo json_encode(['error' => 'Vérifiez votre nom, votre e-mail, votre téléphone et le nombre de pass (1 à 10).']);
    exit;
}

try {
    $pdo->beginTransaction();

    $ticketQuery = $pdo->prepare(
        'SELECT t.id, t.nom, t.prix_ticket, t.capacite, t.statut AS ticket_status,
                s.id AS seance_id, s.capacite_totale, s.statut AS seance_status
         FROM types_tickets t
         JOIN seances s ON s.id = t.seance_id
         JOIN films f ON f.id = s.film_id
         WHERE t.id = ? AND f.statut = \'publie\' AND s.date_seance >= CURRENT_DATE
         FOR UPDATE'
    );
    $ticketQuery->execute([$typeId]);
    $ticket = $ticketQuery->fetch();

    if (!$ticket || $ticket['ticket_status'] !== 'actif' || $ticket['seance_status'] !== 'ouverte') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Cette catégorie de pass n’est plus disponible.']);
        exit;
    }

    $usageQuery = $pdo->prepare(
        'SELECT
           COALESCE(SUM(CASE WHEN type_ticket_id = ? THEN nombre_tickets ELSE 0 END), 0) AS type_usage,
           COALESCE(SUM(nombre_tickets), 0) AS session_usage
         FROM reservations
         WHERE seance_id = ? AND statut_reservation IN (\'en_attente\', \'confirmee\')'
    );
    $usageQuery->execute([$typeId, $ticket['seance_id']]);
    $usage = $usageQuery->fetch();

    $typeRemaining = (int) $ticket['capacite'] - (int) $usage['type_usage'];
    $sessionRemaining = (int) $ticket['capacite_totale'] - (int) $usage['session_usage'];
    if ($quantity > min($typeRemaining, $sessionRemaining)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Le nombre de places disponibles a changé. Réduisez la quantité et réessayez.']);
        exit;
    }

    $unitPrice = (int) $ticket['prix_ticket'];
    $total = $unitPrice * $quantity;
    $reference = 'MTV-' . strtoupper(bin2hex(random_bytes(6)));
    $insert = $pdo->prepare(
        'INSERT INTO reservations
          (reference_reservation, seance_id, type_ticket_id, nom_client, email_client,
           telephone_client, nombre_tickets, prix_unitaire, montant_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $reference,
        $ticket['seance_id'],
        $typeId,
        $name,
        $email,
        $phone,
        $quantity,
        $unitPrice,
        $total,
    ]);

    $pdo->commit();
    send_admin_notification(
        'Nouvelle demande de réservation — M’AS-TU VU ?',
        "Une nouvelle demande de réservation est en attente.\n"
        . 'Référence : ' . $reference . "\n"
        . 'Nom : ' . $name . "\n"
        . 'E-mail : ' . $email . "\n"
        . 'Téléphone : ' . $phone . "\n"
        . 'Pass : ' . $ticket['nom'] . ' × ' . $quantity . "\n"
        . 'Total estimé : ' . number_format($total, 0, ',', ' ') . " FCFA\n"
        . 'Date : ' . date(DATE_ATOM) . "\n"
    );
    echo json_encode([
        'message' => 'Votre demande a bien été reçue. Elle reste en attente de confirmation.',
        'reference' => $reference,
        'ticket' => $ticket['nom'],
        'quantity' => $quantity,
        'total' => $total,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reservation creation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Impossible d’enregistrer votre demande pour le moment. Réessayez plus tard.']);
}
