<?php
require_once "config.php";
requireConnexion();

if (estAdmin()) rediriger("admin_dashboard.php");
if (!estPrestataire()) rediriger("client_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";
$nb_notifs = nbNotificationsNonLues($pdo, $idUser);
$nb_msgs   = nbMessagesNonLus($pdo, $idUser);

$wallet_prest = getOuCreerWallet($pdo, $idUser);
$solde_prest  = (float)$wallet_prest['solde'];

// ---------------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    if ($_POST['action'] === 'creer_prestation') {
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $prix        = (float)str_replace(',', '.', $_POST['prix'] ?? 0);
        $id_service  = (int)($_POST['id_service'] ?? 0);

        if (empty($titre) || $prix <= 0 || $id_service === 0) {
            $err = "Veuillez remplir tous les champs obligatoires.";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO Prestation (titre_prestation, description_prestation, prix_prestation, id_service, id_utilisateur)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$titre, $description, $prix, $id_service, $idUser]);
            $msg = "Prestation « $titre » créée avec succès.";
        }
    }

    elseif ($_POST['action'] === 'modifier_prestation') {
        $id          = (int)($_POST['id_prestation'] ?? 0);
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $prix        = (float)str_replace(',', '.', $_POST['prix'] ?? 0);
        $id_service  = (int)($_POST['id_service'] ?? 0);

        if ($id <= 0 || empty($titre) || $prix <= 0 || $id_service === 0) {
            $err = "Veuillez remplir tous les champs obligatoires.";
        } else {
            $upd = $pdo->prepare("
                UPDATE Prestation
                SET titre_prestation = ?, description_prestation = ?, prix_prestation = ?, id_service = ?
                WHERE id_prestation = ? AND id_utilisateur = ?
            ");
            $upd->execute([$titre, $description, $prix, $id_service, $id, $idUser]);
            $msg = "Prestation « $titre » mise à jour.";
        }
    }

    elseif ($_POST['action'] === 'supprimer_prestation') {
        $id  = (int)($_POST['id_prestation'] ?? 0);
        $del = $pdo->prepare("DELETE FROM Prestation WHERE id_prestation = ? AND id_utilisateur = ?");
        $del->execute([$id, $idUser]);
        $msg = "Prestation supprimée.";
    }

    // Workflow strict : le prestataire réel est déduit en base (Cibler → Prestation →
    // Utilisateur) à l'intérieur de changerStatutCommande(), jamais depuis le navigateur.
    elseif (in_array($_POST['action'], ['accepter_commande', 'demarrer_commande', 'terminer_commande', 'annuler_commande_prest'], true)) {
        $id_cmd = (int)($_POST['id_commande'] ?? 0);
        $motif  = trim($_POST['motif'] ?? '');
        $action = match ($_POST['action']) {
            'accepter_commande'       => 'accepter',
            'demarrer_commande'       => 'demarrer',
            'terminer_commande'       => 'terminer',
            'annuler_commande_prest'  => 'annuler',
        };
        $res = changerStatutCommande($pdo, $id_cmd, $idUser, $action, $motif);
        $res['ok'] ? $msg = $res['message'] : $err = $res['message'];
    }
}

$onglet = $_GET['tab'] ?? 'overview';
$search = trim($_GET['q'] ?? '');

// ---------------------------------------------------------------------------
// Statistiques
// ---------------------------------------------------------------------------
$st = $pdo->prepare("SELECT COUNT(*) FROM Prestation WHERE id_utilisateur = ?");
$st->execute([$idUser]); $nb_prest = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT COUNT(DISTINCT cm.id_commande) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ?
");
$st->execute([$idUser]); $nb_cmd = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT COALESCE(ROUND(AVG(ci.evaluation), 1), 0), COUNT(ci.evaluation)
    FROM Cibler ci JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND ci.evaluation IS NOT NULL
");
$st->execute([$idUser]);
[$note_moy, $nb_avis_total] = $st->fetch(PDO::FETCH_NUM);
$nb_avis_total = (int)$nb_avis_total;

$st = $pdo->prepare("
    SELECT COALESCE(SUM(cm.montant_total), 0) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND cm.statut = 'Terminée'
");
$st->execute([$idUser]); $revenus_total = (float)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT COUNT(DISTINCT cm.id_commande) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND cm.statut = 'En attente'
");
$st->execute([$idUser]); $nb_attente = (int)$st->fetchColumn();

// ---------------------------------------------------------------------------
// Mes prestations (grille + recherche + pagination)
// ---------------------------------------------------------------------------
$limit_prest_p  = 9;
$page_prest_p   = max(1, (int)($_GET['p_prest'] ?? 1));
$offset_prest_p = ($page_prest_p - 1) * $limit_prest_p;

$params_pc = [$idUser];
$sql_pc = "SELECT COUNT(*) FROM Prestation p JOIN Service s ON p.id_service = s.id_service WHERE p.id_utilisateur = ?";
if ($search !== '' && $onglet === 'prestations') {
    $sql_pc .= " AND (p.titre_prestation ILIKE ? OR s.nom_service ILIKE ?)";
    $params_pc[] = "%$search%"; $params_pc[] = "%$search%";
}
$stc = $pdo->prepare($sql_pc); $stc->execute($params_pc);
$nb_prest_filtre  = (int)$stc->fetchColumn();
$nb_pages_prest_p = max(1, (int)ceil($nb_prest_filtre / $limit_prest_p));

$params_p = [$idUser];
$sql_p = "
    SELECT p.*, s.nom_service, c.nom_categorie,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy, COUNT(ci.evaluation) AS nb_avis
    FROM Prestation p
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation
    WHERE p.id_utilisateur = ?
";
if ($search !== '' && $onglet === 'prestations') {
    $sql_p .= " AND (p.titre_prestation ILIKE ? OR s.nom_service ILIKE ?)";
    $params_p[] = "%$search%"; $params_p[] = "%$search%";
}
$sql_p .= "
    GROUP BY p.id_prestation, s.nom_service, c.nom_categorie
    ORDER BY p.datecrea_prestation DESC
    LIMIT $limit_prest_p OFFSET $offset_prest_p
";
$stmt_p = $pdo->prepare($sql_p);
$stmt_p->execute($params_p);
$prestations = $stmt_p->fetchAll();

// ---------------------------------------------------------------------------
// Commandes reçues (+ recherche + pagination)
// ---------------------------------------------------------------------------
$limit_cmd_p  = 8;
$page_cmd_p   = max(1, (int)($_GET['p_cmd'] ?? 1));
$offset_cmd_p = ($page_cmd_p - 1) * $limit_cmd_p;

$params_cc = [$idUser];
$sql_cc = "
    SELECT COUNT(DISTINCT cm.id_commande) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    WHERE p.id_utilisateur = ?
";
if ($search !== '' && $onglet === 'commandes') {
    $sql_cc .= " AND (p.titre_prestation ILIKE ? OR u.nom_utilisateur ILIKE ? OR u.prenom_utilisateur ILIKE ?)";
    $params_cc[] = "%$search%"; $params_cc[] = "%$search%"; $params_cc[] = "%$search%";
}
$stcc = $pdo->prepare($sql_cc); $stcc->execute($params_cc);
$nb_cmd_filtre  = (int)$stcc->fetchColumn();
$nb_pages_cmd_p = max(1, (int)ceil($nb_cmd_filtre / $limit_cmd_p));

$params_c = [$idUser];
$sql_c = "
    SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite, ci.evaluation, ci.commentaire,
           p.titre_prestation, u.nom_utilisateur, u.prenom_utilisateur, u.num_utilisateur, q.nom_quartier
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    JOIN Quartier q ON cm.id_quartier = q.id_quartier
    WHERE p.id_utilisateur = ?
";
if ($search !== '' && $onglet === 'commandes') {
    $sql_c .= " AND (p.titre_prestation ILIKE ? OR u.nom_utilisateur ILIKE ? OR u.prenom_utilisateur ILIKE ?)";
    $params_c[] = "%$search%"; $params_c[] = "%$search%"; $params_c[] = "%$search%";
}
$sql_c .= " ORDER BY cm.date_commande DESC LIMIT $limit_cmd_p OFFSET $offset_cmd_p";
$stmt_c = $pdo->prepare($sql_c);
$stmt_c->execute($params_c);
$commandes  = $stmt_c->fetchAll();
$recent_cmd = array_slice($commandes, 0, 4);

$services = $pdo->query("
    SELECT s.id_service, s.nom_service, c.nom_categorie
    FROM Service s JOIN Categorie c ON s.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();

$prenom  = htmlspecialchars($_SESSION['prenom'] ?? 'Prestataire');
$nom     = htmlspecialchars($_SESSION['nom'] ?? '');
$initiales = strtoupper(mb_substr($_SESSION['prenom'] ?? 'P', 0, 1) . mb_substr($_SESSION['nom'] ?? 'K', 0, 1));
$csrf    = genererTokenCSRF();

$jours_fr     = [0=>'Dimanche',1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi'];
$mois_fr_long = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
$now = new DateTime();
$eyebrowDate = mb_strtoupper($jours_fr[(int)$now->format('w')] . ' ' . (int)$now->format('j') . ' ' . $mois_fr_long[(int)$now->format('n')] . ' ' . $now->format('Y') . ' · ABIDJAN');

$tab_labels = [
    'overview'    => "Vue d'ensemble",
    'prestations' => 'Mes prestations',
    'commandes'   => 'Commandes',
];
$tab_label_courant = $tab_labels[$onglet] ?? "Vue d'ensemble";

$search_placeholder = $onglet === 'commandes' ? 'Rechercher une commande' : 'Rechercher une prestation';

function icon(string $name, int $size = 17): string {
    $paths = [
        'home'             => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'briefcase'        => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><path d="M2 13h20"/>',
        'clipboard-list'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'wallet-cards'     => '<path d="M17 14h.01"/><path d="M7 7h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2h9a2 2 0 0 1 2 2v2"/>',
        'bar-chart'        => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'settings'         => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'log-out'          => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'             => '<line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'chevron-right'    => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left'     => '<path d="m15 18-6-6 6-6"/>',
        'chevron-down'     => '<path d="m6 9 6 6 6-6"/>',
        'bell'             => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'search'           => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'plus'             => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'x'                => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'            => '<polyline points="20 6 9 17 4 12"/>',
        'arrow-right'      => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'package-check'    => '<path d="M16 16h6"/><path d="M19 13v6"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><path d="M3.29 7 12 12l8.71-5"/><path d="M12 22V12"/>',
        'sparkles'         => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'message'          => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'pencil'           => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'            => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'phone'            => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.633 1.4l-.465.354a1 1 0 0 0-.302 1.214 14.11 14.11 0 0 0 6.232 6.232"/>',
        'map-pin'          => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'star'             => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'more-horizontal'  => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'circle-help'      => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    ];
    $body = $paths[$name] ?? $paths['sparkles'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function stars_html(float $note): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= round($note) ? '★' : '☆';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Espace prestataire — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#f4f2ec;--paper-deep:#ebe8df;--surface:#fffefa;
  --ink:#1f201c;--ink-soft:#77766e;--ink-faint:#a6a49a;--line:#dedbd1;
  --amber:#ba6d2c;--amber-deep:#8d4d1d;--amber-tint:#f5e7d7;
  --teal:#2b6d60;--teal-deep:#1d5046;--teal-tint:#e5f0eb;
  --danger:#a74935;--danger-tint:#f4e0da;
  --radius:16px;--sidebar:250px;
  --ease:cubic-bezier(.2,.8,.2,1);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;min-width:320px;background:var(--paper);color:var(--ink);font-family:"DM Sans",sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font:inherit}
button{cursor:pointer;color:inherit;border:0;background:transparent}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--amber-deep);outline-offset:3px}
svg{display:block}
h1,h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:600;letter-spacing:-.03em}
em{font-style:normal;color:var(--amber)}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important;scroll-behavior:auto!important}}

.km-app{display:flex;min-height:100vh}

/* ── SIDEBAR (style client_dashboard.php) ── */
.km-sidebar{
  width:var(--sidebar);flex:0 0 var(--sidebar);background:var(--ink);color:#E8E4D8;
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease;
}
.km-sb-brand{padding:1.5rem 1.4rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.1)}
.km-sb-brand a{font-family:'Fraunces',serif;font-weight:600;font-size:1.3rem;color:#fff}
.km-sb-brand em{font-style:normal;color:var(--amber)}
.km-sb-nav{flex:1;padding:1.1rem .8rem;display:flex;flex-direction:column;gap:.15rem;overflow-y:auto}
.km-sb-link{
  display:flex;align-items:center;gap:.8rem;padding:.68rem .85rem;border-radius:8px;
  font-size:.9rem;color:#B9B4A5;position:relative;transition:background .18s ease,color .18s ease;
}
.km-sb-link:hover{background:rgba(255,255,255,.06);color:#fff}
.km-sb-link.active{background:rgba(186,109,44,.18);color:#F0DFC7;font-weight:600}
.km-sb-link.active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:2px;background:var(--amber)}
.km-sb-icon{width:17px;height:17px;flex-shrink:0}
.km-sb-icon svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.km-sb-badge{margin-left:auto;background:var(--amber);color:var(--ink);font-size:.68rem;font-weight:700;padding:.1rem .45rem;border-radius:10px}
.km-sb-section{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#8B8778;padding:1rem .85rem .3rem}
.km-sb-foot{padding:1rem .9rem 1.3rem;border-top:1px solid rgba(255,255,255,.1)}
.km-wallet-mini{
  display:block;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);
  border-radius:12px;padding:.9rem 1rem;transition:border-color .18s ease;
}
.km-wallet-mini:hover{border-color:var(--amber)}
.km-wallet-mini .w-label{font-size:.7rem;color:#A8A398}
.km-wallet-mini .w-solde{font-family:'Fraunces',serif;font-weight:600;color:#fff;font-size:1.15rem;margin-top:.2rem}

.avatar{width:32px;height:32px;display:grid;place-items:center;flex:0 0 auto;color:#6d3a18;background:#f0d4b7;border-radius:50%;font-size:11px;font-weight:700}
.avatar-sm{width:30px;height:30px}

.main-area{flex:1;min-width:0;margin-left:var(--sidebar)}
.topbar{height:76px;display:flex;align-items:center;justify-content:space-between;gap:22px;padding:0 42px;position:sticky;top:0;z-index:20;background:rgba(244,242,236,.83);border-bottom:1px solid rgba(222,219,209,.8);backdrop-filter:blur(14px)}
.topbar-left,.topbar-actions,.breadcrumb,.top-profile{display:flex;align-items:center}
.topbar-left{gap:20px}
.topbar-actions{gap:16px}
.breadcrumb{gap:9px;color:var(--ink-faint);font-size:12px}
.breadcrumb strong{color:var(--ink);font-weight:600}
.icon-button{width:34px;height:34px;display:grid;place-items:center;position:relative;color:var(--ink-soft);background:transparent;border:1px solid transparent;border-radius:9px;transition:color .16s ease,background .16s ease,transform .16s ease}
.icon-button:hover{color:var(--ink);background:var(--surface);border-color:var(--line)}
.icon-button:active{transform:scale(.97)}
.topbar-left>.icon-button{display:none}
.top-search{width:260px;height:37px;display:flex;align-items:center;gap:8px;padding:0 12px;color:var(--ink-faint);background:var(--surface);border:1px solid var(--line);border-radius:9px}
.top-search input{width:100%;padding:0;color:var(--ink);background:transparent;border:0;outline:0;font-size:11px}
.top-search input::placeholder{color:var(--ink-faint)}
.topbar-actions .icon-button i{position:absolute;top:7px;right:7px;width:5px;height:5px;background:var(--amber);border:1px solid var(--paper);border-radius:50%;font-style:normal}
.top-profile{gap:7px;padding-left:5px}

.page-content{width:min(1180px,100%);margin:0 auto;padding:50px 42px 60px}
.eyebrow{color:var(--ink-faint);font-size:10px;text-transform:uppercase;letter-spacing:.15em;font-weight:700}
h1{font-size:clamp(34px,4.4vw,54px);line-height:1;margin:14px 0 15px}
h2{font-size:22px;line-height:1.1}
.hero-row{display:flex;align-items:end;justify-content:space-between;gap:30px;margin-bottom:34px;flex-wrap:wrap}
.hero-copy{max-width:440px;margin:0;color:var(--ink-soft);font-size:14px;line-height:1.65}
.hero-actions{display:flex;align-items:center;gap:9px;padding-bottom:5px}
.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:37px;padding:0 14px;color:var(--ink);border:1px solid transparent;border-radius:8px;font-size:11px;font-weight:700;transition:transform .16s var(--ease),background .16s ease,border-color .16s ease,color .16s ease}
.button-dark{color:#fff;background:var(--ink)}
.button-dark:hover{background:var(--amber-deep)}
.button-light{background:var(--surface);border-color:var(--line)}
.button-light:hover,.button-outline:hover{background:var(--amber-tint);border-color:#d2ac89;color:var(--amber-deep)}
.button-outline{color:var(--ink-soft);background:transparent;border-color:var(--line)}
.button-danger{color:var(--danger);background:var(--danger-tint)}
.button-danger:hover{color:#fff;background:var(--danger)}
.button.full{width:100%;min-height:44px;margin-top:6px}
.button:active{transform:scale(.97)}

.metrics-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:12px;margin-bottom:20px}
.metric-card{min-height:137px;padding:19px 21px;background:rgba(255,254,250,.7);border:1px solid var(--line);border-radius:var(--radius);box-shadow:3px 4px 0 rgba(31,32,28,.035);transition:transform .2s var(--ease),border-color .2s ease}
.metric-card:hover{transform:translateY(-2px);border-color:#c8bda9}
.metric-main{background:var(--ink);color:#f5f2ea;border-color:var(--ink)}
.metric-head{display:flex;align-items:center;justify-content:space-between;color:var(--ink-faint);font-size:10px;text-transform:uppercase;letter-spacing:.1em;font-weight:700}
.metric-main .metric-head{color:#a9aba1}
.metric-icon{width:27px;height:27px;display:grid;place-items:center;color:var(--amber);background:var(--amber-tint);border-radius:8px}
.metric-icon.green{color:var(--teal);background:var(--teal-tint)}
.metric-icon.amber{color:var(--amber-deep);background:var(--amber-tint)}
.metric-card>strong{display:block;margin-top:14px;font-family:Fraunces,serif;font-size:26px;line-height:1;letter-spacing:-.03em;font-weight:600}
.metric-card>strong small{font-family:"DM Sans",sans-serif;color:var(--ink-faint);font-size:10px;letter-spacing:0}
.metric-main>strong small{color:#93968d}
.metric-foot{display:flex;align-items:center;gap:9px;margin-top:15px;color:var(--ink-faint);font-size:10px}
.metric-main .metric-foot{color:#8d9087}
.green-text{color:var(--teal)}

.dashboard-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:19px;align-items:start;margin-bottom:20px}
.panel{min-width:0;overflow:hidden;background:rgba(255,254,250,.78);border:1px solid rgba(219,216,205,.9);border-radius:var(--radius);box-shadow:3px 4px 0 rgba(31,32,28,.035)}
.panel-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:19px 21px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.panel-header h2{font-size:19px}
.text-link{display:inline-flex;align-items:center;gap:6px;padding:0;color:var(--amber-deep);background:transparent;font-size:11px;font-weight:750;transition:gap .18s ease,color .18s ease}
.text-link:hover{gap:9px;color:var(--ink)}

.activity-list{padding:3px 21px 9px}
.activity-item{display:grid;grid-template-columns:30px 1fr auto auto;align-items:center;gap:10px;padding:14px 0;border-bottom:1px solid var(--line)}
.activity-item:last-child{border-bottom:0}
.activity-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;color:var(--amber-deep);background:var(--amber-tint)}
.activity-item strong,.activity-item span{display:block}
.activity-item strong{color:var(--ink);font-size:11px}
.activity-item div>span{margin-top:2px;color:var(--ink-faint);font-size:10px}
.activity-value{font-family:Fraunces,serif;font-weight:600;font-size:12px;white-space:nowrap}

.status-badge{display:inline-flex;padding:5px 9px;border-radius:20px;font-size:9px;font-weight:800;letter-spacing:.02em}
.status-amber{color:var(--amber-deep);background:var(--amber-tint)}
.status-danger{color:var(--danger);background:var(--danger-tint)}
.status-teal{color:var(--teal);background:var(--teal-tint)}
.status-neutral{color:#62665d;background:#eeece5}

.quick-panel{background:#f1eee6}
.quick-list{padding:5px 21px 8px}
.quick-list button,.quick-list a{width:100%;display:flex;align-items:center;gap:11px;padding:13px 0;border:0;border-bottom:1px solid var(--line);background:transparent;text-align:left;transition:transform .16s var(--ease)}
.quick-list button:hover,.quick-list a:hover{transform:translateX(4px)}
.quick-list button:last-child,.quick-list a:last-child{border-bottom:0}
.quick-list button>span:nth-child(2),.quick-list a>span:nth-child(2){flex:1;min-width:0}
.quick-list strong,.quick-list small{display:block}
.quick-list strong{font-size:12px;font-weight:700}
.quick-list small{margin-top:3px;color:var(--ink-soft);font-size:10px}
.quick-list svg{color:var(--ink-faint)}
.quick-icon{width:33px;height:33px;display:grid;place-items:center;flex:0 0 auto;border-radius:10px}
.dark-bg{color:#fff;background:var(--ink)}
.amber-bg{color:var(--amber-deep);background:var(--amber-tint)}
.green-bg{color:var(--teal);background:var(--teal-tint)}
.quick-note{display:flex;gap:9px;margin:12px 21px 20px;padding:12px 13px;color:#6e6d64;background:rgba(255,255,255,.47);border:1px solid #ded6c8;border-radius:9px;font-size:10px;line-height:1.45}

.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:150px;gap:8px;padding:30px 20px;color:var(--ink-faint);text-align:center}
.empty-state strong{color:var(--ink);font:600 16px/1.1 Fraunces,Georgia,serif}
.empty-state p{margin:0;font-size:11px}

/* Prestations grid */
.prest-toolbar{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:26px;flex-wrap:wrap}
.prest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.prest-card{background:rgba(255,254,250,.85);border:1px solid var(--line);border-radius:var(--radius);padding:20px 21px;display:flex;flex-direction:column;gap:11px;transition:transform .2s var(--ease),border-color .2s ease,box-shadow .2s ease}
.prest-card:hover{transform:translateY(-3px);border-color:#c8bda9;box-shadow:0 16px 34px rgba(31,32,28,.07)}
.prest-tags{display:flex;gap:6px;flex-wrap:wrap}
.prest-tag{display:inline-flex;font-size:9px;font-weight:800;padding:4px 9px;border-radius:20px;letter-spacing:.03em}
.prest-tag-cat{background:var(--teal-tint);color:var(--teal)}
.prest-tag-serv{background:var(--amber-tint);color:var(--amber-deep)}
.prest-card h3{font-family:"DM Sans",sans-serif;font-size:16px;font-weight:700;color:var(--ink)}
.prest-desc{color:var(--ink-soft);font-size:12px;line-height:1.55;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.prest-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:9px;border-top:1px solid var(--line)}
.prest-price{font-family:Fraunces,serif;font-weight:600;font-size:18px;color:var(--amber-deep)}
.prest-stars{font-size:11px;color:var(--amber-deep)}
.prest-stars small{color:var(--ink-faint);font-family:"DM Sans",sans-serif}
.prest-actions{display:flex;gap:8px}
.prest-actions .button{flex:1}

/* Commandes */
.orders-toolbar{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:26px;flex-wrap:wrap}
.orders-chip{display:inline-flex;align-items:center;gap:8px;padding:9px 15px;background:var(--surface);border:1px solid var(--line);border-radius:12px;font-family:Fraunces,serif;font-weight:600;font-size:14px;color:var(--ink)}
.orders-chip span{font-family:"DM Sans",sans-serif;font-weight:600;font-size:10px;color:var(--ink-faint);text-transform:uppercase;letter-spacing:.08em}
.order-card{background:rgba(255,254,250,.85);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:14px}
.order-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 22px;background:var(--paper-deep);flex-wrap:wrap}
.order-id{font-family:Fraunces,serif;font-weight:700;font-size:16px}
.order-date{font-size:10px;color:var(--ink-faint);margin-left:2px}
.order-total{font-family:Fraunces,serif;font-weight:700;font-size:18px}
.order-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));border-bottom:1px solid var(--line)}
.order-field{padding:15px 22px;border-right:1px solid var(--line)}
.order-field:last-child{border-right:0}
.order-field-label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-faint)}
.order-field-val{margin-top:4px;font-size:12.5px;color:var(--ink);font-weight:600}
.order-avis{padding:13px 22px;border-bottom:1px solid var(--line);font-size:11.5px;color:var(--ink-soft);display:flex;align-items:center;gap:8px}
.order-foot{padding:15px 22px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.order-note{display:flex;align-items:center;gap:7px;color:var(--teal);font-size:11.5px;font-weight:700}

/* Modal */
.modal-overlay{position:fixed;inset:0;z-index:100;display:none;place-items:center;padding:20px;background:rgba(26,28,24,.55);backdrop-filter:blur(5px);animation:fade-in .18s ease both}
.modal-overlay.open{display:grid}
.modal-card{width:min(100%,460px);max-height:calc(100vh - 40px);overflow-y:auto;padding:24px;background:var(--surface);border:1px solid rgba(255,255,255,.38);border-radius:17px;box-shadow:0 22px 70px rgba(24,25,22,.25);animation:modal-in .25s var(--ease) both}
@keyframes fade-in{from{opacity:0}to{opacity:1}}
@keyframes modal-in{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-head{display:flex;align-items:start;justify-content:space-between;gap:14px;padding-bottom:18px;margin-bottom:21px;border-bottom:1px solid var(--line)}
.modal-head .icon-button{background:var(--paper);border-color:var(--line)}
.modal-head h2{margin-top:8px;font-size:23px}
.modal-form{display:flex;flex-direction:column;gap:15px}
.modal-form label{color:var(--ink-soft);font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700}
.modal-form input,.modal-form select,.modal-form textarea{display:block;width:100%;margin-top:7px;padding:0 12px;color:var(--ink);background:var(--paper);border:1px solid var(--line);border-radius:8px;outline:0;font-size:12px;text-transform:none;letter-spacing:0;font-family:"DM Sans",sans-serif}
.modal-form input,.modal-form select{height:42px}
.modal-form textarea{padding-top:10px;padding-bottom:10px;min-height:90px;resize:vertical}
.modal-form input:focus,.modal-form select:focus,.modal-form textarea:focus{border-color:var(--amber-deep)}

.km-toast{position:fixed;z-index:200;top:92px;right:37px;display:flex;align-items:center;gap:10px;max-width:360px;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:var(--surface);box-shadow:0 22px 60px rgba(24,25,22,.2);color:var(--ink);font-size:11px;animation:fade-in .2s ease}
.km-toast-ok{border-color:rgba(43,109,96,.25)}
.km-toast-err{border-color:rgba(167,73,53,.25)}

.pagination{display:flex;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap}
.page-button{display:grid;place-items:center;min-width:30px;height:30px;padding:0 9px;color:var(--ink-soft);background:transparent;border:1px solid var(--line);border-radius:8px;font-size:11px;font-weight:700;transition:all .18s ease}
.page-button:hover:not(.active){color:var(--ink);border-color:#aaa99e;background:var(--paper-deep)}
.page-button.active{color:#fffdf7;background:var(--ink);border-color:var(--ink)}

@media(max-width:1100px){.page-content{padding-left:28px;padding-right:28px}.topbar{padding-left:28px;padding-right:28px}.metrics-grid{grid-template-columns:1fr 1fr}.dashboard-grid{grid-template-columns:1fr}}
@media(max-width:760px){
  .km-sidebar{transform:translateX(-100%)}
  .km-sidebar.open{transform:translateX(0);box-shadow:10px 0 40px rgba(26,28,24,.3)}
  .main-area{margin-left:0}
  .topbar-left>.icon-button{display:grid}
  .topbar{height:64px;padding:0 17px}
  .topbar-actions{gap:5px}
  .top-search{display:none}
  .page-content{padding:33px 17px 40px}
  .hero-row{display:block;margin-bottom:26px}
  h1{font-size:40px}
  .hero-actions{margin-top:18px}
  .metrics-grid{grid-template-columns:1fr}
  .order-body{grid-template-columns:1fr}
  .order-field{border-right:0;border-bottom:1px solid var(--line)}
  .km-toast{top:73px;right:17px;left:17px;max-width:unset}
}
@media(max-width:480px){
  .panel-header{padding:17px 16px}
  .activity-list,.quick-list{padding-left:16px;padding-right:16px}
  .order-head,.order-foot,.order-avis{padding-left:16px;padding-right:16px}
  .order-field{padding-left:16px;padding-right:16px}
  .modal-card{padding:20px}
}
</style>
</head>
<body>

<div class="km-app">
  <aside class="km-sidebar" id="sidebar">
    <div class="km-sb-brand"><a href="index.php">Koud<em>Main</em></a></div>

    <nav class="km-sb-nav">
      <a href="?tab=overview" class="km-sb-link <?= $onglet === 'overview' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Vue d'ensemble
      </a>
      <a href="?tab=prestations" class="km-sb-link <?= $onglet === 'prestations' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="3" width="14" height="14" rx="2"/><path d="M7 8h6M7 12h4"/></svg></span>
        Mes prestations
        <?php if ($nb_prest > 0): ?><span class="km-sb-badge"><?= $nb_prest ?></span><?php endif; ?>
      </a>
      <a href="?tab=commandes" class="km-sb-link <?= $onglet === 'commandes' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></span>
        Commandes
        <?php if ($nb_attente > 0): ?><span class="km-sb-badge"><?= $nb_attente ?></span><?php endif; ?>
      </a>

      <div class="km-sb-section">Finance</div>
      <a href="wallet.php" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="2" y="5" width="16" height="11" rx="2"/><path d="M2 9h16"/></svg></span>
        Mon Wallet
      </a>

      <div class="km-sb-section">Compte</div>
      <a href="index.php" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="7"/></svg></span>
        Accueil
      </a>
      <a href="connexion.php?action=logout" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M8 4H4v12h4M13 14l4-4-4-4M17 10H8"/></svg></span>
        Déconnexion
      </a>
    </nav>

    <div class="km-sb-foot">
      <a href="wallet.php" class="km-wallet-mini">
        <div class="w-label">Solde disponible</div>
        <div class="w-solde"><?= number_format($solde_prest, 0, ',', ' ') ?> FCFA</div>
      </a>
    </div>
  </aside>

  <main class="main-area">
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-button km-menu-toggle" aria-label="Ouvrir le menu" onclick="document.getElementById('sidebar').classList.toggle('open')"><?= icon('menu', 21) ?></button>
        <div class="breadcrumb"><span>Espace prestataire</span><?= icon('chevron-right', 14) ?><strong><?= htmlspecialchars($tab_label_courant) ?></strong></div>
      </div>
      <div class="topbar-actions">
        <form class="top-search" method="GET" action="">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($onglet) ?>">
          <?= icon('search', 16) ?>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="<?= htmlspecialchars($search_placeholder) ?>">
        </form>
        <a href="messages.php" class="icon-button" aria-label="Messages" title="<?= $nb_msgs > 0 ? $nb_msgs . ' message(s) non lu(s)' : 'Aucun nouveau message' ?>"><?= icon('message', 18) ?><?php if ($nb_msgs > 0): ?><i></i><?php endif; ?></a>
        <a href="notifications.php" class="icon-button" aria-label="Notifications" title="<?= $nb_notifs > 0 ? $nb_notifs . ' notification(s) non lue(s)' : 'Vous êtes à jour.' ?>"><?= icon('bell', 18) ?><?php if ($nb_notifs > 0): ?><i></i><?php endif; ?></a>
        <a href="wallet.php" class="top-profile"><div class="avatar avatar-sm"><?= htmlspecialchars($initiales) ?></div><?= icon('chevron-down', 14) ?></a>
      </div>
    </header>

    <div class="page-content">
      <?php if ($msg): ?><div class="km-toast km-toast-ok" id="km-toast-msg"><?= icon('check', 15) ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
      <?php if ($err): ?><div class="km-toast km-toast-err" id="km-toast-err"><?= icon('x', 15) ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

      <!-- ════════ OVERVIEW ════════ -->
      <?php if ($onglet === 'overview'): ?>

      <section class="hero-row">
        <div>
          <div class="eyebrow"><?= htmlspecialchars($eyebrowDate) ?></div>
          <h1>Bonjour, <em><?= $prenom ?>.</em></h1>
          <p class="hero-copy">Gérez vos offres, suivez les demandes clients et faites grandir votre activité sereinement.</p>
        </div>
        <div class="hero-actions">
          <a href="?tab=prestations" class="button button-light"><?= icon('briefcase', 16) ?> Mes offres</a>
          <button type="button" class="button button-dark" onclick="openModal('modal-prest')"><?= icon('plus', 16) ?> Nouvelle prestation</button>
        </div>
      </section>

      <section class="metrics-grid">
        <div class="metric-card metric-main">
          <div class="metric-head"><span>Solde wallet</span><span class="metric-icon"><?= icon('wallet-cards', 15) ?></span></div>
          <strong><?= number_format($solde_prest, 0, ',', ' ') ?> <small>FCFA</small></strong>
          <div class="metric-foot"><span>Disponible pour retrait</span></div>
        </div>
        <div class="metric-card">
          <div class="metric-head"><span>Revenus terminés</span><span class="metric-icon green"><?= icon('package-check', 15) ?></span></div>
          <strong class="green-text"><?= number_format($revenus_total, 0, ',', ' ') ?> <small>FCFA</small></strong>
          <div class="metric-foot"><span>Total encaissé</span></div>
        </div>
        <div class="metric-card">
          <div class="metric-head"><span>Prestations actives</span><span class="metric-icon amber"><?= icon('briefcase', 15) ?></span></div>
          <strong><?= $nb_prest ?></strong>
          <div class="metric-foot"><span>Dans votre catalogue</span></div>
        </div>
        <div class="metric-card">
          <div class="metric-head"><span>Note moyenne</span><span class="metric-icon amber"><?= icon('star', 15) ?></span></div>
          <strong><?= $note_moy ?> <small>/ 5</small></strong>
          <div class="metric-foot"><span><?= $nb_avis_total ?> avis clients</span></div>
        </div>
      </section>

      <div class="dashboard-grid">
        <section class="panel">
          <div class="panel-header">
            <div><div class="eyebrow">À ne pas manquer</div><h2>Activité récente</h2></div>
            <a href="?tab=commandes" class="text-link">Voir tout <?= icon('arrow-right', 14) ?></a>
          </div>
          <?php if (empty($recent_cmd)): ?>
            <div class="empty-state"><strong>Aucune commande</strong><p>Publiez une prestation pour commencer à recevoir des demandes.</p></div>
          <?php else: ?>
          <div class="activity-list">
            <?php foreach ($recent_cmd as $c):
              $badgeClass = match($c['statut']) {
                  'Terminée' => 'status-teal',
                  'En attente', 'En cours' => 'status-amber',
                  'Annulée', 'Litige' => 'status-danger',
                  default => 'status-neutral',
              };
            ?>
            <div class="activity-item">
              <div class="activity-icon"><?= icon('package-check', 15) ?></div>
              <div>
                <strong><?= htmlspecialchars($c['titre_prestation']) ?></strong>
                <span><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?> · <?= date('d/m H:i', strtotime($c['date_commande'])) ?></span>
              </div>
              <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span>
              <span class="activity-value"><?= number_format($c['montant_total'], 0, ',', ' ') ?> F</span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="panel quick-panel">
          <div class="panel-header"><div><div class="eyebrow">À portée de main</div><h2>Actions rapides</h2></div><span style="color:var(--amber-deep)"><?= icon('sparkles', 19) ?></span></div>
          <div class="quick-list">
            <button type="button" onclick="openModal('modal-prest')"><span class="quick-icon dark-bg"><?= icon('plus', 17) ?></span><span><strong>Publier une prestation</strong><small>Présentez votre savoir-faire</small></span><?= icon('chevron-right', 16) ?></button>
            <a href="?tab=commandes"><span class="quick-icon amber-bg"><?= icon('clipboard-list', 17) ?></span><span><strong>Voir les commandes</strong><small><?= $nb_attente > 0 ? $nb_attente . ' demande(s) à traiter' : 'Tout est à jour' ?></small></span><?= icon('chevron-right', 16) ?></a>
            <a href="wallet.php"><span class="quick-icon green-bg"><?= icon('wallet-cards', 17) ?></span><span><strong>Wallet &amp; retraits</strong><small>Suivez vos encaissements</small></span><?= icon('chevron-right', 16) ?></a>
          </div>
          <div class="quick-note"><?= icon('circle-help', 16) ?><span>Une offre claire et bien décrite reçoit en moyenne plus de demandes.</span></div>
        </section>
      </div>

      <!-- ════════ PRESTATIONS ════════ -->
      <?php elseif ($onglet === 'prestations'): ?>

      <div class="prest-toolbar">
        <div>
          <div class="eyebrow">Votre catalogue</div>
          <h1>Mes prestations</h1>
          <p class="hero-copy">Publiez et gérez les services que vous proposez aux clients.</p>
        </div>
        <button type="button" class="button button-dark" onclick="openModal('modal-prest')"><?= icon('plus', 16) ?> Nouvelle prestation</button>
      </div>

      <?php if (empty($prestations)): ?>
        <div class="empty-state" style="min-height:220px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius)">
          <strong>Aucune prestation<?= $search !== '' ? ' trouvée' : ' publiée pour le moment' ?></strong>
          <p><?= $search !== '' ? 'Essayez un autre mot-clé.' : "Créez votre première offre pour apparaître dans le catalogue." ?></p>
        </div>
      <?php else: ?>
      <div class="prest-grid">
        <?php foreach ($prestations as $p): ?>
        <div class="prest-card">
          <div class="prest-tags">
            <span class="prest-tag prest-tag-cat"><?= htmlspecialchars($p['nom_categorie']) ?></span>
            <span class="prest-tag prest-tag-serv"><?= htmlspecialchars($p['nom_service']) ?></span>
          </div>
          <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
          <p class="prest-desc"><?= htmlspecialchars($p['description_prestation'] ?: 'Aucune description.') ?></p>
          <div class="prest-foot">
            <span class="prest-price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> FCFA</span>
            <span class="prest-stars"><?= stars_html((float)$p['note_moy']) ?> <small>(<?= (int)$p['nb_avis'] ?>)</small></span>
          </div>
          <div class="prest-actions">
            <button type="button" class="button button-outline"
              onclick='openEditModal(<?= (int)$p["id_prestation"] ?>, <?= json_encode($p["titre_prestation"], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($p["description_prestation"] ?? "", JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (float)$p["prix_prestation"] ?>, <?= (int)$p["id_service"] ?>)'>
              <?= icon('pencil', 14) ?> Modifier
            </button>
            <form method="POST" style="flex:1">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="supprimer_prestation">
              <input type="hidden" name="id_prestation" value="<?= (int)$p['id_prestation'] ?>">
              <button type="submit" class="button button-danger full" onclick="return confirm('Supprimer cette prestation ?')"><?= icon('trash', 14) ?> Supprimer</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($nb_pages_prest_p > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $nb_pages_prest_p; $i++): ?>
          <a href="?tab=prestations&p_prest=<?= $i ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="page-button <?= $i === $page_prest_p ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; endif; ?>

      <!-- ════════ COMMANDES ════════ -->
      <?php elseif ($onglet === 'commandes'): ?>

      <div class="orders-toolbar">
        <div>
          <div class="eyebrow">Votre activité</div>
          <h1>Commandes reçues</h1>
          <p class="hero-copy">Acceptez, suivez et finalisez les demandes de vos clients.</p>
        </div>
        <div class="orders-chip"><?= $nb_cmd_filtre ?> <span>commande<?= $nb_cmd_filtre > 1 ? 's' : '' ?></span></div>
      </div>

      <?php if (empty($commandes)): ?>
        <div class="empty-state" style="min-height:220px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius)">
          <strong>Aucune commande<?= $search !== '' ? ' trouvée' : '' ?></strong>
          <p><?= $search !== '' ? 'Essayez un autre mot-clé.' : "Vos futures commandes apparaîtront ici." ?></p>
        </div>
      <?php else: foreach ($commandes as $c):
        $isDone     = $c['statut'] === 'Terminée';
        $isAccepted = $c['statut'] === 'Acceptée';
        $isPending  = $c['statut'] === 'En attente';
        $isEnCours  = $c['statut'] === 'En cours';
        $isAnnulee  = $c['statut'] === 'Annulée';
        $isLitige   = $c['statut'] === 'Litige';
        $badgeClass = match($c['statut']) {
            'Terminée' => 'status-teal',
            'En attente', 'En cours' => 'status-amber',
            'Annulée', 'Litige' => 'status-danger',
            default => 'status-neutral',
        };
        $telHref = 'tel:' . preg_replace('/\s+/', '', $c['num_utilisateur']);
      ?>
      <div class="order-card">
        <div class="order-head">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span class="order-id">#KM-<?= str_pad((string)$c['id_commande'], 4, '0', STR_PAD_LEFT) ?></span>
            <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span>
            <span class="order-date"><?= date('d M. Y · H:i', strtotime($c['date_commande'])) ?></span>
          </div>
          <span class="order-total"><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</span>
        </div>
        <div class="order-body">
          <div class="order-field"><div class="order-field-label">Prestation</div><div class="order-field-val"><?= htmlspecialchars($c['titre_prestation']) ?></div></div>
          <div class="order-field"><div class="order-field-label">Client</div><div class="order-field-val"><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></div></div>
          <div class="order-field"><div class="order-field-label">Lieu</div><div class="order-field-val"><?= icon('map-pin', 12) ?> <?= htmlspecialchars($c['nom_quartier']) ?></div></div>
        </div>
        <?php $nbMsgCmd = nbMessagesNonLusPourCommande($pdo, (int)$c['id_commande'], $idUser); ?>
        <div class="order-avis" style="border-bottom:1px solid var(--line)">
          <a href="conversation.php?id_commande=<?= (int)$c['id_commande'] ?>" class="button button-outline" style="position:relative">
            <?= icon('sparkles', 14) ?> Discuter avec le client
            <?php if ($nbMsgCmd > 0): ?><span style="position:absolute;top:-7px;right:-7px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 4px;background:var(--amber);color:#fff;border-radius:20px;font-size:9.5px;font-weight:800"><?= $nbMsgCmd ?></span><?php endif; ?>
          </a>
        </div>
        <?php if ($isDone): ?>
          <div class="order-avis">
            <?php if ($c['evaluation'] !== null): ?>
              <span class="prest-stars"><?= stars_html((float)$c['evaluation']) ?></span>
              <?php if ($c['commentaire']): ?><em>« <?= htmlspecialchars(mb_substr($c['commentaire'], 0, 100)) ?> »</em><?php endif; ?>
            <?php else: ?>
              En attente de la note du client.
            <?php endif; ?>
          </div>
          <div class="order-foot"><span class="order-note"><?= icon('check', 14) ?> <?= $c['date_validation_client'] ? 'Paiement crédité sur votre wallet' : 'Paiement en attente de confirmation du client' ?></span></div>
        <?php elseif ($isAnnulee): ?>
          <div class="order-foot"><span style="font-size:12px;color:var(--ink-soft)">Commande annulée<?= $c['motif_annulation'] ? ' — ' . htmlspecialchars($c['motif_annulation']) : '' ?>.</span></div>
        <?php elseif ($isLitige): ?>
          <div class="order-foot"><span style="font-size:12px;color:var(--danger)"><?= icon('x', 14) ?> Litige ouvert<?= $c['motif_litige'] ? ' — ' . htmlspecialchars($c['motif_litige']) : '' ?>. En cours d'arbitrage par l'administration.</span></div>
        <?php else: ?>
          <div class="order-foot">
            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
              <?= champCSRF() ?>
              <input type="hidden" name="id_commande" value="<?= (int)$c['id_commande'] ?>">
              <?php if ($isPending): ?>
                <button type="submit" name="action" value="accepter_commande" class="button button-dark"><?= icon('check', 14) ?> Accepter la commande</button>
              <?php elseif ($isAccepted): ?>
                <button type="submit" name="action" value="demarrer_commande" class="button button-dark"><?= icon('zap', 14) ?> Démarrer la prestation</button>
              <?php elseif ($isEnCours): ?>
                <button type="submit" name="action" value="terminer_commande" class="button button-dark" onclick="return confirm('Marquer cette commande comme terminée ?')"><?= icon('check', 14) ?> Marquer comme terminé</button>
              <?php endif; ?>
            </form>
            <?php if ($isPending || $isAccepted): ?>
            <form method="POST" onsubmit="return confirm('Annuler cette commande ? Le client sera remboursé.');">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="annuler_commande_prest">
              <input type="hidden" name="id_commande" value="<?= (int)$c['id_commande'] ?>">
              <button type="submit" class="button button-outline">Refuser / Annuler</button>
            </form>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($telHref) ?>" class="button button-outline"><?= icon('phone', 14) ?> Contacter le client</a>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($nb_pages_cmd_p > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $nb_pages_cmd_p; $i++): ?>
          <a href="?tab=commandes&p_cmd=<?= $i ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="page-button <?= $i === $page_cmd_p ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; endif; ?>

      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Modal Nouvelle / Modifier prestation -->
<div class="modal-overlay" id="modal-prest">
  <div class="modal-card">
    <div class="modal-head">
      <div><div class="eyebrow" id="modal-prest-eyebrow">Votre catalogue</div><h2 id="modal-prest-title">Nouvelle prestation</h2></div>
      <button type="button" class="icon-button" onclick="closeModal('modal-prest')" aria-label="Fermer"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" action="?tab=prestations" class="modal-form" id="form-prest">
      <?= champCSRF() ?>
      <input type="hidden" name="action" id="prest-action" value="creer_prestation">
      <input type="hidden" name="id_prestation" id="prest-id" value="">
      <label>Titre<input type="text" id="prest-titre" name="titre" placeholder="Ex. Coiffure à domicile Cocody" required></label>
      <label>Service
        <select id="prest-service" name="id_service" required>
          <option value="">— Choisir —</option>
          <?php
          $cat = '';
          foreach ($services as $s):
            if ($s['nom_categorie'] !== $cat) {
              if ($cat) echo '</optgroup>';
              echo '<optgroup label="' . htmlspecialchars($s['nom_categorie']) . '">';
              $cat = $s['nom_categorie'];
            }
          ?>
            <option value="<?= (int)$s['id_service'] ?>"><?= htmlspecialchars($s['nom_service']) ?></option>
          <?php endforeach; if ($cat) echo '</optgroup>'; ?>
        </select>
      </label>
      <label>Prix (FCFA)<input type="number" id="prest-prix" name="prix" min="0" step="100" placeholder="5000" required></label>
      <label>Description<textarea id="prest-description" name="description" placeholder="Spécialités, zone d'intervention, disponibilité…"></textarea></label>
      <button type="submit" class="button button-dark full" id="prest-submit">Publier <?= icon('arrow-right', 15) ?></button>
    </form>
  </div>
</div>

<script>
const KM_CSRF = <?= json_encode($csrf) ?>;

function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('mousedown', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    document.getElementById('sidebar').classList.remove('open');
  }
});
document.addEventListener('click', e => {
  const sb = document.getElementById('sidebar');
  if (window.innerWidth <= 760 && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.km-menu-toggle')) {
    sb.classList.remove('open');
  }
});

function resetPrestModal() {
  document.getElementById('form-prest').reset();
  document.getElementById('prest-action').value = 'creer_prestation';
  document.getElementById('prest-id').value = '';
  document.getElementById('modal-prest-title').textContent = 'Nouvelle prestation';
  document.getElementById('modal-prest-eyebrow').textContent = 'Votre catalogue';
  document.getElementById('prest-submit').innerHTML = 'Publier ' + document.getElementById('prest-submit').querySelector('svg').outerHTML;
}

function openEditModal(id, titre, description, prix, idService) {
  document.getElementById('prest-action').value = 'modifier_prestation';
  document.getElementById('prest-id').value = id;
  document.getElementById('prest-titre').value = titre;
  document.getElementById('prest-description').value = description;
  document.getElementById('prest-prix').value = prix;
  document.getElementById('prest-service').value = idService;
  document.getElementById('modal-prest-title').textContent = 'Modifier la prestation';
  document.getElementById('modal-prest-eyebrow').textContent = 'Votre catalogue';
  document.getElementById('prest-submit').innerHTML = 'Enregistrer les modifications';
  openModal('modal-prest');
}

document.querySelector('[onclick="openModal(\'modal-prest\')"]');
document.querySelectorAll('button[onclick="openModal(\'modal-prest\')"]').forEach(b => {
  b.addEventListener('click', resetPrestModal);
});

function showToast(kind, message) {
  const el = document.createElement('div');
  el.className = 'km-toast ' + (kind === 'err' ? 'km-toast-err' : 'km-toast-ok');
  el.innerHTML = '<span>' + message.replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])) + '</span>';
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3600);
}

['km-toast-msg', 'km-toast-err'].forEach(id => {
  const el = document.getElementById(id);
  if (el) setTimeout(() => el.remove(), 4200);
});
</script>

</body>
</html>
