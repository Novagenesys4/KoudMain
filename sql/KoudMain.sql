-- ===========================================================
--  BASE DE DONNÉES : koudmain_db
-- ===========================================================

CREATE DATABASE IF NOT EXISTS koudmain_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE koudmain_db;

--  1. STRUCTURE DES TABLES ---

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

CREATE TABLE Categorie (
    id_categorie  INT AUTO_INCREMENT PRIMARY KEY,
    nom_categorie VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE Utilisateur (
    id_utilisateur      INT AUTO_INCREMENT PRIMARY KEY,
    email_utilisateur   VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe        VARCHAR(255) NOT NULL,
    est_prestataire     TINYINT(1) DEFAULT 0,
    est_client          TINYINT(1) DEFAULT 1,
    est_admin           TINYINT(1) DEFAULT 0,
    est_valide          TINYINT(1) DEFAULT 0,
    datecrea_utilisateur DATETIME DEFAULT NOW(),
    nom_utilisateur     VARCHAR(50) NOT NULL,
    prenom_utilisateur  VARCHAR(100) NOT NULL,
    num_utilisateur     VARCHAR(15) NOT NULL,
    id_quartier         INT NOT NULL,
    CONSTRAINT FK_Quartier_User FOREIGN KEY (id_quartier) REFERENCES Quartier(id_quartier)
) ENGINE=InnoDB;

CREATE TABLE Service (
    id_service      INT AUTO_INCREMENT PRIMARY KEY,
    nom_service     VARCHAR(100) NOT NULL,
    id_categorie    INT NOT NULL,
    CONSTRAINT FK_Cat_Service FOREIGN KEY (id_categorie) REFERENCES Categorie(id_categorie)
) ENGINE=InnoDB;

CREATE TABLE Prestation (
    id_prestation         INT AUTO_INCREMENT PRIMARY KEY,
    titre_prestation      VARCHAR(150),
    description_prestation TEXT,
    prix_prestation       DECIMAL(10,2),
    datecrea_prestation   DATETIME DEFAULT NOW(),
    id_service            INT NOT NULL,
    id_utilisateur        INT NOT NULL,
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
    CONSTRAINT FK_User_Cmd     FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur)
) ENGINE=InnoDB;

CREATE TABLE Cibler (
    id_prestation INT NOT NULL,
    id_commande   INT NOT NULL,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    evaluation    INT CHECK (evaluation BETWEEN 0 AND 5),
    commentaire   TEXT,
    quantite      INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id_prestation, id_commande),
    CONSTRAINT FK_Prest_Cible FOREIGN KEY (id_prestation) REFERENCES Prestation(id_prestation),
    CONSTRAINT FK_Cmd_Cible   FOREIGN KEY (id_commande) REFERENCES Commande(id_commande)
) ENGINE=InnoDB;

--  2. DONNÉES INITIALES ---

-- Régions
INSERT INTO Region (nom_region) VALUES 
('Abidjan'), ('Lagunes'), ('Comoé'), ('Sud-Comoé'), ('Agnéby-Tiassa'), 
('Grands-Ponts'), ('Nawa'), ('San-Pédro'), ('Gôh'), ('Marahoué'), 
('Bouaké'), ('Poro'), ('Denguélé');

-- Départements (Liaison avec les IDs de Region)
INSERT INTO Departement (nom_departement, id_region) VALUES 
('Abidjan', 1), ('Dabou', 2), ('Grand-Lahou', 2), ('Jacqueville', 2), ('Tiassalé', 2),
('Abengourou', 3), ('Agnibilékrou', 3), ('Adiaké', 4), ('Aboisso', 4), ('Grand-Bassam', 4),
('Agboville', 5), ('Azaguié', 5), ('Dabou', 6), ('Jacqueville', 6), ('Soubré', 7),
('Guéyo', 7), ('San-Pédro', 8), ('Tabou', 8), ('Gagnoa', 9), ('Oumé', 9),
('Bouaflé', 10), ('Sinfra', 10), ('Bouaké', 11), ('Béoumi', 11), ('Korhogo', 12),
('Ferkessédougou', 12), ('Odienné', 13), ('Minignan', 13);

-- Villes
INSERT INTO Ville (nom_ville, id_departement) VALUES 
('Abidjan', 1), ('Dabou', 2), ('Grand-Lahou', 3), ('Jacqueville', 4), ('Tiassalé', 5),
('Abengourou', 6), ('Agnibilékrou', 7), ('Adiaké', 8), ('Aboisso', 9), ('Grand-Bassam', 10),
('Agboville', 11), ('Azaguié', 12), ('Soubré', 15), ('San-Pédro', 17), ('Gagnoa', 19), 
('Bouaflé', 21), ('Bouaké', 23), ('Korhogo', 25), ('Odienné', 27);

-- Quartiers (Sélection stratégique)
INSERT INTO Quartier (nom_quartier, id_ville) VALUES 
('Cocody Riviera 1', 1), ('Cocody Angré', 1), ('Cocody 2 Plateaux', 1), ('Plateau Centre', 1),
('Yopougon Maroc', 1), ('Marcory Zone 4', 1), ('Koumassi', 1), ('Port-Bouët', 1),
('Adjamé', 1), ('Abobo Gare', 1), ('Dabou Centre', 2), ('Grand-Bassam France', 10),
('San-Pédro Port', 14), ('Bouaké Centre', 17), ('Korhogo Commerce', 18);

-- Catégories
INSERT INTO Categorie (nom_categorie) VALUES 
('Beauté et Coiffure'), ('Plomberie et Sanitaire'), ('Laverie et Pressing'), 
('Garde d''enfants'), ('Cuisine et Traiteur'), ('Électricité'), 
('Jardinage'), ('Déménagement'), ('Informatique'), ('Mécanique');

-- Services
INSERT INTO Service (nom_service, id_categorie) VALUES 
('Coiffure femme', 1), ('Coiffure homme', 1), ('Manucure', 1),
('Réparation fuite', 2), ('Installation sanitaire', 2),
('Lavage vêtements', 3), ('Repassage', 3),
('Garde à domicile', 4), ('Baby-sitting', 4),
('Cuisine à domicile', 5), ('Traiteur événement', 5),
('Dépannage informatique', 9), ('Réparation smartphone', 9);

-- Admin (Compte par défaut : admin@service.ci / password)
INSERT INTO Utilisateur 
  (email_utilisateur, mot_de_passe, est_prestataire, est_client, est_admin, est_valide, 
   nom_utilisateur, prenom_utilisateur, num_utilisateur, id_quartier)
VALUES 
  ('admin@service.ci', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
   0, 1, 1, 1, 'Admin', 'KoudMain', '0700000000', 1);

ALTER TABLE Commande DROP FOREIGN KEY FK_User_Cmd;
ALTER TABLE Commande 
ADD CONSTRAINT FK_User_Cmd 
FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur) 
ON DELETE CASCADE;
ALTER TABLE Prestation DROP FOREIGN KEY FK_User_Prest;
ALTER TABLE Prestation 
ADD CONSTRAINT FK_User_Prest 
FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur) 
ON DELETE CASCADE;

-- Pour les avis/détails liés aux prestations
ALTER TABLE Cibler DROP FOREIGN KEY FK_Prest_Cible;
ALTER TABLE Cibler ADD CONSTRAINT FK_Prest_Cible 
FOREIGN KEY (id_prestation) REFERENCES Prestation(id_prestation) ON DELETE CASCADE;

-- Pour les avis/détails liés aux commandes
ALTER TABLE Cibler DROP FOREIGN KEY FK_Cmd_Cible;
ALTER TABLE Cibler ADD CONSTRAINT FK_Cmd_Cible 
FOREIGN KEY (id_commande) REFERENCES Commande(id_commande) ON DELETE CASCADE;

    -- --- Table Wallet (1 wallet par utilisateur) ---
CREATE TABLE IF NOT EXISTS Wallet (
    id_wallet       INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur  INT NOT NULL UNIQUE,
    solde           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_creation   DATETIME DEFAULT NOW(),
    date_maj        DATETIME DEFAULT NOW() ON UPDATE NOW(),
    CONSTRAINT FK_User_Wallet FOREIGN KEY (id_utilisateur)
        REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Table Transaction --- --
CREATE TABLE IF NOT EXISTS Transaction_Wallet (
    id_transaction  INT AUTO_INCREMENT PRIMARY KEY,
    id_wallet       INT NOT NULL,
    type_transaction ENUM('credit','debit','retrait') NOT NULL,
    montant         DECIMAL(12,2) NOT NULL,
    libelle         VARCHAR(200) NOT NULL,
    solde_apres     DECIMAL(12,2) NOT NULL,
    id_commande     INT DEFAULT NULL,          -- lié à une commande si paiement
    date_transaction DATETIME DEFAULT NOW(),
    CONSTRAINT FK_Wallet_Trans FOREIGN KEY (id_wallet)
        REFERENCES Wallet(id_wallet) ON DELETE CASCADE,
    CONSTRAINT FK_Cmd_Trans FOREIGN KEY (id_commande)
        REFERENCES Commande(id_commande) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Créer automatiquement un wallet pour les utilisateurs existants ──
INSERT IGNORE INTO Wallet (id_utilisateur, solde)
SELECT id_utilisateur, 0.00 FROM Utilisateur;

-- ── Wallet de démo : créditer l'admin pour tester ───────────
UPDATE Wallet w
JOIN Utilisateur u ON w.id_utilisateur = u.id_utilisateur
SET w.solde = 50000.00
WHERE u.est_admin = 1;
