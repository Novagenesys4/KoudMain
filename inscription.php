<?php
require_once "config.php";

if (estConnecte()) rediriger("index.php");

$erreur  = "";
$succes  = "";
$donnees = [];

// Pré-sélection du rôle via GET (depuis connexion.php)
$role_defaut = $_GET['role'] ?? 'client';
if (!in_array($role_defaut, ['client', 'prestataire'])) $role_defaut = 'client';

// ── Chargement des quartiers ─────────────────────────────
try {
    $pdo = getConnexion();
    $stmt = $pdo->prepare("
        SELECT q.id_quartier, q.nom_quartier, v.nom_ville, d.nom_departement
        FROM Quartier q
        JOIN Ville v ON q.id_ville = v.id_ville
        JOIN Departement d ON v.id_departement = d.id_departement
        JOIN Region r ON d.id_region = r.id_region
        ORDER BY r.nom_region, d.nom_departement, v.nom_ville, q.nom_quartier
    ");
    $stmt->execute();
    $quartiers = $stmt->fetchAll();

    if (empty($quartiers)) {
        $erreur = "Aucun quartier trouvé. Veuillez importer les données.";
    }
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// ── Traitement du formulaire ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierTokenCSRF();
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $tel       = trim($_POST['tel'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $quartier  = (int)($_POST['id_quartier'] ?? 0);
    $role      = $_POST['role'] ?? 'client';

    $donnees = compact('nom','prenom','email','tel','quartier','role');

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
        $check = $pdo->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_utilisateur = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $erreur = "Cette adresse e-mail est déjà utilisée.";
        } else {
            $hash       = password_hash($password, PASSWORD_DEFAULT);
            $est_prest  = ($role === 'prestataire') ? 1 : 0;
            $est_client = ($role === 'client') ? 1 : 0;
            $est_valide = ($role === 'client') ? 1 : 0;

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
                $succes = "Compte prestataire créé ! En attente de validation par l'administrateur.";
            } else {
                $succes = "Compte créé avec succès ! Vous pouvez maintenant vous connecter.";
            }
            $donnees = [];
        }
    }
}

$role_actuel = $donnees['role'] ?? $role_defaut;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inscription — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;
  --paper-deep:#EAE7DF;
  --surface:#FFFFFF;
  --ink:#1C1B17;
  --ink-soft:#6C675C;
  --ink-faint:#9B9788;
  --line:#DAD6CB;
  --amber:#B96B2A;
  --amber-deep:#8A4E1B;
  --amber-tint:#F1E3D2;
  --teal:#2E6B5E;
  --teal-tint:#E4EDE9;
  --danger:#A6412B;
  --danger-tint:#F3E2DC;
  --radius:14px;
  --radius-sm:8px;
  --maxw:1180px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
  background:var(--paper);color:var(--ink);
  font-family:'Inter',sans-serif;font-size:16px;line-height:1.6;
  -webkit-font-smoothing:antialiased;
  min-height:100vh;display:flex;flex-direction:column;
}
a{color:inherit;text-decoration:none}
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 2rem}
h1,h2,.km-serif{font-family:'Fraunces',serif}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important}
}

/* ── MASTHEAD ── */
.km-masthead{border-bottom:1px solid var(--line);background:var(--paper)}
.km-masthead-inner{
  max-width:var(--maxw);margin:0 auto;padding:0 2rem;height:76px;
  display:flex;align-items:center;justify-content:space-between;gap:2rem;
}
.km-logo{font-family:'Fraunces',serif;font-weight:600;font-size:1.5rem;letter-spacing:-0.01em;color:var(--ink)}
.km-logo em{font-style:normal;color:var(--amber)}
.km-link-muted{font-size:0.88rem;color:var(--ink-soft)}
.km-link-muted:hover{color:var(--ink)}

/* ── BUTTONS ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;
  padding:0.82rem 1.3rem;font-family:'Inter',sans-serif;font-size:0.95rem;font-weight:600;
  border-radius:var(--radius-sm);border:1px solid transparent;cursor:pointer;width:100%;
  transition:transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}
.km-btn:active{transform:scale(0.98)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(139,78,27,0.25)}

/* ── MAIN LAYOUT ── */
main{flex:1;padding:3.2rem 0 4rem}
.km-page-head{max-width:560px;margin:0 auto 2.2rem;text-align:center}
.km-page-head h1{font-size:2.1rem;font-weight:600;letter-spacing:-0.01em}
.km-page-head p{color:var(--ink-soft);margin-top:0.5rem;font-size:0.98rem}

.km-card{
  max-width:640px;margin:0 auto;background:var(--surface);
  border:1px solid var(--line);border-radius:20px;padding:2.6rem 2.6rem 2.4rem;
  box-shadow:0 20px 50px rgba(28,27,23,0.05);
}

.km-error{
  margin-bottom:1.4rem;padding:0.75rem 1rem;border-left:2px solid var(--danger);
  background:var(--danger-tint);font-size:0.86rem;color:#7A2E1D;
}
.km-info-note{
  margin-top:0.9rem;padding:0.7rem 0.95rem;border-left:2px solid var(--teal);
  background:var(--teal-tint);font-size:0.84rem;color:#1E4B41;display:none;
}
.km-info-note.km-visible{display:block}

/* Rôle */
.km-field-label{
  font-size:0.78rem;font-weight:600;color:var(--ink-soft);
  text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.6rem;display:block;
}
.km-role-grid{display:grid;grid-template-columns:1fr 1fr;gap:0.8rem}
.km-role-tile{
  position:relative;border:1px solid var(--line);border-radius:var(--radius-sm);
  padding:1.1rem 1rem;cursor:pointer;transition:border-color 0.18s ease, background 0.18s ease;
  display:flex;flex-direction:column;gap:0.5rem;
}
.km-role-tile input{position:absolute;opacity:0;width:0;height:0}
.km-role-tile svg{width:20px;height:20px;stroke:var(--ink-soft);fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;transition:stroke 0.18s ease}
.km-role-tile-title{font-weight:600;font-size:0.95rem}
.km-role-tile-desc{font-size:0.8rem;color:var(--ink-soft);line-height:1.4}
.km-role-tile.km-checked{border-color:var(--amber-deep);background:var(--amber-tint)}
.km-role-tile.km-checked svg{stroke:var(--amber-deep)}

/* Champs */
.km-field{margin-top:1.3rem}
.km-field label{
  display:block;font-size:0.78rem;font-weight:600;color:var(--ink-soft);
  text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.4rem;
}
.km-row-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.km-input-wrap{position:relative}
.km-field input,.km-field select{
  width:100%;padding:0.78rem 1rem;background:var(--paper);
  border:1px solid var(--line);border-radius:var(--radius-sm);
  font-family:'Inter',sans-serif;font-size:0.94rem;color:var(--ink);
  outline:none;transition:border-color 0.18s ease, background 0.18s ease;
  appearance:none;-webkit-appearance:none;
}
.km-field select{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236C675C' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 1rem center;padding-right:2.3rem;
}
.km-field input:focus,.km-field select:focus{border-color:var(--ink);background:var(--surface)}
.km-field input::placeholder{color:var(--ink-faint)}
.km-toggle-pass{
  position:absolute;right:0.9rem;top:50%;transform:translateY(-50%);
  background:none;border:none;font-size:0.76rem;color:var(--ink-soft);cursor:pointer;
  font-family:'Inter',sans-serif;font-weight:600;
}
.km-toggle-pass:hover{color:var(--ink)}

.km-form-actions{margin-top:2rem}
.km-signup-note{margin-top:1.6rem;text-align:center;font-size:0.88rem;color:var(--ink-soft)}

/* Succès */
.km-success{text-align:center;padding:1rem 0.5rem}
.km-success-icon{
  width:52px;height:52px;border-radius:50%;background:var(--teal-tint);
  display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;
}
.km-success-icon svg{width:24px;height:24px;stroke:var(--teal);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.km-success h2{font-size:1.4rem;font-weight:600}
.km-success p{color:var(--ink-soft);margin-top:0.6rem;font-size:0.95rem;max-width:38ch;margin-left:auto;margin-right:auto}
.km-success .km-btn{max-width:280px;margin:1.6rem auto 0}

a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:640px){
  .wrap{padding:0 1.25rem}
  .km-masthead-inner{padding:0 1.25rem;height:64px}
  .km-card{padding:1.8rem 1.4rem}
  .km-row-2{grid-template-columns:1fr}
  .km-role-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<header class="km-masthead">
  <div class="km-masthead-inner">
    <a href="index.php" class="km-logo">Koud<em>Main</em></a>
    <a href="connexion.php" class="km-link-muted">Déjà un compte ? Se connecter</a>
  </div>
</header>

<main>
  <div class="wrap">

    <?php if ($succes): ?>
    <!-- ── ÉCRAN DE CONFIRMATION ── -->
    <div class="km-card km-success">
      <div class="km-success-icon">
        <svg viewBox="0 0 24 24"><path d="M5 12l5 5L19 7"/></svg>
      </div>
      <h2 class="km-serif">Compte créé</h2>
      <p><?= htmlspecialchars($succes) ?></p>
      <a href="connexion.php" class="km-btn km-btn-primary">Aller à la connexion</a>
    </div>

    <?php else: ?>
    <!-- ── FORMULAIRE ── -->
    <div class="km-page-head">
      <h1>Créer un compte</h1>
      <p>Rejoignez KoudMain pour accéder aux services de votre quartier.</p>
    </div>

    <div class="km-card">
      <?php if ($erreur): ?>
        <div class="km-error"><?= htmlspecialchars($erreur) ?></div>
      <?php endif; ?>

      <form method="POST" action="inscription.php" id="km-form-inscription">
        <?= champCSRF() ?>

        <div>
          <span class="km-field-label">Vous êtes</span>
          <div class="km-role-grid">
            <label class="km-role-tile" id="km-tile-client">
              <input type="radio" name="role" value="client" <?= $role_actuel === 'client' ? 'checked' : '' ?>>
              <svg viewBox="0 0 20 20"><circle cx="10" cy="6" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
              <span class="km-role-tile-title">Client</span>
              <span class="km-role-tile-desc">Je cherche un service près de chez moi</span>
            </label>
            <label class="km-role-tile" id="km-tile-prest">
              <input type="radio" name="role" value="prestataire" <?= $role_actuel === 'prestataire' ? 'checked' : '' ?>>
              <svg viewBox="0 0 20 20"><path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/></svg>
              <span class="km-role-tile-title">Prestataire</span>
              <span class="km-role-tile-desc">Je propose mes services au quartier</span>
            </label>
          </div>
          <div class="km-info-note" id="km-prest-notice">
            Votre compte sera validé par un administrateur avant de pouvoir publier une prestation.
          </div>
        </div>

        <div class="km-row-2">
          <div class="km-field">
            <label for="nom">Nom</label>
            <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($donnees['nom'] ?? '') ?>" placeholder="Koné" required>
          </div>
          <div class="km-field">
            <label for="prenom">Prénom</label>
            <input type="text" id="prenom" name="prenom" value="<?= htmlspecialchars($donnees['prenom'] ?? '') ?>" placeholder="Aminata" required>
          </div>
        </div>

        <div class="km-field">
          <label for="email">Adresse e-mail</label>
          <input type="email" id="email" name="email" value="<?= htmlspecialchars($donnees['email'] ?? '') ?>" placeholder="exemple@email.com" required>
        </div>

        <div class="km-row-2">
          <div class="km-field">
            <label for="tel">Téléphone</label>
            <input type="tel" id="tel" name="tel" value="<?= htmlspecialchars($donnees['tel'] ?? '') ?>" placeholder="07 00 00 00 00" required>
          </div>
          <div class="km-field">
            <label for="id_quartier">Quartier</label>
            <select id="id_quartier" name="id_quartier" required>
              <option value="">— Choisir —</option>
              <?php foreach ($quartiers as $q): ?>
                <option value="<?= $q['id_quartier'] ?>" <?= ($donnees['quartier'] ?? 0) == $q['id_quartier'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($q['nom_quartier'] . ' (' . $q['nom_ville'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="km-row-2">
          <div class="km-field">
            <label for="password">Mot de passe</label>
            <div class="km-input-wrap">
              <input type="password" id="password" name="password" placeholder="Min. 6 caractères" required>
              <button type="button" class="km-toggle-pass" data-target="password">Afficher</button>
            </div>
          </div>
          <div class="km-field">
            <label for="password2">Confirmer</label>
            <div class="km-input-wrap">
              <input type="password" id="password2" name="password2" placeholder="Répéter" required>
              <button type="button" class="km-toggle-pass" data-target="password2">Afficher</button>
            </div>
          </div>
        </div>

        <div class="km-form-actions">
          <button type="submit" class="km-btn km-btn-primary">Créer mon compte</button>
        </div>
      </form>

      <div class="km-signup-note">Déjà un compte ? <a href="connexion.php" style="color:var(--ink);font-weight:600">Se connecter</a></div>
    </div>
    <?php endif; ?>

  </div>
</main>

<script>
// Bascule visuelle du sélecteur de rôle + note prestataire
function majRole() {
  const checked = document.querySelector('input[name="role"]:checked');
  document.getElementById('km-tile-client').classList.toggle('km-checked', checked && checked.value === 'client');
  document.getElementById('km-tile-prest').classList.toggle('km-checked', checked && checked.value === 'prestataire');
  document.getElementById('km-prest-notice').classList.toggle('km-visible', checked && checked.value === 'prestataire');
}
document.querySelectorAll('input[name="role"]').forEach(r => r.addEventListener('change', majRole));
majRole();

// Afficher / masquer les mots de passe
document.querySelectorAll('.km-toggle-pass').forEach(btn => {
  btn.addEventListener('click', function () {
    const input = document.getElementById(this.dataset.target);
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    this.textContent = showing ? 'Afficher' : 'Masquer';
  });
});
</script>

</body>
</html>
