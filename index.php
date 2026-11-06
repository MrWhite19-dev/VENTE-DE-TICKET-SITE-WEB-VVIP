<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

try {
    record_visit($pdo);
    $sessionQuery = $pdo->query(
        "SELECT s.*, f.titre, f.description, f.image_affiche, f.image_banniere,
                f.video_bande_annonce, f.realisateur, f.duree, f.categorie
         FROM seances s
         JOIN films f ON f.id = s.film_id
         WHERE f.statut = 'publie' AND s.statut = 'ouverte' AND s.date_seance >= CURRENT_DATE
         ORDER BY s.date_seance, s.heure_debut
         LIMIT 1"
    );
    $event = $sessionQuery->fetch();
    if (!$event) {
        http_response_code(503);
        exit('Aucun film ou séance publiée n’est actuellement disponible.');
    }

    $publicSessions = $pdo->query(
        "SELECT s.id, s.film_id, s.date_seance, s.heure_debut, s.salle, f.titre
         FROM seances s JOIN films f ON f.id=s.film_id
         WHERE f.statut='publie' AND s.statut='ouverte' AND s.date_seance >= CURRENT_DATE
         ORDER BY f.titre, s.date_seance, s.heure_debut"
    )->fetchAll();
    $catalogFilms = $pdo->query(
        "SELECT DISTINCT f.id, f.titre, f.description, f.image_affiche
         FROM films f JOIN seances s ON s.film_id=f.id
         WHERE f.statut='publie' AND s.statut='ouverte' AND s.date_seance >= CURRENT_DATE
         ORDER BY f.titre"
    )->fetchAll();

    $ticketQuery = $pdo->prepare(
        "SELECT t.id, t.nom, t.prix_ticket, t.capacite, t.statut,
                GREATEST(0, t.capacite - COALESCE(SUM(
                    CASE WHEN r.statut_reservation IN ('en_attente', 'confirmee')
                    THEN r.nombre_tickets ELSE 0 END
                ), 0)) AS places_restantes,
                COALESCE(SUM(CASE WHEN r.statut_reservation = 'confirmee'
                    THEN r.nombre_tickets ELSE 0 END), 0) AS tickets_confirmes
         FROM types_tickets t
         LEFT JOIN reservations r ON r.type_ticket_id = t.id
         WHERE t.seance_id = ?
         GROUP BY t.id, t.nom, t.prix_ticket, t.capacite, t.statut
         ORDER BY FIELD(t.nom, 'GP', 'VIP', 'VVIP'), t.nom"
    );
    $ticketQuery->execute([$event['id']]);
    $tickets = $ticketQuery->fetchAll();
    $contentQuery = $pdo->query(
        "SELECT titre, description, fichier_media, miniature
         FROM contenus
         WHERE statut = 'publie'
           AND (date_publication IS NULL OR date_publication <= CURRENT_TIMESTAMP)
         ORDER BY date_publication DESC, date_creation DESC LIMIT 6"
    );
    $publicTrailers = $contentQuery->fetchAll();
} catch (Throwable $exception) {
    error_log('Public page loading failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Le site ne peut pas charger les informations pour le moment. Réessayez plus tard.');
}

$eventDate = french_date($event['date_seance']);
$eventTime = substr($event['heure_debut'], 0, 5);
$poster = $event['image_affiche'] ?: 'assets/affiche-mas-tu-vu.jpeg';
$trailer = $event['video_bande_annonce'] ?? '';
$trailerEmbed = is_string($trailer) ? video_embed_url($trailer) : null;
$isExternalTrailer = $trailerEmbed !== null;
$titleWords = preg_split('/\s+/', trim($event['titre'])) ?: [];
$titleAccent = (string) array_pop($titleWords);
if (preg_match('/^[?!.,:;]+$/', $titleAccent) && $titleWords) {
    $titleAccent = (string) array_pop($titleWords) . ' ' . $titleAccent;
}
$titleLead = implode(' ', $titleWords);
$whatsappDigits = preg_replace('/\D+/', '', setting($pdo, 'whatsapp_number', '22550182565'));
$whatsappHref = preg_match('/^\d{10,15}$/', $whatsappDigits) ? 'https://wa.me/' . $whatsappDigits : '#';
$whatsappLabel = str_starts_with($whatsappDigits, '225') && strlen($whatsappDigits) === 11
    ? '+225 ' . implode(' ', str_split(substr($whatsappDigits, 3), 2))
    : '+' . $whatsappDigits;
$ticketCopy = [
    'GP' => ['L’expérience cinéma essentielle.', 'Accès à l’avant-première', 'Réservation en ligne', 'Confirmation par l’équipe'],
    'VIP' => ['Une expérience plus exclusive.', 'Accès à l’avant-première', 'Réservation prioritaire', 'Confirmation par l’équipe'],
    'VVIP' => ['Le pass pour une expérience premium.', 'Accès à l’avant-première', 'Demande personnalisée', 'Confirmation par l’équipe'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#090909">
  <meta name="description" content="<?= h($event['description']) ?>">
  <title><?= h($event['titre']) ?> — Réservation des pass</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800;900&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body data-session-id="<?= (int) $event['id'] ?>">
  <!-- Fond décoratif futuriste -->
  <div class="ambient ambient-one" aria-hidden="true"></div>
  <div class="ambient ambient-two" aria-hidden="true"></div>
  <div class="noise" aria-hidden="true"></div>

  <header class="topbar">
    <a class="brand" href="#accueil" aria-label="M'AS-TU VU, accueil">
      <span class="brand-mark">AS<span>V</span></span>
      <span class="brand-copy">ART SPIRIT <b>STUDIO</b></span>
    </a>
    <div class="topbar-actions">
      <?php if (!empty($_SESSION['user_id'])): ?>
        <a class="account-nav" href="utilisateur.php">Mon espace</a>
      <?php else: ?>
        <a class="account-nav" href="connexion.php">Connexion</a>
      <?php endif; ?>
      <a class="admin-nav" href="admin/login.php">Espace administrateur</a>
      <a class="top-cta" href="#tickets">Réserver mon pass <span aria-hidden="true">↗</span></a>
    </div>
  </header>

  <main>
    <!-- Bandeau principal de l'avant-première -->
    <section class="cinematic-hero" id="accueil">
      <?php if (empty($trailer) && !empty($event['image_banniere'])): ?>
        <img class="cinematic-banner-image" src="<?= h($event['image_banniere']) ?>" alt="" aria-hidden="true">
      <?php endif; ?>
      <?php if ($trailer !== '' && !$isExternalTrailer): ?>
        <video class="cinematic-bg-video" autoplay muted loop playsinline
               poster="<?= h($poster) ?>" aria-hidden="true">
          <source src="<?= h($trailer) ?>" type="video/mp4">
        </video>
      <?php endif; ?>
      <div class="cinematic-overlay" aria-hidden="true"></div>

      <div class="cinematic-content">
        <span class="cinematic-tag">
          <span class="live-dot" aria-hidden="true"></span>
          <?= h($event['categorie'] ?: 'AVANT-PREMIÈRE • ÉVÉNEMENT CINÉMA') ?>
        </span>

        <p class="cinematic-category"><?= h($event['realisateur'] ? 'UN FILM DE ' . mb_strtoupper($event['realisateur']) : 'ART SPIRIT STUDIO PRÉSENTE') ?></p>
        <h1><?= h($titleLead) ?><?php if ($titleAccent !== ''): ?><br><span><?= h($titleAccent) ?></span><?php endif; ?></h1>
        <p class="cinematic-description">
          <?= h($event['description']) ?>
        </p>

        <div class="cinematic-details">
          <span><?= h(mb_strtoupper($eventDate)) ?></span>
          <span class="detail-separator" aria-hidden="true"></span>
          <span><?= h($eventTime) ?></span>
          <span class="detail-separator" aria-hidden="true"></span>
          <span><?= h(mb_strtoupper($event['salle'])) ?></span>
        </div>

        <div class="cinematic-actions">
          <a class="trailer-play-btn" href="#tickets">
            RÉSERVER MON PASS <span aria-hidden="true">↗</span>
          </a>
          <?php if ($trailer !== ''): ?><button class="cinematic-info-btn" id="mainTrailerBtn" type="button">
            <span class="play-icon" aria-hidden="true">▶</span>
            VOIR LA BANDE-ANNONCE
          </button><?php endif; ?>
        </div>
      </div>

      <div class="cinematic-poster" aria-hidden="true">
        <img src="<?= h($poster) ?>" alt="<?= h($event['titre']) ?>">
        <span>ART SPIRIT STUDIO <b>•</b> <?= h(date('Y', strtotime($event['date_seance']))) ?></span>
      </div>
    </section>

    <div class="cinema-modal" id="cinemaModal" aria-hidden="true">
      <div class="cinema-modal-content" role="dialog" aria-modal="true"
           aria-label="Bande-annonce de M'AS-TU VU ?">
        <button class="cinema-modal-close" id="closeCinemaModal"
                type="button" aria-label="Fermer la bande-annonce">×</button>
        <?php if ($isExternalTrailer): ?>
            <iframe id="cinemaTrailerFrame" src="about:blank" data-source="<?= h($trailerEmbed) ?>" title="Bande-annonce de <?= h($event['titre']) ?>" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
        <?php else: ?>
        <video id="cinemaTrailerVideo" controls playsinline
               preload="none" aria-label="Bande-annonce de M'AS-TU VU ?">
          <?php if ($trailer !== ''): ?><source src="<?= h($trailer) ?>" type="video/mp4"><?php endif; ?>
          Votre navigateur ne prend pas en charge la lecture vidéo.
        </video>
        <?php endif; ?>
      </div>
    </div>

    <section class="film-catalog section-wrap" aria-label="Films à l’affiche">
      <div class="section-heading">
        <p class="eyebrow">PROGRAMMATION</p>
        <h2>Les films <span>à découvrir.</span></h2>
      </div>
      <div class="film-catalog-grid">
        <?php foreach ($catalogFilms as $catalogFilm): ?>
          <a class="film-catalog-card" href="#tickets" data-film-choice="<?= (int) $catalogFilm['id'] ?>">
            <img src="<?= h($catalogFilm['image_affiche'] ?: 'assets/affiche-mas-tu-vu.jpeg') ?>" alt="">
            <span><strong><?= h($catalogFilm['titre']) ?></strong><small><?= h($catalogFilm['description']) ?></small></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Bandeau d'urgence promotionnelle -->
    <section class="promo-strip section-wrap" aria-label="Offre promotionnelle">
      <span class="promo-icon" aria-hidden="true">%</span>
      <div><strong>RÉSERVATIONS OUVERTES</strong><p>Choisissez votre catégorie de pass pour <?= h($event['titre']) ?>.</p></div>
      <a href="#gp">Voir les pass <span aria-hidden="true">↗</span></a>
    </section>


<!-- SECTION : DISPONIBILITÉ DES BILLETS -->
<section class="inventory section-wrap" id="disponibilite">

  <div class="inventory-heading">
    <p class="eyebrow">DISPONIBILITÉ DES PASS</p>
    <h2>Votre place vous <span>attend.</span></h2>
    <p>Consultez les disponibilités avant de réserver.</p>
  </div>

  <div class="inventory-grid">
    <?php foreach ($tickets as $ticket): $key = strtolower($ticket['nom']); ?>
      <article class="inventory-card">
        <h3>PASS <?= h(mb_strtoupper($ticket['nom'])) ?></h3>
        <p class="stock-number"><strong id="<?= h($key) ?>-stock"><?= (int) $ticket['places_restantes'] ?></strong> places restantes</p>
        <div class="inventory-track"><span id="<?= h($key) ?>-stock-bar" style="width: <?= $ticket['capacite'] > 0 ? round(100 * $ticket['places_restantes'] / $ticket['capacite']) : 0 ?>%"></span></div>
        <p>Pass confirmés : <strong id="<?= h($key) ?>-sold"><?= (int) $ticket['tickets_confirmes'] ?></strong></p>
      </article>
    <?php endforeach; ?>
  </div>

  <p class="inventory-disclaimer">
    Les demandes en attente sont prises en compte dans les places restantes. Les pass ne sont confirmés qu’après validation de l’organisateur.
  </p>

</section>
<!-- FIN SECTION DISPONIBILITÉ -->


    <!-- Trois formulaires de réservation indépendants -->
    <section class="tickets section-wrap" id="tickets">
      <div class="section-heading">
        <p class="eyebrow">VOTRE ACCÈS À L'ÉVÉNEMENT</p>
        <h2>Choisissez votre <span>expérience.</span></h2>
        <p>Choisissez votre pass et envoyez une demande de réservation. Sa disponibilité sera vérifiée avant son enregistrement.</p>
      </div>

      <div class="reservation-selectors" aria-label="Choisir le film et la séance">
        <label>Film
          <select id="film-select">
            <?php
            $seenFilms = [];
            foreach ($publicSessions as $publicSession):
              if (isset($seenFilms[$publicSession['film_id']])) continue;
              $seenFilms[$publicSession['film_id']] = true;
            ?>
              <option value="<?= (int) $publicSession['film_id'] ?>" <?= (int) $event['film_id'] === (int) $publicSession['film_id'] ? 'selected' : '' ?>><?= h($publicSession['titre']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Séance
          <select id="session-select">
            <?php foreach ($publicSessions as $publicSession): ?>
              <option value="<?= (int) $publicSession['id'] ?>" data-film="<?= (int) $publicSession['film_id'] ?>" <?= (int) $event['id'] === (int) $publicSession['id'] ? 'selected' : '' ?>>
                <?= h(french_date($publicSession['date_seance'])) ?> • <?= h(substr($publicSession['heure_debut'], 0, 5)) ?> • <?= h($publicSession['salle']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <p class="selected-session-summary" id="selected-session-summary" aria-live="polite">
        <?= h($event['titre']) ?> — <?= h($eventDate) ?> à <?= h($eventTime) ?> • <?= h($event['salle']) ?>
      </p>

      <div class="ticket-grid">
        <?php foreach ($tickets as $index => $ticket):
          $name = $ticket['nom'];
          $key = strtolower($name);
          $copy = $ticketCopy[$name] ?? ['Découvrez cette catégorie de pass.', 'Accès à la séance', 'Demande de réservation', 'Confirmation par l’équipe'];
          $symbol = ['◈', '◆', '✦'][$index % 3];
          $remaining = (int) $ticket['places_restantes'];
        ?>
          <article class="ticket-card ticket-<?= h($key) ?>" id="<?= h($key) ?>" style="<?= $ticket['statut'] !== 'actif' ? 'display:none' : '' ?>">
            <div class="card-top"><span class="ticket-tag">PASS <?= sprintf('%02d', $index + 1) ?></span><span class="ticket-symbol" aria-hidden="true"><?= $symbol ?></span></div>
            <h3><?= h(mb_strtoupper($name)) ?></h3>
            <p class="ticket-desc"><?= h($copy[0]) ?></p>
            <div class="price-line"><strong><span data-ticket-price><?= number_format((float) $ticket['prix_ticket'], 0, ',', ' ') ?></span> <small>FCFA</small></strong></div>
            <span class="offer-label offer-neutral"><?= $remaining > 0 ? 'PLACES DISPONIBLES : ' . $remaining : 'COMPLET' ?></span>
            <ul class="benefits"><li><?= h($copy[1]) ?></li><li><?= h($copy[2]) ?></li><li><?= h($copy[3]) ?></li></ul>
            <form class="ticket-form" method="post" action="api/reservation.php" data-ticket-type="<?= (int) $ticket['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <label for="<?= h($key) ?>-name">Nom complet</label>
              <input id="<?= h($key) ?>-name" name="name" type="text" maxlength="160" placeholder="Votre nom complet" autocomplete="name" required>
              <label for="<?= h($key) ?>-email">Adresse e-mail</label>
              <input id="<?= h($key) ?>-email" name="email" type="email" maxlength="190" placeholder="vous@exemple.com" autocomplete="email" required>
              <label for="<?= h($key) ?>-phone">Votre numéro de téléphone</label>
              <input id="<?= h($key) ?>-phone" name="phone" type="tel" maxlength="40" placeholder="Ex. : 225 50 18 25 65" autocomplete="tel" required>
              <label for="<?= h($key) ?>-quantity">Nombre de pass</label>
              <select id="<?= h($key) ?>-quantity" name="quantity" required <?= $remaining < 1 || $ticket['statut'] !== 'actif' ? 'disabled' : '' ?>>
                <?php for ($quantity = 1; $quantity <= min(10, $remaining); $quantity++): ?>
                  <option value="<?= $quantity ?>"><?= $quantity ?> pass</option>
                <?php endfor; ?>
              </select>
              <p class="ticket-estimate">Total estimé : <strong data-total-estimate><?= number_format((float) $ticket['prix_ticket'], 0, ',', ' ') ?> FCFA</strong></p>
              <button class="ticket-submit" type="submit" <?= $remaining < 1 || $ticket['statut'] !== 'actif' ? 'disabled' : '' ?>>Demander mon <?= h($name) ?> <span aria-hidden="true">↗</span></button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="payment-note"><span aria-hidden="true">i</span> Votre demande ne vaut pas paiement ni confirmation. L’équipe la vérifiera et vous contactera pour confirmer la réservation et les modalités de règlement. Les coordonnées que vous fournissez sont transmises à l’organisateur pour traiter cette demande.</p>
      <p class="reservation-contact">Réservation directe sur WhatsApp :
        <a id="whatsapp-contact" href="<?= h($whatsappHref) ?>" target="_blank" rel="noopener noreferrer"><?= h($whatsappLabel) ?></a>
      </p>
    </section>

    <?php if ($publicTrailers): ?>
    <section class="public-trailers section-wrap">
      <div class="section-heading"><p class="eyebrow">À DÉCOUVRIR</p><h2>Actualités <span>et vidéos.</span></h2></div>
      <div class="public-trailer-grid">
        <?php foreach ($publicTrailers as $content):
          $media = (string) ($content['fichier_media'] ?? '');
          $embed = video_embed_url($media);
        ?>
          <article class="public-trailer-card">
            <?php if ($media !== '' && $embed): ?>
              <iframe src="<?= h($embed) ?>" title="<?= h($content['titre']) ?>" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
            <?php elseif ($media !== ''): ?>
              <video controls preload="metadata" <?= $content['miniature'] ? 'poster="' . h($content['miniature']) . '"' : '' ?>><source src="<?= h($media) ?>" type="video/mp4"></video>
            <?php elseif ($content['miniature']): ?>
              <img src="<?= h($content['miniature']) ?>" alt="">
            <?php endif; ?>
            <h3><?= h($content['titre']) ?></h3>
            <p><?= h($content['description'] ?? '') ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- Rappel pratique -->
    <section class="event-footer section-wrap">
      <div><p class="eyebrow">RENDEZ-VOUS LE <?= h(date('d.m.Y', strtotime($event['date_seance']))) ?></p><h2>Le grand écran.<br><span>Une seule question.</span></h2></div>
      <div class="event-footer-detail"><p><strong><?= h($event['titre']) ?></strong><?= $event['realisateur'] ? ' — Un film de ' . h($event['realisateur']) : '' ?></p><p><?= h($eventDate) ?> à <?= h($eventTime) ?></p><p><?= h($event['salle']) ?></p><a href="#tickets">Réserver un pass <span aria-hidden="true">↗</span></a></div>
    </section>
  </main>

  <footer class="footer">
    <a class="footer-brand" href="#accueil">ART SPIRIT STUDIO</a>
    <p>Avant-première du film M'AS-TU VU ? • 2026</p>
    <a class="admin-nav" href="admin/login.php">Espace administrateur</a>
    <a href="#accueil">Retour en haut ↑</a>
  </footer>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script src="script.js"></script>
</body>
</html>
