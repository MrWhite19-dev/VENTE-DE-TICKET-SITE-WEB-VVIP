<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $whatsapp = preg_replace('/\D+/', '', (string) ($_POST['whatsapp_number'] ?? ''));
    $retention = filter_var($_POST['visits_retention_days'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^\d{10,15}$/', $whatsapp) || $retention === false || $retention < 1 || $retention > 365) {
        $error = 'Le numéro WhatsApp doit contenir 10 à 15 chiffres et la conservation être entre 1 et 365 jours.';
    } else {
        $update = $pdo->prepare(
            'INSERT INTO parametres (nom_parametre, valeur_parametre) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valeur_parametre=VALUES(valeur_parametre)'
        );
        $update->execute(['whatsapp_number', $whatsapp]);
        $update->execute(['visits_retention_days', (string) $retention]);
        admin_flash('success', 'Les paramètres ont été enregistrés.');
        redirect('parametres.php');
    }
}

admin_header('Paramètres', 'parametres');
?>
<section class="admin-panel">
  <h2>Configuration publique et confidentialité</h2>
  <?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
  <form class="admin-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>Numéro WhatsApp international (chiffres uniquement)<input name="whatsapp_number" inputmode="numeric" pattern="[0-9]{10,15}" required value="<?= h(setting($pdo, 'whatsapp_number', '22550182565')) ?>"></label>
    <label>Conservation des statistiques (jours)<input name="visits_retention_days" type="number" min="1" max="365" required value="<?= h(setting($pdo, 'visits_retention_days', '90')) ?>"></label>
    <div class="full"><button class="admin-button" type="submit">Enregistrer</button></div>
  </form>
</section>
<section class="admin-panel">
  <h2>Connexion MySQL</h2>
  <p>Les identifiants de base de données ne sont pas modifiables depuis l’interface et ne sont jamais affichés. Configurez-les avec les variables d’environnement <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code> et <code>DB_PASSWORD</code>.</p>
</section>
<section class="admin-panel">
  <h2>Notifications et connexion Google</h2>
  <p>Les alertes de connexion Google et de demandes de réservation sont envoyées à <strong>messiefidele@gmail.com</strong>. Configurez <code>MAIL_FROM</code> et le transport SMTP du serveur PHP; Google OAuth nécessite <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code> et <code>GOOGLE_REDIRECT_URI</code>. Les identifiants OAuth doivent rester privés côté serveur.</p>
</section>
<?php admin_footer(); ?>
