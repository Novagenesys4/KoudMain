-- ===========================================================
--  KOUDMAIN — Migration V3
--  Messagerie interne (conversations liées à la commande)
--  Idempotent
-- ===========================================================

BEGIN;

CREATE TABLE IF NOT EXISTS Conversation (
    id_conversation      SERIAL PRIMARY KEY,
    id_commande          INT NOT NULL UNIQUE
        REFERENCES Commande(id_commande) ON DELETE CASCADE,
    date_creation        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_dernier_message TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS Message (
    id_message      SERIAL PRIMARY KEY,
    id_conversation INT NOT NULL
        REFERENCES Conversation(id_conversation) ON DELETE CASCADE,
    id_expediteur   INT NOT NULL
        REFERENCES Utilisateur(id_utilisateur) ON DELETE CASCADE,
    contenu         TEXT NOT NULL CHECK (length(trim(contenu)) BETWEEN 1 AND 5000),
    est_lu          BOOLEAN NOT NULL DEFAULT FALSE,
    date_envoi      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_message_conversation_date
    ON Message(id_conversation, date_envoi);

CREATE INDEX IF NOT EXISTS idx_message_expediteur_lu
    ON Message(id_expediteur, est_lu);

COMMIT;
