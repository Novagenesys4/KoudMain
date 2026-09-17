-- ===========================================================
--  KOUDMAIN — Migration V2
--  Workflow de commande enrichi + Escrow (séquestre) + Notifications
--  Idempotent : peut être rejoué sans doublons ni perte
-- ===========================================================

BEGIN;

-- -----------------------------------------------------------
-- 1. WORKFLOW DE COMMANDE
-- -----------------------------------------------------------
ALTER TABLE Commande
    ADD COLUMN IF NOT EXISTS date_acceptation        TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS date_debut               TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS date_completion           TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS date_annulation           TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS date_validation_client     TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS id_prestataire            INT NULL
        REFERENCES Utilisateur(id_utilisateur),
    ADD COLUMN IF NOT EXISTS motif_annulation          TEXT NULL,
    ADD COLUMN IF NOT EXISTS motif_litige              TEXT NULL;

-- Rétro-remplissage du prestataire à partir de Cibler → Prestation → Utilisateur
UPDATE Commande c
SET id_prestataire = p.id_utilisateur
FROM Cibler ci
JOIN Prestation p ON p.id_prestation = ci.id_prestation
WHERE ci.id_commande = c.id_commande
  AND c.id_prestataire IS NULL;

-- Normalisation des anciens statuts vers le nouveau vocabulaire
UPDATE Commande SET statut = 'Terminée' WHERE statut = 'Terminé';
UPDATE Commande SET statut = 'Acceptée' WHERE statut = 'Acceptée'; -- déjà bon, no-op

ALTER TABLE Commande
    DROP CONSTRAINT IF EXISTS commande_statut_check;

ALTER TABLE Commande
    ADD CONSTRAINT commande_statut_check
    CHECK (statut IN (
        'En attente',
        'Acceptée',
        'En cours',
        'Terminée',
        'Annulée',
        'Litige'
    ));

CREATE INDEX IF NOT EXISTS idx_commande_client_statut
    ON Commande(id_utilisateur, statut);

CREATE INDEX IF NOT EXISTS idx_commande_prestataire_statut
    ON Commande(id_prestataire, statut);

-- -----------------------------------------------------------
-- 2. ESCROW (SÉQUESTRE)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Escrow_Commande (
    id_escrow           SERIAL PRIMARY KEY,
    id_commande         INT NOT NULL UNIQUE
        REFERENCES Commande(id_commande) ON DELETE CASCADE,
    id_client           INT NOT NULL
        REFERENCES Utilisateur(id_utilisateur),
    id_prestataire      INT NOT NULL
        REFERENCES Utilisateur(id_utilisateur),
    montant             NUMERIC(12,2) NOT NULL CHECK (montant > 0),
    statut              VARCHAR(20) NOT NULL DEFAULT 'bloque'
        CHECK (statut IN ('bloque', 'libere', 'rembourse', 'litige')),
    date_blocage        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_liberation      TIMESTAMP NULL,
    date_remboursement    TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_escrow_statut
    ON Escrow_Commande(statut);

-- Assure que Transaction_Wallet a bien une colonne "libelle" (déjà présente
-- dans le schéma d'origine). On garde le nom "libelle" pour rester cohérent
-- avec l'existant plutôt que "description".

-- -----------------------------------------------------------
-- 3. NOTIFICATIONS
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS Notification (
    id_notification     SERIAL PRIMARY KEY,
    id_utilisateur       INT NOT NULL
        REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE,
    type_notification     VARCHAR(50) NOT NULL,
    titre                VARCHAR(150) NOT NULL,
    contenu              TEXT NOT NULL,
    id_commande          INT NULL
        REFERENCES Commande(id_commande) ON DELETE CASCADE,
    id_message           INT NULL,
    est_lue              BOOLEAN NOT NULL DEFAULT FALSE,
    date_creation        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_lecture         TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_notification_user_unread
    ON Notification(id_utilisateur, est_lue, date_creation DESC);

COMMIT;

-- ===========================================================
-- Vérifications utiles après exécution :
-- SELECT column_name FROM information_schema.columns WHERE table_name = 'commande';
-- SELECT conname FROM pg_constraint WHERE conrelid = 'commande'::regclass;
-- SELECT COUNT(*) FROM Escrow_Commande;
-- SELECT COUNT(*) FROM Notification;
-- ===========================================================
