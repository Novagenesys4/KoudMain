

<?php
// ── Paramètres de connexion ──────────────────────────────
define("MYHOST", "localhost");
define("MYUSER", "root");
define("MYPASS", "");          // Adapter selon ton serveur
define("MYBASE", "koudmain_db"); // Adapter selon ta base de données

/**
 * Connexion PDO à MySQL (Chapitre 13 du cours)
 * Retourne un objet PDO ou arrête le script en cas d'erreur.
 */
function getConnexion(): PDO {
    $dsn  = "mysql:host=" . MYHOST . ";dbname=" . MYBASE . ";charset=utf8mb4";
    $user = MYUSER;
    $pass = MYPASS;

    try {
        $pdo = new PDO($dsn, $user, $pass);
        // Active les exceptions PDO pour capturer les erreurs SQL
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Retourne les résultats en tableau associatif par défaut
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("<div style='font-family:sans-serif;color:#f25f5c;padding:2rem;'>
             <b>Erreur de connexion à la base de données :</b><br>" . $e->getMessage() . "
             </div>");
    }
}

// ── Session ─────────────────────────────────────────────
session_start();

// ── Helpers de session ──────────────────────────────────
function estConnecte(): bool {
    return isset($_SESSION['id_utilisateur']);
}

function utilisateurCourant(): array {
    return $_SESSION['utilisateur'] ?? [];
}

function estAdmin(): bool {
    return ($_SESSION['est_admin'] ?? false) === true;
}

function estPrestataire(): bool {
    return ($_SESSION['est_prestataire'] ?? 0) == 1;
}

function estClient(): bool {
    return ($_SESSION['est_client'] ?? 0) == 1;
}

/**
 * Redirige vers une URL et arrête le script.
 */
function rediriger(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Protège une page : redirige vers connexion.php si non connecté.
 */
function requireConnexion(): void {
    if (!estConnecte()) {
        rediriger("connexion.php");
    }
}

/**
 * Protège une page admin.
 */
function requireAdmin(): void {
    requireConnexion();
    if (!estAdmin()) {
        rediriger("index.php");
    }
}

// ── Protection CSRF ──────────────────────────────────────
function genererTokenCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function champCSRF(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(genererTokenCSRF()) . '">';
}

function verifierTokenCSRF(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die("<div style='font-family:sans-serif;color:#f25f5c;padding:2rem;background:#1a1f33;border-radius:8px;'>
                 <b>Erreur de sécurité (CSRF) :</b> Requête invalide ou jeton de sécurité expiré. <a href='javascript:history.back()' style='color:#e8a045;'>← Retour</a>
                 </div>");
        }
    }
}

// ── Helper Wallet Factorisé ──────────────────────────────
function getOuCreerWallet(PDO $pdo, int $idUser): array {
    $stmt = $pdo->prepare("SELECT * FROM Wallet WHERE id_utilisateur = ?");
    $stmt->execute([$idUser]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        try {
            $pdo->prepare("INSERT INTO Wallet (id_utilisateur, solde) VALUES (?, 0.00)")->execute([$idUser]);
        } catch (PDOException $e) {
            // En cas d'accès concurrent, le wallet a pu être créé entre temps (id_utilisateur est UNIQUE)
        }
        $stmt->execute([$idUser]);
        $wallet = $stmt->fetch();
    }
    return $wallet ?: ['id_wallet' => 0, 'id_utilisateur' => $idUser, 'solde' => 0.00];
}
?>
