<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $days = filter_var(setting($pdo, 'visits_retention_days', '90'), FILTER_VALIDATE_INT);
    if ($days === false || $days < 1 || $days > 365) {
        http_response_code(500);
        exit('La durée de conservation configurée est invalide.');
    }
    $pdo->exec('DELETE FROM visites WHERE date_visite < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $days . ' DAY)');
    $pdo->exec('DELETE FROM connexions_utilisateurs WHERE date_connexion < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $days . ' DAY)');
    admin_flash('success', 'Les statistiques au-delà de la durée de conservation ont été supprimées.');
    redirect('visiteurs.php');
}

$total = (int) $pdo->query('SELECT COUNT(DISTINCT identifiant_visiteur) FROM visites')->fetchColumn();
$today = (int) $pdo->query('SELECT COUNT(DISTINCT identifiant_visiteur) FROM visites WHERE date_visite >= CURDATE()')->fetchColumn();
$pageViews = (int) $pdo->query('SELECT COUNT(*) FROM visites')->fetchColumn();
$retention = (int) setting($pdo, 'visits_retention_days', '90');
$daily = $pdo->query(
    'SELECT DATE(date_visite) AS day, COUNT(DISTINCT identifiant_visiteur) AS visitors, COUNT(*) AS page_views
     FROM visites GROUP BY DATE(date_visite) ORDER BY day DESC LIMIT 60'
)->fetchAll();
$recentLogins = $pdo->query(
    'SELECT u.nom, u.email, c.date_connexion
     FROM connexions_utilisateurs c JOIN utilisateurs u ON u.id = c.utilisateur_id
     ORDER BY c.date_connexion DESC LIMIT 50'
)->fetchAll();

admin_header('Statistiques visiteurs', 'visiteurs');
?>
<section class="admin-grid">
  <article class="stat-card"><span>Visiteurs uniques conservés</span><strong><?= number_format($total, 0, ',', ' ') ?></strong><small>Identifiants anonymes</small></article>
  <article class="stat-card"><span>Visiteurs aujourd’hui</span><strong><?= number_format($today, 0, ',', ' ') ?></strong><small>Basé sur un cookie aléatoire</small></article>
  <article class="stat-card"><span>Pages vues conservées</span><strong><?= number_format($pageViews, 0, ',', ' ') ?></strong><small>Sur <?= $retention ?> jours maximum</small></article>
</section>
<section class="admin-panel">
  <h2>Confidentialité</h2>
  <p>Un identifiant aléatoire, conservé dans un cookie HttpOnly pendant 30 jours, sert à estimer les visiteurs uniques. Les adresses IP et agents utilisateurs ne sont pas enregistrés. Les statistiques et le journal des connexions Google sont supprimés au-delà de <?= $retention ?> jours.</p>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button class="admin-button secondary" type="submit">Appliquer immédiatement la purge</button></form>
</section>
<section class="admin-panel">
  <h2>Connexions aux espaces utilisateurs</h2>
  <table class="admin-table"><thead><tr><th>Utilisateur</th><th>E-mail</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($recentLogins as $login): ?><tr><td><?= h($login['nom']) ?></td><td><?= h($login['email']) ?></td><td><?= h($login['date_connexion']) ?></td></tr><?php endforeach; ?>
  <?php if (!$recentLogins): ?><tr><td colspan="3">Aucune connexion enregistrée.</td></tr><?php endif; ?>
  </tbody></table>
</section>
<section class="admin-panel">
  <h2>Visiteurs par jour (60 derniers jours)</h2>
  <table class="admin-table"><thead><tr><th>Date</th><th>Visiteurs uniques</th><th>Pages vues</th></tr></thead><tbody>
  <?php foreach ($daily as $row): ?><tr><td><?= h($row['day']) ?></td><td><?= (int) $row['visitors'] ?></td><td><?= (int) $row['page_views'] ?></td></tr><?php endforeach; ?>
  <?php if (!$daily): ?><tr><td colspan="3">Aucune visite enregistrée.</td></tr><?php endif; ?>
  </tbody></table>
</section>
<?php admin_footer(); ?>
