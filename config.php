

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
    $dsn  = "mysql:host=" . MYHOST . ";dbname=" . MYBASE . ";charset=utf8";
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
?>
