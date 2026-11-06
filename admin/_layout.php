<?php
declare(strict_types=1);

function admin_header(string $title, string $active): void
{
    $navigation = [
        'dashboard' => ['Tableau de bord', 'dashboard.php'],
        'films' => ['Films', 'films.php'],
        'seances' => ['Séances & tickets', 'seances.php'],
        'reservations' => ['Réservations', 'reservations.php'],
        'visiteurs' => ['Visiteurs', 'visiteurs.php'],
        'contenus' => ['Bandes-annonces', 'contenus.php'],
        'parametres' => ['Paramètres', 'parametres.php'],
    ];
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="robots" content="noindex,nofollow">
      <title><?= h($title) ?> — Administration cinéma</title>
      <link rel="stylesheet" href="../admin.css?v=2">
    </head>
    <body class="admin-body">
      <aside class="admin-sidebar">
        <a class="admin-brand" href="dashboard.php"><span>ASV</span><strong>ART SPIRIT<small>ADMINISTRATION</small></strong></a>
        <nav aria-label="Administration">
          <?php foreach ($navigation as $key => [$label, $href]): ?>
            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <form class="admin-public-form" method="post" action="logout.php">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="destination" value="public">
          <button class="admin-public-link" type="submit">Se déconnecter et voir le site public ↗</button>
        </form>
      </aside>
      <main class="admin-main">
        <header class="admin-topbar">
          <div><span class="admin-eyebrow">ESPACE PRIVÉ</span><h1><?= h($title) ?></h1></div>
          <div class="admin-user"><?= h($_SESSION['admin_name'] ?? 'Administrateur') ?>
            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="destination" value="public"><button class="admin-button secondary" type="submit">Déconnexion</button></form>
          </div>
        </header>
        <?php if (!empty($_SESSION['admin_flash'])): ?>
          <div class="admin-flash <?= h($_SESSION['admin_flash']['type']) ?>" role="status"><?= h($_SESSION['admin_flash']['message']) ?></div>
          <?php unset($_SESSION['admin_flash']); ?>
        <?php endif; ?>
    <?php
}

function admin_footer(): void
{
    ?>
      </main>
    </body>
    </html>
    <?php
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}
