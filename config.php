<?php
/**
 * KoudMain — Configuration centrale
 * Support MySQL (local) et PostgreSQL (Supabase / production)
 */

// ---------------------------------------------------------------------------
// 1. Chargement éventuel d'un fichier .env (local uniquement)
// ---------------------------------------------------------------------------
function loadEnv(string $path = __DIR__ . '/.env'): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv();

// ---------------------------------------------------------------------------
// 2. Paramètres DB (priorité aux variables d'environnement)
// ---------------------------------------------------------------------------
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'pgsql');          // 'pgsql' ou 'mysql'
define('DB_HOST',   getenv('DB_HOST')   ?: 'localhost');
define('DB_PORT',   getenv('DB_PORT')   ?: (DB_DRIVER === 'pgsql' ? '6543' : '3306'));
define('DB_NAME',   getenv('DB_NAME')   ?: 'koudmain_db');
define('DB_USER',   getenv('DB_USER')   ?: 'root');
define('DB_PASS',   getenv('DB_PASS')   ?: '');
define('DB_SSL',    getenv('DB_SSL')    ?: 'require');        // pour Supabase : require

// Compatibilité ascendante (anciens define)
define('MYHOST', DB_HOST);
define('MYUSER', DB_USER);
define('MYPASS', DB_PASS);
define('MYBASE', DB_NAME);

// ---------------------------------------------------------------------------
// 3. Connexion PDO unique
// ---------------------------------------------------------------------------
function getConnexion(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower(DB_DRIVER);

    if ($driver === 'pgsql') {
        // Supabase / PostgreSQL
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_SSL
        );
    } else {
        // MySQL local (transition)
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($driver === 'pgsql') {
            $pdo->exec("SET search_path TO public");
        }

        return $pdo;
    } catch (PDOException $e) {
        error_log('[KoudMain] Erreur PDO : ' . $e->getMessage());
        http_response_code(503);
        die("<div style='font-family:system-ui,sans-serif;color:#f25f5c;padding:2rem;background:#0f1219;border-radius:12px;max-width:480px;margin:4rem auto;'>
             <b>Service temporairement indisponible</b><br>
             Impossible de joindre la base de données. Réessayez dans quelques instants.
             </div>");
    }
}

// ---------------------------------------------------------------------------
// 4. Session
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// 5. Helpers de session
// ---------------------------------------------------------------------------
function estConnecte(): bool
{
    return isset($_SESSION['id_utilisateur']);
}

function utilisateurCourant(): array
{
    return $_SESSION['utilisateur'] ?? [];
}

function estAdmin(): bool
{
    return ($_SESSION['est_admin'] ?? false) === true
        || ($_SESSION['est_admin'] ?? 0) == 1;
}

function estPrestataire(): bool
{
    $v = $_SESSION['est_prestataire'] ?? false;
    return $v === true || $v == 1;
}

function estClient(): bool
{
    $v = $_SESSION['est_client'] ?? false;
    return $v === true || $v == 1;
}

function rediriger(string $url): void
{
    header("Location: $url");
    exit;
}

function requireConnexion(): void
{
    if (!estConnecte()) {
        rediriger('connexion.php');
    }
}

function requireAdmin(): void
{
    requireConnexion();
    if (!estAdmin()) {
        rediriger('index.php');
    }
}

// ---------------------------------------------------------------------------
// 6. Protection CSRF
// ---------------------------------------------------------------------------
function genererTokenCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function champCSRF(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(genererTokenCSRF(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifierTokenCSRF(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (
            empty($token)
            || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            http_response_code(403);
            die("<div style='font-family:system-ui,sans-serif;color:#f25f5c;padding:2rem;background:#0f1219;border-radius:12px;max-width:480px;margin:4rem auto;'>
                 <b>Erreur de sécurité (CSRF)</b><br>
                 Requête invalide ou jeton expiré.
                 <a href='javascript:history.back()' style='color:#e8a045;'>← Retour</a>
                 </div>");
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Helper Wallet factorisé
// ---------------------------------------------------------------------------
function getOuCreerWallet(PDO $pdo, int $idUser): array
{
    $stmt = $pdo->prepare('SELECT * FROM Wallet WHERE id_utilisateur = ?');
    $stmt->execute([$idUser]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        try {
            $pdo->prepare('INSERT INTO Wallet (id_utilisateur, solde) VALUES (?, 0.00)')
                ->execute([$idUser]);
        } catch (PDOException $e) {
            error_log('[KoudMain] Wallet insert : ' . $e->getMessage());
        }
        $stmt->execute([$idUser]);
        $wallet = $stmt->fetch();
    }

    return $wallet ?: [
        'id_wallet'      => 0,
        'id_utilisateur' => $idUser,
        'solde'          => 0.00,
    ];
}

// ---------------------------------------------------------------------------
// 8. Notifications
// ---------------------------------------------------------------------------
function creerNotification(
    PDO $pdo,
    int $idUtilisateur,
    string $type,
    string $titre,
    string $contenu,
    ?int $idCommande = null
): void {
    if ($idUtilisateur <= 0) {
        return;
    }
    try {
        $pdo->prepare("
            INSERT INTO Notification
            (id_utilisateur, type_notification, titre, contenu, id_commande)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$idUtilisateur, $type, $titre, $contenu, $idCommande]);
    } catch (Exception $e) {
        error_log('[KoudMain] Notification : ' . $e->getMessage());
    }
}

function nbNotificationsNonLues(PDO $pdo, int $idUtilisateur): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM Notification WHERE id_utilisateur = ? AND est_lue = false");
        $st->execute([$idUtilisateur]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ---------------------------------------------------------------------------
// 9. Workflow de commande (statuts stricts) + Escrow
// ---------------------------------------------------------------------------
const KM_STATUTS_VALIDES = ['En attente', 'Acceptée', 'En cours', 'Terminée', 'Annulée', 'Litige'];

/**
 * Récupère une commande avec le client, le prestataire réel (déduit de
 * Cibler → Prestation → Utilisateur, jamais depuis une valeur du navigateur)
 * et les infos nécessaires au workflow.
 */
function getCommandeAvecActeurs(PDO $pdo, int $idCommande): ?array
{
    $stmt = $pdo->prepare("
        SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite,
               p.id_utilisateur AS id_prestataire_reel, p.titre_prestation
        FROM Commande cm
        JOIN Cibler ci ON ci.id_commande = cm.id_commande
        JOIN Prestation p ON p.id_prestation = ci.id_prestation
        WHERE cm.id_commande = ?
        LIMIT 1
    ");
    $stmt->execute([$idCommande]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Crée une commande + bloque le montant en escrow, dans une seule
 * transaction. Le prestataire est déduit de la prestation commandée,
 * jamais fourni par le client.
 *
 * @return array{ok: bool, message: string, id_commande?: int}
 */
function creerCommandeAvecEscrow(
    PDO $pdo,
    int $idClient,
    int $idPrestation,
    int $quantite,
    int $idQuartier
): array {
    $quantite = max(1, $quantite);

    $stmtPrest = $pdo->prepare("
        SELECT p.*, u.id_utilisateur AS id_prestataire
        FROM Prestation p
        JOIN Utilisateur u ON u.id_utilisateur = p.id_utilisateur
        WHERE p.id_prestation = ?
    ");
    $stmtPrest->execute([$idPrestation]);
    $prestation = $stmtPrest->fetch();

    if (!$prestation) {
        return ['ok' => false, 'message' => 'Prestation introuvable.'];
    }

    $idPrestataire = (int)$prestation['id_prestataire'];
    if ($idPrestataire === $idClient) {
        return ['ok' => false, 'message' => 'Vous ne pouvez pas commander votre propre prestation.'];
    }

    if ($idQuartier <= 0) {
        $uq = $pdo->prepare("SELECT id_quartier FROM Utilisateur WHERE id_utilisateur = ?");
        $uq->execute([$idClient]);
        $idQuartier = (int)$uq->fetchColumn();
    }

    $montant = (float)$prestation['prix_prestation'] * $quantite;

    try {
        $pdo->beginTransaction();

        // Verrouille le wallet du client pour éviter tout débit concurrent
        $stmtWallet = $pdo->prepare("SELECT * FROM Wallet WHERE id_utilisateur = ? FOR UPDATE");
        $stmtWallet->execute([$idClient]);
        $wallet = $stmtWallet->fetch();

        if (!$wallet) {
            $pdo->prepare("INSERT INTO Wallet (id_utilisateur, solde) VALUES (?, 0.00)")->execute([$idClient]);
            $stmtWallet->execute([$idClient]);
            $wallet = $stmtWallet->fetch();
        }

        if ((float)$wallet['solde'] < $montant) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Solde insuffisant. Rechargez votre wallet avant de commander.'];
        }

        // 1. Créer la commande (statut initial : En attente)
        $insCmd = $pdo->prepare("
            INSERT INTO Commande (montant_total, statut, id_quartier, id_utilisateur, id_prestataire)
            VALUES (?, 'En attente', ?, ?, ?)
        ");
        $insCmd->execute([$montant, $idQuartier, $idClient, $idPrestataire]);
        $idCommande = (int)$pdo->lastInsertId();

        // 2. Lier la prestation à la commande
        $pdo->prepare("
            INSERT INTO Cibler (id_prestation, id_commande, prix_unitaire, quantite)
            VALUES (?, ?, ?, ?)
        ")->execute([$idPrestation, $idCommande, $prestation['prix_prestation'], $quantite]);

        // 3. Débiter le wallet du client
        $nouveauSolde = (float)$wallet['solde'] - $montant;
        $pdo->prepare("UPDATE Wallet SET solde = ? WHERE id_wallet = ?")
            ->execute([$nouveauSolde, $wallet['id_wallet']]);

        $pdo->prepare("
            INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
            VALUES (?, 'debit', ?, ?, ?)
        ")->execute([
            $wallet['id_wallet'],
            $montant,
            'Montant bloqué pour la commande #' . $idCommande,
            $nouveauSolde,
        ]);

        // 4. Créer le séquestre (escrow)
        $pdo->prepare("
            INSERT INTO Escrow_Commande (id_commande, id_client, id_prestataire, montant, statut)
            VALUES (?, ?, ?, ?, 'bloque')
        ")->execute([$idCommande, $idClient, $idPrestataire, $montant]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[KoudMain] creerCommandeAvecEscrow : ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Erreur lors de la commande : ' . $e->getMessage()];
    }

    // 5. Notifier le prestataire (hors transaction, non bloquant)
    creerNotification(
        $pdo,
        $idPrestataire,
        'nouvelle_commande',
        'Nouvelle commande reçue',
        'Vous avez reçu une nouvelle demande pour « ' . $prestation['titre_prestation'] . ' ».',
        $idCommande
    );

    return ['ok' => true, 'message' => "Commande #{$idCommande} passée avec succès ! Le montant a été bloqué en attendant la prestation.", 'id_commande' => $idCommande];
}

/**
 * Libère l'escrow d'une commande terminée : crédite le prestataire.
 */
function libererEscrow(PDO $pdo, int $idCommande): bool
{
    try {
        $pdo->beginTransaction();

        $stmtE = $pdo->prepare("SELECT * FROM Escrow_Commande WHERE id_commande = ? AND statut = 'bloque' FOR UPDATE");
        $stmtE->execute([$idCommande]);
        $escrow = $stmtE->fetch();

        if (!$escrow) {
            $pdo->rollBack();
            return false;
        }

        $walletPrest = getOuCreerWallet($pdo, (int)$escrow['id_prestataire']);
        $nouveauSolde = (float)$walletPrest['solde'] + (float)$escrow['montant'];

        $pdo->prepare("UPDATE Wallet SET solde = ? WHERE id_wallet = ?")
            ->execute([$nouveauSolde, $walletPrest['id_wallet']]);

        $pdo->prepare("
            INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
            VALUES (?, 'credit', ?, ?, ?)
        ")->execute([
            $walletPrest['id_wallet'],
            $escrow['montant'],
            'Paiement libéré pour la commande #' . $idCommande,
            $nouveauSolde,
        ]);

        $pdo->prepare("
            UPDATE Escrow_Commande SET statut = 'libere', date_liberation = CURRENT_TIMESTAMP
            WHERE id_escrow = ?
        ")->execute([$escrow['id_escrow']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[KoudMain] libererEscrow : ' . $e->getMessage());
        return false;
    }

    creerNotification(
        $pdo,
        (int)$escrow['id_prestataire'],
        'paiement_libere',
        'Paiement reçu',
        'Le paiement de la commande #' . $idCommande . ' a été crédité sur votre wallet.',
        $idCommande
    );

    return true;
}

/**
 * Rembourse l'escrow d'une commande annulée/litige défavorable au prestataire.
 */
function rembourserEscrow(PDO $pdo, int $idCommande): bool
{
    try {
        $pdo->beginTransaction();

        $stmtE = $pdo->prepare("SELECT * FROM Escrow_Commande WHERE id_commande = ? AND statut IN ('bloque', 'litige') FOR UPDATE");
        $stmtE->execute([$idCommande]);
        $escrow = $stmtE->fetch();

        if (!$escrow) {
            $pdo->rollBack();
            return false;
        }

        $walletClient = getOuCreerWallet($pdo, (int)$escrow['id_client']);
        $nouveauSolde = (float)$walletClient['solde'] + (float)$escrow['montant'];

        $pdo->prepare("UPDATE Wallet SET solde = ? WHERE id_wallet = ?")
            ->execute([$nouveauSolde, $walletClient['id_wallet']]);

        $pdo->prepare("
            INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
            VALUES (?, 'credit', ?, ?, ?)
        ")->execute([
            $walletClient['id_wallet'],
            $escrow['montant'],
            'Remboursement de la commande #' . $idCommande,
            $nouveauSolde,
        ]);

        $pdo->prepare("
            UPDATE Escrow_Commande SET statut = 'rembourse', date_remboursement = CURRENT_TIMESTAMP
            WHERE id_escrow = ?
        ")->execute([$escrow['id_escrow']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[KoudMain] rembourserEscrow : ' . $e->getMessage());
        return false;
    }

    creerNotification(
        $pdo,
        (int)$escrow['id_client'],
        'remboursement',
        'Remboursement effectué',
        'Le montant de la commande #' . $idCommande . ' a été remboursé sur votre wallet.',
        $idCommande
    );

    return true;
}

/**
 * Point d'entrée unique pour toute transition de statut. Vérifie que
 * l'acteur (client ou prestataire) est bien celui déduit en base — jamais
 * une valeur envoyée par le navigateur — et applique la transition
 * uniquement si elle est autorisée par la machine à états.
 *
 * @param string $action 'accepter' | 'demarrer' | 'terminer' | 'annuler' | 'ouvrir_litige' | 'confirmer_reception'
 * @return array{ok: bool, message: string}
 */
function changerStatutCommande(PDO $pdo, int $idCommande, int $idUtilisateurActeur, string $action, string $motif = ''): array
{
    $cmd = getCommandeAvecActeurs($pdo, $idCommande);
    if (!$cmd) {
        return ['ok' => false, 'message' => 'Commande introuvable.'];
    }

    $idClientReel      = (int)$cmd['id_utilisateur'];
    $idPrestataireReel = (int)$cmd['id_prestataire_reel'];
    $statutActuel      = $cmd['statut'];

    $estClient      = ($idUtilisateurActeur === $idClientReel);
    $estPrestataire = ($idUtilisateurActeur === $idPrestataireReel);

    if (!$estClient && !$estPrestataire) {
        return ['ok' => false, 'message' => "Vous n'êtes pas autorisé à agir sur cette commande."];
    }

    // Table des transitions autorisées : [statut_actuel][action] => [nouveau_statut, acteurs_autorisés]
    $transitions = [
        'En attente' => [
            'accepter' => ['Acceptée', ['prestataire']],
            'annuler'  => ['Annulée',  ['client', 'prestataire']],
        ],
        'Acceptée' => [
            'demarrer' => ['En cours', ['prestataire']],
            'annuler'  => ['Annulée',  ['client', 'prestataire']],
        ],
        'En cours' => [
            'terminer'      => ['Terminée', ['prestataire']],
            'ouvrir_litige' => ['Litige',   ['client']],
        ],
        'Terminée' => [
            'confirmer_reception' => ['Terminée', ['client']], // déclenche juste la libération d'escrow si pas déjà fait
        ],
    ];

    if (!isset($transitions[$statutActuel][$action])) {
        return ['ok' => false, 'message' => "Action « $action » impossible depuis le statut « $statutActuel »."];
    }

    [$nouveauStatut, $acteursAutorises] = $transitions[$statutActuel][$action];

    $autorise = (in_array('client', $acteursAutorises, true) && $estClient)
        || (in_array('prestataire', $acteursAutorises, true) && $estPrestataire);

    if (!$autorise) {
        return ['ok' => false, 'message' => "Seul le " . ($acteursAutorises[0] === 'client' ? 'client' : 'prestataire') . " peut effectuer cette action."];
    }

    try {
        $pdo->beginTransaction();

        $champsDate = [
            'Acceptée' => 'date_acceptation',
            'En cours' => 'date_debut',
            'Terminée' => 'date_completion',
            'Annulée'  => 'date_annulation',
        ];
        $champDate = $champsDate[$nouveauStatut] ?? null;

        $sql = "UPDATE Commande SET statut = ?";
        $params = [$nouveauStatut];
        if ($champDate) {
            $sql .= ", $champDate = CURRENT_TIMESTAMP";
        }
        if ($action === 'confirmer_reception') {
            $sql .= ", date_validation_client = CURRENT_TIMESTAMP";
        }
        if ($action === 'annuler' && $motif !== '') {
            $sql .= ", motif_annulation = ?";
            $params[] = $motif;
        }
        if ($action === 'ouvrir_litige' && $motif !== '') {
            $sql .= ", motif_litige = ?";
            $params[] = $motif;
        }
        $sql .= " WHERE id_commande = ?";
        $params[] = $idCommande;

        $pdo->prepare($sql)->execute($params);

        if ($action === 'ouvrir_litige') {
            $pdo->prepare("UPDATE Escrow_Commande SET statut = 'litige' WHERE id_commande = ? AND statut = 'bloque'")
                ->execute([$idCommande]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[KoudMain] changerStatutCommande : ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Erreur lors du changement de statut.'];
    }

    // Effets de bord hors transaction principale (escrow + notifications)
    $messages = [
        'accepter'             => ['prestataire' => null, 'client' => ["commande_acceptee", "Commande acceptée", "Votre commande #$idCommande a été acceptée par le prestataire."]],
        'demarrer'             => ['client' => ["commande_en_cours", "Prestation démarrée", "Le prestataire a démarré votre commande #$idCommande."]],
        'terminer'             => ['client' => ["commande_terminee", "Prestation terminée", "Le prestataire a marqué la commande #$idCommande comme terminée. Confirmez la réception pour libérer le paiement."]],
        'annuler'              => ['client' => ["commande_annulee", "Commande annulée", "La commande #$idCommande a été annulée."], 'prestataire' => ["commande_annulee", "Commande annulée", "La commande #$idCommande a été annulée."]],
        'ouvrir_litige'        => ['prestataire' => ["litige_ouvert", "Litige ouvert", "Le client a ouvert un litige sur la commande #$idCommande."]],
        'confirmer_reception'  => ['prestataire' => ["commande_terminee", "Réception confirmée", "Le client a confirmé la réception de la commande #$idCommande."]],
    ];

    if ($action === 'annuler') {
        rembourserEscrow($pdo, $idCommande);
    }
    if ($action === 'confirmer_reception') {
        // Le paiement n'est libéré qu'à la confirmation explicite du client
        // (ou par expiration automatique — voir libererEscrowsExpires()).
        libererEscrow($pdo, $idCommande);
    }

    foreach (($messages[$action] ?? []) as $destinataireRole => $notif) {
        if ($notif === null) continue;
        $idDest = $destinataireRole === 'client' ? $idClientReel : $idPrestataireReel;
        creerNotification($pdo, $idDest, $notif[0], $notif[1], $notif[2], $idCommande);
    }

    return ['ok' => true, 'message' => "Statut mis à jour : $nouveauStatut."];
}

/**
 * Arbitrage d'un litige par un administrateur : ferme le litige en
 * 'Terminée' (paiement libéré au prestataire) ou 'Annulée' (client remboursé).
 */
function arbitrerLitige(PDO $pdo, int $idCommande, string $decision): array
{
    $cmd = getCommandeAvecActeurs($pdo, $idCommande);
    if (!$cmd || $cmd['statut'] !== 'Litige') {
        return ['ok' => false, 'message' => "Cette commande n'est pas en litige."];
    }
    if (!in_array($decision, ['Terminée', 'Annulée'], true)) {
        return ['ok' => false, 'message' => 'Décision invalide.'];
    }

    $pdo->prepare("UPDATE Commande SET statut = ? WHERE id_commande = ?")->execute([$decision, $idCommande]);

    if ($decision === 'Terminée') {
        $pdo->prepare("UPDATE Escrow_Commande SET statut = 'bloque' WHERE id_commande = ? AND statut = 'litige'")->execute([$idCommande]);
        libererEscrow($pdo, $idCommande);
        creerNotification($pdo, (int)$cmd['id_prestataire_reel'], 'litige_resolu', 'Litige résolu en votre faveur', "Le litige sur la commande #$idCommande a été résolu : le paiement vous a été libéré.", $idCommande);
        creerNotification($pdo, (int)$cmd['id_utilisateur'], 'litige_resolu', 'Litige résolu', "Le litige sur la commande #$idCommande a été résolu en faveur du prestataire.", $idCommande);
    } else {
        // L'escrow est déjà au statut 'litige' (posé lors de l'ouverture du
        // litige) ; rembourserEscrow() accepte 'bloque' OU 'litige'.
        rembourserEscrow($pdo, $idCommande);
        creerNotification($pdo, (int)$cmd['id_utilisateur'], 'litige_resolu', 'Litige résolu en votre faveur', "Le litige sur la commande #$idCommande a été résolu : vous avez été remboursé.", $idCommande);
        creerNotification($pdo, (int)$cmd['id_prestataire_reel'], 'litige_resolu', 'Litige résolu', "Le litige sur la commande #$idCommande a été résolu en faveur du client.", $idCommande);
    }

    return ['ok' => true, 'message' => "Litige tranché : $decision."];
}

/**
 * Libère automatiquement l'escrow des commandes marquées "Terminée" depuis
 * plus de $joursDelai jours sans confirmation ni litige du client (validation
 * tacite). À appeler depuis une tâche planifiée (cron) ou en tête de page.
 */
function libererEscrowsExpires(PDO $pdo, int $joursDelai = 3): int
{
    $stmt = $pdo->prepare("
        SELECT cm.id_commande
        FROM Commande cm
        JOIN Escrow_Commande e ON e.id_commande = cm.id_commande
        WHERE cm.statut = 'Terminée'
          AND cm.date_validation_client IS NULL
          AND e.statut = 'bloque'
          AND cm.date_completion IS NOT NULL
          AND cm.date_completion < (CURRENT_TIMESTAMP - (? || ' days')::interval)
    ");
    $stmt->execute([$joursDelai]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($ids as $idCommande) {
        $pdo->prepare("UPDATE Commande SET date_validation_client = CURRENT_TIMESTAMP WHERE id_commande = ?")
            ->execute([$idCommande]);
        if (libererEscrow($pdo, (int)$idCommande)) {
            $count++;
        }
    }
    return $count;
}