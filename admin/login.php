<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $emailInput = $_POST['email'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $addressHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $pdo->exec('DELETE FROM tentatives_connexion WHERE date_tentative < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)');
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM tentatives_connexion WHERE adresse_hash = ? AND date_tentative >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)'
    );
    $recent->execute([$addressHash]);
    if ((int) $recent->fetchColumn() >= 5) {
        http_response_code(429);
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        $lookup = $pdo->prepare('SELECT id, nom, mot_de_passe_hash FROM administrateurs WHERE email = ? LIMIT 1');
        $lookup->execute([$email]);
        $admin = $lookup->fetch();
        $pdo->prepare('INSERT INTO tentatives_connexion (adresse_hash, email_hash) VALUES (?, ?)')->execute([
            $addressHash,
            hash('sha256', mb_strtolower($email)),
        ]);
        if ($email !== '' && strlen($email) <= 190 && $password !== '' && strlen($password) <= 72
            && $admin && password_verify($password, $admin['mot_de_passe_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['nom'];
            unset($_SESSION['csrf_token']);
            $pdo->prepare('UPDATE administrateurs SET derniere_connexion = CURRENT_TIMESTAMP WHERE id = ?')->execute([$admin['id']]);
            $pdo->prepare('DELETE FROM tentatives_connexion WHERE adresse_hash = ?')->execute([$addressHash]);
            redirect('dashboard.php');
        }
        $error = 'Adresse e-mail ou mot de passe incorrect.';
    }
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Connexion administrateur</title><link rel="stylesheet" href="../admin.css"></head>
<body><main class="login-wrap"><section class="login-card">
  <span class="admin-eyebrow">ART SPIRIT STUDIO • ESPACE PRIVÉ</span><h1>Connexion administrateur</h1>
  <?php if (isset($_GET['created'])): ?><div class="admin-flash success">Le compte administrateur a été créé. Vous pouvez vous connecter.</div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="admin-flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form class="admin-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>E-mail<input name="email" type="email" maxlength="190" required autocomplete="username"></label>
    <label>Mot de passe<input name="password" type="password" required autocomplete="current-password"></label>
    <button class="admin-button full" type="submit">Se connecter</button>
  </form>
</section></main></body></html>
