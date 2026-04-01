<?php
require_once "config.php";

if (estConnecte()) rediriger("index.php");

$erreur  = "";
$succes  = "";
$donnees = [];

// ── Chargement des quartiers AVEC VÉRIFICATION ─────────────────────────────
try {
    $pdo = getConnexion();
    
    // Vérifier que la connexion fonctionne
    $test = $pdo->query("SELECT 1");
    
    // Récupérer les quartiers avec plus d'informations
    $stmt = $pdo->prepare("
        SELECT q.id_quartier, q.nom_quartier, v.nom_ville, d.nom_departement, r.nom_region
        FROM Quartier q
        JOIN Ville v ON q.id_ville = v.id_ville
        JOIN Departement d ON v.id_departement = d.id_departement
        JOIN Region r ON d.id_region = r.id_region
        ORDER BY r.nom_region, d.nom_departement, v.nom_ville, q.nom_quartier
    ");
    $stmt->execute();
    $quartiers = $stmt->fetchAll();
    
    // Vérifier si des quartiers ont été trouvés
    if (empty($quartiers)) {
        $erreur = "Aucun quartier n'a été trouvé dans la base de données. Veuillez d'abord importer les données.";
    }
    
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ── Traitement du formulaire ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Récupération et nettoyage des données
    $nom        = trim($_POST['nom'] ?? '');
    $prenom     = trim($_POST['prenom'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $tel        = trim($_POST['tel'] ?? '');
    $password   = $_POST['password'] ?? '';
    $password2  = $_POST['password2'] ?? '';
    $quartier   = (int)($_POST['id_quartier'] ?? 0);
    $role       = $_POST['role'] ?? 'client';

    $donnees = compact('nom','prenom','email','tel','quartier','role');

    // Validation
    if (empty($nom) || empty($prenom) || empty($email) || empty($tel) || empty($password)) {
        $erreur = "Tous les champs obligatoires doivent être remplis.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "L'adresse e-mail n'est pas valide.";
    } elseif (strlen($password) < 6) {
        $erreur = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif ($password !== $password2) {
        $erreur = "Les mots de passe ne correspondent pas.";
    } elseif ($quartier === 0) {
        $erreur = "Veuillez sélectionner un quartier.";
    } else {
        // Vérifier si l'email existe déjà
        $check = $pdo->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_utilisateur = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $erreur = "Cette adresse e-mail est déjà utilisée.";
        } else {
            $hash         = password_hash($password, PASSWORD_DEFAULT);
            $est_prest    = ($role === 'prestataire') ? 1 : 0;
            $est_client   = ($role === 'client') ? 1 : 0;
            // Un prestataire doit être validé par l'admin avant de pouvoir se connecter
            $est_valide   = ($role === 'client') ? 1 : 0;

            $insert = $pdo->prepare("
                INSERT INTO Utilisateur
                  (email_utilisateur, mot_de_passe, est_prestataire, est_client,
                   est_admin, est_valide, nom_utilisateur, prenom_utilisateur,
                   num_utilisateur, id_quartier)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
            ");

            $insert->execute([
                $email, $hash, $est_prest, $est_client,
                $est_valide, $nom, $prenom, $tel, $quartier
            ]);

            if ($role === 'prestataire') {
                $succes = "Compte prestataire créé ! Votre compte est en attente de validation par l'administrateur.";
            } else {
                $succes = "Compte créé avec succès ! Vous pouvez maintenant vous connecter.";
            }
            $donnees = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Koudmain | Inscription</title>
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

<div class="page" style="max-width:560px;">
  <div style="text-align:center;margin-bottom:2rem;">
    <h1 style="font-size:2rem;">Créer un compte</h1>
    <p style="color:var(--text-muted);margin-top:0.5rem;">
      Rejoignez KoudMain pour accéder à des centaines de services.
    </p>
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-error"><?= htmlspecialchars($erreur) ?></div>
  <?php endif; ?>

  <?php if ($succes): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars($succes) ?>
      <br><a href="connexion.php" style="color:inherit;font-weight:600;">→ Se connecter</a>
    </div>
  <?php else: ?>

  <div class="form-wrap" style="max-width:100%;">
    <form method="POST" action="inscription.php">

      <!-- Choix du rôle -->
      <div class="form-group">
        <label>Je suis</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
          <label style="text-transform:none;display:flex;align-items:center;gap:0.6rem;padding:0.8rem 1rem;background:var(--surface2);border:2px solid <?= ($donnees['role'] ?? 'client') === 'client' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:8px;cursor:pointer;" id="lbl-client">
            <input type="radio" name="role" value="client" <?= ($donnees['role'] ?? 'client') === 'client' ? 'checked' : '' ?> onchange="updateRole(this)">
            👤 Client
          </label>
          <label style="text-transform:none;display:flex;align-items:center;gap:0.6rem;padding:0.8rem 1rem;background:var(--surface2);border:2px solid <?= ($donnees['role'] ?? '') === 'prestataire' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:8px;cursor:pointer;" id="lbl-prest">
            <input type="radio" name="role" value="prestataire" <?= ($donnees['role'] ?? '') === 'prestataire' ? 'checked' : '' ?> onchange="updateRole(this)">
            🛠️ Prestataire
          </label>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Nom *</label>
          <input type="text" name="nom" value="<?= htmlspecialchars($donnees['nom'] ?? '') ?>" placeholder="Koné" required>
        </div>
        <div class="form-group">
          <label>Prénom *</label>
          <input type="text" name="prenom" value="<?= htmlspecialchars($donnees['prenom'] ?? '') ?>" placeholder="Aminata" required>
        </div>
      </div>

      <div class="form-group">
        <label>Adresse e-mail *</label>
        <input type="email" name="email" value="<?= htmlspecialchars($donnees['email'] ?? '') ?>" placeholder="exemple@email.com" required>
      </div>

      <div class="form-group">
        <label>Numéro de téléphone *</label>
        <input type="tel" name="tel" value="<?= htmlspecialchars($donnees['tel'] ?? '') ?>" placeholder="07 00 00 00 00" required>
      </div>

      <div class="form-group">
        <label>Quartier *</label>
        <select name="id_quartier" required>
          <option value="">— Sélectionnez votre quartier —</option>
          <?php if (!empty($quartiers)): ?>
            <?php foreach ($quartiers as $q): ?>
              <option value="<?= $q['id_quartier'] ?>" <?= ($donnees['quartier'] ?? 0) == $q['id_quartier'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($q['nom_quartier'] . ' (' . $q['nom_ville'] . ', ' . $q['nom_departement'] . ')') ?>
              </option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="" disabled>⚠️ Aucun quartier trouvé - Vérifiez la base de données</option>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Mot de passe *</label>
          <input type="password" name="password" placeholder="Min. 6 caractères" required>
        </div>
        <div class="form-group">
          <label>Confirmer *</label>
          <input type="password" name="password2" placeholder="Répétez le mot de passe" required>
        </div>
      </div>

      <div id="info-prest" style="display:<?= ($donnees['role'] ?? '') === 'prestataire' ? 'block' : 'none' ?>;">
        <div class="alert alert-info" style="margin-bottom:1.2rem;">
          ⏳ Votre compte prestataire devra être <strong>validé par un administrateur</strong> avant d'être actif.
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Créer mon compte</button>

      <p style="text-align:center;margin-top:1.2rem;color:var(--text-muted);font-size:0.9rem;">
        Déjà un compte ? <a href="connexion.php" style="color:var(--accent);">Se connecter</a>
      </p>

    </form>
  </div>
  <?php endif; ?>
</div>

<script>
function updateRole(el) {
  document.getElementById('lbl-client').style.borderColor = 'var(--border)';
  document.getElementById('lbl-prest').style.borderColor  = 'var(--border)';
  document.getElementById('info-prest').style.display = 'none';

  if (el.value === 'client') {
    document.getElementById('lbl-client').style.borderColor = 'var(--accent)';
  } else {
    document.getElementById('lbl-prest').style.borderColor = 'var(--accent)';
    document.getElementById('info-prest').style.display = 'block';
  }
}
</script>

</body>
</html>