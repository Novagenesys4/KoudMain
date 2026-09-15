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

// ── Quartiers actifs pour le panneau de vitrine ──
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Connexion — KoudMain</title>
<meta name="theme-color" content="#f4f2ed">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F4F3EE;
  --surface:#FFFFFF;
  --ink:#1B1A16;
  --ink-soft:#6C695F;
  --ink-faint:#9B9788;
  --line:#E1DED4;
  --line-dark:rgba(255,255,255,.12);
  --amber:#C97A34;
  --amber-deep:#8A4E1B;
  --amber-tint:#F1E3D2;
  --teal:#3E9C79;
  --radius-card:26px;
  --radius-sm:10px;
  --maxw:1220px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
  background:var(--paper);color:var(--ink);
  font-family:'Inter',sans-serif;font-size:16px;line-height:1.6;
  -webkit-font-smoothing:antialiased;
  min-height:100vh;display:flex;flex-direction:column;
}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
.km-wrap{max-width:var(--maxw);margin:0 auto;padding:0 2.2rem}
.km-serif{font-family:'Fraunces',serif}
.km-mono{font-family:'DM Mono',monospace}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important}
}

/* ── TOPBAR ── */
.km-topbar{border-bottom:1px solid var(--line)}
.km-topbar-inner{
  max-width:var(--maxw);margin:0 auto;padding:1.5rem 2.2rem;
  display:flex;align-items:center;justify-content:space-between;gap:1.5rem;
}
.km-logo{font-family:'Fraunces',serif;font-weight:700;font-size:1.35rem;letter-spacing:-0.01em;color:var(--ink)}
.km-logo span{color:var(--amber)}
.km-back{
  display:inline-flex;align-items:center;gap:0.5rem;font-size:0.92rem;color:var(--ink-soft);
  transition:color .18s ease, gap .18s ease;
}
.km-back:hover{color:var(--ink);gap:0.7rem}

/* ── MAIN ── */
main{flex:1;display:flex;align-items:center;padding:3.2rem 0 4rem}

.km-auth-card{
  width:100%;display:grid;grid-template-columns:1.08fr 1fr;
  border-radius:var(--radius-card);overflow:hidden;
  box-shadow:0 30px 70px rgba(27,26,22,0.12);
}

/* ── SHOWCASE (panneau sombre) ── */
.km-showcase{
  position:relative;overflow:hidden;background:var(--ink);color:#E8E4D8;
  padding:2.6rem 2.8rem 3rem;
}
.km-showcase-rings{
  position:absolute;top:8%;right:-18%;width:520px;height:520px;
  border-radius:50%;pointer-events:none;
  background:
    radial-gradient(circle, transparent 138px, rgba(255,255,255,0.045) 139px, rgba(255,255,255,0.045) 141px, transparent 142px),
    radial-gradient(circle, transparent 218px, rgba(255,255,255,0.035) 219px, rgba(255,255,255,0.035) 221px, transparent 222px),
    radial-gradient(circle, transparent 298px, rgba(255,255,255,0.025) 299px, rgba(255,255,255,0.025) 301px, transparent 302px);
}
.km-showcase-rings::after{
  content:'';position:absolute;bottom:-260px;left:-160px;width:420px;height:420px;border-radius:50%;
  border:1px solid rgba(255,255,255,0.06);
}
.km-showcase-top{
  position:relative;display:flex;align-items:center;justify-content:space-between;
  font-size:0.72rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#B9B4A5;
  margin-bottom:2.3rem;
}
.km-showcase-eyebrow{display:inline-flex;align-items:center;gap:0.45rem}
.km-showcase-count{color:#7E7B70;letter-spacing:0.06em}

.km-showcase-title{
  position:relative;font-size:clamp(2rem,3.1vw,2.7rem);font-weight:600;line-height:1.12;
  letter-spacing:-0.015em;margin-bottom:1.3rem;
}
.km-showcase-title em{font-style:normal;color:var(--amber)}

.km-showcase-desc{
  position:relative;color:#C9C5B9;font-size:0.98rem;max-width:34ch;margin-bottom:2.2rem;
}

.km-feature-list{position:relative;display:flex;flex-direction:column;gap:1.15rem;margin-bottom:2.3rem}
.km-feature{display:flex;gap:0.85rem;align-items:flex-start}
.km-feature-icon{
  flex-shrink:0;width:32px;height:32px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  border:1px solid rgba(201,122,52,0.35);color:var(--amber);
  background:rgba(201,122,52,0.08);
}
.km-feature-icon svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.km-feature strong{display:block;font-size:0.94rem;font-weight:600;color:#F2EFE6}
.km-feature p{margin-top:0.25rem;font-size:0.85rem;color:#A8A398;line-height:1.5}

.km-quartiers-block{position:relative;margin-bottom:1.6rem}
.km-quartiers-label{
  display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;font-weight:700;
  letter-spacing:0.09em;text-transform:uppercase;color:#8B8778;margin-bottom:0.85rem;
}
.km-quartier-pills{display:flex;flex-wrap:wrap;gap:0.5rem}
.km-qpill{
  font-size:0.82rem;padding:0.42rem 0.9rem;border-radius:20px;
  border:1px solid rgba(255,255,255,0.16);color:#E4E0D3;background:rgba(255,255,255,0.02);
}

.km-showcase-foot{
  position:relative;padding-top:1.4rem;border-top:1px solid rgba(255,255,255,0.1);
  font-size:0.82rem;color:#84806F;font-family:'DM Mono',monospace;
}

/* ── FORM SIDE ── */
.km-form-side{background:var(--surface);padding:3rem 3.2rem}
.km-eyebrow{
  display:block;font-size:0.76rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;
  color:var(--amber-deep);margin-bottom:0.7rem;
}
.km-form-side h1{font-family:'Fraunces',serif;font-size:2.35rem;font-weight:600;letter-spacing:-0.015em}
.km-form-sub{color:var(--ink-soft);margin-top:0.5rem;font-size:0.96rem;margin-bottom:2rem}

.km-error{
  margin-bottom:1.3rem;padding:0.75rem 1rem;border-left:2px solid #A6412B;
  background:#F3E2DC;font-size:0.86rem;color:#7A2E1D;border-radius:4px;
  animation:km-fade-in 0.35s ease;
}
@keyframes km-fade-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}

.km-field{margin-bottom:1.35rem}
.km-field label{
  display:block;font-size:0.76rem;font-weight:700;color:var(--ink-soft);
  text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem;
}
.km-pass-wrap{position:relative}
.km-field input{
  width:100%;padding:0.85rem 1.05rem;background:#F7F6F2;
  border:1px solid var(--line);border-radius:var(--radius-sm);
  font-family:'Inter',sans-serif;font-size:0.96rem;color:var(--ink);
  outline:none;transition:border-color 0.18s ease, background 0.18s ease;
}
.km-field input:focus{border-color:var(--ink);background:var(--surface)}
.km-field input::placeholder{color:var(--ink-faint)}
.km-pass-wrap input{padding-right:5.2rem}
.km-toggle-pass{
  position:absolute;right:0.9rem;top:50%;transform:translateY(-50%);
  display:inline-flex;align-items:center;gap:0.35rem;
  background:none;border:none;font-size:0.82rem;color:var(--ink-soft);cursor:pointer;font-weight:600;
}
.km-toggle-pass:hover{color:var(--ink)}
.km-toggle-pass svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}

.km-submit{
  width:100%;display:inline-flex;align-items:center;justify-content:center;gap:0.55rem;
  padding:0.98rem 1.4rem;margin-top:0.4rem;
  background:var(--ink);color:#F5F4EE;border:none;border-radius:var(--radius-sm);
  font-size:0.98rem;font-weight:700;cursor:pointer;
  transition:background 0.2s ease, transform 0.16s ease, box-shadow 0.2s ease;
}
.km-submit:hover{background:var(--amber-deep);box-shadow:0 10px 26px rgba(138,78,27,0.24)}
.km-submit:active{transform:scale(0.98)}
.km-submit svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.km-divider{height:1px;background:var(--line);margin:2rem 0 1.5rem}

.km-signup-q{text-align:center;color:var(--ink-soft);font-size:0.92rem;margin-bottom:1rem}
.km-role-choices{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem}
.km-role-choice{
  display:flex;align-items:center;gap:0.55rem;padding:0.85rem 1rem;
  border:1px solid var(--line);border-radius:var(--radius-sm);
  font-size:0.88rem;font-weight:600;color:var(--ink);
  transition:border-color 0.18s ease, background 0.18s ease, transform .16s ease;
}
.km-role-choice:hover{border-color:var(--amber-deep);background:var(--amber-tint);transform:translateY(-1px)}
.km-role-choice span{color:var(--ink-faint);font-family:'DM Mono',monospace;font-size:0.78rem}
.km-role-choice svg{width:13px;height:13px;margin-left:auto;fill:none;stroke:var(--amber-deep);stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ── FOOTER ── */
.km-footer{
  border-top:1px solid var(--line);padding:1.6rem 0;
}
.km-footer-inner{
  max-width:var(--maxw);margin:0 auto;padding:0 2.2rem;
  display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
  font-family:'DM Mono',monospace;font-size:0.78rem;color:var(--ink-faint);
}
.km-status{display:inline-flex;align-items:center;gap:0.4rem}
.km-status .dot{width:6px;height:6px;border-radius:50%;background:var(--teal)}

a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:980px){
  .km-auth-card{grid-template-columns:1fr}
  .km-showcase{padding:2.4rem 2rem 2.6rem}
  .km-form-side{padding:2.6rem 2rem}
  .km-feature-list{gap:1rem}
}
@media(max-width:640px){
  .km-wrap{padding:0 1.1rem}
  .km-topbar-inner{padding:1.2rem 1.1rem}
  .km-back span{display:none}
  main{padding:1.6rem 0 2.4rem}
  .km-showcase-title{font-size:2.1rem}
  .km-role-choices{grid-template-columns:1fr}
  .km-footer-inner{padding:0 1.1rem;flex-direction:column;align-items:flex-start;gap:0.4rem}
}
</style>
</head>
<body>

<header class="km-topbar">
  <div class="km-topbar-inner">
    <a href="index.php" class="km-logo">Koud<span>Main</span></a>
    <a href="index.php" class="km-back">
      <svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15 7 10l5-5"/></svg>
      <span>Retour à l'accueil</span>
    </a>
  </div>
</header>

<main>
  <div class="km-wrap">
    <div class="km-auth-card">

      <!-- ── PANNEAU VITRINE ── -->
      <section class="km-showcase">
        <div class="km-showcase-rings" aria-hidden="true"></div>

        <div class="km-showcase-top">
          <span class="km-showcase-eyebrow">✦ L'expérience KoudMain</span>
          <span class="km-showcase-count km-mono">01 / 04</span>
        </div>

        <h2 class="km-showcase-title km-serif">Le coup de main<br><em>qui tombe<br>juste.</em></h2>

        <p class="km-showcase-desc">Retrouvez vos commandes, votre wallet et les personnes de confiance qui font avancer votre quotidien.</p>

        <div class="km-feature-list">
          <div class="km-feature">
            <span class="km-feature-icon">
              <svg viewBox="0 0 20 20"><path d="M10 2.5 16 5v4.5c0 4-2.6 6.6-6 7.8-3.4-1.2-6-3.8-6-7.8V5l6-2.5Z"/><path d="M7.3 10 9 11.7l3.4-3.4"/></svg>
            </span>
            <div>
              <strong>Des profils vérifiés</strong>
              <p>Chaque prestataire est validé avant sa première publication.</p>
            </div>
          </div>
          <div class="km-feature">
            <span class="km-feature-icon">
              <svg viewBox="0 0 20 20"><rect x="3.5" y="9" width="13" height="8" rx="2"/><path d="M6.3 9V6a3.7 3.7 0 0 1 7.4 0v3"/></svg>
            </span>
            <div>
              <strong>Un wallet, simplement</strong>
              <p>Vos commandes sont réglées et suivies au même endroit.</p>
            </div>
          </div>
          <div class="km-feature">
            <span class="km-feature-icon">
              <svg viewBox="0 0 20 20"><path d="M4 10.5 8 14l8-8"/></svg>
            </span>
            <div>
              <strong>Une communauté attentive</strong>
              <p>Après chaque prestation, votre retour compte vraiment.</p>
            </div>
          </div>
        </div>

        <div class="km-quartiers-block">
          <span class="km-quartiers-label">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 18c3-3.2 6-6.4 6-10a6 6 0 1 0-12 0c0 3.6 3 6.8 6 10Z"/><circle cx="10" cy="8" r="2"/></svg>
            Quartiers actifs à Abidjan
          </span>
          <div class="km-quartier-pills">
            <?php foreach ($demo_quartiers as $q): ?>
              <span class="km-qpill"><?= htmlspecialchars($q) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="km-showcase-foot">Des services utiles, près de chez vous.</div>
      </section>

      <!-- ── FORMULAIRE ── -->
      <section class="km-form-side">
        <span class="km-eyebrow">Espace personnel</span>
        <h1 class="km-serif">Connexion</h1>
        <p class="km-form-sub">Accédez à votre espace KoudMain.</p>

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
            <div class="km-pass-wrap">
              <input type="password" id="password" name="password" placeholder="••••••••" required>
              <button type="button" class="km-toggle-pass" id="km-toggle-pass">
                <svg viewBox="0 0 20 20" id="km-eye-icon"><path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6Z"/><circle cx="10" cy="10" r="2.4"/></svg>
                <span id="km-toggle-label">Afficher</span>
              </button>
            </div>
          </div>

          <button type="submit" class="km-submit">
            Se connecter
            <svg viewBox="0 0 20 20"><path d="M6 14 14 6"/><path d="M7 6h7v7"/></svg>
          </button>
        </form>

        <div class="km-divider"></div>

        <p class="km-signup-q">Pas encore de compte ?</p>
        <div class="km-role-choices">
          <a href="inscription.php?role=client" class="km-role-choice">
            <span class="km-mono">01</span> Je suis client
            <svg viewBox="0 0 20 20"><path d="M6 14 14 6"/><path d="M7 6h7v7"/></svg>
          </a>
          <a href="inscription.php?role=prestataire" class="km-role-choice">
            <span class="km-mono">02</span> Je suis prestataire
            <svg viewBox="0 0 20 20"><path d="M6 14 14 6"/><path d="M7 6h7v7"/></svg>
          </a>
        </div>
      </section>

    </div>
  </div>
</main>

<footer class="km-footer">
  <div class="km-footer-inner">
    <span>© <?= date('Y') ?> KoudMain</span>
    <span>Des services utiles, une relation humaine.</span>
    <span class="km-status"><span class="dot"></span>Système opérationnel</span>
  </div>
</footer>

<script>
const toggleBtn = document.getElementById('km-toggle-pass');
const toggleLabel = document.getElementById('km-toggle-label');
const passInput = document.getElementById('password');
toggleBtn.addEventListener('click', function () {
  const showing = passInput.type === 'text';
  passInput.type = showing ? 'password' : 'text';
  toggleLabel.textContent = showing ? 'Afficher' : 'Masquer';
});
</script>

</body>
</html>
