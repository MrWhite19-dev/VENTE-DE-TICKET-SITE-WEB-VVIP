<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (isset($_POST['logout'])) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        redirect('index.php');
    }
    http_response_code(400);
    exit('Action invalide.');
}

$history = $pdo->prepare(
    'SELECT date_connexion FROM connexions_utilisateurs
     WHERE utilisateur_id = ? ORDER BY date_connexion DESC LIMIT 10'
);
$history->execute([(int) $_SESSION['user_id']]);
$connections = $history->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Mon espace — M’AS-TU VU ?</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="account-page">
  <main class="account-card account-dashboard">
    <a class="brand" href="index.php"><span class="brand-mark">AS<span>V</span></span><span class="brand-copy">ART SPIRIT <b>STUDIO</b></span></a>
    <p class="eyebrow">ESPACE PERSONNEL</p>
    <h1>Bonjour <span><?= h((string) $_SESSION['user_name']) ?>.</span></h1>
    <p>Vous êtes connecté avec <?= h((string) $_SESSION['user_email']) ?>.</p>
    <div class="account-history">
      <h2>Vos dernières connexions</h2>
      <?php if ($connections): ?>
        <ul><?php foreach ($connections as $connection): ?>
          <li><?= h(date('d/m/Y à H:i', strtotime($connection['date_connexion']))) ?></li>
        <?php endforeach; ?></ul>
      <?php else: ?>
        <p>Aucune connexion enregistrée.</p>
      <?php endif; ?>
    </div>
    <a class="google-signin" href="index.php">Retour au site public</a>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <button class="account-logout" type="submit" name="logout" value="1">Se déconnecter</button>
    </form>
  </main>
</body>
</html>
