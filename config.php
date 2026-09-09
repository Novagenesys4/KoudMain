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
define('DB_PORT',   getenv('DB_PORT')   ?: (DB_DRIVER === 'pgsql' ? '5432' : '3306'));
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