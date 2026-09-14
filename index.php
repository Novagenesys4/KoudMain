<?php
require_once "config.php";
// --- Contenu vitrine ---
$demo_prestations = [];
$demo_quartiers   = [];
$demo_categories  = [];

try {

    $pdo = getConnexion();

    $stmt = $pdo->query("
        SELECT p.titre_prestation, p.prix_prestation, q.nom_quartier
        FROM Prestation p
        JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
        JOIN Quartier q ON u.id_quartier = q.id_quartier
        ORDER BY p.datecrea_prestation DESC
        LIMIT 3
    ");
    $demo_prestations = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT nom_quartier FROM Quartier ORDER BY nom_quartier LIMIT 8");
    $demo_quartiers = array_column($stmt->fetchAll(), 'nom_quartier');

    $stmt = $pdo->query("
        SELECT c.nom_categorie, COUNT(s.id_service) AS nb
        FROM Categorie c
        LEFT JOIN Service s ON c.id_categorie = s.id_categorie
        GROUP BY c.id_categorie
        ORDER BY c.nom_categorie
    ");
    $demo_categories = $stmt->fetchAll();
} catch (Throwable $e) {
    // La vitrine reste fonctionnelle même si la base est indisponible.
}

if (empty($demo_prestations)) {
    $demo_prestations = [
        ['titre_prestation' => 'Coiffure à domicile',  'prix_prestation' => 5000, 'nom_quartier' => 'Cocody'],
        ['titre_prestation' => 'Réparation de fuite',   'prix_prestation' => 8000, 'nom_quartier' => 'Yopougon'],
        ['titre_prestation' => 'Repassage express',     'prix_prestation' => 3500, 'nom_quartier' => 'Marcory'],
    ];
}
if (empty($demo_quartiers)) {
    $demo_quartiers = ['Cocody', 'Yopougon', 'Marcory', 'Adjamé', 'Koumassi', 'Plateau', 'Port-Bouët', 'Abobo'];
}
if (empty($demo_categories)) {
    $noms = ['Beauté et Coiffure','Plomberie et Sanitaire','Laverie et Pressing',"Garde d'enfants",
             'Cuisine et Traiteur','Électricité','Jardinage','Déménagement','Informatique','Mécanique'];
    $demo_categories = array_map(fn($n) => ['nom_categorie' => $n, 'nb' => null], $noms);
}

// --- Icônes de catégories ---
function iconeCategorie(string $nom): string {
    $icones = [
        'Beauté et Coiffure'      => '<path d="M6 4 L14 14 M14 4 L6 14" /><circle cx="4.5" cy="15.5" r="1.7"/><circle cx="15.5" cy="15.5" r="1.7"/>',
        'Plomberie et Sanitaire'  => '<path d="M10 3c2.5 3 4 5.6 4 7.8a4 4 0 1 1-8 0C6 8.6 7.5 6 10 3Z"/>',
        'Laverie et Pressing'     => '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="10" cy="11" r="4"/><path d="M7 3h6"/>',
        "Garde d'enfants"         => '<circle cx="10" cy="6" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/>',
        'Cuisine et Traiteur'     => '<path d="M4 9h12M5 9V5.5a2 2 0 0 1 2-2M8 9V5.5M11 9V5.5a2 2 0 0 0-2-2M5 9v6a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9"/>',
        'Électricité'             => '<path d="M11 2 4 12h5l-1 6 7-10h-5l1-6Z"/>',
        'Jardinage'               => '<path d="M10 18V9M10 9C6 9 4 6.5 4 3c3.5 0 6 2 6 6Zm0 0c0-4 2.5-6 6-6 0 3.5-2 6-6 6Z"/>',
        'Déménagement'            => '<rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3M3 12h14"/>',
        'Informatique'            => '<rect x="3" y="3" width="14" height="10" rx="1"/><path d="M7 17h6M10 13v4"/>',
        'Mécanique'               => '<path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/>',
    ];
    return $icones[$nom] ?? '<circle cx="10" cy="10" r="6"/>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KoudMain — Les services de votre quartier</title>
<meta name="description" content="Trouvez un prestataire près de chez vous à Abidjan : coiffure, plomberie, laverie, garde d'enfants et plus. Publié par de vrais artisans de quartier.">
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
  --radius:14px;
  --radius-sm:8px;
  --maxw:1180px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  background:var(--paper);
  color:var(--ink);
  font-family:'Inter',sans-serif;
  font-size:16px;
  line-height:1.6;
  -webkit-font-smoothing:antialiased;
}
a{color:inherit;text-decoration:none}
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 2rem}
h1,h2,h3,.km-serif{font-family:'Fraunces',serif}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important;scroll-behavior:auto !important}
}

/* --- MASTHEAD --- */
.km-masthead{
  border-bottom:1px solid var(--line);
  background:var(--paper);
  position:sticky;top:0;z-index:40;
}
.km-masthead-inner{
  max-width:var(--maxw);margin:0 auto;padding:0 2rem;
  height:76px;display:flex;align-items:center;justify-content:space-between;gap:2rem;
}
.km-logo{font-family:'Fraunces',serif;font-weight:600;font-size:1.5rem;letter-spacing:-0.01em;color:var(--ink)}
.km-logo em{font-style:normal;color:var(--amber)}
.km-nav-links{display:flex;align-items:center;gap:2.1rem}
.km-nav-link{
  font-size:0.92rem;color:var(--ink-soft);position:relative;padding:0.3rem 0;
}
.km-nav-link::after{
  content:'';position:absolute;left:0;right:0;bottom:0;height:1px;background:var(--ink);
  transform:scaleX(0);transform-origin:right;transition:transform 0.28s ease;
}
.km-nav-link:hover{color:var(--ink)}
.km-nav-link:hover::after{transform:scaleX(1);transform-origin:left}
.km-nav-actions{display:flex;align-items:center;gap:1rem}
.km-link-muted{font-size:0.88rem;color:var(--ink-soft)}
.km-link-muted:hover{color:var(--ink)}

/* --- BUTTONS --- */
.km-btn{
  display:inline-flex;align-items:center;gap:0.5rem;
  padding:0.72rem 1.3rem;
  font-family:'Inter',sans-serif;font-size:0.9rem;font-weight:600;
  border-radius:var(--radius-sm);border:1px solid transparent;cursor:pointer;
  transition:transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
}
.km-btn:active{transform:scale(0.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(139,78,27,0.25)}
.km-btn-outline{background:transparent;color:var(--ink);border-color:var(--line)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-sm{padding:0.5rem 1rem;font-size:0.84rem}

/* --- HERO --- */
.km-hero{padding:4.5rem 0 3.5rem}
.km-hero-grid{
  display:grid;grid-template-columns:1.15fr 0.85fr;gap:3.5rem;align-items:center;
}
.km-eyebrow-note{
  font-size:0.85rem;color:var(--ink-soft);margin-bottom:1.1rem;max-width:30ch;
}
.km-hero h1{
  font-size:clamp(2.5rem,4.6vw,3.6rem);
  font-weight:600;line-height:1.06;letter-spacing:-0.015em;
  color:var(--ink);max-width:15ch;
}
.km-hero-line{display:block;overflow:hidden}
.km-hero-line span{display:block;transform:translateY(110%);transition:transform 0.7s cubic-bezier(.22,1,.36,1)}
.km-hero.km-loaded .km-hero-line span{transform:translateY(0)}
.km-hero-line:nth-child(1) span{transition-delay:0.05s}
.km-hero-line:nth-child(2) span{transition-delay:0.15s}
.km-hero-line:nth-child(3) span{transition-delay:0.25s}
.km-hero p{
  margin-top:1.4rem;color:var(--ink-soft);font-size:1.06rem;max-width:42ch;
  opacity:0;transform:translateY(8px);transition:opacity 0.6s ease 0.5s, transform 0.6s ease 0.5s;
}
.km-hero.km-loaded p{opacity:1;transform:translateY(0)}
.km-hero-actions{
  display:flex;gap:0.9rem;margin-top:2.1rem;flex-wrap:wrap;
  opacity:0;transform:translateY(8px);transition:opacity 0.6s ease 0.62s, transform 0.6s ease 0.62s;
}
.km-hero.km-loaded .km-hero-actions{opacity:1;transform:translateY(0)}

/* Listing preview stack */
.km-stack{position:relative;height:300px}
.km-listing-card{
  position:absolute;left:0;right:0;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);
  padding:1.2rem 1.35rem;box-shadow:0 10px 28px rgba(28,27,23,0.06);
  opacity:0;transform:translateY(26px) rotate(var(--r,0deg));
  transition:opacity 0.6s cubic-bezier(.22,1,.36,1), transform 0.6s cubic-bezier(.22,1,.36,1);
}
.km-hero.km-loaded .km-listing-card{opacity:1;transform:translateY(0) rotate(var(--r,0deg))}
.km-listing-card:nth-child(1){top:0;--r:-2.2deg;z-index:3;transition-delay:0.35s}
.km-listing-card:nth-child(2){top:78px;--r:1.4deg;z-index:2;transition-delay:0.46s}
.km-listing-card:nth-child(3){top:156px;--r:-1.1deg;z-index:1;transition-delay:0.57s}
.km-listing-top{display:flex;justify-content:space-between;align-items:baseline;gap:0.6rem}
.km-listing-title{font-weight:600;font-size:0.98rem;color:var(--ink)}
.km-listing-price{font-family:'Fraunces',serif;font-weight:600;color:var(--amber-deep);white-space:nowrap}
.km-listing-meta{font-size:0.8rem;color:var(--ink-soft);margin-top:0.35rem}
.km-listing-tag{
  display:inline-block;margin-top:0.7rem;font-size:0.72rem;color:var(--teal);
  background:var(--teal-tint);padding:0.2rem 0.55rem;border-radius:20px;font-weight:600;
}

/* Ticker */
.km-ticker-wrap{border-top:1px solid var(--line);border-bottom:1px solid var(--line);overflow:hidden;background:var(--paper-deep)}
.km-ticker{display:flex;width:max-content;animation:km-scroll 32s linear infinite}
.km-ticker:hover{animation-play-state:paused}
@keyframes km-scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.km-ticker-item{
  padding:0.85rem 2rem;font-size:0.86rem;color:var(--ink-soft);white-space:nowrap;
  border-right:1px solid var(--line);
}
.km-ticker-item b{color:var(--ink);font-weight:600}

/* --- SECTIONS --- */
.km-section{padding:5rem 0}
.km-section-head{max-width:44ch;margin-bottom:3rem}
.km-section-head h2{font-size:clamp(1.7rem,2.6vw,2.2rem);font-weight:600;letter-spacing:-0.01em}
.km-section-head p{color:var(--ink-soft);margin-top:0.8rem;font-size:1rem}

/* Steps (séquence réelle → numérotation légitime) */
.km-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:2.2rem}
.km-step{border-top:1px solid var(--ink);padding-top:1.2rem}
.km-step-num{font-family:'Fraunces',serif;font-size:0.95rem;color:var(--amber-deep);font-weight:600}
.km-step h3{margin-top:0.6rem;font-size:1.15rem;font-weight:600}
.km-step p{margin-top:0.5rem;color:var(--ink-soft);font-size:0.94rem}

/* Categories directory */
.km-directory{border-top:1px solid var(--line)}
.km-cat-row{
  display:flex;align-items:center;gap:1rem;
  padding:1.05rem 0;border-bottom:1px solid var(--line);
  transition:padding-left 0.22s ease;
}
.km-cat-row:hover{padding-left:0.4rem}
.km-cat-icon{
  width:34px;height:34px;flex-shrink:0;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:var(--amber-tint);color:var(--amber-deep);
}
.km-cat-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.km-cat-name{font-weight:500;font-size:1.02rem;white-space:nowrap}
.km-cat-leader{flex:1;border-bottom:1px dotted var(--line);margin-top:0.35rem;min-width:20px}
.km-cat-count{font-size:0.82rem;color:var(--ink-faint);white-space:nowrap}
.km-cat-arrow{
  width:16px;height:16px;flex-shrink:0;color:var(--ink-soft);
  transition:transform 0.22s ease;
}
.km-cat-row:hover .km-cat-arrow{transform:translateX(4px);color:var(--ink)}
.km-directory-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:3rem}
@media(max-width:760px){.km-directory-grid{grid-template-columns:1fr}}

/* CTA band */
.km-cta-band{
  background:var(--ink);color:var(--paper);border-radius:20px;
  padding:3.2rem 3rem;display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;
}
.km-cta-band h2{color:var(--paper);font-size:clamp(1.5rem,2.4vw,2rem);max-width:20ch}
.km-cta-band p{color:#C9C5B9;margin-top:0.6rem;max-width:38ch;font-size:0.96rem}
.km-cta-band .km-btn-primary{background:var(--amber);color:var(--ink)}
.km-cta-band .km-btn-primary:hover{background:#CE8442}

/* Footer */
.km-footer{border-top:1px solid var(--line);padding:2.6rem 0;margin-top:1rem}
.km-footer-inner{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.km-footer p{color:var(--ink-faint);font-size:0.83rem}
.km-footer-links{display:flex;gap:1.4rem}
.km-footer-links a{font-size:0.83rem;color:var(--ink-soft)}
.km-footer-links a:hover{color:var(--ink)}

/* Focus visibility */
a:focus-visible,button:focus-visible{outline:2px solid var(--amber-deep);outline-offset:3px;border-radius:4px}

/* Responsive */
@media(max-width:900px){
  .km-hero-grid{grid-template-columns:1fr}
  .km-stack{margin-top:1rem}
  .km-nav-links{display:none}
  .km-steps{grid-template-columns:1fr;gap:1.6rem}
}
@media(max-width:640px){
  .wrap{padding:0 1.25rem}
  .km-masthead-inner{padding:0 1.25rem;height:64px}
  .km-cta-band{flex-direction:column;align-items:flex-start;padding:2.2rem 1.6rem}
  .km-hero{padding:2.6rem 0 2rem}
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
    <nav class="km-nav-links">
      <a href="#categories" class="km-nav-link">Catalogue</a>
      <a href="#comment-ca-marche" class="km-nav-link">Comment ça marche</a>
      <a href="#devenir-prestataire" class="km-nav-link">Devenir prestataire</a>
    </nav>
    <div class="km-nav-actions">
      <?php if (estConnecte()): ?>
        <?php if (estAdmin()): ?>
          <a href="admin_dashboard.php" class="km-link-muted">Admin</a>
        <?php elseif (estPrestataire()): ?>
          <a href="prestataire_dashboard.php" class="km-link-muted">Mon espace</a>
        <?php else: ?>
          <a href="client_dashboard.php" class="km-link-muted">Mon espace</a>
        <?php endif; ?>
        <a href="connexion.php?action=logout" class="km-btn km-btn-outline km-btn-sm">Déconnexion</a>
      <?php else: ?>
        <a href="connexion.php" class="km-link-muted">Connexion</a>
        <a href="inscription.php" class="km-btn km-btn-primary km-btn-sm">S'inscrire</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- ── HERO ── -->
<section class="km-hero" id="km-hero">
  <div class="wrap km-hero-grid">
    <div>
      <p class="km-eyebrow-note">Abidjan et ses quartiers</p>
      <h1>
        <span class="km-hero-line"><span>Un service de quartier,</span></span>
        <span class="km-hero-line"><span>trouvé en quelques</span></span>
        <span class="km-hero-line"><span>minutes.</span></span>
      </h1>
      <p>Coiffure, plomberie, laverie, garde d'enfants — publiés par de vrais prestataires près de chez vous, sans intermédiaire ni rendez-vous compliqué.</p>
      <div class="km-hero-actions">
        <?php if (estConnecte()): ?>
          <?php
            $lien = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
          ?>
          <a href="<?= $lien ?>" class="km-btn km-btn-primary">Accéder à mon espace</a>
        <?php else: ?>
          <a href="#categories" class="km-btn km-btn-primary">Explorer les services</a>
          <a href="inscription.php?role=prestataire" class="km-btn km-btn-outline">Devenir prestataire</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="km-stack">
      <?php foreach ($demo_prestations as $i => $p): ?>
      <div class="km-listing-card">
        <div class="km-listing-top">
          <span class="km-listing-title"><?= htmlspecialchars($p['titre_prestation']) ?></span>
          <span class="km-listing-price"><?= number_format((float)$p['prix_prestation'], 0, ',', ' ') ?> F</span>
        </div>
        <div class="km-listing-meta">📍 <?= htmlspecialchars($p['nom_quartier']) ?></div>
        <?php if ($i === 0): ?><span class="km-listing-tag">Récemment publié</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── TICKER QUARTIERS ── -->
<div class="km-ticker-wrap">
  <div class="km-ticker">
    <?php
      $liste = array_merge($demo_quartiers, $demo_quartiers); // boucle continue
      foreach ($liste as $q): ?>
      <span class="km-ticker-item">Disponible à <b><?= htmlspecialchars($q) ?></b></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── COMMENT ÇA MARCHE ── -->
<section class="km-section" id="comment-ca-marche">
  <div class="wrap">
    <div class="km-section-head">
      <h2 class="km-serif">Comment ça marche</h2>
      <p>Trois étapes, du besoin à la prestation terminée.</p>
    </div>
    <div class="km-steps">
      <div class="km-step">
        <div class="km-step-num">1</div>
        <h3>Décrivez votre besoin</h3>
        <p>Parcourez le catalogue par catégorie ou cherchez directement le service qu'il vous faut.</p>
      </div>
      <div class="km-step">
        <div class="km-step-num">2</div>
        <h3>Choisissez un prestataire</h3>
        <p>Comparez les prix, les avis et la localisation, puis commandez en un clic.</p>
      </div>
      <div class="km-step">
        <div class="km-step-num">3</div>
        <h3>Suivez et notez</h3>
        <p>Le prestataire confirme la commande ; une fois le service rendu, laissez votre avis.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── CATÉGORIES (annuaire) ── -->
<section class="km-section" id="categories" style="background:var(--paper-deep)">
  <div class="wrap">
    <div class="km-section-head">
      <h2 class="km-serif">Le catalogue</h2>
      <p>Dix domaines, des dizaines de services proposés par des prestataires validés.</p>
    </div>
    <div class="km-directory-grid km-directory">
      <?php foreach ($demo_categories as $c): ?>
      <a href="<?= estConnecte() ? 'client_dashboard.php?tab=catalogue' : 'inscription.php' ?>" class="km-cat-row">
        <span class="km-cat-icon"><svg viewBox="0 0 20 20"><?= iconeCategorie($c['nom_categorie']) ?></svg></span>
        <span class="km-cat-name"><?= htmlspecialchars($c['nom_categorie']) ?></span>
        <span class="km-cat-leader"></span>
        <?php if ($c['nb'] !== null): ?><span class="km-cat-count"><?= (int)$c['nb'] ?> service<?= (int)$c['nb'] > 1 ? 's' : '' ?></span><?php endif; ?>
        <svg class="km-cat-arrow" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 4l6 6-6 6"/></svg>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── CTA DEVENIR PRESTATAIRE ── -->
<section class="km-section" id="devenir-prestataire" style="padding-top:0">
  <div class="wrap">
    <div class="km-cta-band">
      <div>
        <h2 class="km-serif">Un savoir-faire à proposer ?</h2>
        <p>Publiez vos prestations gratuitement et recevez des commandes de clients de votre quartier.</p>
      </div>
      <?php if (!estConnecte() || (!estPrestataire() && !estAdmin())): ?>
        <a href="inscription.php?role=prestataire" class="km-btn km-btn-primary">Devenir prestataire</a>
      <?php else: ?>
        <a href="prestataire_dashboard.php" class="km-btn km-btn-primary">Voir mon espace</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<footer class="km-footer">
  <div class="wrap km-footer-inner">
    <p>© <?= date('Y') ?> KoudMain — Plateforme de mise en relation de services de quartier.</p>
    <div class="km-footer-links">
      <a href="connexion.php">Connexion</a>
      <a href="inscription.php">S'inscrire</a>
      <a href="#comment-ca-marche">Comment ça marche</a>
    </div>
  </div>
</footer>

<script>
// Séquence d'entrée unique et orchestrée (respecte prefers-reduced-motion via CSS)
window.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => {
    document.getElementById('km-hero').classList.add('km-loaded');
  });
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
