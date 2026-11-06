# M'AS-TU VU ? — Billetterie cinéma PHP / MySQL

## Analyse et architecture retenue

Le projet d'origine est une page unique `index.html` stylée par `style.css` et animée par `script.js`. Elle présente l'avant-première de M'AS-TU VU ?, son affiche et son MP4, ainsi que trois formulaires GP, VIP et VVIP. Le stock localStorage et le compte à rebours étaient des démonstrations et ne constituaient pas une réservation réelle.

Le design, les feuilles de style et les médias existants sont conservés. La page est devenue `index.php` pour pouvoir lire films, séances, prix et disponibilités depuis MySQL. Les formulaires créent désormais des demandes en attente via Fetch API; ils ne retirent plus de stock dans le navigateur et n'ouvrent pas automatiquement WhatsApp. Le nom, l'e-mail et le téléphone sont vérifiés côté serveur. Les demandes en attente et confirmées occupent une place; seules les confirmées comptent comme ventes et chiffre d'affaires. Les catégories GP/VIP/VVIP ont leurs propres capacités en plus de la capacité totale de la séance.

Fichiers conservés : `style.css`, `script.js`, `assets/affiche-mas-tu-vu.jpeg`, les images et la vidéo sous `assets/`.

Fichiers applicatifs : `index.php`, `config/database.php`, `includes/bootstrap.php`, `database/cinema_billetterie.sql`, `api/`, `admin/`, `admin.css` et `.htaccess`.

## Installation sur WampServer

1. Copiez le dossier dans `C:\wamp64\www\mas-tu-vu-ticket-site` (ou dans le dossier `www` de votre installation).
2. Démarrez Apache et MySQL depuis WampServer.
3. Ouvrez phpMyAdmin, puis importez `database/cinema_billetterie.sql`. Le script crée `cinema_billetterie`, les tables, les index et les relations. Il ajoute les informations déjà présentes dans le site : le film, sa séance du 12 décembre 2026 à 17 h et les capacités GP/VIP/VVIP 100/50/20 avec les prix actuellement affichés. Ce ne sont pas des réservations fictives.
4. Configurez MySQL avant le premier accès. Par défaut, `config/database.php` utilise `127.0.0.1:3306`, base `cinema_billetterie`, utilisateur `root` et mot de passe vide, valeurs courantes d'une installation Wamp locale. Pour tout autre environnement, définissez `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` et `DB_PASSWORD` dans la configuration Apache/PHP. Ne publiez pas de vrais identifiants dans un dépôt public.
5. Ouvrez `http://localhost/mas-tu-vu-ticket-site/admin/setup.php` sur la machine Wamp. Cette page n'accepte que les connexions locales et disparaît dès qu'un premier administrateur existe. Créez un mot de passe d'au moins 12 caractères, puis connectez-vous sur `admin/login.php`. En production, réalisez l'initialisation depuis un environnement local contrôlé et supprimez ou bloquez `admin/setup.php` après usage.
6. Ouvrez `http://localhost/mas-tu-vu-ticket-site/` pour le site public. Les pages d'administration sont sous `/admin/`.

PHP 8+, MySQL/InnoDB, PDO MySQL, mbstring, Fileinfo et cURL sont requis. Activez `file_uploads` et réglez `upload_max_filesize` et `post_max_size` à au moins 100 Mo si vous téléversez des vidéos MP4. Apache doit autoriser `.htaccess`; le module `mod_rewrite` n'est pas nécessaire. Le serveur doit pouvoir écrire dans `uploads/affiches`, `uploads/bannieres` et `uploads/videos`; ils sont créés au premier téléversement.

## Chemin de test complet

1. Démarrez **Apache** et **MySQL** dans Wamp, puis ouvrez `http://localhost/mas-tu-vu-ticket-site/`. Si la page indique qu'aucune séance n'est disponible, vérifiez la séance et le statut du film dans l'administration.
2. Ouvrez `http://localhost/mas-tu-vu-ticket-site/admin/setup.php` une seule fois pour créer l'administrateur, puis `http://localhost/mas-tu-vu-ticket-site/admin/login.php`.
3. Dans `admin/films.php`, créez ou publiez un film et ajoutez sa bande-annonce MP4; dans `admin/seances.php`, ajoutez une séance et les tarifs/capacités GP, VIP et VVIP. Testez aussi la bande-annonce depuis le bouton du site public : elle doit s'ouvrir dans le lecteur avec le son activé (le navigateur peut encore exiger un clic sur Lecture).
4. Depuis le site public, sélectionnez film, séance et catégorie, puis envoyez une demande avec un e-mail et téléphone de test. Vérifiez la référence, les places restantes et la demande `en_attente` dans `admin/reservations.php`; essayez sa confirmation puis son annulation et vérifiez le stock après chacune.
5. Publiez une vidéo ou actualité depuis `admin/contenus.php`, puis vérifiez son apparition sous « Actualités et vidéos ». Visitez le site plusieurs fois et contrôlez les statistiques et les connexions dans `admin/visiteurs.php`.
6. Après configuration de Google OAuth, visitez `http://localhost/mas-tu-vu-ticket-site/connexion.php`, connectez-vous avec un compte de test, vérifiez l'historique dans `utilisateur.php` et `admin/visiteurs.php`, utilisez « Retour au site public », puis testez « Se déconnecter ». En ouvrant directement `utilisateur.php` après déconnexion, vous devez revenir à la page de connexion.
7. Vérifiez la réception d'une alerte à `messiefidele@gmail.com` après une connexion Google et après une demande de réservation. Une visite anonyme ne génère pas d'adresse e-mail ni d'alerte nominative.
8. Testez l'affichage mobile avec les outils de développement du navigateur (largeurs 360 px, 768 px et ordinateur), ainsi que les formulaires et la vidéo sur téléphone.

### Connexion Google et courriels

La connexion Google est un OAuth 2.0 côté serveur (state, nonce, PKCE); aucun mot de passe Google ni jeton n'est enregistré. Après l'accord Google, le nom, l'adresse e-mail et l'identifiant Google du compte sont enregistrés, ainsi qu'une ligne par connexion dans `connexions_utilisateurs`. Les journaux sont conservés au maximum pendant la durée configurée pour les statistiques (90 jours par défaut). Le compte et son historique sont accessibles uniquement dans une session utilisateur authentifiée.

1. Dans Google Cloud Console, activez Google Identity, configurez l'écran de consentement et créez un identifiant OAuth de type « application Web ».
2. Ajoutez l'URI de redirection exacte `http://localhost/mas-tu-vu-ticket-site/connexion.php` aux URI de redirection autorisés pour les tests locaux. En production, utilisez exclusivement l'adresse HTTPS de votre site, par exemple `https://exemple.com/connexion.php`, et enregistrez exactement la même adresse dans Google Cloud.
3. Configurez côté serveur Apache/PHP `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` et `MAIL_FROM`. Conservez le secret Google hors du dossier public et hors du dépôt. Pour une configuration Wamp locale, utilisez les directives `SetEnv` dans le VirtualHost Apache et redémarrez Apache après modification. Le callback HTTP est accepté uniquement pour `localhost` ou `127.0.0.1`; ailleurs, HTTPS est obligatoire.
4. Activez cURL dans le `php.ini` utilisé par Apache, puis redémarrez les services Wamp. Le bouton « Connexion » ouvre l'authentification; le nouvel utilisateur arrive ensuite dans `utilisateur.php`, où il peut retourner au site public ou se déconnecter.
5. Configurez le transport de courrier du serveur PHP. Sous Wamp, `mail()` utilise les paramètres `SMTP`, `smtp_port` et `sendmail_from` du `php.ini` Windows. Utilisez un relais SMTP autorisé par votre fournisseur et vérifiez la livraison réelle; les fournisseurs exigeant TLS/authentification nécessitent un relais compatible ou une intégration SMTP adaptée. Les notifications destinées à `messiefidele@gmail.com` portent sur les connexions Google et les demandes de réservation, pas sur les visites anonymes.

Un visiteur anonyme n'a pas transmis son adresse e-mail : le site ne peut donc pas l'envoyer à l'administrateur. La fréquentation anonyme reste comptabilisée sous forme agrégée, sans conservation de l'adresse IP ou du navigateur. Les adresses fournies dans une réservation sont signalées comme utilisées pour le traitement de la demande et une alerte est envoyée à l'organisateur.

## Administration

- `admin/dashboard.php` : statistiques MySQL, visiteurs, réservations, chiffre confirmé, prochaines séances, séances presque complètes et dernières demandes; les cartes sont actualisées toutes les 30 secondes.
- `admin/films.php` : films, description, affiche, bannière, bande-annonce MP4 ou lien HTTPS YouTube/Vimeo, durée, acteurs, réalisateur et statut.
- `admin/seances.php` : séances, capacité de salle, horaires, statut et prix/capacités de chaque type de pass. Les capacités ne peuvent pas descendre sous les demandes actives ou confirmées.
- `admin/reservations.php` : recherche, historique paginé et passage entre attente, confirmée et annulée.
- `admin/contenus.php` : bandes-annonces et contenus publiés/brouillons, médias MP4, liens vidéo et miniatures. Les bandes-annonces publiées sont visibles sur le site public.
- `admin/visiteurs.php` : statistiques agrégées et purge selon la durée de conservation.
- `admin/parametres.php` : numéro de contact WhatsApp, durée de conservation et rappels de configuration des alertes.
- `connexion.php` et `utilisateur.php` : connexion Google, espace utilisateur, historique récent et déconnexion protégée.

## Sécurité et règles de disponibilité

PDO utilise les requêtes préparées. Les actions d'administration vérifient la session et un jeton CSRF; les mots de passe sont hachés avec `password_hash()`. Les fichiers téléversés sont contrôlés par MIME, taille et nom généré aléatoirement. Les liens externes vidéo sont limités à YouTube/Vimeo. Le site applique l'échappement HTML aux données affichées.

La création d'une demande utilise une transaction et verrouille les lignes de séance/catégorie pendant le contrôle du stock. Une référence unique est générée par demande. Les demandes en attente occupent des places jusqu'à une action manuelle de l'administrateur (confirmation ou annulation); il n'y a pas d'expiration automatique. Une annulation libère les places. Une demande en attente n'est pas une vente, un paiement ou un billet confirmé.

Le tableau de bord signale une augmentation des demandes en attente pendant qu’un administrateur y est connecté. Une alerte e-mail est tentée à chaque connexion Google et demande de réservation; son acheminement dépend de la configuration du relais du serveur. L'historique des connexions Google est consultable dans l'administration et par l'utilisateur concerné.

Les statistiques de fréquentation utilisent un identifiant aléatoire dans un cookie HttpOnly de 30 jours. Les adresses IP et agents utilisateurs ne sont pas conservés. Les lignes de visites sont purgées au-delà de 90 jours par défaut (modifiable entre 1 et 365 jours dans les paramètres). Adaptez votre information de confidentialité aux obligations applicables avant une mise en ligne publique.

## Vérifications recommandées avant production

- Configurer un compte MySQL dédié avec droits minimaux, mot de passe fort, HTTPS et sauvegardes.
- Vérifier la connexion Google avec un compte de test, l'écriture d'un enregistrement de connexion, l'accès refusé sans session et le retour/déconnexion vers le site public.
- Effectuer un test de livraison du courriel vers `messiefidele@gmail.com` pour une connexion et une réservation; contrôler également les journaux PHP en cas d'échec.
- Tester une demande en attente, sa confirmation, son annulation et les dernières places simultanément depuis plusieurs navigateurs.
- Contrôler les capacités, les prix, les horaires et les informations de contact dans l'administration.
- Supprimer `admin/setup.php` après initialisation et vérifier les permissions des dossiers téléversés.
- Passer en revue les mentions de confidentialité et de conservation applicables avant d'ouvrir la connexion Google et les réservations au public.
