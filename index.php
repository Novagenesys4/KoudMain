<?php
require_once "config.php";
// --- Contenu vitrine ---
$demo_prestations = [];
$demo_quartiers   = [];
$demo_categories  = [];
$nb_services      = 1842;

try {
    $pdo = getConnexion();

    $stmt = $pdo->query("
        SELECT p.titre_prestation, p.prix_prestation, q.nom_quartier, p.note_moyenne
        FROM Prestation p
        JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
        JOIN Quartier q ON u.id_quartier = q.id_quartier
        ORDER BY p.datecrea_prestation DESC
        LIMIT 3
    ");
    $demo_prestations = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT nom_quartier FROM Quartier ORDER BY nom_quartier LIMIT 10");
    $demo_quartiers = array_column($stmt->fetchAll(), 'nom_quartier');

    $stmt = $pdo->query("
        SELECT c.nom_categorie, COUNT(s.id_service) AS nb
        FROM Categorie c
        LEFT JOIN Service s ON c.id_categorie = s.id_categorie
        GROUP BY c.id_categorie
        ORDER BY c.nom_categorie
    ");
    $demo_categories = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT COUNT(*) FROM Prestation");
    $nb_services = (int)$stmt->fetchColumn() ?: 1842;
} catch (Throwable $e) {
    // La vitrine reste fonctionnelle même si la base est indisponible.
}

if (empty($demo_prestations)) {
    $demo_prestations = [
        ['titre_prestation' => 'Coiffure à domicile',  'prix_prestation' => 5000, 'nom_quartier' => 'Cocody',   'note_moyenne' => 4.9],
        ['titre_prestation' => 'Réparation de fuite',   'prix_prestation' => 8000, 'nom_quartier' => 'Yopougon', 'note_moyenne' => 4.8],
        ['titre_prestation' => 'Repassage express',     'prix_prestation' => 3500, 'nom_quartier' => 'Marcory',  'note_moyenne' => 4.7],
    ];
}
if (empty($demo_quartiers)) {
    $demo_quartiers = ['Cocody', 'Yopougon', 'Marcory', 'Adjamé', 'Koumassi', 'Plateau', 'Port-Bouët', 'Abobo'];
}
if (empty($demo_categories)) {
    $noms = ['Beauté & Coiffure','Plomberie & Sanitaire','Laverie & Pressing',"Garde d'enfants",
             'Cuisine & Traiteur','Électricité','Jardinage','Déménagement','Informatique','Mécanique'];
    $counts = [24,18,16,12,31,14,11,9,17,13];
    $demo_categories = array_map(fn($n,$i) => ['nom_categorie' => $n, 'nb' => $counts[$i] ?? null], $noms, array_keys($noms));
}

// --- Icônes de catégories ---
function iconeCategorie(string $nom): string {
    $icones = [
        'Beauté & Coiffure'       => '<path d="M6 4 L14 14 M14 4 L6 14" /><circle cx="4.5" cy="15.5" r="1.7"/><circle cx="15.5" cy="15.5" r="1.7"/>',
        'Beauté et Coiffure'      => '<path d="M6 4 L14 14 M14 4 L6 14" /><circle cx="4.5" cy="15.5" r="1.7"/><circle cx="15.5" cy="15.5" r="1.7"/>',
        'Plomberie & Sanitaire'   => '<path d="M10 3c2.5 3 4 5.6 4 7.8a4 4 0 1 1-8 0C6 8.6 7.5 6 10 3Z"/>',
        'Plomberie et Sanitaire'  => '<path d="M10 3c2.5 3 4 5.6 4 7.8a4 4 0 1 1-8 0C6 8.6 7.5 6 10 3Z"/>',
        'Laverie & Pressing'      => '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="10" cy="11" r="4"/><path d="M7 3h6"/>',
        'Laverie et Pressing'     => '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="10" cy="11" r="4"/><path d="M7 3h6"/>',
        "Garde d'enfants"         => '<circle cx="10" cy="6" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/>',
        'Cuisine & Traiteur'      => '<path d="M4 9h12M5 9V5.5a2 2 0 0 1 2-2M8 9V5.5M11 9V5.5a2 2 0 0 0-2-2M5 9v6a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9"/>',
        'Cuisine et Traiteur'     => '<path d="M4 9h12M5 9V5.5a2 2 0 0 1 2-2M8 9V5.5M11 9V5.5a2 2 0 0 0-2-2M5 9v6a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9"/>',
        'Électricité'             => '<path d="M11 2 4 12h5l-1 6 7-10h-5l1-6Z"/>',
        'Jardinage'               => '<path d="M10 18V9M10 9C6 9 4 6.5 4 3c3.5 0 6 2 6 6Zm0 0c0-4 2.5-6 6-6 0 3.5-2 6-6 6Z"/>',
        'Déménagement'            => '<rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3M3 12h14"/>',
        'Informatique'            => '<rect x="3" y="3" width="14" height="10" rx="1"/><path d="M7 17h6M10 13v4"/>',
        'Mécanique'               => '<path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/>',
    ];
    return $icones[$nom] ?? '<circle cx="10" cy="10" r="6"/>';
}

function iconeService(string $titre): string {
    $t = mb_strtolower($titre);
    if (str_contains($t, 'coiff') || str_contains($t, 'brush') || str_contains($t, 'tresse')) {
        return '<path d="M6 4 L14 14 M14 4 L6 14"/><circle cx="4.5" cy="15.5" r="1.7"/><circle cx="15.5" cy="15.5" r="1.7"/>';
    }
    if (str_contains($t, 'fuite') || str_contains($t, 'plomb') || str_contains($t, 'réparation')) {
        return '<path d="M10 3c2.5 3 4 5.6 4 7.8a4 4 0 1 1-8 0C6 8.6 7.5 6 10 3Z"/>';
    }
    if (str_contains($t, 'repass') || str_contains($t, 'laver') || str_contains($t, 'pressing')) {
        return '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="10" cy="11" r="4"/><path d="M7 3h6"/>';
    }
    return '<circle cx="10" cy="10" r="6"/>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<meta name="theme-color" content="#f5f4f0">
<title>KoudMain — Les services de votre quartier</title>
<meta name="description" content="KoudMain relie les habitants d'Abidjan aux services et savoir-faire de leur quartier. Des services simples, des prix clairs, des visages du quartier.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;
  --paper-deep:#EBE8DF;
  --surface:#FFFEFA;
  --ink:#1D211C;
  --ink-soft:#6B6D64;
  --ink-faint:#9A9B91;
  --line:#DDDCD3;
  --amber:#BB6C2D;
  --amber-deep:#8D4E1F;
  --amber-tint:#F4E4D4;
  --teal:#2E6B5E;
  --teal-tint:#E3EFEA;
  --rose:#A85245;
  --radius:15px;
  --radius-sm:8px;
  --radius-xs:6px;
  --maxw:1180px;
  --ease:cubic-bezier(.22,1,.36,1);
  --shadow-sm:0 1px 2px rgba(28,27,23,0.04);
  --shadow-md:0 8px 24px rgba(28,27,23,0.07);
  --shadow-lg:0 18px 40px rgba(28,27,23,0.10);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  background:var(--paper);
  color:var(--ink);
  font-family:'Inter',system-ui,sans-serif;
  font-size:16px;
  line-height:1.6;
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
}
a{color:inherit;text-decoration:none}
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 2rem}
h1,h2,h3,.km-serif{font-family:'Fraunces',Georgia,serif}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:0.001ms !important;animation-iteration-count:1 !important;transition-duration:0.001ms !important;scroll-behavior:auto !important}
}

/* ── MASTHEAD ── */
.km-masthead{
  border-bottom:1px solid var(--line);
  background:rgba(245,244,240,0.85);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  position:sticky;top:0;z-index:50;
}
.km-masthead-inner{
  max-width:var(--maxw);margin:0 auto;padding:0 2rem;
  height:72px;display:flex;align-items:center;justify-content:space-between;gap:2rem;
}
.km-logo{
  font-family:'Fraunces',serif;font-weight:600;font-size:1.45rem;
  letter-spacing:-0.02em;color:var(--ink);
}
.km-logo em{font-style:normal;color:var(--amber)}
.km-nav-links{display:flex;align-items:center;gap:2rem}
.km-nav-link{
  font-size:0.9rem;color:var(--ink-soft);position:relative;padding:0.25rem 0;
  transition:color 0.2s ease;
}
.km-nav-link::after{
  content:'';position:absolute;left:0;right:0;bottom:0;height:1px;background:var(--ink);
  transform:scaleX(0);transform-origin:right;transition:transform 0.28s var(--ease);
}
.km-nav-link:hover{color:var(--ink)}
.km-nav-link:hover::after{transform:scaleX(1);transform-origin:left}
.km-nav-actions{display:flex;align-items:center;gap:0.9rem}
.km-link-muted{font-size:0.88rem;color:var(--ink-soft);transition:color 0.2s}
.km-link-muted:hover{color:var(--ink)}

/* ── BUTTONS ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:0.45rem;
  padding:0.7rem 1.25rem;
  font-family:'Inter',sans-serif;font-size:0.9rem;font-weight:600;
  border-radius:var(--radius-sm);border:1px solid transparent;cursor:pointer;
  transition:transform 0.18s var(--ease), box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
  white-space:nowrap;
}
.km-btn:active{transform:scale(0.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(141,78,31,0.28)}
.km-btn-amber{background:var(--amber);color:#fff}
.km-btn-amber:hover{background:var(--amber-deep);box-shadow:0 6px 18px rgba(141,78,31,0.28)}
.km-btn-outline{background:transparent;color:var(--ink);border-color:var(--line)}
.km-btn-outline:hover{border-color:var(--ink);background:var(--surface)}
.km-btn-sm{padding:0.48rem 1rem;font-size:0.84rem}
.km-btn svg{width:16px;height:16px;flex-shrink:0}

/* ── HERO ── */
.km-hero{padding:4rem 0 3.5rem;position:relative;overflow:hidden}
.km-hero-grid{
  display:grid;grid-template-columns:1.05fr 0.95fr;gap:3rem;align-items:center;
}
.km-eyebrow{
  display:inline-flex;align-items:center;gap:0.5rem;
  font-size:0.78rem;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;
  color:var(--ink-soft);margin-bottom:1.25rem;
}
.km-eyebrow-dot{
  width:7px;height:7px;border-radius:50%;background:var(--amber);
  box-shadow:0 0 0 3px var(--amber-tint);
}
.km-hero h1{
  font-size:clamp(2.6rem,5vw,3.75rem);
  font-weight:600;line-height:1.05;letter-spacing:-0.025em;
  color:var(--ink);max-width:14ch;
}
.km-hero h1 .accent{color:var(--amber)}
.km-hero-line{display:block;overflow:hidden}
.km-hero-line span{
  display:block;transform:translateY(110%);
  transition:transform 0.75s var(--ease);
}
.km-hero.km-loaded .km-hero-line span{transform:translateY(0)}
.km-hero-line:nth-child(1) span{transition-delay:0.06s}
.km-hero-line:nth-child(2) span{transition-delay:0.16s}
.km-hero-line:nth-child(3) span{transition-delay:0.26s}
.km-hero-desc{
  margin-top:1.35rem;color:var(--ink-soft);font-size:1.05rem;max-width:40ch;line-height:1.65;
  opacity:0;transform:translateY(10px);
  transition:opacity 0.6s ease 0.45s, transform 0.6s ease 0.45s;
}
.km-hero.km-loaded .km-hero-desc{opacity:1;transform:translateY(0)}

/* Search bar */
.km-search{
  margin-top:2rem;
  display:flex;align-items:center;gap:0;
  background:var(--surface);
  border:1px solid var(--line);
  border-radius:14px;
  padding:0.35rem 0.4rem 0.35rem 1rem;
  box-shadow:var(--shadow-md);
  opacity:0;transform:translateY(10px);
  transition:opacity 0.6s ease 0.55s, transform 0.6s ease 0.55s, box-shadow 0.25s ease;
}
.km-hero.km-loaded .km-search{opacity:1;transform:translateY(0)}
.km-search:focus-within{box-shadow:0 10px 32px rgba(28,27,23,0.12);border-color:#c8c5ba}
.km-search-input{
  flex:1;border:none;background:transparent;outline:none;
  font-family:inherit;font-size:0.95rem;color:var(--ink);padding:0.65rem 0.5rem;
  min-width:0;
}
.km-search-input::placeholder{color:var(--ink-faint)}
.km-search-divider{width:1px;height:28px;background:var(--line);flex-shrink:0;margin:0 0.4rem}
.km-search-select{
  border:none;background:transparent;outline:none;
  font-family:inherit;font-size:0.9rem;color:var(--ink-soft);
  padding:0.5rem 0.3rem;cursor:pointer;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B6D64' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 0.2rem center;padding-right:1.3rem;
}
.km-search .km-btn{border-radius:10px;padding:0.7rem 1.15rem}

.km-trust{
  margin-top:1.1rem;display:flex;align-items:center;gap:0.5rem;
  font-size:0.82rem;color:var(--ink-faint);
  opacity:0;transition:opacity 0.5s ease 0.7s;
}
.km-hero.km-loaded .km-trust{opacity:1}
.km-trust svg{width:15px;height:15px;color:var(--teal);flex-shrink:0}

/* Floating visual */
.km-visual{
  position:relative;height:420px;
  opacity:0;transform:translateY(16px);
  transition:opacity 0.7s ease 0.3s, transform 0.7s var(--ease) 0.3s;
}
.km-hero.km-loaded .km-visual{opacity:1;transform:translateY(0)}
.km-visual-orbit{
  position:absolute;inset:10% 5%;
  border-radius:50%;
  border:1px solid rgba(187,108,45,0.12);
  pointer-events:none;
}
.km-visual-orbit::before{
  content:'';position:absolute;inset:-18%;
  border-radius:50%;
  border:1px solid rgba(46,107,94,0.08);
}
.km-visual-orbit::after{
  content:'';position:absolute;inset:12%;
  border-radius:50%;
  border:1px solid rgba(187,108,45,0.07);
}

.km-float-card{
  position:absolute;
  background:var(--surface);
  border:1px solid var(--line);
  border-radius:14px;
  padding:0.9rem 1.1rem;
  box-shadow:var(--shadow-md);
  display:flex;align-items:center;gap:0.75rem;
  animation:km-float 5.5s ease-in-out infinite;
}
.km-float-card.dark{
  background:var(--ink);color:var(--paper);border-color:transparent;
  box-shadow:0 12px 32px rgba(28,27,23,0.25);
}
.km-float-icon{
  width:36px;height:36px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  background:var(--amber-tint);color:var(--amber-deep);
}
.km-float-card.dark .km-float-icon{background:rgba(255,255,255,0.08);color:#c9c5b9}
.km-float-icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.km-float-title{font-weight:600;font-size:0.9rem;line-height:1.25}
.km-float-meta{font-size:0.78rem;color:var(--ink-soft);margin-top:0.15rem}
.km-float-card.dark .km-float-meta{color:#a8a59a}
.km-float-badge{
  margin-left:auto;font-size:0.75rem;font-weight:600;
  display:flex;align-items:center;gap:0.25rem;color:var(--amber-deep);
}
.km-float-check{
  width:18px;height:18px;border-radius:50%;background:var(--teal);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.km-float-check svg{width:11px;height:11px;stroke:#fff;fill:none;stroke-width:2.5}

.km-float-1{top:8%;right:8%;animation-delay:0s}
.km-float-2{top:42%;left:0;animation-delay:1.2s}
.km-float-3{bottom:12%;right:4%;animation-delay:2.4s}
.km-float-caption{
  position:absolute;bottom:0;left:50%;transform:translateX(-50%);
  font-size:0.8rem;color:var(--ink-faint);white-space:nowrap;
  display:flex;align-items:center;gap:0.5rem;
}
.km-float-caption::before{
  content:'';width:24px;height:1px;background:var(--amber);
}

@keyframes km-float{
  0%,100%{transform:translateY(0)}
  50%{transform:translateY(-7px)}
}

/* Ticker */
.km-ticker-wrap{
  border-top:1px solid var(--line);border-bottom:1px solid var(--line);
  overflow:hidden;background:var(--paper-deep);
}
.km-ticker{display:flex;width:max-content;animation:km-scroll 40s linear infinite}
.km-ticker:hover{animation-play-state:paused}
@keyframes km-scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.km-ticker-item{
  padding:0.8rem 2.2rem;font-size:0.85rem;color:var(--ink-soft);white-space:nowrap;
  border-right:1px solid var(--line);
}
.km-ticker-item b{color:var(--ink);font-weight:600}

/* ── SECTIONS ── */
.km-section{padding:5.5rem 0}
.km-section-head{max-width:48ch;margin-bottom:3rem}
.km-section-eyebrow{
  font-size:0.78rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;
  color:var(--amber-deep);margin-bottom:0.7rem;
}
.km-section-head h2{
  font-size:clamp(1.75rem,2.8vw,2.35rem);font-weight:600;letter-spacing:-0.015em;line-height:1.15;
}
.km-section-head p{color:var(--ink-soft);margin-top:0.85rem;font-size:1.02rem}

/* Steps */
.km-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:2.5rem}
.km-step{
  border-top:1.5px solid var(--ink);padding-top:1.4rem;
  transition:transform 0.3s var(--ease);
}
.km-step:hover{transform:translateY(-3px)}
.km-step-num{
  font-family:'Fraunces',serif;font-size:0.95rem;color:var(--amber-deep);font-weight:600;
}
.km-step h3{margin-top:0.7rem;font-size:1.15rem;font-weight:600}
.km-step p{margin-top:0.55rem;color:var(--ink-soft);font-size:0.94rem;line-height:1.55}

/* Categories */
.km-cats-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;
}
.km-cat-card{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.25rem 1.2rem;transition:transform 0.22s var(--ease), box-shadow 0.22s ease, border-color 0.22s;
  display:flex;flex-direction:column;gap:0.6rem;
}
.km-cat-card:hover{
  transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:#c8c5ba;
}
.km-cat-card-icon{
  width:38px;height:38px;border-radius:10px;
  background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;
}
.km-cat-card-icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.km-cat-card-name{font-weight:600;font-size:0.95rem}
.km-cat-card-count{font-size:0.8rem;color:var(--ink-faint)}

/* Offers grid */
.km-offers{display:grid;grid-template-columns:repeat(3,1fr);gap:1.4rem}
.km-offer-card{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.4rem;display:flex;flex-direction:column;gap:0.7rem;
  transition:transform 0.22s var(--ease), box-shadow 0.22s ease;
}
.km-offer-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md)}
.km-offer-cat{
  font-size:0.75rem;font-weight:600;color:var(--teal);
  background:var(--teal-tint);padding:0.2rem 0.55rem;border-radius:20px;width:fit-content;
}
.km-offer-card h3{font-size:1.1rem;font-weight:600;line-height:1.3}
.km-offer-desc{font-size:0.9rem;color:var(--ink-soft);line-height:1.5;flex:1}
.km-offer-meta{font-size:0.82rem;color:var(--ink-faint)}
.km-offer-footer{
  display:flex;align-items:center;justify-content:space-between;gap:0.8rem;margin-top:0.3rem;
  padding-top:0.9rem;border-top:1px solid var(--line);
}
.km-offer-price{font-family:'Fraunces',serif;font-weight:600;font-size:1.05rem;color:var(--amber-deep)}
.km-offer-rating{display:flex;align-items:center;gap:0.25rem;font-size:0.82rem;font-weight:600;color:var(--ink)}
.km-offer-rating svg{width:14px;height:14px;fill:var(--amber);stroke:none}

/* CTA band */
.km-cta-band{
  background:var(--ink);color:var(--paper);border-radius:20px;
  padding:3.4rem 3.2rem;display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;
}
.km-cta-band h2{color:var(--paper);font-size:clamp(1.55rem,2.5vw,2.1rem);max-width:22ch;line-height:1.2}
.km-cta-band p{color:#C9C5B9;margin-top:0.7rem;max-width:40ch;font-size:0.97rem}
.km-cta-band .km-btn-primary{background:var(--amber);color:#fff}
.km-cta-band .km-btn-primary:hover{background:#CE8442}

/* Footer */
.km-footer{border-top:1px solid var(--line);padding:2.8rem 0;margin-top:0.5rem}
.km-footer-inner{display:flex;justify-content:space-between;align-items:center;gap:1.5rem;flex-wrap:wrap}
.km-footer p{color:var(--ink-faint);font-size:0.84rem}
.km-footer-brand{font-family:'Fraunces',serif;font-weight:600;font-size:1.1rem;color:var(--ink)}
.km-footer-brand em{font-style:normal;color:var(--amber)}
.km-footer-links{display:flex;gap:1.5rem}
.km-footer-links a{font-size:0.84rem;color:var(--ink-soft);transition:color 0.2s}
.km-footer-links a:hover{color:var(--ink)}
.km-footer-tagline{font-size:0.8rem;color:var(--ink-faint);margin-top:0.3rem}

/* Focus */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{
  outline:2px solid var(--amber-deep);outline-offset:3px;border-radius:4px;
}

/* Responsive */
@media(max-width:960px){
  .km-hero-grid{grid-template-columns:1fr;gap:2.5rem}
  .km-visual{height:340px;max-width:420px;margin:0 auto}
  .km-nav-links{display:none}
  .km-steps{grid-template-columns:1fr;gap:1.8rem}
  .km-offers{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  .wrap{padding:0 1.25rem}
  .km-masthead-inner{padding:0 1.25rem;height:64px}
  .km-hero{padding:2.8rem 0 2.2rem}
  .km-hero h1{font-size:2.35rem}
  .km-search{flex-wrap:wrap;padding:0.5rem}
  .km-search-divider{display:none}
  .km-search-select{width:100%;padding:0.5rem 0.6rem}
  .km-search .km-btn{width:100%;justify-content:center}
  .km-offers{grid-template-columns:1fr}
  .km-cats-grid{grid-template-columns:1fr 1fr}
  .km-cta-band{flex-direction:column;align-items:flex-start;padding:2.4rem 1.6rem}
  .km-section{padding:3.8rem 0}
  .km-visual{height:300px}
}
</style>
</head>
<body>

<header class="km-masthead">
  <div class="km-masthead-inner">
    <a href="index.php" class="km-logo">Koud<em>Main</em></a>
    <nav class="km-nav-links">
      <a href="#catalogue" class="km-nav-link">Catalogue</a>
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
        <a href="connexion.php" class="km-link-muted">Se connecter</a>
        <a href="inscription.php" class="km-btn km-btn-primary km-btn-sm">Créer un compte <span aria-hidden="true">→</span></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- ── HERO ── -->
<section class="km-hero" id="km-hero">
  <div class="wrap km-hero-grid">
    <div>
      <div class="km-eyebrow">
        <span class="km-eyebrow-dot"></span>
        Le savoir-faire de votre quartier
      </div>
      <h1>
        <span class="km-hero-line"><span>Trouvez</span></span>
        <span class="km-hero-line"><span>quelqu'un de</span></span>
        <span class="km-hero-line"><span><span class="accent">confiance</span>, juste à</span></span>
        <span class="km-hero-line"><span>côté.</span></span>
      </h1>
      <p class="km-hero-desc">
        KoudMain relie les habitants d'Abidjan aux personnes qui savent faire.
        Des services simples, des prix clairs, des visages du quartier.
      </p>

      <form class="km-search" action="<?= estConnecte() ? 'client_dashboard.php' : 'inscription.php' ?>" method="get" role="search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="color:var(--ink-faint);flex-shrink:0"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input type="search" name="q" class="km-search-input" placeholder="Que recherchez-vous ?" aria-label="Rechercher un service">
        <div class="km-search-divider"></div>
        <select name="quartier" class="km-search-select" aria-label="Quartier">
          <option value="">Mon quartier</option>
          <?php foreach ($demo_quartiers as $q): ?>
            <option value="<?= htmlspecialchars($q) ?>"><?= htmlspecialchars($q) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="km-btn km-btn-amber">Rechercher <span aria-hidden="true">→</span></button>
      </form>

      <div class="km-trust">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Profils vérifiés · Paiement sécurisé par Wallet KoudMain
      </div>
    </div>

    <div class="km-visual">
      <div class="km-visual-orbit" aria-hidden="true"></div>

      <!-- Card dark : compteur -->
      <div class="km-float-card dark km-float-1">
        <div class="km-float-icon">
          <svg viewBox="0 0 20 20"><path d="M3 10c2-4 5-6 7-6s5 2 7 6c-2 4-5 6-7 6s-5-2-7-6Z"/><circle cx="10" cy="10" r="2.2"/></svg>
        </div>
        <div>
          <div class="km-float-meta" style="letter-spacing:0.04em;text-transform:uppercase;font-size:0.68rem">Aujourd'hui à Abidjan</div>
          <div class="km-float-title"><?= number_format($nb_services, 0, ',', ' ') ?> services disponibles</div>
        </div>
      </div>

      <!-- Card 1 -->
      <?php $p0 = $demo_prestations[0] ?? null; if ($p0): ?>
      <div class="km-float-card km-float-2">
        <div class="km-float-icon">
          <svg viewBox="0 0 20 20"><?= iconeService($p0['titre_prestation']) ?></svg>
        </div>
        <div>
          <div class="km-float-title"><?= htmlspecialchars($p0['titre_prestation']) ?></div>
          <div class="km-float-meta"><?= htmlspecialchars($p0['nom_quartier']) ?> · <?= number_format((float)$p0['prix_prestation'], 0, ',', ' ') ?> F</div>
        </div>
        <div class="km-float-check" title="Vérifié">
          <svg viewBox="0 0 12 12"><path d="M2.5 6.5l2.5 2.5 4.5-5"/></svg>
        </div>
      </div>
      <?php endif; ?>

      <!-- Card 2 -->
      <?php $p1 = $demo_prestations[1] ?? null; if ($p1): ?>
      <div class="km-float-card km-float-3">
        <div class="km-float-icon">
          <svg viewBox="0 0 20 20"><?= iconeService($p1['titre_prestation']) ?></svg>
        </div>
        <div>
          <div class="km-float-title"><?= htmlspecialchars($p1['titre_prestation']) ?></div>
          <div class="km-float-meta"><?= htmlspecialchars($p1['nom_quartier']) ?> · <?= number_format((float)$p1['prix_prestation'], 0, ',', ' ') ?> F</div>
        </div>
        <?php if (!empty($p1['note_moyenne'])): ?>
        <div class="km-float-badge">
          <svg width="12" height="12" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          <?= number_format((float)$p1['note_moyenne'], 1) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="km-float-caption">Des pros, pas des profils anonymes.</div>
    </div>
  </div>
</section>

<!-- ── TICKER QUARTIERS ── -->
<div class="km-ticker-wrap" aria-hidden="true">
  <div class="km-ticker">
    <?php
      $liste = array_merge($demo_quartiers, $demo_quartiers);
      foreach ($liste as $q): ?>
      <span class="km-ticker-item"><b><?= htmlspecialchars($q) ?></b> · services locaux</span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── COMMENT ÇA MARCHE ── -->
<section class="km-section" id="comment-ca-marche">
  <div class="wrap">
    <div class="km-section-head">
      <div class="km-section-eyebrow">La bonne rencontre, sans détour</div>
      <h2 class="km-serif">Un service de quartier devrait rester simple.</h2>
      <p>Pas besoin de parcourir une liste interminable. KoudMain vous aide à trouver la bonne personne, au bon endroit, au bon moment.</p>
    </div>
    <div class="km-steps">
      <div class="km-step">
        <div class="km-step-num">01</div>
        <h3>Dites-nous ce qu'il vous faut</h3>
        <p>Une recherche, un quartier, une envie. Vous gardez la main sur chaque détail.</p>
      </div>
      <div class="km-step">
        <div class="km-step-num">02</div>
        <h3>Choisissez un visage connu</h3>
        <p>Regardez les avis, le prix et la distance avant de faire votre choix.</p>
      </div>
      <div class="km-step">
        <div class="km-step-num">03</div>
        <h3>Suivez votre commande</h3>
        <p>Le Wallet sécurise le paiement et chaque étape reste visible dans votre espace.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── CATALOGUE CATÉGORIES ── -->
<section class="km-section" id="catalogue" style="background:var(--paper-deep);padding-top:4.5rem;padding-bottom:4.5rem">
  <div class="wrap">
    <div class="km-section-head">
      <div class="km-section-eyebrow">Le catalogue du quotidien</div>
      <h2 class="km-serif">Ce que le quartier sait déjà faire.</h2>
      <p>Des prestations concrètes, proposées par des personnes qui connaissent votre ville.</p>
    </div>
    <div class="km-cats-grid">
      <?php foreach ($demo_categories as $c): ?>
      <a href="<?= estConnecte() ? 'client_dashboard.php?tab=catalogue' : 'inscription.php' ?>" class="km-cat-card">
        <div class="km-cat-card-icon">
          <svg viewBox="0 0 20 20"><?= iconeCategorie($c['nom_categorie']) ?></svg>
        </div>
        <div class="km-cat-card-name"><?= htmlspecialchars($c['nom_categorie']) ?></div>
        <?php if ($c['nb'] !== null): ?>
          <div class="km-cat-card-count"><?= (int)$c['nb'] ?> service<?= (int)$c['nb'] > 1 ? 's' : '' ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── OFFRES EN VEDETTE ── -->
<section class="km-section" id="offres">
  <div class="wrap">
    <div class="km-section-head" style="display:flex;align-items:flex-end;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;max-width:none">
      <div>
        <div class="km-section-eyebrow">À découvrir maintenant</div>
        <h2 class="km-serif">Les offres qui circulent près de vous.</h2>
      </div>
      <a href="<?= estConnecte() ? 'client_dashboard.php?tab=catalogue' : 'inscription.php' ?>" class="km-btn km-btn-outline km-btn-sm">
        Voir tout le catalogue <span aria-hidden="true">→</span>
      </a>
    </div>
    <div class="km-offers">
      <?php
        $cats_demo = ['Beauté & Coiffure', 'Plomberie & Sanitaire', 'Laverie & Pressing'];
        foreach ($demo_prestations as $i => $p):
          $cat = $cats_demo[$i] ?? 'Service';
          $note = $p['note_moyenne'] ?? (4.9 - $i * 0.1);
      ?>
      <article class="km-offer-card">
        <span class="km-offer-cat"><?= htmlspecialchars($cat) ?></span>
        <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
        <p class="km-offer-desc">
          <?php
            $descs = [
              'Brushing, tresses et coupe au calme, chez vous.',
              'Diagnostic rapide et intervention propre, même le week-end.',
              'Votre linge prêt dans la journée, plié avec soin.',
            ];
            echo $descs[$i] ?? 'Prestation de qualité par un professionnel de votre quartier.';
          ?>
        </p>
        <div class="km-offer-meta">
          <?= htmlspecialchars($p['nom_quartier']) ?> · par un prestataire vérifié
        </div>
        <div class="km-offer-footer">
          <span class="km-offer-price"><?= number_format((float)$p['prix_prestation'], 0, ',', ' ') ?> F</span>
          <span class="km-offer-rating">
            <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            <?= number_format((float)$note, 1) ?>
          </span>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── CTA DEVENIR PRESTATAIRE ── -->
<section class="km-section" id="devenir-prestataire" style="padding-top:1rem">
  <div class="wrap">
    <div class="km-cta-band">
      <div>
        <h2 class="km-serif">Et si votre prochain client habitait à deux rues ?</h2>
        <p>Rejoignez les prestataires qui font vivre KoudMain. Publiez vos services, gérez vos commandes et soyez payé simplement.</p>
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
    <div>
      <div class="km-footer-brand">Koud<em>Main</em></div>
      <p class="km-footer-tagline">Fait ici. Pour ici.</p>
    </div>
    <div class="km-footer-links">
      <a href="connexion.php">Connexion</a>
      <a href="inscription.php">S'inscrire</a>
      <a href="#comment-ca-marche">Comment ça marche</a>
    </div>
  </div>
</footer>

<script>
window.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => {
    document.getElementById('km-hero')?.classList.add('km-loaded');
  });
});
</script>
</body>
</html>
