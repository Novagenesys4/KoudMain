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

/* ── MASTHEAD (identique à index.php) ── */
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
  padding:0.78rem 1.3rem;font-family:'Inter',sans-serif;font-size:0.92rem;font-weight:600;
  border-radius:var(--radius-sm);border:1px solid transparent;cursor:pointer;width:100%;
  transition:transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}
.km-btn:active{transform:scale(0.98)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(139,78,27,0.25)}

/* ── MAIN LAYOUT ── */
main{flex:1;display:flex;align-items:center;padding:3.5rem 0}
.km-auth-grid{
  display:grid;grid-template-columns:0.95fr 1.05fr;gap:0;
  max-width:980px;margin:0 auto;
  border:1px solid var(--line);border-radius:20px;overflow:hidden;
  background:var(--surface);
  box-shadow:0 20px 50px rgba(28,27,23,0.05);
}

/* Panneau de contexte (gauche) */
.km-context{
  background:var(--ink);color:var(--paper);padding:3rem 2.6rem;
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
  font-family:'Inter',sans-serif;font-size:0.95rem;color:var(--ink);
  outline:none;transition:border-color 0.18s ease, background 0.18s ease;
}
.km-field input:focus{border-color:var(--ink);background:var(--surface)}
.km-field input::placeholder{color:var(--ink-faint)}
.km-toggle-pass{
  position:absolute;right:0.9rem;top:50%;transform:translateY(-50%);
  background:none;border:none;font-size:0.78rem;color:var(--ink-soft);cursor:pointer;
  font-family:'Inter',sans-serif;font-weight:600;
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

<style id="koudmain-ui-inline">
:root{
  --km-border-soft:color-mix(in srgb,var(--line,#dad6cb) 82%,transparent);
  --km-shadow-sm:0 4px 18px rgba(38,35,27,.06);
  --km-shadow-md:0 16px 42px rgba(38,35,27,.10);
  --km-focus:#c77732;
  --km-success:#2e6b5e;
}
html{transition:background-color .25s ease;color-scheme:light}
body{transition:background-color .25s ease,color .25s ease}
button,input,select,textarea{transition:background-color .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease,transform .16s ease}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,a:focus-visible{outline:3px solid color-mix(in srgb,var(--km-focus) 48%,transparent);outline-offset:3px}
.km-theme-toggle{display:inline-grid;place-items:center;width:38px;height:38px;border:1px solid var(--line,#dad6cb);border-radius:11px;background:var(--surface,#fff);color:var(--ink,#1c1b17);cursor:pointer;box-shadow:var(--km-shadow-sm);font-size:1rem}
.km-theme-toggle:hover{transform:translateY(-1px);border-color:var(--amber,#b96b2a)}
.km-collapse-toggle{display:inline-grid;place-items:center;width:34px;height:34px;border:1px solid rgba(255,255,255,.14);border-radius:9px;color:inherit;cursor:pointer}
.km-collapse-toggle:hover{background:rgba(255,255,255,.1)}
.km-sidebar,.admin-sidebar,.sidebar{transition:width .28s ease,transform .28s ease,box-shadow .28s ease}
body.km-sidebar-collapsed .km-sidebar{width:78px}
body.km-sidebar-collapsed .km-main{margin-left:78px}
body.km-sidebar-collapsed .km-sb-brand{padding-left:1rem;padding-right:1rem}
body.km-sidebar-collapsed .km-sb-brand a,.km-sidebar .km-collapse-label,body.km-sidebar-collapsed .km-sb-link span:not(.km-sb-icon):not(.km-sb-badge),body.km-sidebar-collapsed .km-sb-section,body.km-sidebar-collapsed .km-wallet-mini .w-label,body.km-sidebar-collapsed .km-wallet-mini .w-solde{display:none}
body.km-sidebar-collapsed .km-sb-link{justify-content:center;padding-left:.65rem;padding-right:.65rem}
body.km-sidebar-collapsed .km-sb-badge{position:absolute;right:7px;top:4px;font-size:.58rem;padding:.04rem .28rem}
body.km-sidebar-collapsed .km-wallet-mini{text-align:center;padding:.65rem .2rem}
body.km-admin-collapsed .admin-sidebar{width:82px}
body.km-admin-collapsed .admin-main{margin-left:82px}
body.km-admin-collapsed .admin-sidebar .brand-name,body.km-admin-collapsed .admin-sidebar .sidebar-caption,body.km-admin-collapsed .admin-sidebar .sidebar-link span,body.km-admin-collapsed .admin-sidebar .sidebar-bottom div{display:none}
body.km-admin-collapsed .admin-sidebar .sidebar-link{justify-content:center;padding-left:8px;padding-right:8px}
body.km-admin-collapsed .admin-sidebar .sidebar-badge{position:absolute;right:7px;top:3px}
.km-sidebar .km-sb-link,.sidebar-link{transition:transform .18s ease,background-color .18s ease,color .18s ease}
.km-sidebar .km-sb-link:hover,.sidebar-link:hover{transform:translateX(3px)}
.km-stat,.stat-card,.km-panel,.panel,.km-prest-card,.km-order-card{box-shadow:var(--km-shadow-sm);transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease,background-color .22s ease}
.km-stat:hover,.stat-card:hover,.km-prest-card:hover{transform:translateY(-3px);box-shadow:var(--km-shadow-md)}
.km-prest-card{position:relative;overflow:hidden}
.km-prest-card::after{content:"";position:absolute;inset:auto 0 0;height:3px;background:linear-gradient(90deg,var(--amber,#b96b2a),var(--teal,#2e6b5e));transform:scaleX(0);transform-origin:left;transition:transform .25s ease}
.km-prest-card:hover::after{transform:scaleX(1)}
.km-btn,.km-button{position:relative;overflow:hidden}
.km-btn::after,.km-button::after{content:"";position:absolute;inset:0;background:rgba(255,255,255,.18);transform:translateX(-105%) skewX(-18deg);transition:transform .35s ease}
.km-btn:hover::after,.km-button:hover::after{transform:translateX(105%) skewX(-18deg)}
.data-table tbody tr,.km-order-card{animation:km-rise .36s both}
.data-table tbody tr:nth-child(2),.km-order-card:nth-child(2){animation-delay:.035s}.data-table tbody tr:nth-child(3),.km-order-card:nth-child(3){animation-delay:.07s}
@keyframes km-rise{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.km-rate-stars{display:inline-flex;align-items:center;gap:2px}
.km-rate-stars button{border:0;background:transparent;color:var(--ink-faint,#9b9788);padding:3px;cursor:pointer;font-size:1.15rem;line-height:1}
.km-rate-stars button:hover,.km-rate-stars button.active{color:var(--amber-deep,#8a4e1b);transform:scale(1.12)}
.km-rate-stars button:focus-visible{outline:2px solid var(--amber,#b96b2a);outline-offset:1px}
.km-live-empty{grid-column:1/-1;padding:2rem;text-align:center;color:var(--ink-soft,#6c675c);border:1px dashed var(--line,#dad6cb);border-radius:var(--radius,14px);background:var(--surface,#fff)}
.km-confirm-overlay{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:1rem;background:rgba(20,22,18,.52);backdrop-filter:blur(5px);opacity:0;pointer-events:none;transition:opacity .2s ease}
.km-confirm-overlay.open{opacity:1;pointer-events:auto}
.km-confirm-card{width:min(430px,100%);background:var(--surface,#fff);color:var(--ink,#1c1b17);border:1px solid var(--line,#dad6cb);border-radius:18px;padding:1.5rem;box-shadow:0 24px 70px rgba(0,0,0,.22);transform:translateY(10px) scale(.98);transition:transform .22s ease}
.km-confirm-overlay.open .km-confirm-card{transform:none}.km-confirm-card h2{font:600 1.3rem 'Fraunces',serif}.km-confirm-card p{margin:.55rem 0 1.3rem;color:var(--ink-soft,#6c675c);font-size:.92rem}.km-confirm-actions{display:flex;justify-content:flex-end;gap:.65rem}.km-confirm-cancel,.km-confirm-ok{padding:.62rem 1rem;border-radius:9px;font-weight:700;cursor:pointer}.km-confirm-cancel{border:1px solid var(--line,#dad6cb);background:transparent;color:inherit}.km-confirm-ok{border:1px solid var(--danger,#a6412b);background:var(--danger,#a6412b);color:#fff}.km-confirm-ok:hover{filter:brightness(1.08)}
body.km-dark,html[data-theme=dark]{color-scheme:dark}
body.km-dark{--paper:#151815;--paper-deep:#20251f;--surface:#20251f;--ink:#f0eee6;--ink-soft:#b4b7aa;--ink-faint:#858b80;--line:#3a4038;--amber-tint:#3b2d20;--teal-tint:#203a34;--danger-tint:#402a26;background:var(--paper)}
body.km-dark .km-topbar,body.km-dark .topbar{background:rgba(21,24,21,.86)}
body.km-dark .km-stat,body.km-dark .stat-card,body.km-dark .km-panel,body.km-dark .panel,body.km-dark .km-prest-card,body.km-dark .km-order-card,body.km-dark .km-modal,body.km-dark .modal-card{background:var(--surface);color:var(--ink)}
body.km-dark input,body.km-dark select,body.km-dark textarea{background:#191d19;color:var(--ink);border-color:var(--line)}
body.km-dark .km-theme-toggle{background:#252a25;color:var(--ink);border-color:var(--line)}
body.km-dark .data-table tbody tr:hover{background:#272d27}
@media(max-width:860px){
  .km-main{margin-left:0!important}.km-sidebar{transform:translateX(-100%)}.km-sidebar.open{transform:translateX(0);box-shadow:16px 0 42px rgba(0,0,0,.22)}
  .km-sidebar .km-collapse-toggle{display:none}.km-topbar{padding:.8rem 1rem}.km-menu-toggle{display:inline-grid!important;place-items:center;width:38px;height:38px;border:1px solid var(--line);border-radius:10px}.km-content{padding:1.25rem 1rem 2.5rem}.km-stats{grid-template-columns:repeat(2,1fr)}
  .admin-main{margin-left:0!important}.admin-sidebar{transform:translateX(-100%)}.admin-sidebar.open{transform:translateX(0)}
  .topbar{padding-left:1rem!important;padding-right:1rem!important}.content-wrap{padding-left:1rem!important;padding-right:1rem!important}.stats-grid{grid-template-columns:repeat(2,1fr)!important}
}
@media(max-width:520px){.km-stats,.stats-grid{grid-template-columns:1fr 1fr!important;gap:.6rem}.km-stat{padding:.9rem}.km-stat-val{font-size:1.25rem}.km-topbar-search{max-width:none}.km-topbar-user-name,.km-topbar-user-role,.profile-copy{display:none}.km-order-detail{border-right:0;border-bottom:1px solid var(--line)}.km-confirm-actions{flex-direction:column-reverse}.km-confirm-actions button{width:100%}}
@media(prefers-reduced-motion:reduce){.data-table tbody tr,.km-order-card{animation:none!important}.km-btn::after,.km-button::after{display:none}}

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


<script id="koudmain-ui-inline-js">
(() => {
  'use strict';
  const root = document.body;
  const storageKey = 'koudmain-theme';
  const applyTheme = (theme) => { root.classList.toggle('km-dark', theme === 'dark'); document.documentElement.dataset.theme = theme; document.querySelectorAll('[data-theme-toggle]').forEach(btn => { btn.setAttribute('aria-pressed', theme === 'dark'); btn.textContent = theme === 'dark' ? '☼' : '☾'; btn.setAttribute('aria-label', theme === 'dark' ? 'Activer le mode clair' : 'Activer le mode sombre'); }); };
  const saved = localStorage.getItem(storageKey); applyTheme(saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  const topbar = document.querySelector('.km-topbar,.topbar');
  if (topbar && !topbar.querySelector('[data-theme-toggle]')) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'km-theme-toggle'; button.dataset.themeToggle = '1'; button.title = 'Changer de thème';
    topbar.querySelector('.km-topbar-user,.topbar-actions')?.prepend(button) || topbar.appendChild(button);
  }
  const sidebar = document.querySelector('.km-sidebar,.admin-sidebar,.sidebar');
  if (sidebar && !sidebar.querySelector('[data-sidebar-collapse]') && !matchMedia('(max-width:860px)').matches) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'km-collapse-toggle'; button.dataset.sidebarCollapse = '1'; button.setAttribute('aria-label','Réduire ou développer le menu'); button.textContent = '‹';
    sidebar.querySelector('.km-sb-brand,.brand-lockup,.sidebar-brand')?.appendChild(button) || sidebar.prepend(button);
  }
  applyTheme(root.classList.contains('km-dark') ? 'dark' : 'light');
  document.querySelectorAll('table').forEach(table => { if (!table.dataset.sortTable) table.dataset.sortTable = '1'; });
  document.querySelectorAll('input[name="q"]').forEach(input => {
    const scope = input.closest('main') || document.body;
    const items = [...scope.querySelectorAll('.km-prest-card,.km-order-card,.data-table tbody tr,table tbody tr')];
    if (!items.length) return;
    input.addEventListener('input', () => { const q = input.value.trim().toLocaleLowerCase(); let visible = 0; items.forEach(item => { const show = !q || item.textContent.toLocaleLowerCase().includes(q); item.hidden = !show; if(show) visible++; }); emptyMessage(scope, visible > 0); });
  });
  document.addEventListener('click', (event) => {
    const theme = event.target.closest('[data-theme-toggle]'); if (theme) { const next = root.classList.contains('km-dark') ? 'light' : 'dark'; localStorage.setItem(storageKey, next); applyTheme(next); }
    const collapse = event.target.closest('[data-sidebar-collapse]'); if (collapse) { const admin = !!document.querySelector('.admin-sidebar'); const cls = admin ? 'km-admin-collapsed' : 'km-sidebar-collapsed'; root.classList.toggle(cls); localStorage.setItem(cls, root.classList.contains(cls) ? '1' : '0'); }
    const close = event.target.closest('[data-close-modal]'); if (close) close.closest('.km-confirm-overlay')?.classList.remove('open');
  });
  if (localStorage.getItem('km-sidebar-collapsed') === '1') root.classList.add('km-sidebar-collapsed');
  if (localStorage.getItem('km-admin-collapsed') === '1') root.classList.add('km-admin-collapsed');

  const setConfirm = (form, message) => {
    let overlay = document.getElementById('km-confirm-overlay');
    if (!overlay) { overlay = document.createElement('div'); overlay.id = 'km-confirm-overlay'; overlay.className = 'km-confirm-overlay'; overlay.innerHTML = '<div class="km-confirm-card" role="dialog" aria-modal="true" aria-labelledby="km-confirm-title"><h2 id="km-confirm-title">Confirmer cette action</h2><p id="km-confirm-message"></p><div class="km-confirm-actions"><button type="button" class="km-confirm-cancel" data-close-modal>Annuler</button><button type="button" class="km-confirm-ok" id="km-confirm-ok">Confirmer</button></div></div>'; document.body.appendChild(overlay); }
    overlay.querySelector('#km-confirm-message').textContent = message || 'Cette action peut être irréversible.'; overlay.classList.add('open'); overlay.querySelector('.km-confirm-ok').focus();
    const ok = overlay.querySelector('.km-confirm-ok'); const cancel = () => { overlay.classList.remove('open'); ok.onclick = null; }; ok.onclick = () => { cancel(); form.dataset.kmConfirmed = '1'; form.submit(); }; overlay.querySelector('.km-confirm-cancel').onclick = cancel; overlay.onclick = (e) => { if (e.target === overlay) cancel(); };
  };
  document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-confirm]');
    if (!target || target.dataset.kmConfirmed === '1') return;
    const form = target.closest('form'); if (!form) return;
    event.preventDefault(); event.stopImmediatePropagation();
    setConfirm(form, target.dataset.confirm);
  }, true);
  document.addEventListener('submit', (event) => { const form = event.target; if (!form.matches('form[onsubmit*="confirm"], form[data-confirm]') || form.dataset.kmConfirmed === '1') return; event.preventDefault(); setConfirm(form, form.dataset.confirm || (form.getAttribute('onsubmit') || '').match(/confirm\(['"](.+?)['"]\)/)?.[1]); });

  const emptyMessage = (container, visible) => { let empty = container.querySelector('.km-live-empty'); if (!visible) { if (!empty) { empty = document.createElement('div'); empty.className = 'km-live-empty'; empty.textContent = 'Aucun résultat ne correspond à votre recherche.'; container.appendChild(empty); } } else empty?.remove(); };
  document.querySelectorAll('[data-live-search]').forEach(input => { const selector = input.dataset.liveSearch; const container = document.querySelector(selector); if (!container) return; const items = [...container.querySelectorAll('[data-search-item], tbody tr')]; input.addEventListener('input', () => { const query = input.value.trim().toLocaleLowerCase(); let count = 0; items.forEach(item => { const show = !query || item.textContent.toLocaleLowerCase().includes(query); item.hidden = !show; if (show) count++; }); emptyMessage(container, count > 0); }); });
  document.querySelectorAll('[data-sort-table]').forEach(table => { const headers = [...table.querySelectorAll('thead th')]; headers.forEach((header, index) => { header.classList.add('km-sortable'); header.tabIndex = 0; const sort = () => { const body = table.querySelector('tbody'); if (!body) return; const rows = [...body.rows]; const direction = header.dataset.sortDir === 'asc' ? -1 : 1; header.dataset.sortDir = direction === 1 ? 'asc' : 'desc'; rows.sort((a,b) => { const av = a.cells[index]?.textContent.trim() || ''; const bv = b.cells[index]?.textContent.trim() || ''; const an = parseFloat(av.replace(/[^\d,-]/g,'').replace(',','.')); const bn = parseFloat(bv.replace(/[^\d,-]/g,'').replace(',','.')); return (Number.isNaN(an) || Number.isNaN(bn) ? av.localeCompare(bv,'fr') : an-bn) * direction; }); rows.forEach(row => body.appendChild(row)); }; header.addEventListener('click', sort); header.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } }); }); });
  document.querySelectorAll('.km-rate-select').forEach(select => { const wrap = document.createElement('div'); wrap.className = 'km-rate-stars'; wrap.setAttribute('role','radiogroup'); wrap.setAttribute('aria-label','Choisir une note sur 5'); for(let n=1;n<=5;n++){ const btn=document.createElement('button'); btn.type='button'; btn.textContent='★'; btn.setAttribute('aria-label', `${n} étoile${n>1?'s':''}`); btn.setAttribute('aria-checked','false'); btn.addEventListener('mouseenter',()=>paint(n)); btn.addEventListener('focus',()=>paint(n)); btn.addEventListener('click',()=>{select.value=String(n); paint(n,true);}); wrap.appendChild(btn); } const paint=(value, permanent=false)=>wrap.querySelectorAll('button').forEach((b,i)=>{b.classList.toggle('active',i<value);b.setAttribute('aria-checked',i<value?'true':'false');}); select.hidden=true; select.parentNode.insertBefore(wrap,select); const initial=Number(select.value)||0; if(initial) paint(initial,true); wrap.addEventListener('mouseleave',()=>paint(Number(select.value)||0)); });
})();

</script>
</body>
</html>
