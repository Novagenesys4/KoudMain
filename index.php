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

    $stmt = $pdo->query("SELECT COUNT(*) FROM Quartier");
    $nb_quartiers_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_admin = false");
    $nb_voisins_total = (int)$stmt->fetchColumn();
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
$nb_quartiers_total = $nb_quartiers_total ?? count($demo_quartiers);
$nb_voisins_total   = $nb_voisins_total ?? 2400;

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

// Icônes des 3 étapes "Comment ça marche"
function iconeEtape(int $n): string {
    $icones = [
        1 => '<circle cx="9" cy="9" r="6"/><path d="m18 18-3.8-3.8"/>',
        2 => '<circle cx="10" cy="7" r="3.4"/><path d="M4 17.5c0-3.3 2.7-6 6-6s6 2.7 6 6"/>',
        3 => '<path d="M3 5.5A2.5 2.5 0 0 1 5.5 3h9A2.5 2.5 0 0 1 17 5.5v6A2.5 2.5 0 0 1 14.5 14H8l-4 3.5V14.4A2.5 2.5 0 0 1 3 11.5Z"/>',
    ];
    return $icones[$n] ?? '';
}

$avatarNoms = ['A', 'K', 'M', 'O'];
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
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500;1,9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F3EE;
  --paper-deep:#EBE7DC;
  --surface:#FFFEFA;
  --ink:#1C1B17;
  --ink-soft:#6C695E;
  --ink-faint:#9B9788;
  --line:#DEDACE;
  --amber:#C17A42;
  --amber-deep:#8A4E1B;
  --amber-tint:#F1E1CC;
  --teal:#2E6B5E;
  --teal-tint:#E1EDE8;
  --rose:#D98F73;
  --rose-tint:#F3DFD3;
  --radius:16px;
  --radius-sm:10px;
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
em{font-style:italic;color:var(--amber)}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important;scroll-behavior:auto !important}
}

/* ── MASTHEAD ── */
.km-masthead{border-bottom:1px solid var(--line);background:rgba(245,243,238,.9);backdrop-filter:blur(10px);position:sticky;top:0;z-index:40}
.km-masthead-inner{max-width:var(--maxw);margin:0 auto;padding:0 2rem;height:74px;display:flex;align-items:center;justify-content:space-between;gap:2rem}
.km-logo{font-family:'Fraunces',serif;font-weight:600;font-size:1.4rem;letter-spacing:-0.01em;color:var(--ink);display:flex;align-items:center;gap:0.15rem}
.km-logo::after{content:'';width:6px;height:6px;border-radius:50%;background:var(--amber);margin-left:0.1rem;transform:translateY(-0.6em)}
.km-nav-links{display:flex;align-items:center;gap:2.1rem}
.km-nav-link{font-size:0.9rem;color:var(--ink-soft);position:relative;padding:0.3rem 0}
.km-nav-link::after{content:'';position:absolute;left:0;right:0;bottom:0;height:1px;background:var(--ink);transform:scaleX(0);transform-origin:right;transition:transform 0.28s ease}
.km-nav-link:hover{color:var(--ink)}
.km-nav-link:hover::after{transform:scaleX(1);transform-origin:left}
.km-nav-actions{display:flex;align-items:center;gap:1.3rem}
.km-link-muted{font-size:0.88rem;color:var(--ink-soft)}
.km-link-muted:hover{color:var(--ink)}

/* ── BUTTONS ── */
.km-btn{display:inline-flex;align-items:center;gap:0.5rem;padding:0.72rem 1.3rem;font-family:'Inter',sans-serif;font-size:0.9rem;font-weight:600;border-radius:50px;border:1px solid transparent;cursor:pointer;transition:transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease}
.km-btn:active{transform:scale(0.97)}
.km-btn svg{width:15px;height:15px;transition:transform 0.22s ease}
.km-btn:hover svg{transform:translateX(3px)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 8px 20px rgba(139,78,27,0.28)}
.km-btn-outline{background:transparent;color:var(--ink);border-color:var(--line)}
.km-btn-outline:hover{border-color:var(--ink);background:var(--surface)}
.km-btn-sm{padding:0.55rem 1.1rem;font-size:0.84rem}
.km-btn-light{background:var(--surface);color:var(--ink);box-shadow:0 4px 14px rgba(28,27,23,.05)}
.km-btn-light:hover{background:var(--amber-tint)}

/* ── HERO ── */
.km-hero{padding:4.6rem 0 3rem;overflow:hidden}
.km-hero-grid{display:grid;grid-template-columns:1.05fr 0.95fr;gap:3.5rem;align-items:center}
.km-eyebrow{font-size:0.76rem;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:1.1rem;display:flex;align-items:center;gap:0.6rem}
.km-eyebrow::before{content:'';width:22px;height:1px;background:var(--ink-faint)}
.km-hero h1{font-size:clamp(2.5rem,4.4vw,3.5rem);font-weight:600;line-height:1.06;letter-spacing:-0.015em;color:var(--ink);max-width:14ch}
.km-hero-line{display:block;overflow:hidden}
.km-hero-line span{display:block;transform:translateY(112%);transition:transform 0.75s cubic-bezier(.22,1,.36,1)}
.km-hero.km-loaded .km-hero-line span{transform:translateY(0)}
.km-hero-line:nth-child(1) span{transition-delay:0.05s}
.km-hero-line:nth-child(2) span{transition-delay:0.16s}
.km-hero-line:nth-child(3) span{transition-delay:0.27s}
.km-hero p.km-lede{margin-top:1.4rem;color:var(--ink-soft);font-size:1.04rem;max-width:40ch;opacity:0;transform:translateY(8px);transition:opacity 0.6s ease 0.5s, transform 0.6s ease 0.5s}
.km-hero.km-loaded p.km-lede{opacity:1;transform:translateY(0)}
.km-hero-actions{display:flex;gap:0.9rem;margin-top:2rem;flex-wrap:wrap;opacity:0;transform:translateY(8px);transition:opacity 0.6s ease 0.6s, transform 0.6s ease 0.6s}
.km-hero.km-loaded .km-hero-actions{opacity:1;transform:translateY(0)}
.km-social-proof{display:flex;align-items:center;gap:0.8rem;margin-top:2.4rem;opacity:0;transform:translateY(8px);transition:opacity 0.6s ease 0.7s, transform 0.6s ease 0.7s}
.km-hero.km-loaded .km-social-proof{opacity:1;transform:translateY(0)}
.km-avatar-stack{display:flex}
.km-avatar-stack span{
  width:30px;height:30px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;
  border:2px solid var(--paper);margin-left:-9px;
}
.km-avatar-stack span:first-child{margin-left:0}
.km-social-proof small{font-size:0.82rem;color:var(--ink-soft)}

/* Illustration */
.km-illustration{position:relative;height:360px;display:flex;align-items:center;justify-content:center}
.km-blob-outer{
  position:absolute;width:340px;height:340px;border-radius:50%;
  background:var(--rose-tint);opacity:0;transform:scale(.86);
  transition:opacity .8s ease .2s, transform .8s cubic-bezier(.22,1,.36,1) .2s;
}
.km-blob-inner{
  position:absolute;width:250px;height:250px;border-radius:50%;
  background:var(--rose);opacity:0;left:38%;top:32%;
  transition:opacity .8s ease .32s, transform .8s cubic-bezier(.22,1,.36,1) .32s;
  transform:scale(.86);
  animation:km-float 7s ease-in-out infinite;
}
.km-hero.km-loaded .km-blob-outer,.km-hero.km-loaded .km-blob-inner{opacity:1;transform:scale(1)}
@keyframes km-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.km-illustration-copy{position:relative;z-index:2;text-align:center;opacity:0;transition:opacity .6s ease .5s}
.km-hero.km-loaded .km-illustration-copy{opacity:1}
.km-illustration-copy .km-tiny{font-size:0.72rem;letter-spacing:0.14em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:0.5rem}
.km-illustration-copy h2{font-size:1.7rem;font-weight:600;color:var(--ink);line-height:1.15}
.km-float-card{
  position:absolute;background:var(--surface);border-radius:12px;padding:0.7rem 0.95rem;
  box-shadow:0 14px 32px rgba(28,27,23,.1);font-size:0.8rem;display:flex;align-items:center;gap:0.5rem;
  opacity:0;transform:translateY(14px);transition:opacity .6s ease, transform .6s cubic-bezier(.22,1,.36,1);
}
.km-hero.km-loaded .km-float-card{opacity:1;transform:translateY(0)}
.km-float-card-1{top:6%;right:2%;transition-delay:.55s;animation:km-float 6s ease-in-out infinite}
.km-float-card-2{bottom:14%;left:0;transition-delay:.68s;animation:km-float 6.6s ease-in-out infinite reverse}
.km-float-card strong{display:block;font-weight:700;font-size:0.86rem}
.km-float-card span{color:var(--ink-faint);font-size:0.72rem}
.km-float-pin{width:26px;height:26px;border-radius:50%;background:var(--teal-tint);color:var(--teal);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.km-float-star{width:26px;height:26px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* Ticker */
.km-ticker-wrap{border-top:1px solid var(--line);border-bottom:1px solid var(--line);overflow:hidden;background:var(--paper-deep)}
.km-ticker{display:flex;width:max-content;animation:km-scroll 34s linear infinite}
.km-ticker:hover{animation-play-state:paused}
@keyframes km-scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.km-ticker-item{padding:0.85rem 2rem;font-size:0.85rem;color:var(--ink-soft);white-space:nowrap;border-right:1px solid var(--line);display:flex;align-items:center;gap:0.6rem}
.km-ticker-item b{color:var(--ink);font-weight:600}
.km-ticker-item svg{width:12px;height:12px;color:var(--amber)}

/* ── SECTIONS générique ── */
.km-section{padding:5.2rem 0}
.km-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:2rem;margin-bottom:3rem;flex-wrap:wrap}
.km-section-head .km-htext{max-width:44ch}
.km-eyebrow-label{font-size:0.76rem;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:var(--amber-deep);margin-bottom:0.9rem}
.km-section-head h2{font-size:clamp(1.9rem,3vw,2.5rem);font-weight:600;letter-spacing:-0.015em;line-height:1.12}
.km-section-head p{color:var(--ink-soft);margin-top:0.9rem;font-size:1rem;max-width:42ch}

/* Steps */
.km-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:1.4rem}
.km-step{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.8rem 1.7rem;position:relative;transition:transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}
.km-step:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(28,27,23,.07);border-color:var(--amber-deep)}
.km-step-num{position:absolute;top:1.6rem;right:1.7rem;font-family:'Fraunces',serif;font-size:0.82rem;color:var(--ink-faint);font-weight:600}
.km-step-icon{
  width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:1.3rem;
}
.km-step-icon svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.km-step:nth-child(1) .km-step-icon{background:var(--rose-tint);color:#B85C3E}
.km-step:nth-child(2) .km-step-icon{background:var(--teal-tint);color:var(--teal)}
.km-step:nth-child(3) .km-step-icon{background:var(--amber-tint);color:var(--amber-deep)}
.km-step h3{font-size:1.14rem;font-weight:600}
.km-step p{margin-top:0.55rem;color:var(--ink-soft);font-size:0.92rem;line-height:1.55}
.km-step-arrow{
  display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;
  border:1px solid var(--line);margin-top:1.1rem;transition:background .2s ease, border-color .2s ease, transform .2s ease;
}
.km-step-arrow svg{width:13px;height:13px}
.km-step:hover .km-step-arrow{background:var(--ink);border-color:var(--ink);color:var(--paper);transform:translateX(3px)}

/* Directory / catalogue */
.km-directory-section{background:var(--paper-deep)}
.km-directory-stat{display:flex;align-items:center;gap:0.6rem;font-size:0.86rem;color:var(--ink-soft);background:var(--surface);border:1px solid var(--line);border-radius:50px;padding:0.5rem 1rem 0.5rem 0.6rem}
.km-directory-stat-icon{width:30px;height:30px;border-radius:50%;background:var(--teal-tint);color:var(--teal);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.km-directory-stat-icon svg{width:15px;height:15px}
.km-directory-stat strong{color:var(--ink);font-weight:700}
.km-directory-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:3rem}
.km-cat-row{display:flex;align-items:center;gap:1rem;padding:1.05rem 0;border-bottom:1px solid var(--line);transition:padding-left 0.22s ease}
.km-cat-row:hover{padding-left:0.4rem}
.km-cat-icon{width:36px;height:36px;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--amber-tint);color:var(--amber-deep);transition:background .2s ease,color .2s ease}
.km-cat-row:hover .km-cat-icon{background:var(--ink);color:var(--paper)}
.km-cat-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.km-cat-name{font-weight:500;font-size:1.02rem;white-space:nowrap}
.km-cat-leader{flex:1;border-bottom:1px dotted var(--line);margin-top:0.35rem;min-width:20px}
.km-cat-count{font-size:0.82rem;color:var(--ink-faint);white-space:nowrap}
.km-cat-arrow{width:16px;height:16px;flex-shrink:0;color:var(--ink-soft);transition:transform 0.22s ease}
.km-cat-row:hover .km-cat-arrow{transform:translateX(4px);color:var(--ink)}
@media(max-width:760px){.km-directory-grid{grid-template-columns:1fr}}

/* Local / map section */
.km-local-grid{display:grid;grid-template-columns:1fr 1fr;gap:3.5rem;align-items:center}
.km-local-copy p{color:var(--ink-soft);margin-top:1rem;max-width:38ch;font-size:1rem}
.km-local-stats{display:flex;gap:2.4rem;margin-top:2rem}
.km-local-stat strong{font-family:'Fraunces',serif;font-size:1.9rem;font-weight:600;color:var(--ink);display:block}
.km-local-stat span{font-size:0.82rem;color:var(--ink-soft)}
.km-local-stat strong em{color:var(--amber-deep);font-style:normal}
.km-map{
  position:relative;height:320px;border-radius:var(--radius);overflow:hidden;background:
    repeating-linear-gradient(0deg, transparent, transparent 34px, rgba(28,27,23,.05) 35px),
    repeating-linear-gradient(90deg, transparent, transparent 34px, rgba(28,27,23,.05) 35px),
    var(--teal-tint);
  border:1px solid var(--line);
}
.km-map-road{position:absolute;background:rgba(255,255,255,.55);border-radius:20px}
.km-map-road-1{width:150%;height:16px;top:38%;left:-15%;transform:rotate(-9deg)}
.km-map-road-2{width:120%;height:12px;top:64%;left:-10%;transform:rotate(6deg)}
.km-map-pin{position:absolute;display:flex;align-items:center;gap:0.4rem;font-size:0.74rem;font-weight:600;color:var(--ink)}
.km-map-pin i{width:11px;height:11px;border-radius:50%;background:var(--teal);box-shadow:0 0 0 4px rgba(46,107,94,.18);flex-shrink:0}
.km-map-pin.km-map-pin-you i{background:var(--amber-deep);box-shadow:0 0 0 6px rgba(138,78,27,.2);animation:km-pulse 2.2s ease-in-out infinite}
@keyframes km-pulse{0%,100%{box-shadow:0 0 0 6px rgba(138,78,27,.2)}50%{box-shadow:0 0 0 10px rgba(138,78,27,.08)}}
.km-map-caption{
  position:absolute;left:1rem;bottom:1rem;background:var(--surface);border-radius:10px;
  padding:0.55rem 0.85rem;font-size:0.76rem;color:var(--ink-soft);display:flex;align-items:center;gap:0.5rem;
  box-shadow:0 10px 24px rgba(28,27,23,.08);
}
.km-map-caption i{width:7px;height:7px;border-radius:50%;background:var(--teal)}

/* Listings */
.km-listings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem}
.km-listing{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.5rem;transition:transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;position:relative;overflow:hidden;
}
.km-listing:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(28,27,23,.08);border-color:var(--amber-deep)}
.km-listing-icon{width:38px;height:38px;border-radius:50%;background:var(--rose-tint);color:#B85C3E;display:flex;align-items:center;justify-content:center;margin-bottom:1.1rem}
.km-listing-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.km-listing-top{display:flex;justify-content:space-between;align-items:baseline;gap:0.6rem}
.km-listing-title{font-weight:600;font-size:1.02rem;color:var(--ink)}
.km-listing-price{font-family:'Fraunces',serif;font-weight:600;color:var(--amber-deep);white-space:nowrap}
.km-listing-meta{font-size:0.82rem;color:var(--ink-soft);margin-top:0.6rem;display:flex;align-items:center;gap:0.9rem}
.km-listing-meta span{display:flex;align-items:center;gap:0.3rem}
.km-listing-meta svg{width:13px;height:13px}
.km-listing-tag{position:absolute;top:1.2rem;right:1.2rem;font-size:0.68rem;color:var(--teal);background:var(--teal-tint);padding:0.22rem 0.6rem;border-radius:20px;font-weight:700;letter-spacing:.02em}

/* CTA band */
.km-cta-dark{background:var(--ink);color:var(--paper);border-radius:24px;padding:3.6rem 3.2rem;position:relative;overflow:hidden}
.km-cta-dark::before{
  content:'';position:absolute;inset:0;opacity:.55;pointer-events:none;
  background-image:radial-gradient(rgba(255,255,255,.05) 1px, transparent 1px);background-size:16px 16px;
}
.km-cta-grid{position:relative;display:flex;align-items:center;justify-content:space-between;gap:2.5rem;flex-wrap:wrap}
.km-eyebrow-inverse{font-size:0.76rem;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:#C9C5B9;margin-bottom:1rem}
.km-cta-dark h2{color:var(--paper);font-size:clamp(1.7rem,2.6vw,2.3rem);max-width:18ch;line-height:1.14}
.km-cta-dark p{color:#C9C5B9;margin-top:0.8rem;max-width:34ch;font-size:0.98rem}
.km-cta-dark .km-btn-primary{background:var(--amber);color:var(--ink)}
.km-cta-dark .km-btn-primary:hover{background:#D98F52}
.km-cta-checks{display:flex;gap:1.4rem;margin-top:1.4rem;flex-wrap:wrap}
.km-cta-checks span{display:flex;align-items:center;gap:0.45rem;font-size:0.82rem;color:#D8D4C8}
.km-cta-checks svg{width:15px;height:15px;color:var(--amber)}
.km-cta-orb{
  position:relative;width:132px;height:132px;border-radius:50%;flex-shrink:0;
  background:radial-gradient(circle at 40% 30%, rgba(193,122,66,.55), rgba(193,122,66,.12) 60%, transparent 72%);
  display:flex;align-items:center;justify-content:center;
}
.km-cta-orb::before{content:'';position:absolute;inset:14px;border:1px solid rgba(255,255,255,.14);border-radius:50%}
.km-cta-orb svg{width:32px;height:32px;color:var(--amber);position:relative}

/* Footer */
.km-footer{border-top:1px solid var(--line);padding:3rem 0 2.4rem;margin-top:1rem;position:relative}
.km-footer::before{
  content:'';position:absolute;inset:0;opacity:.5;pointer-events:none;z-index:-1;
  background-image:radial-gradient(rgba(28,27,23,.06) 1px, transparent 1px);background-size:14px 14px;
}
.km-footer-inner{display:flex;justify-content:space-between;gap:2rem;flex-wrap:wrap}
.km-footer-brand .km-logo{font-size:1.3rem}
.km-footer-brand p{color:var(--ink-soft);font-size:0.86rem;margin-top:0.6rem;max-width:26ch}
.km-footer-cols{display:flex;gap:3.2rem;flex-wrap:wrap}
.km-footer-col span{display:block;font-size:0.7rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:0.9rem}
.km-footer-col a{display:block;font-size:0.88rem;color:var(--ink-soft);margin-bottom:0.6rem}
.km-footer-col a:hover{color:var(--ink)}
.km-footer-bottom{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:2.4rem;padding-top:1.6rem;border-top:1px solid var(--line)}
.km-footer-bottom p{color:var(--ink-faint);font-size:0.8rem}

/* Focus visibility */
a:focus-visible,button:focus-visible{outline:2px solid var(--amber-deep);outline-offset:3px;border-radius:4px}

/* Responsive */
@media(max-width:980px){
  .km-hero-grid{grid-template-columns:1fr}
  .km-illustration{margin-top:1.5rem;height:300px}
  .km-nav-links{display:none}
  .km-steps{grid-template-columns:1fr;gap:1.1rem}
  .km-local-grid{grid-template-columns:1fr}
  .km-listings-grid{grid-template-columns:1fr}
}
@media(max-width:640px){
  .wrap{padding:0 1.25rem}
  .km-masthead-inner{padding:0 1.25rem;height:64px}
  .km-cta-dark{padding:2.2rem 1.6rem;border-radius:18px}
  .km-cta-grid{flex-direction:column;align-items:flex-start}
  .km-cta-orb{display:none}
  .km-hero{padding:2.6rem 0 1.6rem}
  .km-section{padding:3.4rem 0}
  .km-footer-inner{flex-direction:column}
}
</style>
</head>
<body>

<header class="km-masthead">
  <div class="km-masthead-inner">
    <a href="index.php" class="km-logo">KoudMain</a>
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
        <a href="inscription.php" class="km-btn km-btn-primary km-btn-sm">
          S'inscrire
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
        </a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- ── HERO ── -->
<section class="km-hero" id="km-hero">
  <div class="wrap km-hero-grid">
    <div>
      <p class="km-eyebrow">Abidjan et ses quartiers</p>
      <h1>
        <span class="km-hero-line"><span>Un service de</span></span>
        <span class="km-hero-line"><span>quartier, <em>trouvé en</em></span></span>
        <span class="km-hero-line"><span><em>quelques minutes.</em></span></span>
      </h1>
      <p class="km-lede">Coiffure, plomberie, laverie, garde d'enfants — trouvez des personnes de confiance, près de chez vous.</p>
      <div class="km-hero-actions">
        <?php if (estConnecte()): ?>
          <?php
            $lien = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
          ?>
          <a href="<?= $lien ?>" class="km-btn km-btn-primary">
            Accéder à mon espace
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
          </a>
        <?php else: ?>
          <a href="#categories" class="km-btn km-btn-primary">
            Explorer les services
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
          </a>
          <a href="inscription.php?role=prestataire" class="km-btn km-btn-outline">Je propose un service</a>
        <?php endif; ?>
      </div>
      <div class="km-social-proof">
        <div class="km-avatar-stack">
          <?php foreach ($avatarNoms as $a): ?><span><?= htmlspecialchars($a) ?></span><?php endforeach; ?>
        </div>
        <small>Déjà adopté par <?= number_format($nb_voisins_total, 0, ',', ' ') ?>+ voisins</small>
      </div>
    </div>

    <div class="km-illustration">
      <div class="km-blob-outer"></div>
      <div class="km-blob-inner"></div>
      <div class="km-illustration-copy">
        <p class="km-tiny">À proximité</p>
        <h2 class="km-serif">des gens<br><em>de confiance</em></h2>
      </div>

      <div class="km-float-card km-float-card-1">
        <span class="km-float-pin">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 18s6-5.2 6-9.8A6 6 0 0 0 4 8.2C4 12.8 10 18 10 18Z"/><circle cx="10" cy="8" r="2"/></svg>
        </span>
        <div>
          <strong><?= htmlspecialchars($demo_quartiers[0] ?? 'Plateau') ?></strong>
          <span><?= max(3, (int)round($nb_voisins_total / max(1,$nb_quartiers_total) / 40)) ?> prestataires</span>
        </div>
      </div>
      <div class="km-float-card km-float-card-2">
        <span class="km-float-star">
          <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 1.5l2.6 5.4 5.9.7-4.3 4.1 1.1 5.9L10 14.7l-5.3 2.9 1.1-5.9L1.5 7.6l5.9-.7L10 1.5Z"/></svg>
        </span>
        <div>
          <strong>4.9/5</strong>
          <span>avis vérifiés</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── TICKER QUARTIERS ── -->
<div class="km-ticker-wrap">
  <div class="km-ticker">
    <?php
      $liste = array_merge($demo_quartiers, $demo_quartiers); // boucle continue
      foreach ($liste as $q): ?>
      <span class="km-ticker-item">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 18s6-5.2 6-9.8A6 6 0 0 0 4 8.2C4 12.8 10 18 10 18Z"/><circle cx="10" cy="8" r="1.6"/></svg>
        Disponible à <b><?= htmlspecialchars($q) ?></b>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── COMMENT ÇA MARCHE ── -->
<section class="km-section" id="comment-ca-marche">
  <div class="wrap">
    <div class="km-section-head">
      <div class="km-htext">
        <div class="km-eyebrow-label">Simple comme un message</div>
        <h2 class="km-serif">Du besoin à la solution,<br><em>sans détour.</em></h2>
        <p>Pas de formulaire compliqué. Pas d'appel qui n'aboutit pas. Juste le bon service, au bon endroit.</p>
      </div>
    </div>
    <div class="km-steps">
      <div class="km-step">
        <span class="km-step-num">01</span>
        <div class="km-step-icon"><svg viewBox="0 0 20 20"><?= iconeEtape(1) ?></svg></div>
        <h3>Décrivez votre besoin</h3>
        <p>Parcourez le catalogue ou cherchez directement ce qu'il vous faut.</p>
        <span class="km-step-arrow"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg></span>
      </div>
      <div class="km-step">
        <span class="km-step-num">02</span>
        <div class="km-step-icon"><svg viewBox="0 0 20 20"><?= iconeEtape(2) ?></svg></div>
        <h3>Choisissez un voisin</h3>
        <p>Comparez les prix, les avis et la distance. À vous de décider.</p>
        <span class="km-step-arrow"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg></span>
      </div>
      <div class="km-step">
        <span class="km-step-num">03</span>
        <div class="km-step-icon"><svg viewBox="0 0 20 20"><?= iconeEtape(3) ?></svg></div>
        <h3>Échangez simplement</h3>
        <p>Un message, un accord, et votre service est en route.</p>
        <span class="km-step-arrow"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg></span>
      </div>
    </div>
  </div>
</section>

<!-- ── CATÉGORIES (annuaire) ── -->
<section class="km-section km-directory-section" id="categories">
  <div class="wrap">
    <div class="km-section-head">
      <div class="km-htext">
        <div class="km-eyebrow-label">Le catalogue</div>
        <h2 class="km-serif">Tout ce qu'il vous faut,<br><em>juste à côté.</em></h2>
        <p>Des savoir-faire locaux, proposés par des personnes qui connaissent votre quartier.</p>
      </div>
      <div class="km-directory-stat">
        <span class="km-directory-stat-icon"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l7-5 7 5v8a1 1 0 0 1-1 1h-3v-5H7v5H4a1 1 0 0 1-1-1V8Z"/></svg></span>
        <span><strong><?= count($demo_categories) ?> domaines</strong><br>et des dizaines de talents</span>
      </div>
    </div>
    <div class="km-directory-grid">
      <?php foreach ($demo_categories as $i => $c): if ($i % 2 !== 0) continue; ?>
      <a href="<?= estConnecte() ? 'client_dashboard.php?tab=catalogue' : 'inscription.php' ?>" class="km-cat-row">
        <span class="km-cat-icon"><svg viewBox="0 0 20 20"><?= iconeCategorie($c['nom_categorie']) ?></svg></span>
        <span class="km-cat-name"><?= htmlspecialchars($c['nom_categorie']) ?></span>
        <span class="km-cat-leader"></span>
        <?php if ($c['nb'] !== null): ?><span class="km-cat-count"><?= (int)$c['nb'] ?> service<?= (int)$c['nb'] > 1 ? 's' : '' ?></span><?php endif; ?>
        <svg class="km-cat-arrow" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 4l6 6-6 6"/></svg>
      </a>
      <?php endforeach; ?>
      <div>
        <?php foreach ($demo_categories as $i => $c): if ($i % 2 === 0) continue; ?>
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
  </div>
</section>

<!-- ── PENSÉ POUR VOUS / QUARTIER ── -->
<section class="km-section">
  <div class="wrap km-local-grid">
    <div class="km-local-copy">
      <div class="km-eyebrow-label">Pensé pour vous</div>
      <h2 class="km-serif">Votre quartier.<br><em>Votre rythme.</em></h2>
      <p>KoudMain commence là où vous vivez. Parce qu'un service de confiance, c'est souvent quelqu'un que l'on peut croiser au marché.</p>
      <div class="km-local-stats">
        <div class="km-local-stat"><strong><em><?= $nb_quartiers_total ?></em></strong><span>quartiers couverts</span></div>
        <div class="km-local-stat"><strong><em>96%</em></strong><span>de voisins satisfaits</span></div>
      </div>
    </div>
    <div class="km-map">
      <div class="km-map-road km-map-road-1"></div>
      <div class="km-map-road km-map-road-2"></div>
      <span class="km-map-pin" style="top:22%;left:14%"><i></i><?= htmlspecialchars($demo_quartiers[0] ?? 'Cocody') ?></span>
      <span class="km-map-pin km-map-pin-you" style="top:48%;left:52%"><i></i>Vous</span>
      <span class="km-map-pin" style="top:70%;left:30%"><i></i><?= htmlspecialchars($demo_quartiers[1] ?? 'Yopougon') ?></span>
      <span class="km-map-pin" style="top:60%;left:74%"><i></i><?= htmlspecialchars($demo_quartiers[2] ?? 'Marcory') ?></span>
      <div class="km-map-caption"><i></i> Des prestataires actifs maintenant</div>
    </div>
  </div>
</section>

<!-- ── DERNIÈRES ANNONCES ── -->
<section class="km-section" style="padding-top:0">
  <div class="wrap">
    <div class="km-section-head">
      <div class="km-htext">
        <div class="km-eyebrow-label">Les dernières annonces</div>
        <h2 class="km-serif">Des voisins qui<br><em>ont du talent.</em></h2>
      </div>
      <a href="<?= estConnecte() ? 'client_dashboard.php?tab=catalogue' : 'inscription.php' ?>" class="km-nav-link" style="font-weight:600">
        Voir le catalogue
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="width:13px;height:13px;display:inline-block;vertical-align:-1px;margin-left:0.2rem"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
      </a>
    </div>
    <div class="km-listings-grid">
      <?php foreach ($demo_prestations as $i => $p): ?>
      <div class="km-listing">
        <?php if ($i === 0): ?><span class="km-listing-tag">Nouveau</span><?php endif; ?>
        <div class="km-listing-icon"><svg viewBox="0 0 20 20"><path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/></svg></div>
        <div class="km-listing-top">
          <span class="km-listing-title"><?= htmlspecialchars($p['titre_prestation']) ?></span>
        </div>
        <div class="km-listing-price"><?= number_format((float)$p['prix_prestation'], 0, ',', ' ') ?> F</div>
        <div class="km-listing-meta">
          <span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 18s6-5.2 6-9.8A6 6 0 0 0 4 8.2C4 12.8 10 18 10 18Z"/><circle cx="10" cy="8" r="2"/></svg><?= htmlspecialchars($p['nom_quartier']) ?></span>
          <span><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 1.5l2.6 5.4 5.9.7-4.3 4.1 1.1 5.9L10 14.7l-5.3 2.9 1.1-5.9L1.5 7.6l5.9-.7L10 1.5Z"/></svg>4.8</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── CTA DEVENIR PRESTATAIRE ── -->
<section class="km-section" id="devenir-prestataire" style="padding-top:0">
  <div class="wrap">
    <div class="km-cta-dark">
      <div class="km-cta-grid">
        <div>
          <div class="km-eyebrow-inverse">Vous avez un savoir-faire ?</div>
          <h2 class="km-serif">Votre talent<br><em>mérite un quartier.</em></h2>
          <p>Publiez vos prestations gratuitement et rencontrez les clients qui vous cherchent déjà.</p>
          <?php if (!estConnecte() || (!estPrestataire() && !estAdmin())): ?>
            <a href="inscription.php?role=prestataire" class="km-btn km-btn-primary" style="margin-top:1.6rem">
              Devenir prestataire
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
            </a>
          <?php else: ?>
            <a href="prestataire_dashboard.php" class="km-btn km-btn-primary" style="margin-top:1.6rem">
              Voir mon espace
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
            </a>
          <?php endif; ?>
          <div class="km-cta-checks">
            <span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10l4 4 8-8"/></svg>Gratuit pour commencer</span>
            <span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10l4 4 8-8"/></svg>Sans engagement</span>
          </div>
        </div>
        <div class="km-cta-orb">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/></svg>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="km-footer">
  <div class="wrap">
    <div class="km-footer-inner">
      <div class="km-footer-brand">
        <a href="index.php" class="km-logo">KoudMain</a>
        <p>Les services de confiance, au coin de votre rue.</p>
      </div>
      <div class="km-footer-cols">
        <div class="km-footer-col">
          <span>Découvrir</span>
          <a href="#categories">Le catalogue</a>
          <a href="#comment-ca-marche">Comment ça marche</a>
        </div>
        <div class="km-footer-col">
          <span>Participer</span>
          <a href="inscription.php?role=prestataire">Devenir prestataire</a>
          <a href="connexion.php">Se connecter</a>
        </div>
        <div class="km-footer-col">
          <span>Suivez-nous</span>
          <a href="#">Instagram</a>
          <a href="#">WhatsApp</a>
        </div>
      </div>
    </div>
    <div class="km-footer-bottom">
      <p>© <?= date('Y') ?> KoudMain — Fait avec soin à Abidjan.</p>
      <p>Une plateforme pour les voisins.</p>
    </div>
  </div>
</footer>

<script>
window.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => {
    document.getElementById('km-hero').classList.add('km-loaded');
  });
});
</script>

</body>
</html>
