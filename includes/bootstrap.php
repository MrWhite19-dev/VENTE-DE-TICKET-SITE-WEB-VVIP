<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
}

function require_user(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('connexion.php');
    }

    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
}

function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Requête invalide ou expirée. Rechargez la page puis réessayez.');
    }
}

function redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

function setting(PDO $pdo, string $key, string $fallback = ''): string
{
    $statement = $pdo->prepare('SELECT valeur_parametre FROM parametres WHERE nom_parametre = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();

    return $value === false ? $fallback : (string) $value;
}

function record_visit(PDO $pdo): void
{
    $cookieName = 'mtv_visitor';
    $visitorId = $_COOKIE[$cookieName] ?? '';
    if (!is_string($visitorId) || !preg_match('/^[a-f0-9]{64}$/', $visitorId)) {
        $visitorId = $_SESSION['mtv_visitor_fallback'] ?? '';
        if (!is_string($visitorId) || !preg_match('/^[a-f0-9]{64}$/', $visitorId)) {
            $visitorId = bin2hex(random_bytes(32));
            $_SESSION['mtv_visitor_fallback'] = $visitorId;
        }
        setcookie($cookieName, $visitorId, [
            'expires' => time() + 30 * 24 * 60 * 60,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $retentionDays = filter_var(setting($pdo, 'visits_retention_days', '90'), FILTER_VALIDATE_INT);
    if ($retentionDays === false || $retentionDays < 1 || $retentionDays > 365) {
        throw new RuntimeException('La durée de conservation des statistiques est invalide.');
    }
    $pdo->exec('DELETE FROM visites WHERE date_visite < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $retentionDays . ' DAY)');
    $pdo->exec('DELETE FROM connexions_utilisateurs WHERE date_connexion < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $retentionDays . ' DAY)');

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? mb_substr($path, 0, 255) : '/';
    $insert = $pdo->prepare(
        'INSERT INTO visites (identifiant_visiteur, page_visitee, adresse_ip_hash, user_agent)
         VALUES (?, ?, NULL, NULL)'
    );
    $insert->execute([$visitorId, $path]);
}

function send_admin_notification(string $subject, string $message): bool
{
    $recipient = 'messiefidele@gmail.com';
    $sender = getenv('MAIL_FROM') ?: '';
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        error_log('Email notification not sent: configure a valid MAIL_FROM value.');
        return false;
    }

    $headers = [
        'From: ' . $sender,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    if (!mail($recipient, $encodedSubject, $message, implode("\r\n", $headers))) {
        error_log('Email notification could not be handed to the configured mail transport.');
        return false;
    }

    return true;
}

function french_date(string $date): string
{
    $months = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];
    $timestamp = strtotime($date);

    return date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

function valid_video_source(string $source): bool
{
    $source = trim($source);
    if ($source === '') {
        return true;
    }

    $parts = parse_url($source);
    if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
        return strtolower($parts['scheme']) === 'https' && video_embed_url($source) !== null;
    }

    if (str_contains($source, '..') || str_starts_with($source, '/') || str_starts_with($source, '\\')) {
        return false;
    }
    $fullPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $source));
    $assetRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets');
    $uploadRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');

    return $fullPath !== false
        && str_ends_with(strtolower($fullPath), '.mp4')
        && (($assetRoot !== false && str_starts_with($fullPath, $assetRoot . DIRECTORY_SEPARATOR))
            || ($uploadRoot !== false && str_starts_with($fullPath, $uploadRoot . DIRECTORY_SEPARATOR)));
}

function video_embed_url(string $source): ?string
{
    $parts = parse_url($source);
    if (!is_array($parts) || !isset($parts['host'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    if (in_array($host, ['youtube.com', 'www.youtube.com'], true)) {
        parse_str($parts['query'] ?? '', $query);
        $id = $query['v'] ?? '';
        if (($parts['path'] ?? '') !== '/watch') {
            $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
            $id = in_array($segments[0] ?? '', ['embed', 'shorts'], true) ? ($segments[1] ?? '') : $id;
        }
        return preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $id)
            ? 'https://www.youtube-nocookie.com/embed/' . $id
            : null;
    }
    if ($host === 'youtu.be') {
        $id = trim((string) ($parts['path'] ?? ''), '/');
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id)
            ? 'https://www.youtube-nocookie.com/embed/' . $id
            : null;
    }
    if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
        $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
        $id = end($segments);
        return ctype_digit((string) $id) ? 'https://player.vimeo.com/video/' . $id : null;
    }

    return null;
}

function upload_media(string $field, string $directory, array $allowedMimeTypes, int $maxBytes): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxBytes || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Fichier invalide, trop volumineux ou téléversement incomplet.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimeTypes, true)) {
        throw new RuntimeException('Le type de fichier envoyé n’est pas autorisé.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
    ];
    $extension = $extensions[$mime] ?? null;
    if ($extension === null) {
        throw new RuntimeException('Extension de fichier non autorisée.');
    }

    $targetDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Impossible de préparer le dossier de téléversement.');
    }

    $filename = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $targetDirectory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Impossible d’enregistrer le fichier envoyé.');
    }

    return 'uploads/' . $directory . '/' . $filename;
}

date_default_timezone_set(setting($pdo, 'timezone', 'Africa/Abidjan'));
