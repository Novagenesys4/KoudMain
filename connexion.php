<?php
require_once "config.php";

// ── Déconnexion ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    rediriger("index.php");
}

if (estConnecte()) rediriger("index.php");

$erreur = "";

// ── Traitement connexion ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $erreur = "Veuillez remplir tous les champs.";
    } else {
        $pdo  = getConnexion();
        $stmt = $pdo->prepare("
            SELECT * FROM Utilisateur WHERE email_utilisateur = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $erreur = "Aucun compte trouvé avec cette adresse e-mail.";
        } elseif (!password_verify($password, $user['mot_de_passe'])) {
            $erreur = "Mot de passe incorrect.";
        } elseif ($user['est_prestataire'] && !$user['est_valide'] && !$user['est_admin']) {
            $erreur = "Votre compte prestataire est en attente de validation par l'administrateur.";
        } else {
            // Enregistrement en session
            $_SESSION['id_utilisateur']  = $user['id_utilisateur'];
            $_SESSION['nom']             = $user['nom_utilisateur'];
            $_SESSION['prenom']          = $user['prenom_utilisateur'];
            $_SESSION['email']           = $user['email_utilisateur'];
            $_SESSION['est_prestataire'] = $user['est_prestataire'];
            $_SESSION['est_client']      = $user['est_client'];
            $_SESSION['est_admin']       = (bool)$user['est_admin'];
            $_SESSION['utilisateur']     = $user;

            // Redirection selon le rôle
            if ($user['est_admin']) {
                rediriger("admin_dashboard.php");
            } elseif ($user['est_prestataire']) {
                rediriger("prestataire_dashboard.php");
            } else {
                rediriger("client_dashboard.php");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Koudmain | Connexion</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<nav>
  <a class="nav-brand" href="index.php">Koud<span>Main</span></a>
  <div class="nav-links">
    <a href="connexion.php">Connexion</a>
    <a href="inscription.php" class="btn-nav">S'inscrire</a>
  </div>
</nav>

<div class="page" style="max-width:440px;">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-size:2.5rem;margin-bottom:0.5rem;">🔐</div>
    <h1 style="font-size:2rem;">Bienvenue</h1>
    <p style="color:var(--text-muted);margin-top:0.5rem;">Connectez-vous à votre espace KoudMain</p>
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-error"><?= htmlspecialchars($erreur) ?></div>
  <?php endif; ?>

  <div class="form-wrap" style="max-width:100%;">
    <form method="POST" action="connexion.php">

      <div class="form-group">
        <label>Adresse e-mail</label>
        <input type="email" name="email" placeholder="votre@email.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
      </div>

      <div class="form-group">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:0.5rem;">
        Se connecter
      </button>

      <p style="text-align:center;margin-top:1.2rem;color:var(--text-muted);font-size:0.9rem;">
        Pas encore de compte ? <a href="inscription.php" style="color:var(--accent);">S'inscrire</a>
      </p>

    </form>
  </div>

  <!-- Accès démo -->
  <div style="margin-top:1.5rem;padding:1rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);">
    <p style="font-size:0.8rem;color:var(--text-muted);text-align:center;margin-bottom:0.5rem;">
      🔑 Comptes administrateur
    </p>
    <p style="font-size:0.8rem;color:var(--text-muted);text-align:center;">
      Admin : <code style="color:var(--accent);">admin@service.ci</code> / <code style="color:var(--accent);">password</code>
    </p>
  </div>
</div>

</body>
</html>
