CREATE DATABASE IF NOT EXISTS cinema_billetterie
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cinema_billetterie;

CREATE TABLE IF NOT EXISTS administrateurs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  mot_de_passe_hash VARCHAR(255) NOT NULL,
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  derniere_connexion DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS films (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  slug VARCHAR(220) NOT NULL UNIQUE,
  description TEXT NOT NULL,
  image_affiche VARCHAR(500) NULL,
  image_banniere VARCHAR(500) NULL,
  video_bande_annonce VARCHAR(1000) NULL,
  duree SMALLINT UNSIGNED NULL,
  categorie VARCHAR(120) NULL,
  realisateur VARCHAR(190) NULL,
  acteurs TEXT NULL,
  date_sortie DATE NULL,
  statut ENUM('brouillon','publie','archive') NOT NULL DEFAULT 'brouillon',
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modification TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_films_statut_sortie (statut, date_sortie)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  film_id BIGINT UNSIGNED NOT NULL,
  date_seance DATE NOT NULL,
  heure_debut TIME NOT NULL,
  heure_fin TIME NULL,
  salle VARCHAR(120) NOT NULL,
  capacite_totale INT UNSIGNED NOT NULL,
  statut ENUM('ouverte','annulee','terminee') NOT NULL DEFAULT 'ouverte',
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_seances_film FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE RESTRICT,
  INDEX idx_seances_date_statut (date_seance, statut)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS types_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seance_id BIGINT UNSIGNED NOT NULL,
  nom VARCHAR(80) NOT NULL,
  prix_ticket DECIMAL(12,2) UNSIGNED NOT NULL,
  capacite INT UNSIGNED NOT NULL,
  statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
  CONSTRAINT fk_types_tickets_seance FOREIGN KEY (seance_id) REFERENCES seances(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ticket_type_session (seance_id, nom),
  UNIQUE KEY uq_ticket_type_id_seance (id, seance_id),
  INDEX idx_types_tickets_seance_statut (seance_id, statut)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_reservation VARCHAR(32) NOT NULL UNIQUE,
  seance_id BIGINT UNSIGNED NOT NULL,
  type_ticket_id BIGINT UNSIGNED NOT NULL,
  nom_client VARCHAR(160) NOT NULL,
  email_client VARCHAR(190) NOT NULL,
  telephone_client VARCHAR(40) NOT NULL,
  nombre_tickets SMALLINT UNSIGNED NOT NULL,
  prix_unitaire DECIMAL(12,2) UNSIGNED NOT NULL,
  montant_total DECIMAL(14,2) UNSIGNED NOT NULL,
  statut_reservation ENUM('en_attente','confirmee','annulee') NOT NULL DEFAULT 'en_attente',
  date_reservation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservations_seance FOREIGN KEY (seance_id) REFERENCES seances(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_type_session FOREIGN KEY (type_ticket_id, seance_id) REFERENCES types_tickets(id, seance_id) ON DELETE RESTRICT,
  INDEX idx_reservations_statut_date (statut_reservation, date_reservation),
  INDEX idx_reservations_client (nom_client, email_client),
  INDEX idx_reservations_seance_type_statut (seance_id, type_ticket_id, statut_reservation),
  CONSTRAINT chk_reservations_tickets CHECK (nombre_tickets > 0),
  CONSTRAINT chk_reservations_total CHECK (montant_total = prix_unitaire * nombre_tickets)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifiant_visiteur CHAR(64) NOT NULL,
  page_visitee VARCHAR(255) NOT NULL,
  date_visite TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  adresse_ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  INDEX idx_visites_date_visitor (date_visite, identifiant_visiteur),
  INDEX idx_visites_date (date_visite)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parametres (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom_parametre VARCHAR(120) NOT NULL UNIQUE,
  valeur_parametre TEXT NOT NULL,
  date_modification TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contenus (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  description TEXT NULL,
  type_contenu ENUM('bande_annonce','actualite','media') NOT NULL DEFAULT 'bande_annonce',
  fichier_media VARCHAR(1000) NULL,
  miniature VARCHAR(500) NULL,
  statut ENUM('brouillon','publie') NOT NULL DEFAULT 'brouillon',
  date_publication DATETIME NULL,
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contenus_type_statut (type_contenu, statut, date_publication)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tentatives_connexion (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  adresse_hash CHAR(64) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  date_tentative TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_connexion_limiter (adresse_hash, email_hash, date_tentative)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS utilisateurs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  google_sub VARCHAR(255) NOT NULL UNIQUE,
  nom VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  derniere_connexion DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS connexions_utilisateurs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id BIGINT UNSIGNED NOT NULL,
  date_connexion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_connexions_utilisateur
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
  INDEX idx_connexions_date (date_connexion),
  INDEX idx_connexions_utilisateur_date (utilisateur_id, date_connexion)
) ENGINE=InnoDB;

INSERT IGNORE INTO films
  (id, titre, slug, description, image_affiche, image_banniere, video_bande_annonce, realisateur, statut)
VALUES
  (1, 'M’AS-TU VU ?', 'mas-tu-vu', 'Découvrez le film de Félix Assahoua lors d’une soirée cinéma exceptionnelle.', 'assets/affiche-mas-tu-vu.jpeg', NULL, 'assets/Videos/bande d’annonce.mp4', 'Félix Assahoua', 'publie');

INSERT IGNORE INTO seances
  (id, film_id, date_seance, heure_debut, salle, capacite_totale, statut)
VALUES
  (1, 1, '2026-12-12', '17:00:00', 'Jeckad Espace Événementiel', 170, 'ouverte');

INSERT IGNORE INTO types_tickets (seance_id, nom, prix_ticket, capacite, statut)
VALUES
  (1, 'GP', 3000, 100, 'actif'),
  (1, 'VIP', 10000, 50, 'actif'),
  (1, 'VVIP', 20000, 20, 'actif');

INSERT IGNORE INTO parametres (nom_parametre, valeur_parametre)
VALUES
  ('timezone', 'Africa/Abidjan'),
  ('visits_retention_days', '90'),
  ('event_location', 'Jeckad Espace Événementiel'),
  ('whatsapp_number', '22550182565');
