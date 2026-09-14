<?php
require_once "config.php";
requireAdmin();

$pdo = getConnexion();
$msg = "";
$err = "";

// ---------------------------------------------------------------------------
// Actions admin (logique identique à la version précédente)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    if ($_POST['action'] === 'valider_prestataire') {
        $id = (int)$_POST['id_utilisateur'];
        $pdo->prepare("UPDATE Utilisateur SET est_valide = true WHERE id_utilisateur = ?")
            ->execute([$id]);
        $msg = "Compte prestataire validé avec succès.";
    }

    elseif ($_POST['action'] === 'suspendre') {
        $id = (int)$_POST['id_utilisateur'];
        $pdo->prepare("UPDATE Utilisateur SET est_valide = false WHERE id_utilisateur = ? AND est_admin = false")
            ->execute([$id]);
        $msg = "Compte suspendu.";
    }

    elseif ($_POST['action'] === 'supprimer_compte') {
        $id = (int)$_POST['id_utilisateur'];
        $check = $pdo->prepare("SELECT est_admin FROM Utilisateur WHERE id_utilisateur = ?");
        $check->execute([$id]);
        $u = $check->fetch();
        if ($u && !$u['est_admin']) {
            $pdo->prepare("DELETE FROM Utilisateur WHERE id_utilisateur = ?")
                ->execute([$id]);
            $msg = "Compte supprimé.";
        } else {
            $err = "Impossible de supprimer ce compte.";
        }
    }

    elseif ($_POST['action'] === 'ajouter_categorie') {
        $nom = trim($_POST['nom_categorie'] ?? '');
        if (empty($nom)) {
            $err = "Le nom de la catégorie ne peut pas être vide.";
        } else {
            $pdo->prepare("INSERT INTO Categorie (nom_categorie) VALUES (?)")
                ->execute([$nom]);
            $msg = "Catégorie « $nom » ajoutée.";
        }
    }

    elseif ($_POST['action'] === 'supprimer_categorie') {
        $id = (int)$_POST['id_categorie'];
        try {
            $pdo->prepare("DELETE FROM Categorie WHERE id_categorie = ?")
                ->execute([$id]);
            $msg = "Catégorie supprimée.";
        } catch (PDOException $e) {
            $err = "Impossible de supprimer : cette catégorie est liée à des services existants.";
        }
    }

    elseif ($_POST['action'] === 'ajouter_service') {
        $nom = trim($_POST['nom_service'] ?? '');
        $cat = (int)$_POST['id_categorie'];
        if (empty($nom) || $cat === 0) {
            $err = "Veuillez remplir tous les champs.";
        } else {
            $pdo->prepare("INSERT INTO Service (nom_service, id_categorie) VALUES (?, ?)")
                ->execute([$nom, $cat]);
            $msg = "Service « $nom » ajouté.";
        }
    }
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$onglet    = $_GET['tab'] ?? 'tableau_bord';
$search_u  = trim($_GET['q'] ?? '');

$nb_users       = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_admin = false")->fetchColumn();
$nb_clients     = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_client = true AND est_admin = false")->fetchColumn();
$nb_prest       = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = true")->fetchColumn();
$nb_attente     = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = true AND est_valide = false")->fetchColumn();
$nb_commandes   = (int)$pdo->query("SELECT COUNT(*) FROM Commande")->fetchColumn();
$nb_cats        = (int)$pdo->query("SELECT COUNT(*) FROM Categorie")->fetchColumn();
$nb_prestations = (int)$pdo->query("SELECT COUNT(*) FROM Prestation")->fetchColumn();
$nb_prest_valid = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = true AND est_valide = true")->fetchColumn();
$nb_cmd_termine = (int)$pdo->query("SELECT COUNT(*) FROM Commande WHERE statut = 'Terminé'")->fetchColumn();

$prest_attente = $pdo->query("
    SELECT u.*, q.nom_quartier, v.nom_ville
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    JOIN Ville v ON q.id_ville = v.id_ville
    WHERE u.est_prestataire = true AND u.est_valide = false
    ORDER BY u.datecrea_utilisateur DESC
")->fetchAll();

// --- Prestataires (onglet dédié, liste complète) ---
$prestataires_tous = [];
if ($onglet === 'prestataires') {
    $prestataires_tous = $pdo->query("
        SELECT u.*, q.nom_quartier
        FROM Utilisateur u
        JOIN Quartier q ON u.id_quartier = q.id_quartier
        WHERE u.est_prestataire = true
        ORDER BY u.est_valide ASC, u.datecrea_utilisateur DESC
    ")->fetchAll();
}

// --- Utilisateurs (recherche + pagination) ---
$limit_users  = 15;
$page_users   = max(1, (int)($_GET['p_u'] ?? 1));
$offset_users = ($page_users - 1) * $limit_users;

$sql_u_count = "SELECT COUNT(*) FROM Utilisateur u WHERE u.est_admin = false";
$sql_u       = "
    SELECT u.*, q.nom_quartier
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    WHERE u.est_admin = false
";
$params_u = [];
if ($search_u !== '') {
    $clause = " AND (u.nom_utilisateur ILIKE ? OR u.prenom_utilisateur ILIKE ? OR u.email_utilisateur ILIKE ?)";
    $sql_u_count .= $clause;
    $sql_u       .= $clause;
    $params_u = ["%$search_u%", "%$search_u%", "%$search_u%"];
}
$stmt_u_count = $pdo->prepare($sql_u_count);
$stmt_u_count->execute($params_u);
$nb_users_filtered = (int)$stmt_u_count->fetchColumn();
$nb_pages_users     = max(1, (int)ceil($nb_users_filtered / $limit_users));

$sql_u .= " ORDER BY u.datecrea_utilisateur DESC LIMIT $limit_users OFFSET $offset_users";
$stmt_u = $pdo->prepare($sql_u);
$stmt_u->execute($params_u);
$tous_users = $stmt_u->fetchAll();

// --- Catalogue ---
$categories = $pdo->query("
    SELECT c.*, COUNT(s.id_service) AS nb_services
    FROM Categorie c
    LEFT JOIN Service s ON c.id_categorie = s.id_categorie
    GROUP BY c.id_categorie
    ORDER BY c.nom_categorie
")->fetchAll();

$services = $pdo->query("
    SELECT s.*, c.nom_categorie, COUNT(p.id_prestation) AS nb_prestations
    FROM Service s
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Prestation p ON s.id_service = p.id_service
    GROUP BY s.id_service, c.nom_categorie
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();

// --- Commandes ---
$limit_cmd_adm    = 15;
$page_cmd_adm     = max(1, (int)($_GET['p_c'] ?? 1));
$offset_cmd_adm   = ($page_cmd_adm - 1) * $limit_cmd_adm;
$nb_pages_cmd_adm = max(1, (int)ceil($nb_commandes / $limit_cmd_adm));

$stmt_c = $pdo->prepare("
    SELECT cm.*, u.nom_utilisateur, u.prenom_utilisateur,
           ci.id_prestation, p.titre_prestation,
           pu.nom_utilisateur AS prest_nom, pu.prenom_utilisateur AS prest_prenom
    FROM Commande cm
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur pu ON p.id_utilisateur = pu.id_utilisateur
    ORDER BY cm.date_commande DESC
    LIMIT $limit_cmd_adm OFFSET $offset_cmd_adm
");
$stmt_c->execute();
$commandes = $stmt_c->fetchAll();

$prenom  = htmlspecialchars($_SESSION['prenom'] ?? 'Admin');
$nomFull = htmlspecialchars(trim(($_SESSION['prenom'] ?? 'Admin') . ' ' . ($_SESSION['nom'] ?? '')));
$initiales = strtoupper(mb_substr($_SESSION['prenom'] ?? 'A', 0, 1) . mb_substr($_SESSION['nom'] ?? 'M', 0, 1));

$tab_labels = [
    'tableau_bord' => 'Tableau de bord',
    'prestataires' => 'Prestataires',
    'utilisateurs' => 'Utilisateurs',
    'categories'   => 'Catalogue',
    'commandes'    => 'Commandes',
];
$tab_label_courant = $tab_labels[$onglet] ?? 'Tableau de bord';

// ---------------------------------------------------------------------------
// Icônes (mini-set style lucide, viewBox 24x24)
// ---------------------------------------------------------------------------
function icon(string $name, int $size = 17): string {
    $paths = [
        'layout-dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'user-circle'      => '<path d="M18 20a6 6 0 0 0-12 0"/><circle cx="12" cy="10" r="4"/><circle cx="12" cy="12" r="10"/>',
        'users-round'      => '<path d="M18 21a8 8 0 0 0-16 0"/><circle cx="10" cy="8" r="5"/><path d="M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3"/>',
        'boxes'            => '<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19.24l3.97 2.14a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L18 11.1"/><path d="m7 16.5-4.74-2.85"/><path d="m7 16.5 5-3"/><path d="M7 16.5v5.17"/><path d="M12 13.5V19"/><path d="M12 13.5 9.03 11.65"/><path d="m12 13.5 5-3"/><path d="M17 16.5v5.17"/><path d="m17 16.5 4.74-2.85"/><path d="m12 4 3.97 2.14a2 2 0 0 1 .03 3.44L12 11.5 7.97 9.6a2 2 0 0 1 .03-3.44Z"/>',
        'clipboard-list'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'arrow-right'      => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'log-out'          => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'             => '<line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'bell'             => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'chevron-right'    => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left'     => '<path d="m15 18-6-6 6-6"/>',
        'check'            => '<polyline points="20 6 9 17 4 12"/>',
        'x'                => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'trash'            => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'shield-check'     => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'bar-chart'        => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'sparkles'         => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'search'           => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'plus'             => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'package-check'    => '<path d="M16 16h6"/><path d="M19 13v6"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><path d="M3.29 7 12 12l8.71-5"/><path d="M12 22V12"/>',
        'grid'             => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
    ];
    $body = $paths[$name] ?? $paths['sparkles'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function formatMoney($v): string {
    return number_format((float)$v, 0, ',', ' ') . ' F';
}
function formatDate(string $v, bool $withTime = false): string {
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', strtotime($v));
}
function initiales_de(string $prenom, string $nom): string {
    return mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administration — KoudMain</title>
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
  --sidebar:#1e201d;
  --sidebar-ink:#e8e8df;
  --sidebar-muted:#a3a79a;
  --radius:16px;
  --ease:cubic-bezier(.2,.8,.2,1);
  --shadow-soft:0 18px 46px rgba(47,43,34,.06), 0 2px 8px rgba(47,43,34,.04);
  --shadow-float:0 22px 60px rgba(27,29,25,.16), 0 6px 18px rgba(27,29,25,.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{min-width:320px;background:var(--paper);scroll-behavior:smooth}
body{
  margin:0;min-width:320px;min-height:100vh;background:var(--paper);color:var(--ink);
  font-family:"DM Sans",sans-serif;font-size:15px;line-height:1.6;
  -webkit-font-smoothing:antialiased;
}
body::before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.035;z-index:1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.5'/%3E%3C/svg%3E")}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
button{border:0;background:transparent}
button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--amber-deep);outline-offset:3px}
button:not(:disabled){cursor:pointer}
h1,h2,h3,.km-serif{font-family:Fraunces,Georgia,serif;font-weight:600;letter-spacing:-.03em}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}}

.admin-shell{min-height:100vh;position:relative;z-index:2;background:radial-gradient(circle at 74% -10%, rgba(255,255,255,.76), transparent 31rem), var(--paper)}

/* ── SIDEBAR ── */
.admin-sidebar{
  position:fixed;z-index:30;inset:0 auto 0 0;width:255px;padding:25px 14px 17px;
  display:flex;flex-direction:column;background:var(--sidebar);color:var(--sidebar-ink);
  box-shadow:10px 0 35px rgba(20,22,19,.06);
}
.brand-lockup{display:flex;align-items:center;gap:11px;padding:4px 13px 28px;border-bottom:1px solid rgba(255,255,255,.10)}
.brand-mark{display:grid;place-items:center;width:29px;height:29px;color:var(--sidebar);background:#d58d51;border-radius:9px;box-shadow:0 0 0 4px rgba(217,165,110,.10)}
.brand-name{font:600 22px/1 Fraunces,Georgia,serif;letter-spacing:-.035em;color:#fffdf7}
.brand-name span{color:#d58d51}
.sidebar-caption{padding:23px 13px 10px;color:#777d70;text-transform:uppercase;letter-spacing:.13em;font-size:9px;font-weight:700}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.sidebar-link{
  position:relative;display:flex;align-items:center;gap:11px;width:100%;min-height:42px;padding:0 12px;
  color:var(--sidebar-muted);text-align:left;background:transparent;border-radius:10px;font-size:13px;
  transition:background .18s ease,color .18s ease,transform .18s ease;
}
.sidebar-link svg{flex:0 0 auto;opacity:.92}
.sidebar-link:hover{color:#fff;background:rgba(255,255,255,.06);transform:translateX(2px)}
.sidebar-link.active{color:#f6e2cb;background:linear-gradient(90deg, rgba(185,107,42,.25), rgba(185,107,42,.09));font-weight:650}
.sidebar-link.active::before{position:absolute;content:"";left:0;top:9px;bottom:9px;width:2px;border-radius:2px;background:#d49758}
.sidebar-badge{display:grid;place-items:center;min-width:20px;height:20px;margin-left:auto;padding:0 6px;color:#2b241c;background:#e1ad74;border-radius:30px;font-size:10px;font-weight:800}
.sidebar-section-label{margin:23px 12px 6px;color:#777d70;text-transform:uppercase;letter-spacing:.13em;font-size:9px;font-weight:700}
.muted-link{color:#7f8579}
.sidebar-bottom{display:flex;align-items:center;gap:10px;margin-top:auto;padding:14px 12px 4px;border-top:1px solid rgba(255,255,255,.10);color:#a9ad9f}
.sidebar-bottom-dot{width:8px;height:8px;border-radius:50%;background:#72a78d;box-shadow:0 0 0 4px rgba(114,167,141,.12)}
.sidebar-bottom strong,.sidebar-bottom span{display:block}
.sidebar-bottom strong{color:#e5e3d8;font-size:11px;font-weight:650}
.sidebar-bottom span{margin-top:2px;color:#7e8579;font-size:10px}

/* ── MAIN ── */
.admin-main{min-height:100vh;margin-left:255px}
.topbar{
  position:sticky;z-index:20;top:0;display:flex;align-items:center;justify-content:space-between;
  height:76px;padding:0 40px;border-bottom:1px solid rgba(211,208,197,.72);
  background:rgba(245,243,238,.88);backdrop-filter:blur(18px);
}
.topbar-context{display:flex;align-items:center;gap:8px;color:var(--ink-faint);font-size:12px}
.topbar-context strong{color:var(--ink-soft);font-weight:600}
.topbar-actions{display:flex;align-items:center;gap:16px}
.icon-button,.mobile-menu-button{display:grid;place-items:center;padding:0;color:var(--ink-soft);background:transparent}
.icon-button{position:relative;width:35px;height:35px;border:1px solid transparent;border-radius:9px;transition:background .18s ease,border .18s ease}
.icon-button:hover{border-color:var(--line);background:rgba(255,255,255,.58)}
.notification-button i{position:absolute;top:6px;right:7px;width:5px;height:5px;border-radius:50%;background:var(--amber);box-shadow:0 0 0 2px var(--paper);font-style:normal}
.topbar-divider{width:1px;height:24px;background:var(--line)}
.admin-profile{display:flex;align-items:center;gap:10px}
.avatar{display:grid;place-items:center;width:35px;height:35px;color:#7c481c;background:var(--amber-tint);border:1px solid rgba(185,107,42,.13);border-radius:50%;font-size:11px;font-weight:750}
.profile-copy strong,.profile-copy span{display:block}
.profile-copy strong{color:var(--ink);font-size:12px;font-weight:700}
.profile-copy span{margin-top:1px;color:var(--ink-faint);font-size:10px}
.profile-chevron{color:#b7b6ad;transform:rotate(90deg)}
.mobile-menu-button{display:none}

.content-wrap{max-width:1450px;margin:0 auto;padding:46px 40px 72px}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:31px;flex-wrap:wrap}
.page-kicker,.panel-eyebrow{margin-bottom:9px;color:var(--amber-deep);text-transform:uppercase;letter-spacing:.13em;font-size:10px;font-weight:800}
.page-header h1{margin:0;color:var(--ink);font:600 clamp(30px,3vw,42px)/1.06 Fraunces,Georgia,serif;letter-spacing:-.035em}
.page-header p{max-width:610px;margin:11px 0 0;color:var(--ink-soft);font-size:13px;line-height:1.65}
.header-date,.catalogue-summary,.orders-total{display:flex;align-items:center;gap:7px;color:var(--ink-faint);font-size:11px}
.live-dot{width:6px;height:6px;border-radius:50%;background:#6aa786;box-shadow:0 0 0 4px rgba(106,167,134,.12)}

.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:25px}
.stat-card{min-height:150px;padding:19px 20px 18px;background:rgba(255,254,250,.78);border:1px solid rgba(219,216,205,.88);border-radius:var(--radius);box-shadow:var(--shadow-soft);transition:transform .2s ease,box-shadow .2s ease}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 23px 52px rgba(47,43,34,.09), 0 3px 10px rgba(47,43,34,.05)}
.stat-card-top{display:flex;align-items:center;justify-content:space-between}
.stat-icon{display:grid;place-items:center;width:32px;height:32px;color:var(--ink-soft);background:var(--paper-deep);border-radius:9px}
.stat-teal .stat-icon{color:var(--teal);background:var(--teal-tint)}
.stat-amber .stat-icon{color:var(--amber-deep);background:var(--amber-tint)}
.stat-card-top span{color:#7ea28e;font-size:10px;font-weight:700}
.stat-amber .stat-card-top span{color:var(--amber-deep)}
.stat-value{margin-top:18px;font:600 32px/1 Fraunces,Georgia,serif;letter-spacing:-.04em}
.stat-label{margin-top:7px;color:var(--ink-soft);font-size:11px}

.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(285px,.75fr);gap:19px;align-items:start}
.side-stack{display:grid;gap:19px}
.km-panel{overflow:hidden;background:rgba(255,254,250,.84);border:1px solid rgba(219,216,205,.9);border-radius:var(--radius);box-shadow:var(--shadow-soft)}
.panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:78px;padding:19px 21px 17px;border-bottom:1px solid rgba(224,221,211,.88);flex-wrap:wrap}
.panel-header h2{margin:0;color:var(--ink);font:600 19px/1.1 Fraunces,Georgia,serif;letter-spacing:-.025em}
.text-link{display:inline-flex;align-items:center;gap:6px;padding:0;color:var(--amber-deep);background:transparent;font-size:11px;font-weight:750;transition:gap .18s ease,color .18s ease}
.text-link:hover{gap:9px;color:var(--ink)}
.panel-filter{display:inline-flex;align-items:center;gap:7px;color:var(--ink-soft);font-size:10px}
.filter-dot{width:6px;height:6px;border-radius:50%;background:#6aa786}
.panel-meta{display:flex;align-items:center;gap:8px;color:var(--ink-faint);font-size:10px}

.table-scroll{width:100%;overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;min-width:720px;font-size:11px}
.data-table th{padding:13px 18px;color:#9b9e93;background:rgba(245,243,238,.55);border-bottom:1px solid var(--line);text-align:left;text-transform:uppercase;letter-spacing:.095em;white-space:nowrap;font-size:9px;font-weight:800}
.data-table td{padding:15px 18px;color:var(--ink-soft);border-bottom:1px solid rgba(227,224,214,.76);vertical-align:middle;white-space:nowrap}
.data-table tbody tr{transition:background .18s ease}
.data-table tbody tr:hover{background:rgba(247,244,236,.7)}
.data-table tbody tr:last-child td{border-bottom:0}
.person-cell{display:flex;align-items:center;gap:10px}
.person-avatar{display:grid;place-items:center;flex:0 0 auto;width:32px;height:32px;color:#2f665b;background:#e0eee9;border-radius:10px;font-size:9px;font-weight:800}
.person-avatar.warm{color:#8e5b2e;background:#f1e3d2}
.person-avatar.neutral{color:#676862;background:#eceae2}
.person-cell strong,.person-cell span,.primary-cell,.secondary-cell{display:block}
.person-cell strong{color:var(--ink);font-size:11px;font-weight:750}
.person-cell span,.secondary-cell{margin-top:3px;color:var(--ink-faint);font-size:10px}
.primary-cell{color:var(--ink);font-weight:600}
.muted-cell{color:var(--ink-faint)!important}
.row-actions{display:flex;align-items:center;gap:6px}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:5px 8px;border-radius:20px;font-size:9px;font-weight:800}
.status-teal{color:var(--teal);background:var(--teal-tint)}
.status-amber{color:var(--amber-deep);background:var(--amber-tint)}
.status-neutral{color:#62665d;background:#eeece5}
.status-danger{color:var(--danger);background:var(--danger-tint)}

.km-button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:0 14px;border:1px solid transparent;border-radius:9px;font-size:11px;font-weight:750;transition:transform .16s ease,background .18s ease,color .18s ease,border .18s ease,box-shadow .18s ease}
.km-button:active{transform:scale(.97)}
.button-sm{min-height:31px;padding:0 9px;border-radius:8px;font-size:10px}
.button-dark{color:#fffdf7;background:var(--ink);box-shadow:0 7px 15px rgba(30,32,28,.1)}
.button-dark:hover{background:var(--amber-deep)}
.button-outline{color:var(--ink-soft);background:transparent;border-color:var(--line)}
.button-outline:hover{color:var(--ink);border-color:#a9aa9e;background:rgba(255,255,255,.5)}
.button-teal{color:#24594f;background:var(--teal-tint)}
.button-teal:hover{color:#fff;background:var(--teal)}
.button-danger{color:var(--danger);background:var(--danger-tint)}
.button-danger:hover{color:#fff;background:var(--danger)}
.full-width{width:100%}
.table-icon-danger{display:grid;place-items:center;width:30px;height:30px;color:var(--danger);background:transparent;border-radius:8px;transition:background .18s ease}
.table-icon-danger:hover{background:var(--danger-tint)}
.empty-state{display:flex;flex-direction:column;align-items:center;gap:7px;padding:48px 22px;color:var(--ink-soft);text-align:center}
.empty-state strong{color:var(--ink);font:600 17px/1.1 Fraunces,Georgia,serif}
.empty-state span{font-size:11px}
.empty-icon{display:grid;place-items:center;width:35px;height:35px;margin-bottom:5px;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%}
.activity-list{padding:3px 20px 9px}
.activity-item{display:grid;grid-template-columns:30px 1fr auto;align-items:center;gap:10px;padding:14px 0;border-bottom:1px solid rgba(227,224,214,.76)}
.activity-item:last-child{border-bottom:0}
.activity-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:9px}
.activity-icon.teal{color:var(--teal);background:var(--teal-tint)}
.activity-icon.amber{color:var(--amber-deep);background:var(--amber-tint)}
.activity-icon.ink{color:var(--ink-soft);background:var(--paper-deep)}
.activity-item strong,.activity-item span{display:block}
.activity-item strong{color:var(--ink);font-size:11px}
.activity-item div>span{margin-top:2px;color:var(--ink-faint);font-size:10px}
.activity-value{color:var(--teal);font-size:10px;font-weight:750}
.tip-panel{position:relative;display:flex;gap:12px;padding:19px 20px;border-color:rgba(185,107,42,.18);background:linear-gradient(125deg, rgba(248,235,216,.85), rgba(255,254,250,.88))}
.tip-glow{position:absolute;top:-45px;right:-36px;width:130px;height:130px;background:rgba(211,150,83,.14);border-radius:50%;filter:blur(10px)}
.tip-icon{position:relative;display:grid;place-items:center;flex:0 0 auto;width:32px;height:32px;color:var(--amber-deep);background:rgba(255,255,255,.63);border:1px solid rgba(185,107,42,.16);border-radius:10px}
.tip-panel strong,.tip-panel p{position:relative;display:block}
.tip-panel strong{color:var(--ink);font:600 16px/1.1 Fraunces,Georgia,serif}
.tip-panel p{max-width:210px;margin:7px 0 12px;color:var(--ink-soft);font-size:10px;line-height:1.5}

.search-field{display:flex;align-items:center;gap:8px;width:250px;height:39px;padding:0 11px;color:var(--ink-faint);background:rgba(255,254,250,.78);border:1px solid var(--line);border-radius:9px}
.search-field input{min-width:0;flex:1;color:var(--ink);background:transparent;border:0;outline:none;font-size:11px}
.search-field input::placeholder{color:#a4a69e}
.search-field button{display:grid;place-items:center;padding:0;color:var(--ink-faint);background:transparent}
.catalogue-summary{gap:18px}
.catalogue-summary span{display:inline-flex;align-items:center;gap:5px}
.catalogue-grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:19px;align-items:start}
.catalog-list{padding:4px 20px 10px}
.catalog-row{display:flex;align-items:center;justify-content:space-between;gap:15px;min-height:67px;border-bottom:1px solid rgba(227,224,214,.76)}
.catalog-row:last-child{border-bottom:0}
.catalog-leading{display:flex;align-items:center;gap:11px;min-width:0}
.category-swatch,.service-icon{display:grid;place-items:center;flex:0 0 auto;width:32px;height:32px;color:var(--amber-deep);background:var(--amber-tint);border-radius:9px}
.service-icon{color:var(--teal);background:var(--teal-tint)}
.catalog-leading strong,.catalog-leading span{display:block}
.catalog-leading strong{overflow:hidden;color:var(--ink);font-size:11px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.catalog-leading span{margin-top:3px;color:var(--ink-faint);font-size:10px}
.catalog-row-actions{display:flex;align-items:center;gap:7px}
.count-pill{display:grid;place-items:center;min-width:26px;height:24px;padding:0 7px;color:var(--ink-soft);background:var(--paper-deep);border-radius:8px;font-size:10px;font-weight:750}
.orders-total{color:var(--teal);font-weight:650}
.orders-table{min-width:880px}
.order-id{color:var(--ink-soft)!important;font:600 13px Fraunces,Georgia,serif}
.money-cell{color:var(--ink)!important;font:600 13px Fraunces,Georgia,serif}
.pagination{display:flex;justify-content:flex-end;gap:5px;padding:16px 19px 18px;flex-wrap:wrap}
.page-button{display:grid;place-items:center;min-width:28px;height:28px;padding:0 8px;color:var(--ink-soft);background:transparent;border:1px solid var(--line);border-radius:7px;font-size:10px;font-weight:700;transition:all .18s ease}
.page-button:hover:not(.active){color:var(--ink);border-color:#aaa99e;background:var(--paper-deep)}
.page-button.active{color:#fffdf7;background:var(--ink);border-color:var(--ink)}

.feedback{position:fixed;z-index:60;top:92px;right:37px;display:flex;align-items:center;gap:10px;max-width:380px;padding:11px 12px 11px 10px;border:1px solid var(--line);border-radius:10px;background:rgba(255,254,250,.96);box-shadow:var(--shadow-float);color:var(--ink);font-size:11px;animation:toast-in .26s cubic-bezier(.23,1,.32,1)}
.feedback-success{border-color:rgba(45,109,95,.23)}
.feedback-error{border-color:rgba(166,65,43,.23)}
.feedback-icon{display:grid;place-items:center;width:24px;height:24px;border-radius:7px}
.feedback-success .feedback-icon{color:var(--teal);background:var(--teal-tint)}
.feedback-error .feedback-icon{color:var(--danger);background:var(--danger-tint)}
.feedback>span{line-height:1.4}
.feedback>button{display:grid;place-items:center;padding:0;color:var(--ink-faint);background:transparent}
@keyframes toast-in{from{opacity:0;transform:translateY(-8px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}

.modal-overlay{position:fixed;z-index:50;inset:0;display:none;place-items:center;padding:20px;background:rgba(24,26,22,.54);backdrop-filter:blur(5px);animation:overlay-in .2s ease-out}
.modal-overlay.open{display:grid}
.modal-card{width:min(100%,430px);padding:25px;background:var(--surface);border:1px solid rgba(255,255,255,.5);border-radius:18px;box-shadow:var(--shadow-float);animation:modal-in .25s cubic-bezier(.23,1,.32,1)}
@keyframes overlay-in{from{opacity:0}to{opacity:1}}
@keyframes modal-in{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:19px;margin-bottom:20px;border-bottom:1px solid var(--line)}
.modal-header h2{margin:0;color:var(--ink);font:600 24px/1.05 Fraunces,Georgia,serif;letter-spacing:-.025em}
.modal-close{display:grid;place-items:center;width:29px;height:29px;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:8px}
.form-field{display:block;margin-bottom:16px}
.form-field>span{display:block;margin-bottom:7px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.09em;font-size:9px;font-weight:800}
.form-field input,.form-field select{width:100%;height:43px;padding:0 12px;color:var(--ink);background:var(--paper);border:1px solid var(--line);border-radius:9px;outline:none;font-size:12px}
.form-field input:focus,.form-field select:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(185,107,42,.11)}

.sidebar-backdrop{display:none}

@media(max-width:1150px){.content-wrap{padding-right:28px;padding-left:28px}.topbar{padding-right:28px;padding-left:28px}.dashboard-grid{grid-template-columns:1fr}.side-stack{grid-template-columns:1fr 1fr}}
@media(max-width:900px){
  .admin-sidebar{transform:translateX(-102%);transition:transform .25s cubic-bezier(.23,1,.32,1)}
  .admin-sidebar.open{transform:translateX(0)}
  .admin-main{margin-left:0}
  .mobile-menu-button{display:grid}
  .topbar-context{display:none}
  .sidebar-backdrop{position:fixed;z-index:25;inset:0;display:block;background:rgba(25,27,23,.35);backdrop-filter:blur(2px)}
}
@media(max-width:700px){
  .content-wrap{padding:32px 17px 55px}
  .topbar{height:64px;padding:0 17px}
  .profile-copy,.profile-chevron,.topbar-divider{display:none}
  .page-header{align-items:flex-start;flex-direction:column;gap:16px;margin-bottom:24px}
  .page-header h1{font-size:34px}
  .search-field{width:min(100%,310px)}
  .stats-grid{grid-template-columns:1fr 1fr;gap:9px}
  .stat-card{min-height:131px;padding:15px}
  .stat-value{margin-top:14px;font-size:27px}
  .stat-card-top span{max-width:65px;text-align:right;line-height:1.25}
  .side-stack,.catalogue-grid{grid-template-columns:1fr}
  .panel-header{padding:17px}
  .catalog-list,.activity-list{padding-right:17px;padding-left:17px}
  .feedback{top:73px;right:17px;left:17px;max-width:unset}
}
@media(max-width:420px){.stats-grid{grid-template-columns:1fr}.stat-card{min-height:120px}.stat-value{margin-top:11px}.catalogue-summary{display:none}}
</style>
</head>
<body>

<div class="admin-shell">
  <button class="sidebar-backdrop" id="sidebar-backdrop" onclick="document.getElementById('admin-sidebar').classList.remove('open'); this.style.display='none';" style="display:none" aria-label="Fermer le menu"></button>

  <aside class="admin-sidebar" id="admin-sidebar">
    <div class="brand-lockup">
      <div class="brand-mark"><?= icon('grid', 17) ?></div>
      <div class="brand-name"><a href="index.php"> Koud<span>Main</span></a></div>
    </div>
    <div class="sidebar-caption">Espace administration</div>
    <nav class="sidebar-nav" aria-label="Navigation principale">
      <a href="?tab=tableau_bord" class="sidebar-link <?= $onglet === 'tableau_bord' ? 'active' : '' ?>"><?= icon('layout-dashboard') ?><span>Tableau de bord</span></a>
      <a href="?tab=prestataires" class="sidebar-link <?= $onglet === 'prestataires' ? 'active' : '' ?>"><?= icon('user-circle') ?><span>Prestataires</span><?php if ($nb_attente > 0): ?><span class="sidebar-badge"><?= $nb_attente ?></span><?php endif; ?></a>
      <a href="?tab=utilisateurs" class="sidebar-link <?= $onglet === 'utilisateurs' ? 'active' : '' ?>"><?= icon('users-round') ?><span>Utilisateurs</span></a>
      <a href="?tab=categories" class="sidebar-link <?= $onglet === 'categories' ? 'active' : '' ?>"><?= icon('boxes') ?><span>Catalogue</span></a>
      <a href="?tab=commandes" class="sidebar-link <?= $onglet === 'commandes' ? 'active' : '' ?>"><?= icon('clipboard-list') ?><span>Commandes</span></a>
      <div class="sidebar-section-label">Navigation</div>
      <a href="index.php" class="sidebar-link muted-link"><?= icon('arrow-right') ?><span>Accueil</span></a>
      <a href="connexion.php?action=logout" class="sidebar-link muted-link"><?= icon('log-out') ?><span>Déconnexion</span></a>
    </nav>
    <div class="sidebar-bottom">
      <div class="sidebar-bottom-dot"></div>
      <div><strong>Administration</strong><span><?= $nb_users ?> comptes actifs</span></div>
    </div>
  </aside>

  <main class="admin-main">
    <header class="topbar">
      <button class="mobile-menu-button" onclick="document.getElementById('admin-sidebar').classList.add('open'); document.getElementById('sidebar-backdrop').style.display='block';" aria-label="Ouvrir le menu"><?= icon('menu', 21) ?></button>
      <div class="topbar-context"><span>Plateforme de services</span><strong>/ <?= htmlspecialchars($tab_label_courant) ?></strong></div>
      <div class="topbar-actions">
        <button class="icon-button notification-button" aria-label="Notifications" title="<?= $nb_attente > 0 ? $nb_attente . ' profils attendent votre validation.' : 'Tout est à jour.' ?>">
          <?= icon('bell', 18) ?>
          <?php if ($nb_attente > 0): ?><i></i><?php endif; ?>
        </button>
        <div class="topbar-divider"></div>
        <div class="admin-profile">
          <div class="avatar"><?= htmlspecialchars($initiales) ?></div>
          <div class="profile-copy"><strong><?= $nomFull ?: $prenom ?></strong><span>Administratrice</span></div>
          <span class="profile-chevron"><?= icon('chevron-right', 15) ?></span>
        </div>
      </div>
    </header>

    <div class="content-wrap">
      <?php if ($msg): ?>
      <div class="feedback feedback-success" role="status" id="km-toast">
        <div class="feedback-icon"><?= icon('check', 15) ?></div>
        <span><?= htmlspecialchars($msg) ?></span>
        <button onclick="document.getElementById('km-toast').remove()" aria-label="Fermer"><?= icon('x', 15) ?></button>
      </div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="feedback feedback-error" role="status" id="km-toast-err">
        <div class="feedback-icon"><?= icon('x', 15) ?></div>
        <span><?= htmlspecialchars($err) ?></span>
        <button onclick="document.getElementById('km-toast-err').remove()" aria-label="Fermer"><?= icon('x', 15) ?></button>
      </div>
      <?php endif; ?>

      <!-- ════════ TABLEAU DE BORD ════════ -->
      <?php if ($onglet === 'tableau_bord'): ?>

      <div class="page-header">
        <div>
          <div class="page-kicker">Vue d'ensemble</div>
          <h1>Panneau de contrôle</h1>
          <p>Un aperçu clair de l'activité et des actions qui nécessitent votre attention.</p>
        </div>
        <div class="header-date"><span class="live-dot"></span>Mis à jour à l'instant</div>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-card-top"><div class="stat-icon"><?= icon('users-round', 18) ?></div><span><?= $nb_clients ?> clients</span></div>
          <div class="stat-value"><?= $nb_users ?></div>
          <div class="stat-label">Utilisateurs</div>
        </div>
        <div class="stat-card stat-teal">
          <div class="stat-card-top"><div class="stat-icon"><?= icon('user-circle', 18) ?></div><span><?= $nb_prest_valid ?> validés</span></div>
          <div class="stat-value"><?= $nb_prest ?></div>
          <div class="stat-label">Prestataires</div>
        </div>
        <div class="stat-card stat-amber">
          <div class="stat-card-top"><div class="stat-icon"><?= icon('shield-check', 18) ?></div><span><?= $nb_attente ? 'À traiter' : 'Tout est à jour' ?></span></div>
          <div class="stat-value"><?= $nb_attente ?></div>
          <div class="stat-label">En attente</div>
        </div>
        <div class="stat-card">
          <div class="stat-card-top"><div class="stat-icon"><?= icon('bar-chart', 18) ?></div><span><?= $nb_cmd_termine ?> terminées</span></div>
          <div class="stat-value"><?= $nb_commandes ?></div>
          <div class="stat-label">Commandes totales</div>
        </div>
      </div>

      <div class="dashboard-grid">
        <section class="km-panel">
          <div class="panel-header">
            <div><div class="panel-eyebrow">Priorité du jour</div><h2>Profils à valider</h2></div>
            <a href="?tab=prestataires" class="text-link">Voir tous <?= icon('arrow-right', 14) ?></a>
          </div>
          <?php if (empty($prest_attente)): ?>
            <div class="empty-state">
              <div class="empty-icon"><?= icon('sparkles', 18) ?></div>
              <strong>Aucun profil en attente</strong>
              <span>Votre file de validation est vide.</span>
            </div>
          <?php else: ?>
          <div class="table-scroll">
            <table class="data-table">
              <thead><tr><th>Profil</th><th>Contact</th><th>Localisation</th><th>Inscription</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($prest_attente as $u): ?>
                <tr>
                  <td><div class="person-cell"><div class="person-avatar"><?= initiales_de($u['prenom_utilisateur'], $u['nom_utilisateur']) ?></div><div><strong><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></strong><span>Prestataire</span></div></div></td>
                  <td><span class="primary-cell"><?= htmlspecialchars($u['email_utilisateur']) ?></span><span class="secondary-cell"><?= htmlspecialchars($u['num_utilisateur']) ?></span></td>
                  <td><?= htmlspecialchars($u['nom_ville']) ?><span class="secondary-cell"><?= htmlspecialchars($u['nom_quartier']) ?></span></td>
                  <td class="muted-cell"><?= formatDate($u['datecrea_utilisateur']) ?></td>
                  <td>
                    <div class="row-actions">
                      <form method="POST">
                        <?= champCSRF() ?>
                        <input type="hidden" name="action" value="valider_prestataire">
                        <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                        <button type="submit" class="km-button button-teal button-sm"><?= icon('check', 14) ?>Valider</button>
                      </form>
                      <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                        <?= champCSRF() ?>
                        <input type="hidden" name="action" value="supprimer_compte">
                        <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                        <button type="submit" class="km-button button-danger button-sm"><?= icon('trash', 14) ?>Refuser</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </section>

        <div class="side-stack">
          <section class="km-panel">
            <div class="panel-header"><div><div class="panel-eyebrow">Activité récente</div><h2>En un coup d'œil</h2></div></div>
            <div class="activity-list">
              <div class="activity-item">
                <div class="activity-icon teal"><?= icon('check', 15) ?></div>
                <div><strong><?= $nb_cmd_termine ?> commandes terminées</strong><span>au total sur la plateforme</span></div>
                <span class="activity-value">Total</span>
              </div>
              <div class="activity-item">
                <div class="activity-icon amber"><?= icon('user-circle', 15) ?></div>
                <div><strong><?= $nb_prest ?> prestataires</strong><span>dont <?= $nb_prest_valid ?> validés</span></div>
                <span class="activity-value"><?= $nb_prest > 0 ? round($nb_prest_valid / $nb_prest * 100) . '%' : '—' ?></span>
              </div>
              <div class="activity-item">
                <div class="activity-icon ink"><?= icon('sparkles', 15) ?></div>
                <div><strong><?= $nb_cats ?> catégories actives</strong><span>dans votre catalogue</span></div>
                <span class="activity-value">Stable</span>
              </div>
            </div>
          </section>

          <section class="km-panel tip-panel">
            <div class="tip-glow"></div>
            <div class="tip-icon"><?= icon('sparkles', 17) ?></div>
            <div>
              <div class="panel-eyebrow">Astuce admin</div>
              <strong>Gardez votre catalogue vivant</strong>
              <p>Des services précis rendent la mise en relation plus simple.</p>
              <a href="?tab=categories" class="text-link">Gérer le catalogue <?= icon('arrow-right', 14) ?></a>
            </div>
          </section>
        </div>
      </div>

      <!-- ════════ PRESTATAIRES ════════ -->
      <?php elseif ($onglet === 'prestataires'): ?>

      <div class="page-header">
        <div>
          <div class="page-kicker">Comptes professionnels</div>
          <h1>Gestion des prestataires</h1>
          <p>Validez, suspendez ou supprimez les comptes qui proposent leurs services.</p>
        </div>
      </div>

      <section class="km-panel">
        <div class="panel-header">
          <div><div class="panel-eyebrow"><?= count($prestataires_tous) ?> profils</div><h2>Tous les prestataires</h2></div>
          <div class="panel-filter"><span class="filter-dot"></span><?= count(array_filter($prestataires_tous, fn($p) => $p['est_valide'])) ?> profils actifs</div>
        </div>
        <?php if (empty($prestataires_tous)): ?>
          <div class="empty-state"><div class="empty-icon"><?= icon('sparkles', 18) ?></div><strong>Aucun prestataire</strong><span>Aucun compte prestataire pour l'instant.</span></div>
        <?php else: ?>
        <div class="table-scroll">
          <table class="data-table">
            <thead><tr><th>Profil</th><th>Contact</th><th>Quartier</th><th>Statut</th><th>Inscrit le</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($prestataires_tous as $u): ?>
              <tr>
                <td><div class="person-cell"><div class="person-avatar warm"><?= initiales_de($u['prenom_utilisateur'], $u['nom_utilisateur']) ?></div><div><strong><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></strong><span>Prestataire indépendant</span></div></div></td>
                <td><span class="primary-cell"><?= htmlspecialchars($u['email_utilisateur']) ?></span><span class="secondary-cell"><?= htmlspecialchars($u['num_utilisateur']) ?></span></td>
                <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
                <td><?php if ($u['est_valide']): ?><span class="status-badge status-teal"><?= icon('check', 12) ?>Validé</span><?php else: ?><span class="status-badge status-amber">En attente</span><?php endif; ?></td>
                <td class="muted-cell"><?= formatDate($u['datecrea_utilisateur']) ?></td>
                <td>
                  <div class="row-actions">
                    <?php if (!$u['est_valide']): ?>
                    <form method="POST">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="valider_prestataire">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-button button-teal button-sm"><?= icon('check', 14) ?>Valider</button>
                    </form>
                    <?php else: ?>
                    <form method="POST">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="suspendre">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-button button-outline button-sm">Suspendre</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Supprimer ce compte définitivement ?')">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="supprimer_compte">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-button button-danger button-sm"><?= icon('trash', 14) ?>Supprimer</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <!-- ════════ UTILISATEURS ════════ -->
      <?php elseif ($onglet === 'utilisateurs'): ?>

      <div class="page-header">
        <div>
          <div class="page-kicker">Annuaire de la plateforme</div>
          <h1>Tous les utilisateurs</h1>
          <p><?= $nb_users_filtered ?> comptes · <?= $nb_clients ?> clients · <?= $nb_prest ?> prestataires</p>
        </div>
        <form method="GET" class="search-field">
          <input type="hidden" name="tab" value="utilisateurs">
          <?= icon('search', 16) ?>
          <input type="text" name="q" value="<?= htmlspecialchars($search_u) ?>" placeholder="Rechercher un compte" aria-label="Rechercher un compte">
          <?php if ($search_u !== ''): ?><a href="?tab=utilisateurs" aria-label="Effacer la recherche"><?= icon('x', 14) ?></a><?php endif; ?>
        </form>
      </div>

      <section class="km-panel">
        <div class="panel-header">
          <div><div class="panel-eyebrow"><?= $nb_users_filtered ?> comptes</div><h2>Annuaire utilisateurs</h2></div>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead><tr><th>Profil</th><th>Email</th><th>Rôle</th><th>Quartier</th><th>Statut</th><th>Inscrit le</th><th></th></tr></thead>
            <tbody>
              <?php if (empty($tous_users)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><?= icon('search', 18) ?></div><strong>Aucun résultat</strong><span>Essayez un autre nom ou une autre adresse email.</span></div></td></tr>
              <?php else: foreach ($tous_users as $u): ?>
              <tr>
                <td><div class="person-cell"><div class="person-avatar neutral"><?= initiales_de($u['prenom_utilisateur'], $u['nom_utilisateur']) ?></div><div><strong><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></strong><span><?= htmlspecialchars($u['num_utilisateur']) ?></span></div></div></td>
                <td class="primary-cell"><?= htmlspecialchars($u['email_utilisateur']) ?></td>
                <td><?php if ($u['est_prestataire']): ?><span class="status-badge status-neutral">Prestataire</span><?php else: ?><span class="status-badge status-amber">Client</span><?php endif; ?></td>
                <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
                <td><span class="status-badge <?= $u['est_valide'] ? 'status-teal' : 'status-danger' ?>"><?= $u['est_valide'] ? 'Actif' : 'Inactif' ?></span></td>
                <td class="muted-cell"><?= formatDate($u['datecrea_utilisateur']) ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                    <?= champCSRF() ?>
                    <input type="hidden" name="action" value="supprimer_compte">
                    <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                    <button type="submit" class="table-icon-danger" aria-label="Supprimer"><?= icon('trash', 15) ?></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($nb_pages_users > 1): ?>
        <div class="pagination">
          <?php $qs = $search_u !== '' ? '&q=' . urlencode($search_u) : ''; ?>
          <a href="?tab=utilisateurs&p_u=<?= max(1, $page_users - 1) . $qs ?>" class="page-button"><?= icon('chevron-left', 14) ?></a>
          <?php for ($i = 1; $i <= $nb_pages_users; $i++): ?>
            <a href="?tab=utilisateurs&p_u=<?= $i . $qs ?>" class="page-button <?= $i === $page_users ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a href="?tab=utilisateurs&p_u=<?= min($nb_pages_users, $page_users + 1) . $qs ?>" class="page-button"><?= icon('chevron-right', 14) ?></a>
        </div>
        <?php endif; ?>
      </section>

      <!-- ════════ CATALOGUE ════════ -->
      <?php elseif ($onglet === 'categories'): ?>

      <div class="page-header">
        <div>
          <div class="page-kicker">Offre de services</div>
          <h1>Catalogue</h1>
          <p><?= $nb_cats ?> catégories · <?= $nb_prestations ?> prestations publiées</p>
        </div>
        <div class="catalogue-summary">
          <span><?= icon('boxes', 15) ?><?= $nb_cats ?> catégories</span>
          <span><?= icon('package-check', 15) ?><?= count($services) ?> services</span>
        </div>
      </div>

      <div class="catalogue-grid">
        <section class="km-panel">
          <div class="panel-header">
            <div><div class="panel-eyebrow">Organisation</div><h2>Catégories</h2></div>
            <button type="button" class="km-button button-dark button-sm" onclick="document.getElementById('modal-cat').classList.add('open')"><?= icon('plus', 15) ?>Ajouter</button>
          </div>
          <div class="catalog-list">
            <?php foreach ($categories as $c): ?>
            <div class="catalog-row">
              <div class="catalog-leading">
                <div class="category-swatch"><?= icon('grid', 15) ?></div>
                <div><strong><?= htmlspecialchars($c['nom_categorie']) ?></strong><span><?= (int)$c['nb_services'] === 0 ? 'Aucun service' : (int)$c['nb_services'] . ' service' . ((int)$c['nb_services'] > 1 ? 's' : '') ?></span></div>
              </div>
              <div class="catalog-row-actions">
                <span class="count-pill"><?= (int)$c['nb_services'] ?></span>
                <form method="POST" onsubmit="return confirm('Supprimer cette catégorie ?')">
                  <?= champCSRF() ?>
                  <input type="hidden" name="action" value="supprimer_categorie">
                  <input type="hidden" name="id_categorie" value="<?= (int)$c['id_categorie'] ?>">
                  <button type="submit" class="table-icon-danger" aria-label="Supprimer"><?= icon('trash', 15) ?></button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="km-panel">
          <div class="panel-header">
            <div><div class="panel-eyebrow">Services publiés</div><h2>Services</h2></div>
            <button type="button" class="km-button button-dark button-sm" onclick="document.getElementById('modal-serv').classList.add('open')"><?= icon('plus', 15) ?>Ajouter</button>
          </div>
          <div class="catalog-list">
            <?php foreach ($services as $s): ?>
            <div class="catalog-row">
              <div class="catalog-leading">
                <div class="service-icon"><?= icon('package-check', 15) ?></div>
                <div><strong><?= htmlspecialchars($s['nom_service']) ?></strong><span><?= htmlspecialchars($s['nom_categorie']) ?></span></div>
              </div>
              <span class="count-pill"><?= (int)$s['nb_prestations'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <!-- ════════ COMMANDES ════════ -->
      <?php elseif ($onglet === 'commandes'): ?>

      <div class="page-header">
        <div>
          <div class="page-kicker">Activité commerciale</div>
          <h1>Toutes les commandes</h1>
          <p>Une vue globale des transactions et de leur avancement.</p>
        </div>
      </div>

      <section class="km-panel">
        <div class="panel-header">
          <div><div class="panel-eyebrow">Historique des transactions</div><h2>Commandes récentes</h2></div>
          <div class="orders-total"><span class="live-dot"></span><?= empty($commandes) ? 'Aucune commande' : 'Synchronisé' ?></div>
        </div>
        <?php if (empty($commandes)): ?>
          <div class="empty-state"><div class="empty-icon"><?= icon('clipboard-list', 18) ?></div><strong>Aucune commande</strong><span>Aucune transaction pour l'instant.</span></div>
        <?php else: ?>
        <div class="table-scroll">
          <table class="data-table orders-table">
            <thead><tr><th>#</th><th>Prestation</th><th>Client</th><th>Prestataire</th><th>Total</th><th>Statut</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($commandes as $c):
                $badgeClass = match($c['statut']) { 'Terminé' => 'status-teal', 'Acceptée' => 'status-neutral', default => 'status-amber' };
              ?>
              <tr>
                <td class="order-id">#<?= (int)$c['id_commande'] ?></td>
                <td><span class="primary-cell"><?= htmlspecialchars($c['titre_prestation']) ?></span></td>
                <td><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></td>
                <td><?= htmlspecialchars($c['prest_prenom'] . ' ' . $c['prest_nom']) ?></td>
                <td class="money-cell"><?= formatMoney($c['montant_total']) ?></td>
                <td><span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span></td>
                <td class="muted-cell"><?= formatDate($c['date_commande'], true) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($nb_pages_cmd_adm > 1): ?>
        <div class="pagination">
          <a href="?tab=commandes&p_c=<?= max(1, $page_cmd_adm - 1) ?>" class="page-button"><?= icon('chevron-left', 14) ?></a>
          <?php for ($i = 1; $i <= $nb_pages_cmd_adm; $i++): ?>
            <a href="?tab=commandes&p_c=<?= $i ?>" class="page-button <?= $i === $page_cmd_adm ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a href="?tab=commandes&p_c=<?= min($nb_pages_cmd_adm, $page_cmd_adm + 1) ?>" class="page-button"><?= icon('chevron-right', 14) ?></a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </section>

      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Modal catégorie -->
<div class="modal-overlay" id="modal-cat">
  <div class="modal-card">
    <div class="modal-header">
      <div><div class="page-kicker">Catalogue</div><h2>Nouvelle catégorie</h2></div>
      <button type="button" class="modal-close" onclick="document.getElementById('modal-cat').classList.remove('open')" aria-label="Fermer"><?= icon('x', 17) ?></button>
    </div>
    <form method="POST" action="?tab=categories">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_categorie">
      <label class="form-field"><span>Nom de la catégorie</span><input type="text" name="nom_categorie" placeholder="Ex. Informatique" required></label>
      <button type="submit" class="km-button button-dark full-width">Ajouter la catégorie</button>
    </form>
  </div>
</div>

<!-- Modal service -->
<div class="modal-overlay" id="modal-serv">
  <div class="modal-card">
    <div class="modal-header">
      <div><div class="page-kicker">Catalogue</div><h2>Nouveau service</h2></div>
      <button type="button" class="modal-close" onclick="document.getElementById('modal-serv').classList.remove('open')" aria-label="Fermer"><?= icon('x', 17) ?></button>
    </div>
    <form method="POST" action="?tab=categories">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_service">
      <label class="form-field"><span>Nom du service</span><input type="text" name="nom_service" placeholder="Ex. Réparation PC" required></label>
      <label class="form-field">
        <span>Catégorie</span>
        <select name="id_categorie" required>
          <option value="">Sélectionnez une catégorie</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id_categorie'] ?>"><?= htmlspecialchars($c['nom_categorie']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="km-button button-dark full-width">Ajouter le service</button>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.modal-overlay').forEach(function (el) {
  el.addEventListener('mousedown', function (e) { if (e.target === el) el.classList.remove('open'); });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(function (m) { m.classList.remove('open'); });
    document.getElementById('admin-sidebar').classList.remove('open');
    document.getElementById('sidebar-backdrop').style.display = 'none';
  }
});
['km-toast', 'km-toast-err'].forEach(function (id) {
  var el = document.getElementById(id);
  if (el) setTimeout(function () { el.remove(); }, 4200);
});
</script>

</body>
</html>
