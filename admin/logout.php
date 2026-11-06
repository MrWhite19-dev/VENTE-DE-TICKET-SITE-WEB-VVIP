<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}
require_post_csrf();
$returnToPublic = ($_POST['destination'] ?? '') === 'public';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirect($returnToPublic ? '../index.php' : 'login.php');
