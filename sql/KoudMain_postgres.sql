-- ===========================================================
--  KOUDMAIN - Schéma PostgreSQL complet pour Supabase
--  Version nettoyée et adaptée depuis MySQL
-- ===========================================================
-- Instructions :
-- 1. Ouvre Supabase → SQL Editor → New query
-- 2. Colle tout ce fichier et exécute (Run)
-- 3. Vérifie dans Table Editor que les tables et données sont présentes
-- ===========================================================

-- -----------------------------------------------------------
-- 1. TABLES DE RÉFÉRENCE GÉOGRAPHIQUES
-- -----------------------------------------------------------

CREATE TABLE Region (
    id_region      SERIAL PRIMARY KEY,
    nom_region     VARCHAR(100) NOT NULL
);

CREATE TABLE Departement (
    id_departement  SERIAL PRIMARY KEY,
    nom_departement VARCHAR(100) NOT NULL,
    id_region       INT NOT NULL REFERENCES Region(id_region)
);

CREATE TABLE Ville (
    id_ville       SERIAL PRIMARY KEY,
    nom_ville      VARCHAR(100) NOT NULL,
    id_departement INT NOT NULL REFERENCES Departement(id_departement)
);

CREATE TABLE Quartier (
    id_quartier  SERIAL PRIMARY KEY,
    nom_quartier VARCHAR(150) NOT NULL,
    id_ville     INT NOT NULL REFERENCES Ville(id_ville)
);

-- -----------------------------------------------------------
-- 2. CATÉGORIES ET SERVICES
-- -----------------------------------------------------------

CREATE TABLE Categorie (
    id_categorie  SERIAL PRIMARY KEY,
    nom_categorie VARCHAR(100) NOT NULL
);

CREATE TABLE Service (
    id_service      SERIAL PRIMARY KEY,
    nom_service     VARCHAR(100) NOT NULL,
    id_categorie    INT NOT NULL REFERENCES Categorie(id_categorie)
);

-- -----------------------------------------------------------
-- 3. UTILISATEURS
-- -----------------------------------------------------------

CREATE TABLE Utilisateur (
    id_utilisateur       SERIAL PRIMARY KEY,
    email_utilisateur    VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe         VARCHAR(255) NOT NULL,
    est_prestataire      BOOLEAN NOT NULL DEFAULT FALSE,
    est_client           BOOLEAN NOT NULL DEFAULT TRUE,
    est_admin            BOOLEAN NOT NULL DEFAULT FALSE,
    est_valide           BOOLEAN NOT NULL DEFAULT FALSE,
    datecrea_utilisateur TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nom_utilisateur      VARCHAR(50) NOT NULL,
    prenom_utilisateur   VARCHAR(100) NOT NULL,
    num_utilisateur      VARCHAR(15) NOT NULL,
    id_quartier          INT NOT NULL REFERENCES Quartier(id_quartier)
);

-- -----------------------------------------------------------
-- 4. PRESTATIONS
-- -----------------------------------------------------------

CREATE TABLE Prestation (
    id_prestation          SERIAL PRIMARY KEY,
    titre_prestation       VARCHAR(150),
    description_prestation TEXT,
    prix_prestation        DECIMAL(10,2),
    datecrea_prestation    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_service             INT NOT NULL REFERENCES Service(id_service),
    id_utilisateur         INT NOT NULL REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE
);

-- -----------------------------------------------------------
-- 5. COMMANDES
-- -----------------------------------------------------------

CREATE TABLE Commande (
    id_commande    SERIAL PRIMARY KEY,
    date_commande  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    montant_total  DECIMAL(10,2) NOT NULL,
    statut         VARCHAR(50) NOT NULL DEFAULT 'En attente',
    id_quartier    INT NOT NULL REFERENCES Quartier(id_quartier),
    id_utilisateur INT NOT NULL REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE
);

-- -----------------------------------------------------------
-- 6. LIAISON PRESTATION ↔ COMMANDE (Cibler)
-- -----------------------------------------------------------

CREATE TABLE Cibler (
    id_prestation INT NOT NULL REFERENCES Prestation(id_prestation) ON DELETE CASCADE,
    id_commande   INT NOT NULL REFERENCES Commande(id_commande) ON DELETE CASCADE,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    evaluation    INT CHECK (evaluation IS NULL OR (evaluation BETWEEN 0 AND 5)),
    commentaire   TEXT,
    quantite      INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id_prestation, id_commande)
);

-- -----------------------------------------------------------
-- 7. WALLET ET TRANSACTIONS
-- -----------------------------------------------------------

CREATE TABLE Wallet (
    id_wallet       SERIAL PRIMARY KEY,
    id_utilisateur  INT NOT NULL UNIQUE REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE,
    solde           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_maj        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Trigger pour mettre à jour automatiquement date_maj
CREATE OR REPLACE FUNCTION update_wallet_date_maj()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_maj = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_wallet_date_maj
    BEFORE UPDATE ON Wallet
    FOR EACH ROW
    EXECUTE FUNCTION update_wallet_date_maj();

CREATE TABLE Transaction_Wallet (
    id_transaction   SERIAL PRIMARY KEY,
    id_wallet        INT NOT NULL REFERENCES Wallet(id_wallet) ON DELETE CASCADE,
    type_transaction TEXT NOT NULL CHECK (type_transaction IN ('credit', 'debit', 'retrait')),
    montant          DECIMAL(12,2) NOT NULL,
    libelle          VARCHAR(200) NOT NULL,
    solde_apres      DECIMAL(12,2) NOT NULL,
    id_commande      INT REFERENCES Commande(id_commande) ON DELETE SET NULL,
    date_transaction TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------
-- 8. DONNÉES INITIALES
-- -----------------------------------------------------------

-- Régions
INSERT INTO Region (nom_region) VALUES
    ('Abidjan'),
    ('Lagunes'),
    ('Comoé'),
    ('Sud-Comoé'),
    ('Agnéby-Tiassa'),
    ('Grands-Ponts'),
    ('Nawa'),
    ('San-Pédro'),
    ('Gôh'),
    ('Marahoué'),
    ('Bouaké'),
    ('Poro'),
    ('Denguélé');

-- Départements
INSERT INTO Departement (nom_departement, id_region) VALUES
    ('Abidjan', 1),
    ('Dabou', 2),
    ('Grand-Lahou', 2),
    ('Jacqueville', 2),
    ('Tiassalé', 2),
    ('Abengourou', 3),
    ('Agnibilékrou', 3),
    ('Adiaké', 4),
    ('Aboisso', 4),
    ('Grand-Bassam', 4),
    ('Agboville', 5),
    ('Azaguié', 5),
    ('Dabou', 6),
    ('Jacqueville', 6),
    ('Soubré', 7),
    ('Guéyo', 7),
    ('San-Pédro', 8),
    ('Tabou', 8),
    ('Gagnoa', 9),
    ('Oumé', 9),
    ('Bouaflé', 10),
    ('Sinfra', 10),
    ('Bouaké', 11),
    ('Béoumi', 11),
    ('Korhogo', 12),
    ('Ferkessédougou', 12),
    ('Odienné', 13),
    ('Minignan', 13);

-- Villes
INSERT INTO Ville (nom_ville, id_departement) VALUES
    ('Abidjan', 1),
    ('Dabou', 2),
    ('Grand-Lahou', 3),
    ('Jacqueville', 4),
    ('Tiassalé', 5),
    ('Abengourou', 6),
    ('Agnibilékrou', 7),
    ('Adiaké', 8),
    ('Aboisso', 9),
    ('Grand-Bassam', 10),
    ('Agboville', 11),
    ('Azaguié', 12),
    ('Soubré', 15),
    ('San-Pédro', 17),
    ('Gagnoa', 19),
    ('Bouaflé', 21),
    ('Bouaké', 23),
    ('Korhogo', 25),
    ('Odienné', 27);

-- Quartiers
INSERT INTO Quartier (nom_quartier, id_ville) VALUES
    ('Cocody Riviera 1', 1),
    ('Cocody Angré', 1),
    ('Cocody 2 Plateaux', 1),
    ('Plateau Centre', 1),
    ('Yopougon Maroc', 1),
    ('Marcory Zone 4', 1),
    ('Koumassi', 1),
    ('Port-Bouët', 1),
    ('Adjamé', 1),
    ('Abobo Gare', 1),
    ('Dabou Centre', 2),
    ('Grand-Bassam France', 10),
    ('San-Pédro Port', 14),
    ('Bouaké Centre', 17),
    ('Korhogo Commerce', 18);

-- Catégories
INSERT INTO Categorie (nom_categorie) VALUES
    ('Beauté et Coiffure'),
    ('Plomberie et Sanitaire'),
    ('Laverie et Pressing'),
    ('Garde d''enfants'),
    ('Cuisine et Traiteur'),
    ('Électricité'),
    ('Jardinage'),
    ('Déménagement'),
    ('Informatique'),
    ('Mécanique');

-- Services
INSERT INTO Service (nom_service, id_categorie) VALUES
    ('Coiffure femme', 1),
    ('Coiffure homme', 1),
    ('Manucure', 1),
    ('Réparation fuite', 2),
    ('Installation sanitaire', 2),
    ('Lavage vêtements', 3),
    ('Repassage', 3),
    ('Garde à domicile', 4),
    ('Baby-sitting', 4),
    ('Cuisine à domicile', 5),
    ('Traiteur événement', 5),
    ('Dépannage informatique', 9),
    ('Réparation smartphone', 9);

-- Compte administrateur par défaut
-- Email    : admin@service.ci
-- Mot de passe : password
INSERT INTO Utilisateur (
    email_utilisateur,
    mot_de_passe,
    est_prestataire,
    est_client,
    est_admin,
    est_valide,
    nom_utilisateur,
    prenom_utilisateur,
    num_utilisateur,
    id_quartier
) VALUES (
    'admin@service.ci',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    FALSE,
    TRUE,
    TRUE,
    TRUE,
    'Admin',
    'KoudMain',
    '0700000000',
    1
);

-- Créer un wallet pour chaque utilisateur existant
INSERT INTO Wallet (id_utilisateur, solde)
SELECT id_utilisateur, 0.00
FROM Utilisateur
ON CONFLICT (id_utilisateur) DO NOTHING;

-- Créditer le wallet de l'administrateur pour les tests (50 000 FCFA)
UPDATE Wallet
SET solde = 50000.00
WHERE id_utilisateur = (
    SELECT id_utilisateur FROM Utilisateur WHERE est_admin = TRUE LIMIT 1
);

-- ===========================================================
-- Fin du script
-- ===========================================================
-- Vérifications utiles après exécution :
-- SELECT COUNT(*) FROM Region;          -- doit retourner 13
-- SELECT COUNT(*) FROM Departement;     -- doit retourner 28
-- SELECT COUNT(*) FROM Ville;           -- doit retourner 19
-- SELECT COUNT(*) FROM Quartier;        -- doit retourner 15
-- SELECT COUNT(*) FROM Categorie;       -- doit retourner 10
-- SELECT COUNT(*) FROM Service;         -- doit retourner 13
-- SELECT * FROM Utilisateur;            -- doit contenir l'admin
-- SELECT * FROM Wallet;                 -- solde admin = 50000.00
-- ===========================================================