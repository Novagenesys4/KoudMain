-- ===========================================================
--  KOUDMAIN — Migration V4
--  Avis clients modifiables (avec historique) + Favoris
--  Idempotent
-- ===========================================================

BEGIN;

-- -----------------------------------------------------------
-- 1. AVIS MODIFIABLES
-- -----------------------------------------------------------
ALTER TABLE Cibler
    ADD COLUMN IF NOT EXISTS date_evaluation         TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS date_modification_avis   TIMESTAMP NULL;

-- Cibler a déjà (id_prestation, id_commande) comme clé primaire composite ;
-- cet index unique protège explicitement la règle « un avis par commande ×
-- prestation » côté avis, même si la PK le garantit déjà.
CREATE UNIQUE INDEX IF NOT EXISTS uq_avis_commande_prestation
    ON Cibler(id_commande, id_prestation);

CREATE TABLE IF NOT EXISTS Historique_Avis (
    id_historique   SERIAL PRIMARY KEY,
    id_commande     INT NOT NULL,
    id_prestation   INT NOT NULL,
    id_utilisateur  INT NOT NULL,
    evaluation      INT NOT NULL CHECK (evaluation BETWEEN 1 AND 5),
    commentaire     TEXT,
    date_action     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_historique_avis_commande
    ON Historique_Avis(id_commande, id_prestation);

-- -----------------------------------------------------------
-- 2. FAVORIS
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Favori (
    id_utilisateur INT NOT NULL
        REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE,
    id_prestation  INT NOT NULL
        REFERENCES Prestation(id_prestation) ON DELETE CASCADE,
    date_creation  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_utilisateur, id_prestation)
);

CREATE INDEX IF NOT EXISTS idx_favori_user_date
    ON Favori(id_utilisateur, date_creation DESC);

COMMIT;
