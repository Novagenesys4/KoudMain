-- ═══════════════════════════════════════════════════════════
--  BASE DE DONNÉES : gestion_service
--  Adapté pour MySQL (depuis SQL Server)
--  Modifications :
--    IDENTITY(1,1)  →  AUTO_INCREMENT
--    BIT            →  TINYINT(1)
--    GETDATE()      →  NOW()
--    VARCHAR(MAX)   →  TEXT
--    Ajout : mot_de_passe, est_valide, est_admin dans Utilisateur
-- ═══════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS gestion_service
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE gestion_service;

-- ─── 1. Références Géographiques ────────────────────────

CREATE TABLE Region (
    id_region      INT AUTO_INCREMENT PRIMARY KEY,
    nom_region     VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE Departement (
    id_departement  INT AUTO_INCREMENT PRIMARY KEY,
    nom_departement VARCHAR(100) NOT NULL,
    id_region       INT NOT NULL,
    CONSTRAINT FK_Region_Departement FOREIGN KEY (id_region) REFERENCES Region(id_region)
) ENGINE=InnoDB;

CREATE TABLE Ville (
    id_ville       INT AUTO_INCREMENT PRIMARY KEY,
    nom_ville      VARCHAR(100) NOT NULL,
    id_departement INT NOT NULL,
    CONSTRAINT FK_Dept_Ville FOREIGN KEY (id_departement) REFERENCES Departement(id_departement)
) ENGINE=InnoDB;

CREATE TABLE Quartier (
    id_quartier  INT AUTO_INCREMENT PRIMARY KEY,
    nom_quartier VARCHAR(150) NOT NULL,
    id_ville     INT NOT NULL,
    CONSTRAINT FK_Ville_Quartier FOREIGN KEY (id_ville) REFERENCES Ville(id_ville)
) ENGINE=InnoDB;

-- ─── 2. Utilisateurs & Catégories ───────────────────────

CREATE TABLE Categorie (
    id_categorie  INT AUTO_INCREMENT PRIMARY KEY,
    nom_categorie VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE Utilisateur (
    id_utilisateur      INT AUTO_INCREMENT PRIMARY KEY,
    email_utilisateur   VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe        VARCHAR(255) NOT NULL,         -- Ajouté pour la connexion
    est_prestataire     TINYINT(1) DEFAULT 0,
    est_client          TINYINT(1) DEFAULT 1,
    est_admin           TINYINT(1) DEFAULT 0,          -- Ajouté pour l'admin
    est_valide          TINYINT(1) DEFAULT 0,          -- Validation par l'admin
    datecrea_utilisateur DATETIME DEFAULT NOW(),
    nom_utilisateur     VARCHAR(50) NOT NULL,
    prenom_utilisateur  VARCHAR(100) NOT NULL,
    num_utilisateur     VARCHAR(15) NOT NULL,
    id_quartier         INT NOT NULL,
    CONSTRAINT FK_Quartier_User FOREIGN KEY (id_quartier) REFERENCES Quartier(id_quartier)
) ENGINE=InnoDB;

CREATE TABLE Service (
    id_service   INT AUTO_INCREMENT PRIMARY KEY,
    nom_service  VARCHAR(100) NOT NULL,
    id_categorie INT NOT NULL,
    CONSTRAINT FK_Cat_Service FOREIGN KEY (id_categorie) REFERENCES Categorie(id_categorie)
) ENGINE=InnoDB;

-- ─── 3. Prestations & Commandes ─────────────────────────

CREATE TABLE Prestation (
    id_prestation        INT AUTO_INCREMENT PRIMARY KEY,
    titre_prestation     VARCHAR(150),
    description_prestation TEXT,
    prix_prestation      DECIMAL(10,2),
    datecrea_prestation  DATETIME DEFAULT NOW(),
    id_service           INT NOT NULL,
    id_utilisateur       INT NOT NULL,
    CONSTRAINT FK_Serv_Prest FOREIGN KEY (id_service) REFERENCES Service(id_service),
    CONSTRAINT FK_User_Prest FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur)
) ENGINE=InnoDB;

CREATE TABLE Commande (
    id_commande    INT AUTO_INCREMENT PRIMARY KEY,
    date_commande  DATETIME DEFAULT NOW(),
    montant_total  DECIMAL(10,2) NOT NULL,
    statut         VARCHAR(50) NOT NULL DEFAULT 'En attente',
    id_quartier    INT NOT NULL,
    id_utilisateur INT NOT NULL,
    CONSTRAINT FK_Quartier_Cmd FOREIGN KEY (id_quartier) REFERENCES Quartier(id_quartier),
    CONSTRAINT FK_User_Cmd    FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur)
) ENGINE=InnoDB;

-- ─── 4. Cibler (détail commande + avis) ─────────────────

CREATE TABLE Cibler (
    id_prestation INT NOT NULL,
    id_commande   INT NOT NULL,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    evaluation    INT CHECK (evaluation BETWEEN 0 AND 5),
    commentaire   TEXT,
    quantite      INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id_prestation, id_commande),
    CONSTRAINT FK_Prest_Cible FOREIGN KEY (id_prestation) REFERENCES Prestation(id_prestation),
    CONSTRAINT FK_Cmd_Cible   FOREIGN KEY (id_commande)   REFERENCES Commande(id_commande)
) ENGINE=InnoDB;

-- ─── 5. Données initiales ────────────────────────────────

-- Compte admin par défaut (mot de passe : admin123)
INSERT INTO Region (nom_region) VALUES ('Abidjan'), ('Bouaké'), ('Yamoussoukro');

INSERT INTO Departement (nom_departement, id_region)
VALUES ('Cocody', 1), ('Plateau', 1), ('Yopougon', 1);

INSERT INTO Ville (nom_ville, id_departement)
VALUES ('Abidjan', 1), ('Abidjan', 2), ('Abidjan', 3);

INSERT INTO Quartier (nom_quartier, id_ville)
VALUES ('Cocody Riviera', 1), ('Plateau Centre', 2), ('Yopougon Siporex', 3),
       ('Cocody Angré', 1), ('Deux Plateaux', 1);

INSERT INTO Categorie (nom_categorie)
VALUES ('Beaute et Coiffure'), ('Plomberie et Sanitaire'),
       ('Laverie et Pressing'), ('Garde d enfants'), ('Cuisine et Traiteur'),
       ('Electricite'), ('Jardinage'), ('Demenagement');

INSERT INTO Service (nom_service, id_categorie) VALUES
  ('Coiffure femme', 1), ('Coiffure homme', 1), ('Manucure', 1),
  ('Reparation fuite', 2), ('Installation sanitaire', 2),
  ('Lavage vetements', 3), ('Pressing costume', 3),
  ('Garde a domicile', 4), ('Sortie ecole', 4),
  ('Cuisine a domicile', 5), ('Traiteur evenement', 5);

-- Admin : mot de passe = password
INSERT INTO Utilisateur
  (email_utilisateur, mot_de_passe, est_prestataire, est_client, est_admin, est_valide,
   nom_utilisateur, prenom_utilisateur, num_utilisateur, id_quartier)
VALUES
  ('admin@service.ci',
   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
   0, 0, 1, 1, 'Admin', 'Plateforme', '0700000000', 1);
