<?php
require_once "config.php";

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    rediriger("index.php");
}

if (estConnecte()) rediriger("index.php");

$erreur = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierTokenCSRF();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $erreur = "Veuillez remplir tous les champs.";
    } else {
        $pdo  = getConnexion();
        $stmt = $pdo->prepare("SELECT * FROM Utilisateur WHERE email_utilisateur = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $erreur = "Aucun compte trouvé avec cette adresse e-mail.";
        } elseif (!password_verify($password, $user['mot_de_passe'])) {
            $erreur = "Mot de passe incorrect.";
        } elseif ($user['est_prestataire'] && !$user['est_valide'] && !$user['est_admin']) {
            $erreur = "Votre compte prestataire est en attente de validation par l'administrateur.";
        } else {
            session_regenerate_id(true);
            $_SESSION['id_utilisateur']  = $user['id_utilisateur'];
            $_SESSION['nom']             = $user['nom_utilisateur'];
            $_SESSION['prenom']          = $user['prenom_utilisateur'];
            $_SESSION['email']           = $user['email_utilisateur'];
            $_SESSION['est_prestataire'] = $user['est_prestataire'];
            $_SESSION['est_client']      = $user['est_client'];
            $_SESSION['est_admin']       = (bool)$user['est_admin'];

            unset($user['mot_de_passe']);
            $_SESSION['utilisateur']     = $user;

            if ($user['est_admin'])          rediriger("admin_dashboard.php");
            elseif ($user['est_prestataire']) rediriger("prestataire_dashboard.php");
            else                             rediriger("client_dashboard.php");
        }
    }
}

// ── Quartiers pour le panneau de contexte ──
$demo_quartiers = [];
try {
    $pdo2 = getConnexion();
    $demo_quartiers = array_column(
        $pdo2->query("SELECT nom_quartier FROM Quartier ORDER BY nom_quartier LIMIT 6")->fetchAll(),
        'nom_quartier'
    );
} catch (Throwable $e) {
    // Le panneau reste utilisable sans base de données.
}
if (empty($demo_quartiers)) {
    $demo_quartiers = ['Cocody', 'Yopougon', 'Marcory', 'Adjamé', 'Koumassi', 'Plateau'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#f4f2ec;
  --paper-deep:#ebe8df;
  --surface:#fffefa;
  --ink:#1f201c;
  --ink-soft:#77766e;
  --ink-faint:#a6a49a;
  --line:#dedbd1;
  --amber:#ba6d2c;
  --amber-deep:#8d4d1d;
  --amber-tint:#f5e7d7;
  --teal:#2b6d60;
  --teal-tint:#e5f0eb;
  --danger:#a74935;
  --danger-tint:#f4e3df;
  --radius:16px;
  --radius-sm:8px;
  --maxw:1180px;
  --ease:cubic-bezier(.2,.8,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
  background:var(--paper);color:var(--ink);
  font-family:"DM Sans",sans-serif;font-size:16px;line-height:1.6;
  -webkit-font-smoothing:antialiased;
  min-height:100vh;display:flex;flex-direction:column;position:relative;
}
body::before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.035;z-index:1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.5'/%3E%3C/svg%3E")}
a{color:inherit;text-decoration:none}
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 2rem}
h1,h2,.km-serif{font-family:Fraunces,Georgia,serif}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important}
}

/* ── MASTHEAD (identique à index.php) ── */
.km-masthead{border-bottom:1px solid var(--line);background:var(--paper)}
.km-masthead-inner{
  max-width:var(--maxw);margin:0 auto;padding:0 2rem;height:76px;
  display:flex;align-items:center;justify-content:space-between;gap:2rem;
}
.km-logo{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:1.5rem;letter-spacing:-0.01em;color:var(--ink)}
.km-logo em{font-style:normal;color:#d58d51}
.km-link-muted{font-size:0.88rem;color:var(--ink-soft)}
.km-link-muted:hover{color:var(--ink)}

/* ── BUTTONS ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;
  padding:0.78rem 1.3rem;font-family:"DM Sans",sans-serif;font-size:0.92rem;font-weight:600;
  border-radius:var(--radius-sm);border:1px solid transparent;cursor:pointer;width:100%;
  transition:transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}
.km-btn:active{transform:scale(0.98)}
.km-btn-primary{background:var(--ink);color:#fff}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(139,78,27,0.25)}

/* ── MAIN LAYOUT ── */
main{flex:1;display:flex;align-items:center;padding:3.5rem 0}
.km-auth-grid{
  display:grid;grid-template-columns:0.95fr 1.05fr;gap:0;
  max-width:980px;margin:0 auto;
  border:1px solid var(--line);border-radius:20px;overflow:hidden;
  background:var(--surface);
  box-shadow:3px 4px 0 rgba(31,32,28,.035), 0 20px 50px rgba(28,27,23,0.05);
}

/* Panneau de contexte (gauche) */
.km-context{
  background:#1e201d;color:#e8e8df;padding:3rem 2.6rem;
  display:flex;flex-direction:column;justify-content:space-between;
}
.km-context h2{font-size:1.6rem;font-weight:600;line-height:1.25;letter-spacing:-0.01em;max-width:18ch}
.km-context-facts{margin-top:2rem;display:flex;flex-direction:column;gap:1.1rem}
.km-fact{display:flex;gap:0.8rem;align-items:flex-start}
.km-fact-dot{
  width:6px;height:6px;border-radius:50%;background:var(--amber);
  margin-top:0.55rem;flex-shrink:0;
}
.km-fact p{font-size:0.9rem;color:#CFCBBD;line-height:1.55}
.km-context-quartiers{margin-top:2.4rem;padding-top:1.6rem;border-top:1px solid rgba(255,255,255,0.14)}
.km-context-quartiers-label{font-size:0.76rem;color:#9C9788;margin-bottom:0.6rem}
.km-quartier-chips{display:flex;flex-wrap:wrap;gap:0.45rem}
.km-quartier-chip{
  font-size:0.78rem;padding:0.28rem 0.65rem;border-radius:20px;
  border:1px solid rgba(255,255,255,0.18);color:#E8E4D8;
}

/* Formulaire (droite) */
.km-form-panel{padding:3rem 2.8rem}
.km-form-panel h1{font-size:1.9rem;font-weight:600;letter-spacing:-0.01em}
.km-form-panel > p{color:var(--ink-soft);margin-top:0.5rem;font-size:0.95rem}

.km-error{
  margin-top:1.4rem;padding:0.75rem 1rem;border-left:2px solid var(--danger);
  background:var(--danger-tint);font-size:0.86rem;color:#7A2E1D;
  opacity:0;transform:translateY(-4px);animation:km-fade-in 0.35s ease forwards;
}
@keyframes km-fade-in{to{opacity:1;transform:translateY(0)}}

.km-field{
  margin-top:1.4rem;
  opacity:0;transform:translateY(8px);
  animation:km-rise 0.5s cubic-bezier(.22,1,.36,1) forwards;
}
.km-field:nth-of-type(1){animation-delay:0.06s}
.km-field:nth-of-type(2){animation-delay:0.14s}
@keyframes km-rise{to{opacity:1;transform:translateY(0)}}

.km-field label{
  display:block;font-size:0.78rem;font-weight:600;color:var(--ink-soft);
  text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.45rem;
}
.km-input-wrap{position:relative}
.km-field input{
  width:100%;padding:0.8rem 1rem;background:var(--paper);
  border:1px solid var(--line);border-radius:var(--radius-sm);
  font-family:"DM Sans",sans-serif;font-size:0.95rem;color:var(--ink);
  outline:none;transition:border-color 0.18s ease, background 0.18s ease;
}
.km-field input:focus{border-color:var(--ink);background:var(--surface)}
.km-field input::placeholder{color:var(--ink-faint)}
.km-toggle-pass{
  position:absolute;right:0.9rem;top:50%;transform:translateY(-50%);
  background:none;border:none;font-size:0.78rem;color:var(--ink-soft);cursor:pointer;
  font-family:"DM Sans",sans-serif;font-weight:600;
}
.km-toggle-pass:hover{color:var(--ink)}

.km-form-actions{
  margin-top:1.9rem;
  opacity:0;transform:translateY(8px);animation:km-rise 0.5s cubic-bezier(.22,1,.36,1) forwards;
  animation-delay:0.22s;
}

.km-signup-note{
  margin-top:1.8rem;padding-top:1.6rem;border-top:1px solid var(--line);
  font-size:0.88rem;color:var(--ink-soft);text-align:center;
}
.km-signup-choices{display:flex;gap:0.7rem;margin-top:0.8rem}
.km-signup-choice{
  flex:1;text-align:center;padding:0.65rem 0.5rem;border:1px solid var(--line);
  border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;color:var(--ink);
  transition:border-color 0.18s ease, background 0.18s ease;
}
.km-signup-choice:hover{border-color:var(--amber-deep);background:var(--amber-tint)}

a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:820px){
  .km-auth-grid{grid-template-columns:1fr;max-width:460px;border-radius:16px}
  .km-context{display:none}
  .km-form-panel{padding:2.4rem 1.8rem}
}
@media(max-width:520px){
  .wrap{padding:0 1.25rem}
  .km-masthead-inner{padding:0 1.25rem;height:64px}
  main{padding:2rem 0}
}
</style>
</head>
<body>

<header class="km-masthead">
  <div class="km-masthead-inner">
    <a href="index.php" class="km-logo">Koud<em>Main</em></a>
    <a href="index.php" class="km-link-muted">← Retour à l'accueil</a>
  </div>
</header>

<main>
  <div class="wrap">
    <div class="km-auth-grid">

      <!-- ── Panneau de contexte ── -->
      <div class="km-context">
        <div>
          <h2 class="km-serif">Retrouvez vos commandes et votre wallet en un instant.</h2>
          <div class="km-context-facts">
            <div class="km-fact">
              <span class="km-fact-dot"></span>
              <p>Les comptes prestataires sont validés par un administrateur avant leur première publication.</p>
            </div>
            <div class="km-fact">
              <span class="km-fact-dot"></span>
              <p>Chaque commande est réglée via votre Wallet KoudMain et suivie jusqu'à sa fin.</p>
            </div>
            <div class="km-fact">
              <span class="km-fact-dot"></span>
              <p>Une note et un commentaire sont demandés après chaque prestation terminée.</p>
            </div>
          </div>
        </div>
        <div class="km-context-quartiers">
          <div class="km-context-quartiers-label">Quartiers actifs</div>
          <div class="km-quartier-chips">
            <?php foreach ($demo_quartiers as $q): ?>
              <span class="km-quartier-chip"><?= htmlspecialchars($q) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ── Formulaire ── -->
      <div class="km-form-panel">
        <h1>Connexion</h1>
        <p>Accédez à votre espace KoudMain.</p>

        <?php if ($erreur): ?>
          <div class="km-error"><?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <form method="POST" action="connexion.php">
          <?= champCSRF() ?>

          <div class="km-field">
            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" placeholder="votre@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
          </div>

          <div class="km-field">
            <label for="password">Mot de passe</label>
            <div class="km-input-wrap">
              <input type="password" id="password" name="password" placeholder="••••••••" required>
              <button type="button" class="km-toggle-pass" id="km-toggle-pass">Afficher</button>
            </div>
          </div>

          <div class="km-form-actions">
            <button type="submit" class="km-btn km-btn-primary">Se connecter</button>
          </div>
        </form>

        <div class="km-signup-note">
          Pas encore de compte ?
          <div class="km-signup-choices">
            <a href="inscription.php?role=client" class="km-signup-choice">Je suis client</a>
            <a href="inscription.php?role=prestataire" class="km-signup-choice">Je suis prestataire</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<script>
document.getElementById('km-toggle-pass').addEventListener('click', function () {
  const input = document.getElementById('password');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  this.textContent = showing ? 'Afficher' : 'Masquer';
});
</script>

</body>
</html>
