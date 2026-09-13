-- ===========================================================
--  KOUDMAIN - Schéma PostgreSQL complet pour Supabase
--  Version fusionnée : structure + géographie CI complète
--  Idempotent : peut être rejoué sans doublons ni perte
--  Dernière mise à jour : géographie complète (toutes les régions)
-- ===========================================================
-- Instructions :
-- 1. Ouvre Supabase → SQL Editor → New query
-- 2. Colle tout ce fichier et exécute (Run)
-- 3. Vérifie dans Table Editor que les tables et données sont présentes
-- ===========================================================

-- -----------------------------------------------------------
-- 1. TABLES DE RÉFÉRENCE GÉOGRAPHIQUES
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Region (
    id_region      SERIAL PRIMARY KEY,
    nom_region     VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS Departement (
    id_departement  SERIAL PRIMARY KEY,
    nom_departement VARCHAR(100) NOT NULL,
    id_region       INT NOT NULL REFERENCES Region(id_region),
    UNIQUE (nom_departement, id_region)
);

CREATE TABLE IF NOT EXISTS Ville (
    id_ville       SERIAL PRIMARY KEY,
    nom_ville      VARCHAR(100) NOT NULL,
    id_departement INT NOT NULL REFERENCES Departement(id_departement),
    UNIQUE (nom_ville, id_departement)
);

CREATE TABLE IF NOT EXISTS Quartier (
    id_quartier  SERIAL PRIMARY KEY,
    nom_quartier VARCHAR(150) NOT NULL,
    id_ville     INT NOT NULL REFERENCES Ville(id_ville),
    UNIQUE (nom_quartier, id_ville)
);

-- -----------------------------------------------------------
-- 2. CATÉGORIES ET SERVICES
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Categorie (
    id_categorie  SERIAL PRIMARY KEY,
    nom_categorie VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS Service (
    id_service      SERIAL PRIMARY KEY,
    nom_service     VARCHAR(100) NOT NULL,
    id_categorie    INT NOT NULL REFERENCES Categorie(id_categorie),
    UNIQUE (nom_service, id_categorie)
);

-- -----------------------------------------------------------
-- 3. UTILISATEURS
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Utilisateur (
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
CREATE TABLE IF NOT EXISTS Prestation (
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
CREATE TABLE IF NOT EXISTS Commande (
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
CREATE TABLE IF NOT EXISTS Cibler (
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
CREATE TABLE IF NOT EXISTS Wallet (
    id_wallet       SERIAL PRIMARY KEY,
    id_utilisateur  INT NOT NULL UNIQUE REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE,
    solde           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_maj        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE OR REPLACE FUNCTION update_wallet_date_maj()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_maj = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_wallet_date_maj ON Wallet;
CREATE TRIGGER trg_wallet_date_maj
    BEFORE UPDATE ON Wallet
    FOR EACH ROW
    EXECUTE FUNCTION update_wallet_date_maj();

CREATE TABLE IF NOT EXISTS Transaction_Wallet (
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
-- 7b. CARTES VIRTUELLES (Wallet dashboard)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Carte_Virtuelle (
    id_carte          SERIAL PRIMARY KEY,
    id_wallet         INT NOT NULL REFERENCES Wallet(id_wallet) ON DELETE CASCADE,
    libelle           VARCHAR(80) NOT NULL DEFAULT 'Carte KoudMain',
    type_carte        TEXT NOT NULL CHECK (type_carte IN ('visa', 'mastercard')) DEFAULT 'visa',
    couleur           VARCHAR(40) NOT NULL DEFAULT 'emerald',
    numero_masque     VARCHAR(19) NOT NULL,
    nom_titulaire     VARCHAR(120) NOT NULL,
    date_expiration   VARCHAR(5) NOT NULL DEFAULT '12/28',
    est_principale    BOOLEAN NOT NULL DEFAULT FALSE,
    est_gelee         BOOLEAN NOT NULL DEFAULT FALSE,
    date_creation     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_carte_wallet ON Carte_Virtuelle(id_wallet);
CREATE INDEX IF NOT EXISTS idx_carte_gelee ON Carte_Virtuelle(id_wallet, est_gelee);

ALTER TABLE Transaction_Wallet
    ADD COLUMN IF NOT EXISTS id_carte INT REFERENCES Carte_Virtuelle(id_carte) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_tx_carte ON Transaction_Wallet(id_carte);

-- ===========================================================
-- 8. GÉOGRAPHIE — CÔTE D'IVOIRE
-- ===========================================================

-- 8.1 Régions
INSERT INTO Region (nom_region) VALUES
('Abidjan'),
('Yamoussoukro'),
('Gbêkê'),
('Hambol'),
('San-Pédro'),
('Gbôklé'),
('Nawa'),
('Haut-Sassandra'),
('Marahoué'),
('Poro'),
('Bagoué'),
('Tchologo'),
('Gôh'),
('Lôh-Djiboua'),
('Tonkpi'),
('Guémon'),
('Cavally'),
('Agnéby-Tiassa'),
('Grands-Ponts'),
('La Mé'),
('Sud-Comoé'),
('Indénié-Djuablin'),
('Gontougo'),
('Bounkani'),
('Bélier'),
('Iffou'),
('N''Zi'),
('Moronou'),
('Worodougou'),
('Béré'),
('Bafing'),
('Kabadougou'),
('Folon')
ON CONFLICT (nom_region) DO NOTHING;

-- 8.2 Départements / Villes / Quartiers (bloc PL/pgSQL idempotent - VERSION COMPLÈTE)
DO $$
DECLARE
    r_id INT;
    d_id INT;
    v_id INT;
BEGIN
    ---------------------------------------------------------
    -- ABIDJAN
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Abidjan';

    -- Abidjan Ville
    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Abidjan' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Abidjan', r_id) RETURNING id_departement INTO d_id;
    END IF;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Cocody)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Cocody)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Riviera 1'), ('Riviera 2'), ('Riviera 3'), ('Riviera 4'),
        ('Angré'), ('Deux Plateaux'), ('Danga'), ('Attoban'), ('M''Badon')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Yopougon)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Yopougon)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Niangon'), ('Selmer'), ('Sicogi'), ('Maroc'),
        ('Wassakara'), ('Toits Rouges'), ('Gesco')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Marcory)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Marcory)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Zone 4'), ('Biétry'), ('Champroux'), ('Anoumabo'), ('Hibiscus')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Abobo)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Abobo)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('PK18'), ('Abobo-Baoulé'), ('Sogefiha'), ('Sagbé'), ('Samaké'), ('Akeïkoi')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Plateau)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Plateau)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre des affaires'), ('Cité Administrative'), ('RAN')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Adjamé)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Adjamé)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Mirador'), ('Renault'), ('Paillet'), ('Williamsville'), ('220 Logements')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Treichville)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Treichville)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Arras'), ('Avenue 16'), ('Belleville'), ('Chicago'), ('Zone 2')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Koumassi)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Koumassi)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Grand Campement'), ('Remblais'), ('Prodomo'), ('Sicogi')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Port-Bouët)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Port-Bouët)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Vridi'), ('Gondwana'), ('Cité Universitaire'), ('Derrière L''Aéroport'), ('Jean-Folly')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abidjan (Attécoubé)' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abidjan (Attécoubé)', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Boribana'), ('Sebroko'), ('Locodjro'), ('Agban-Village')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    -- Anyama
    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Anyama' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Anyama', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Anyama' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Anyama', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Zossonkoi'), ('Schneider'), ('Ran'), ('Christiankoi')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    -- Bingerville
    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bingerville' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bingerville', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bingerville' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bingerville', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Santé 2'), ('Marché'), ('Cité FEH'), ('Gbagba')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    -- Songon
    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Songon' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Songon', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Songon' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Songon', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Songon-Agban'), ('Songon-Dagbé'), ('Songon-Kassemblé')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- YAMOUSSOUKRO
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Yamoussoukro';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Yamoussoukro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Yamoussoukro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Yamoussoukro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Yamoussoukro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Assabou'), ('N''Gokro'), ('220 Logements'), ('Habitat'),
        ('Fondation'), ('Dioulabougou'), ('Morofé')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Attiégouakro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Attiégouakro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Attiégouakro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Attiégouakro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GBÊKÊ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Gbêkê';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bouaké' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bouaké', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bouaké' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bouaké', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Commerce'), ('Nimbo'), ('Dar-Es-Salam'), ('Koko'),
        ('Ahougnansou'), ('Air France'), ('Belleville'), ('Broukro')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Béoumi' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Béoumi', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Béoumi' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Béoumi', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Résidentiel'), ('Commerce'), ('Zêdê')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sakassou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sakassou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sakassou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sakassou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Résidentiel'), ('Walèbo')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Botro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Botro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Botro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Botro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- HAMBOL
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Hambol';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Katiola' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Katiola', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Katiola' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Katiola', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Dabakala' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Dabakala', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Dabakala' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Dabakala', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Niakaramadougou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Niakaramadougou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Niakaramadougou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Niakaramadougou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- SAN-PÉDRO
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'San-Pédro';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'San-Pédro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('San-Pédro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'San-Pédro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('San-Pédro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Cité'), ('Bardot'), ('Seweke'), ('Balmer'), ('Mhoye'), ('Lac'), ('Zone Industrielle')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tabou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tabou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tabou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tabou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Kablaké'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GBÔKLÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Gbôklé';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sassandra' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sassandra', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sassandra' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sassandra', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Port'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Fresco' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Fresco', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Fresco' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Fresco', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- NAWA
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Nawa';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Soubré' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Soubré', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Soubré' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Soubré', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Méagui' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Méagui', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Méagui' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Méagui', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Buyo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Buyo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Buyo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Buyo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Guéyo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Guéyo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Guéyo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Guéyo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- HAUT-SASSANDRA
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Haut-Sassandra';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Daloa' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Daloa', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Daloa' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Daloa', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Tazibouo'), ('Labia'), ('Garage'), ('Orly'), ('Marais'), ('Baoulébougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Issia' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Issia', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Issia' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Issia', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Issia-Kpassaré'), ('Wandaguhé')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Vavoua' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Vavoua', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Vavoua' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Vavoua', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Dioulabougou'), ('Baoulébougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Zoukougbeu' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Zoukougbeu', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Zoukougbeu' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Zoukougbeu', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- MARAHOUÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Marahoué';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bouaflé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bouaflé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bouaflé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bouaflé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sinfra' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sinfra', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sinfra' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sinfra', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Zuénoula' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Zuénoula', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Zuénoula' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Zuénoula', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bonon' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bonon', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bonon' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bonon', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Gohitafla' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Gohitafla', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Gohitafla' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Gohitafla', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- PORO
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Poro';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Korhogo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Korhogo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Korhogo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Korhogo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Koko'), ('Soba'), ('Prefehua'), ('Petit-Paris'),
        ('Quatorze'), ('Haoussabougou'), ('Bannaï')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Dikodougou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Dikodougou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Dikodougou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Dikodougou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Dioulabougou'), ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'M''Bengué' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('M''Bengué', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'M''Bengué' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('M''Bengué', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('M''Benguékaha'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sinématiali' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sinématiali', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sinématiali' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sinématiali', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Sinéma'), ('Koko')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- BAGOUÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Bagoué';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Boundiali' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Boundiali', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Boundiali' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Boundiali', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tengréla' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tengréla', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tengréla' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tengréla', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kouto' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kouto', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kouto' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kouto', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- TCHOLOGO
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Tchologo';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Ferkessédougou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Ferkessédougou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Ferkessédougou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Ferkessédougou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Ouangolodougou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Ouangolodougou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Ouangolodougou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Ouangolodougou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kong' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kong', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kong' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kong', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GÔH
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Gôh';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Gagnoa' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Gagnoa', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Gagnoa' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Gagnoa', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Garahio'), ('Babré'), ('Dioulabougou'), ('Zapato'), ('Soleil'), ('Barouhio')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Oumé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Oumé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Oumé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Oumé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Oumé-Kassipri'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- LÔH-DJIBOUA
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Lôh-Djiboua';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Divo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Divo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Divo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Divo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Lakota' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Lakota', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Lakota' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Lakota', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Guitry' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Guitry', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Guitry' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Guitry', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- TONKPI
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Tonkpi';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Man' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Man', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Man' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Man', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Grand-Gbapleu'), ('Koko'), ('Sari'), ('Domoraud'), ('Doyagouiné'), ('Belleville')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Danané' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Danané', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Danané' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Danané', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Doyagouiné'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Biankouma' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Biankouma', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Biankouma' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Biankouma', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Gbonné'), ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Zouan-Hounien' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Zouan-Hounien', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Zouan-Hounien' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Zouan-Hounien', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Mine'), ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sipilou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sipilou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sipilou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sipilou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Sipilou-Ville')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GUÉMON
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Guémon';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Duékoué' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Duékoué', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Duékoué' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Duékoué', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bangolo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bangolo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bangolo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bangolo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kouibly' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kouibly', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kouibly' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kouibly', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Facobly' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Facobly', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Facobly' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Facobly', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- CAVALLY
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Cavally';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Guiglo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Guiglo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Guiglo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Guiglo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bloléquin' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bloléquin', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bloléquin' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bloléquin', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Toulepleu' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Toulepleu', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Toulepleu' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Toulepleu', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Taï' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Taï', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Taï' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Taï', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- AGNÉBY-TIASSA
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Agnéby-Tiassa';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Agboville' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Agboville', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Agboville' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Agboville', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Gatcouleur'), ('Arti'), ('Sambregnan'), ('Offoriguié')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tiassalé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tiassalé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tiassalé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tiassalé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Brakagny'), ('Château')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sikensi' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sikensi', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sikensi' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sikensi', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Sikensi 1'), ('Sikensi 2')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Taabo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Taabo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Taabo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Taabo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Taabo-Cité'), ('Taabo-Village')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GRANDS-PONTS
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Grands-Ponts';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Dabou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Dabou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Dabou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Dabou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Jacqueville' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Jacqueville', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Jacqueville' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Jacqueville', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Grand-Lahou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Grand-Lahou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Grand-Lahou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Grand-Lahou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- LA MÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'La Mé';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Adzopé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Adzopé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Adzopé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Adzopé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Akoupé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Akoupé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Akoupé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Akoupé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Alépé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Alépé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Alépé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Alépé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Yakassé-Attobrou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Yakassé-Attobrou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Yakassé-Attobrou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Yakassé-Attobrou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- SUD-COMOÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Sud-Comoé';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Grand-Bassam' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Grand-Bassam', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Grand-Bassam' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Grand-Bassam', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Quartier France'), ('Impérial'), ('Mocker'), ('Rosiers'), ('Azuretti')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Aboisso' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Aboisso', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Aboisso' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Aboisso', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Sikèmssou'), ('Ebouakro'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Adiaké' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Adiaké', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Adiaké' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Adiaké', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Adiaké-Ville'), ('Roassal')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tiapoum' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tiapoum', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tiapoum' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tiapoum', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Tiapoum-Ville')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- INDÉNIÉ-DJUABLIN
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Indénié-Djuablin';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Abengourou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Abengourou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Abengourou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Abengourou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Agnibilékrou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Agnibilékrou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Agnibilékrou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Agnibilékrou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bettié' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bettié', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bettié' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bettié', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- GONTOUGO
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Gontougo';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bondoukou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bondoukou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bondoukou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bondoukou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tanda' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tanda', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tanda' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tanda', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Koun-Fao' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Koun-Fao', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Koun-Fao' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Koun-Fao', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Sandégué' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Sandégué', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Sandégué' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Sandégué', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Transua' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Transua', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Transua' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Transua', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- BOUNKANI
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Bounkani';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bouna' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bouna', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bouna' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bouna', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Doropo' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Doropo', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Doropo' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Doropo', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Nassian' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Nassian', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Nassian' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Nassian', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Téhini' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Téhini', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Téhini' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Téhini', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- BÉLIER
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Bélier';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Toumodi' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Toumodi', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Toumodi' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Toumodi', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Tiébissou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Tiébissou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Tiébissou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Tiébissou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Didiévi' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Didiévi', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Didiévi' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Didiévi', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Djékanou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Djékanou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Djékanou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Djékanou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- IFFOU
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Iffou';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Daoukro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Daoukro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Daoukro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Daoukro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'M’Bahiakro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('M’Bahiakro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'M’Bahiakro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('M’Bahiakro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Prikro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Prikro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Prikro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Prikro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Ouellé' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Ouellé', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Ouellé' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Ouellé', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- N’ZI
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'N''Zi';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Dimbokro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Dimbokro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Dimbokro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Dimbokro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bocanda' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bocanda', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bocanda' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bocanda', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kouassi-Kouassikro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kouassi-Kouassikro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kouassi-Kouassikro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kouassi-Kouassikro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- MORONOU
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Moronou';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Bongouanou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Bongouanou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Bongouanou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Bongouanou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Arrah' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Arrah', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Arrah' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Arrah', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'M’Batto' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('M’Batto', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'M’Batto' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('M’Batto', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- WORODOUGOU
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Worodougou';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Séguéla' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Séguéla', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Séguéla' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Séguéla', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kani' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kani', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kani' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kani', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- BÉRÉ
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Béré';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Mankono' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Mankono', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Mankono' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Mankono', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Dianra' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Dianra', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Dianra' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Dianra', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kounahiri' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kounahiri', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kounahiri' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kounahiri', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- BAFING
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Bafing';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Touba' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Touba', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Touba' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Touba', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Koro' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Koro', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Koro' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Koro', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Ouaninou' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Ouaninou', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Ouaninou' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Ouaninou', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- KABADOUGOU
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Kabadougou';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Odienné' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Odienné', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Odienné' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Odienné', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel'), ('Commerce'), ('Dioulabougou')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Madinani' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Madinani', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Madinani' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Madinani', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Samatiguila' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Samatiguila', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Samatiguila' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Samatiguila', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Séguélon' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Séguélon', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Séguélon' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Séguélon', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Gbéléban' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Gbéléban', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Gbéléban' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Gbéléban', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    ---------------------------------------------------------
    -- FOLON
    ---------------------------------------------------------
    SELECT id_region INTO r_id FROM Region WHERE nom_region = 'Folon';

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Minignan' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Minignan', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Minignan' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Minignan', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre'), ('Résidentiel')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

    SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = 'Kaniasso' AND id_region = r_id;
    IF d_id IS NULL THEN
        INSERT INTO Departement (nom_departement, id_region) VALUES ('Kaniasso', r_id) RETURNING id_departement INTO d_id;
    END IF;
    SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = 'Kaniasso' AND id_departement = d_id;
    IF v_id IS NULL THEN
        INSERT INTO Ville (nom_ville, id_departement) VALUES ('Kaniasso', d_id) RETURNING id_ville INTO v_id;
    END IF;
    INSERT INTO Quartier (nom_quartier, id_ville) VALUES
        ('Centre')
    ON CONFLICT (nom_quartier, id_ville) DO NOTHING;

END $$;

-- ===========================================================
-- 9. CATÉGORIES ET SERVICES
-- ===========================================================
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
    ('Mécanique')
ON CONFLICT (nom_categorie) DO NOTHING;

-- Insertion robuste des services : on résout la catégorie par son NOM
INSERT INTO Service (nom_service, id_categorie)
SELECT s.nom, c.id_categorie
FROM (VALUES
    ('Coiffure femme',         'Beauté et Coiffure'),
    ('Coiffure homme',         'Beauté et Coiffure'),
    ('Manucure',               'Beauté et Coiffure'),
    ('Réparation fuite',       'Plomberie et Sanitaire'),
    ('Installation sanitaire', 'Plomberie et Sanitaire'),
    ('Lavage vêtements',       'Laverie et Pressing'),
    ('Repassage',              'Laverie et Pressing'),
    ('Garde à domicile',       'Garde d''enfants'),
    ('Baby-sitting',           'Garde d''enfants'),
    ('Cuisine à domicile',     'Cuisine et Traiteur'),
    ('Traiteur événement',     'Cuisine et Traiteur'),
    ('Dépannage informatique', 'Informatique'),
    ('Réparation smartphone',  'Informatique')
) AS s(nom, cat)
JOIN Categorie c ON c.nom_categorie = s.cat
ON CONFLICT (nom_service, id_categorie) DO NOTHING;

-- ===========================================================
-- 10. COMPTE ADMIN PAR DÉFAUT
-- ===========================================================
-- Email        : admin@service.ci
-- Mot de passe : password (hash bcrypt)
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
)
SELECT
    'admin@service.ci',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    FALSE, TRUE, TRUE, TRUE,
    'Admin', 'KoudMain', '0700000000',
    q.id_quartier
FROM Quartier q
WHERE q.nom_quartier = 'Riviera 1'
LIMIT 1
ON CONFLICT (email_utilisateur) DO NOTHING;

-- ===========================================================
-- 11. WALLET ADMIN + CARTES VIRTUELLES
-- ===========================================================
-- Créer un wallet pour chaque utilisateur qui n'en a pas
INSERT INTO Wallet (id_utilisateur, solde)
SELECT u.id_utilisateur, 0.00
FROM Utilisateur u
WHERE NOT EXISTS (
    SELECT 1 FROM Wallet w WHERE w.id_utilisateur = u.id_utilisateur
);

-- Créditer le wallet admin (50 000 FCFA pour tests)
UPDATE Wallet
SET solde = 50000.00
WHERE id_utilisateur = (
    SELECT id_utilisateur FROM Utilisateur WHERE est_admin = TRUE LIMIT 1
);

-- Carte principale Emerald
INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale, est_gelee)
SELECT w.id_wallet, 'Emerald', 'visa', 'emerald', '**** **** **** 0212',
       u.prenom_utilisateur || ' ' || u.nom_utilisateur, '12/28', TRUE, FALSE
FROM Wallet w
JOIN Utilisateur u ON u.id_utilisateur = w.id_utilisateur
WHERE u.est_admin = TRUE
  AND NOT EXISTS (
      SELECT 1 FROM Carte_Virtuelle c
      WHERE c.id_wallet = w.id_wallet AND c.libelle = 'Emerald'
  );

-- Carte secondaire Amber Prestige
INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale, est_gelee)
SELECT w.id_wallet, 'Amber Prestige', 'mastercard', 'amber', '**** **** **** 7741',
       u.prenom_utilisateur || ' ' || u.nom_utilisateur, '09/29', FALSE, FALSE
FROM Wallet w
JOIN Utilisateur u ON u.id_utilisateur = w.id_utilisateur
WHERE u.est_admin = TRUE
  AND NOT EXISTS (
      SELECT 1 FROM Carte_Virtuelle c
      WHERE c.id_wallet = w.id_wallet AND c.libelle = 'Amber Prestige'
  );

-- Carte secondaire Midnight
INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale, est_gelee)
SELECT w.id_wallet, 'Midnight', 'visa', 'midnight', '**** **** **** 3908',
       u.prenom_utilisateur || ' ' || u.nom_utilisateur, '04/30', FALSE, FALSE
FROM Wallet w
JOIN Utilisateur u ON u.id_utilisateur = w.id_utilisateur
WHERE u.est_admin = TRUE
  AND NOT EXISTS (
      SELECT 1 FROM Carte_Virtuelle c
      WHERE c.id_wallet = w.id_wallet AND c.libelle = 'Midnight'
  );

-- ===========================================================
-- Fin du script
-- ===========================================================
-- Vérifications utiles après exécution :
-- SELECT COUNT(*) FROM Region;         -- doit retourner 33
-- SELECT COUNT(*) FROM Departement;    -- ~100-110
-- SELECT COUNT(*) FROM Ville;          -- ~110-130
-- SELECT COUNT(*) FROM Quartier;       -- ~350-450
-- SELECT COUNT(*) FROM Categorie;      -- doit retourner 10
-- SELECT COUNT(*) FROM Service;        -- doit retourner 13
-- SELECT * FROM Utilisateur;           -- doit contenir l'admin
-- SELECT * FROM Wallet;                -- solde admin = 50000.00
-- ===========================================================
