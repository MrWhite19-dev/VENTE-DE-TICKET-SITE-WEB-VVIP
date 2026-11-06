<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$localAddresses = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $localAddresses, true)) {
    http_response_code(403);
    exit('Cette initialisation est réservée à l’accès local sur WampServer.');
}

if ((int) $pdo->query('SELECT COUNT(*) FROM administrateurs')->fetchColumn() > 0) {
    http_response_code(404);
    exit('Le compte initial est déjà créé.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $nameInput = $_POST['name'] ?? null;
    $emailInput = $_POST['email'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Vérifiez le nom et l’adresse e-mail.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Le mot de passe doit comporter entre 12 et 72 octets.';
    } else {
        $insert = $pdo->prepare('INSERT INTO administrateurs (nom, email, mot_de_passe_hash) VALUES (?, ?, ?)');
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        header('Location: login.php?created=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Créer l’administrateur</title><link rel="stylesheet" href="../admin.css"></head>
<body><main class="login-wrap"><section class="login-card">
  <span class="admin-eyebrow">INITIALISATION LOCALE</span><h1>Créer l’administrateur</h1>
  <p>Cette page n’est accessible que depuis la machine locale et disparaîtra après la création du premier compte.</p>
  <?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
  <form class="admin-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>Nom<input name="name" maxlength="120" required autocomplete="name"></label>
    <label>E-mail<input name="email" type="email" maxlength="190" required autocomplete="email"></label>
    <label>Mot de passe (12 à 72 octets)<input name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
    <button class="admin-button full" type="submit">Créer le compte</button>
  </form>
</section></main></body></html>
