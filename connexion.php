<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect('utilisateur.php');
}

$clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
$clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: '';
$redirectParts = parse_url($redirectUri);
$localCallback = is_array($redirectParts)
    && in_array($redirectParts['host'] ?? '', ['localhost', '127.0.0.1'], true)
    && ($redirectParts['scheme'] ?? '') === 'http';
$secureCallback = is_array($redirectParts) && ($redirectParts['scheme'] ?? '') === 'https';
$error = '';

if (isset($_GET['action']) && $_GET['action'] === 'google') {
    if ($clientId === '' || $clientSecret === '' || $redirectUri === ''
        || !filter_var($redirectUri, FILTER_VALIDATE_URL)
        || (!$secureCallback && !$localCallback)) {
        http_response_code(503);
        exit('La connexion Google n’est pas configurée. Contactez l’administrateur du site.');
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $_SESSION['google_oauth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'created_at' => time(),
    ];
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $query = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $query);
}

if (isset($_GET['code']) || isset($_GET['error'])) {
    $oauth = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);
    $state = $_GET['state'] ?? null;
    $code = $_GET['code'] ?? null;

    if (isset($_GET['error']) || !is_array($oauth)
        || !is_string($state) || !hash_equals($oauth['state'], $state)
        || time() - $oauth['created_at'] > 600
        || !is_string($code) || $code === ''
        || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
        http_response_code(400);
        $error = 'La connexion Google a échoué ou a expiré. Veuillez réessayer.';
    } elseif (!function_exists('curl_init')) {
        http_response_code(503);
        $error = 'La connexion Google nécessite l’extension PHP cURL.';
    } else {
        $tokenRequest = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($tokenRequest, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $oauth['verifier'],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $tokenBody = curl_exec($tokenRequest);
        $tokenStatus = (int) curl_getinfo($tokenRequest, CURLINFO_RESPONSE_CODE);
        $tokenError = curl_error($tokenRequest);
        curl_close($tokenRequest);

        $tokenData = is_string($tokenBody) ? json_decode($tokenBody, true) : null;
        $idToken = is_array($tokenData) ? ($tokenData['id_token'] ?? null) : null;
        if ($tokenError !== '' || $tokenStatus !== 200 || !is_string($idToken) || $idToken === '') {
            error_log('Google OAuth token exchange failed: ' . ($tokenError ?: 'HTTP ' . $tokenStatus));
            http_response_code(502);
            $error = 'Google n’a pas pu terminer la connexion. Veuillez réessayer.';
        } else {
            $verifyRequest = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
            curl_setopt_array($verifyRequest, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $verifyBody = curl_exec($verifyRequest);
            $verifyStatus = (int) curl_getinfo($verifyRequest, CURLINFO_RESPONSE_CODE);
            $verifyError = curl_error($verifyRequest);
            curl_close($verifyRequest);

            $claims = is_string($verifyBody) ? json_decode($verifyBody, true) : null;
            $emailVerified = is_array($claims) && in_array($claims['email_verified'] ?? null, [true, 'true'], true);
            $validClaims = is_array($claims)
                && ($claims['aud'] ?? null) === $clientId
                && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
                && isset($claims['exp']) && (int) $claims['exp'] > time()
                && isset($claims['nonce']) && hash_equals($oauth['nonce'], (string) $claims['nonce'])
                && $emailVerified
                && isset($claims['sub'], $claims['email'], $claims['name'])
                && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
                && mb_strlen((string) $claims['name']) <= 190
                && strlen((string) $claims['sub']) <= 255;

            if ($verifyError !== '' || $verifyStatus !== 200 || !$validClaims) {
                error_log('Google OAuth identity verification failed: ' . ($verifyError ?: 'invalid token claims'));
                http_response_code(401);
                $error = 'Le compte Google n’a pas pu être vérifié. Veuillez réessayer.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $lookup = $pdo->prepare('SELECT id FROM utilisateurs WHERE google_sub = ? LIMIT 1');
                    $lookup->execute([(string) $claims['sub']]);
                    $userId = $lookup->fetchColumn();

                    if ($userId === false) {
                        $create = $pdo->prepare('INSERT INTO utilisateurs (google_sub, nom, email) VALUES (?, ?, ?)');
                        $create->execute([(string) $claims['sub'], (string) $claims['name'], (string) $claims['email']]);
                        $userId = $pdo->lastInsertId();
                    } else {
                        $update = $pdo->prepare('UPDATE utilisateurs SET nom = ?, email = ? WHERE id = ?');
                        $update->execute([(string) $claims['name'], (string) $claims['email'], $userId]);
                    }

                    $userId = (int) $userId;
                    $pdo->prepare('INSERT INTO connexions_utilisateurs (utilisateur_id) VALUES (?)')->execute([$userId]);
                    $pdo->prepare('UPDATE utilisateurs SET derniere_connexion = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
                    $pdo->commit();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = (string) $claims['name'];
                    $_SESSION['user_email'] = (string) $claims['email'];
                    unset($_SESSION['csrf_token']);

                    send_admin_notification(
                        'Nouvelle connexion utilisateur — M’AS-TU VU ?',
                        "Un utilisateur vient de se connecter avec Google.\n"
                        . 'Nom : ' . (string) $claims['name'] . "\n"
                        . 'E-mail : ' . (string) $claims['email'] . "\n"
                        . 'Date : ' . date(DATE_ATOM) . "\n"
                    );
                    redirect('utilisateur.php');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Google user sign-in failed: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'Impossible d’enregistrer cette connexion. Veuillez réessayer.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Connexion utilisateur — M’AS-TU VU ?</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="account-page">
  <main class="account-card">
    <a class="brand" href="index.php"><span class="brand-mark">AS<span>V</span></span><span class="brand-copy">ART SPIRIT <b>STUDIO</b></span></a>
    <p class="eyebrow">ESPACE UTILISATEUR</p>
    <h1>Connexion <span>sécurisée.</span></h1>
    <p>Connectez-vous avec Google pour accéder à votre espace personnel. Nous enregistrons la date de connexion et utilisons votre nom et votre e-mail pour le compte et les alertes de sécurité envoyées à l’administrateur.</p>
    <?php if ($error !== ''): ?><div class="account-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <a class="google-signin" href="connexion.php?action=google">Continuer avec Google</a>
    <a class="account-back" href="index.php">Retour au site public</a>
  </main>
</body>
</html>
