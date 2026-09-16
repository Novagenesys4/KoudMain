<?php
require_once "config.php";
requireConnexion();

if (estAdmin()) rediriger("admin_dashboard.php");
if (estPrestataire()) rediriger("prestataire_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

// --- Wallet ---
$wallet_cli = getOuCreerWallet($pdo, $idUser);
$solde_cli  = (float)$wallet_cli['solde'];

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    // Commander une prestation
    if ($_POST['action'] === 'commander') {
        $id_prest = (int)($_POST['id_prestation'] ?? 0);
        $qty      = max(1, (int)($_POST['quantite'] ?? 1));
        $id_quartier = (int)($_POST['id_quartier'] ?? 0);

        if ($id_prest <= 0) {
            $err = "Prestation invalide.";
        } else {
            $prest = $pdo->prepare("SELECT * FROM Prestation WHERE id_prestation = ?");
            $prest->execute([$id_prest]);
            $p = $prest->fetch();

            if (!$p) {
                $err = "Prestation introuvable.";
            } else {
                if ($id_quartier <= 0) {
                    $uq = $pdo->prepare("SELECT id_quartier FROM Utilisateur WHERE id_utilisateur = ?");
                    $uq->execute([$idUser]);
                    $id_quartier = (int)$uq->fetchColumn();
                }

                $montant = (float)$p['prix_prestation'] * $qty;

                try {
                    $pdo->beginTransaction();

                    $insCmd = $pdo->prepare("
                        INSERT INTO Commande (montant_total, statut, id_quartier, id_utilisateur)
                        VALUES (?, 'En attente', ?, ?)
                    ");
                    $insCmd->execute([$montant, $id_quartier, $idUser]);
                    $id_cmd = (int)$pdo->lastInsertId();

                    $insCib = $pdo->prepare("
                        INSERT INTO Cibler (id_prestation, id_commande, prix_unitaire, quantite)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insCib->execute([$id_prest, $id_cmd, $p['prix_prestation'], $qty]);

                    $pdo->commit();
                    $msg = "Commande #{$id_cmd} passée avec succès ! Le prestataire va la traiter.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $err = "Erreur lors de la commande : " . $e->getMessage();
                }
            }
        }
    }

    // Noter une commande terminée
    elseif ($_POST['action'] === 'noter') {
        $id_cmd   = (int)($_POST['id_commande'] ?? 0);
        $id_prest = (int)($_POST['id_prestation'] ?? 0);
        $note     = (int)($_POST['evaluation'] ?? 0);
        $commentaire = trim($_POST['commentaire'] ?? '');

        if ($note < 1 || $note > 5 || $id_cmd <= 0 || $id_prest <= 0) {
            $err = "Note invalide.";
        } else {
            $chk = $pdo->prepare("
                SELECT cm.id_commande FROM Commande cm
                JOIN Cibler ci ON cm.id_commande = ci.id_commande
                WHERE cm.id_commande = ? AND cm.id_utilisateur = ? AND cm.statut = 'Terminé'
                  AND ci.id_prestation = ?
            ");
            $chk->execute([$id_cmd, $idUser, $id_prest]);
            if ($chk->fetch()) {
                $upd = $pdo->prepare("
                    UPDATE Cibler SET evaluation = ?, commentaire = ?
                    WHERE id_commande = ? AND id_prestation = ?
                ");
                $upd->execute([$note, $commentaire, $id_cmd, $id_prest]);
                $msg = "Merci pour votre avis !";
            } else {
                $err = "Impossible de noter cette commande.";
            }
        }
    }
}

// --- Onglet --- //
$onglet = $_GET['tab'] ?? 'overview';
$search = trim($_GET['q'] ?? '');
$cat_filter = (int)($_GET['cat'] ?? 0);

// Stats client
$nb_cmd = $pdo->prepare("SELECT COUNT(*) FROM Commande WHERE id_utilisateur = ?");
$nb_cmd->execute([$idUser]); $nb_cmd = (int)$nb_cmd->fetchColumn();

$nb_attente = $pdo->prepare("SELECT COUNT(*) FROM Commande WHERE id_utilisateur = ? AND statut = 'En attente'");
$nb_attente->execute([$idUser]); $nb_attente = (int)$nb_attente->fetchColumn();

$nb_termine = $pdo->prepare("SELECT COUNT(*) FROM Commande WHERE id_utilisateur = ? AND statut = 'Terminé'");
$nb_termine->execute([$idUser]); $nb_termine = (int)$nb_termine->fetchColumn();

$depense = $pdo->prepare("
    SELECT COALESCE(SUM(montant_total), 0) FROM Commande
    WHERE id_utilisateur = ? AND statut = 'Terminé'
");
$depense->execute([$idUser]); $depense_total = (float)$depense->fetchColumn();

// Catégories
$categories = $pdo->query("SELECT * FROM Categorie ORDER BY nom_categorie")->fetchAll();

// Prestations disponibles (catalogue)
$limit_prest = 12;
$page_prest  = max(1, (int)($_GET['p'] ?? 1));
$offset_prest = ($page_prest - 1) * $limit_prest;

$sql_count = "
    SELECT COUNT(*) FROM Prestation p
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    WHERE 1=1
";
$params_count = [];
if ($cat_filter > 0) {
    $sql_count .= " AND c.id_categorie = ?";
    $params_count[] = $cat_filter;
}
if ($search !== '') {
    $sql_count .= " AND (p.titre_prestation ILIKE ? OR p.description_prestation ILIKE ? OR s.nom_service ILIKE ?)";
    $params_count[] = "%$search%";
    $params_count[] = "%$search%";
    $params_count[] = "%$search%";
}
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params_count);
$total_prest = (int)$stmt_count->fetchColumn();
$nb_pages_prest = max(1, (int)ceil($total_prest / $limit_prest));

$sql_prest = "
    SELECT p.*, s.nom_service, c.nom_categorie, c.id_categorie,
           u.prenom_utilisateur, u.nom_utilisateur,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy,
           COUNT(ci.evaluation) AS nb_avis
    FROM Prestation p
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation AND ci.evaluation IS NOT NULL
    WHERE 1=1
";
$params_prest = [];
if ($cat_filter > 0) {
    $sql_prest .= " AND c.id_categorie = ?";
    $params_prest[] = $cat_filter;
}
if ($search !== '') {
    $sql_prest .= " AND (p.titre_prestation ILIKE ? OR p.description_prestation ILIKE ? OR s.nom_service ILIKE ?)";
    $params_prest[] = "%$search%";
    $params_prest[] = "%$search%";
    $params_prest[] = "%$search%";
}
$sql_prest .= "
    GROUP BY p.id_prestation, s.nom_service, c.nom_categorie, c.id_categorie,
             u.prenom_utilisateur, u.nom_utilisateur
    ORDER BY p.datecrea_prestation DESC
    LIMIT $limit_prest OFFSET $offset_prest
";
$stmt_prest = $pdo->prepare($sql_prest);
$stmt_prest->execute($params_prest);
$prestations = $stmt_prest->fetchAll();

// Mes commandes
$limit_cmd = 10;
$page_cmd  = max(1, (int)($_GET['p_cmd'] ?? 1));
$offset_cmd = ($page_cmd - 1) * $limit_cmd;
$nb_pages_cmd = max(1, (int)ceil($nb_cmd / $limit_cmd));

$stmt_c = $pdo->prepare("
    SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite,
           ci.evaluation, ci.commentaire,
           p.titre_prestation,
           u.nom_utilisateur AS prest_nom, u.prenom_utilisateur AS prest_prenom, u.num_utilisateur AS prest_tel,
           q.nom_quartier
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    JOIN Quartier q ON cm.id_quartier = q.id_quartier
    WHERE cm.id_utilisateur = ?
    ORDER BY cm.date_commande DESC
    LIMIT $limit_cmd OFFSET $offset_cmd
");
$stmt_c->execute([$idUser]);
$commandes = $stmt_c->fetchAll();
$recent_cmd = array_slice($commandes, 0, 4);

// Quartiers pour formulaire
$quartiers = $pdo->query("
    SELECT q.id_quartier, q.nom_quartier, v.nom_ville
    FROM Quartier q JOIN Ville v ON q.id_ville = v.id_ville
    ORDER BY v.nom_ville, q.nom_quartier
")->fetchAll();

$prenom  = htmlspecialchars($_SESSION['prenom'] ?? 'Client');
$nomFull = trim(($_SESSION['prenom'] ?? 'Client') . ' ' . ($_SESSION['nom'] ?? ''));
$initiales = strtoupper(mb_substr($_SESSION['prenom'] ?? 'C', 0, 1) . mb_substr($_SESSION['nom'] ?? 'L', 0, 1));

$jours_fr = [0=>'Dimanche',1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi'];
$mois_fr_long = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
$now = new DateTime();
$eyebrowDate = strtoupper($jours_fr[(int)$now->format('w')] . ' ' . (int)$now->format('j') . ' ' . $mois_fr_long[(int)$now->format('n')] . ' ' . $now->format('Y'));

$tab_labels = [
    'overview'  => "Vue d'ensemble",
    'catalogue' => 'Catalogue',
    'commandes' => 'Mes commandes',
];
$tab_label_courant = $tab_labels[$onglet] ?? "Vue d'ensemble";

// Progression d'activité (commandes terminées / total) — utilisée à la place d'un "budget"
$activite_pct = $nb_cmd > 0 ? min(100, round($nb_termine / $nb_cmd * 100)) : 0;

// --- Icônes catégories ---
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

// Palette de fond par catégorie (pour les vignettes du catalogue)
function teinteCategorie(int $id): string {
    $palette = ['peche','sauge','ble','lilas','corail','menthe','ciel','rose'];
    return $palette[$id % count($palette)];
}

function icon(string $name, int $size = 17): string {
    $paths = [
        'home'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'package'       => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.29 7 8.71 5 8.71-5"/><path d="M12 22V12"/>',
        'wallet'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'user'          => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
        'bell'          => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'sparkles'      => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'zap'           => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'arrow-right'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'credit-card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'check'         => '<polyline points="20 6 9 17 4 12"/>',
        'clock'         => '<circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 1.5"/>',
        'help'          => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'menu'          => '<line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'pin'           => '<path d="M10 18s6-5.2 6-9.8A6 6 0 0 0 4 8.2C4 12.8 10 18 10 18Z"/><circle cx="10" cy="8" r="2"/>',
        'phone'         => '<path d="M15.05 5A5 5 0 0 1 19 9M15.05 1A9 9 0 0 1 22.94 9M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    ];
    $body = $paths[$name] ?? $paths['sparkles'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon espace — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F3EE;--paper-deep:#EBE7DC;--surface:#FFFEFA;
  --ink:#1D211C;--ink-soft:#6C695E;--ink-faint:#9B9788;--line:#E1DED2;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;
  --teal:#2E6B5E;--teal-tint:#E3EFEA;
  --danger:#A85245;--danger-tint:#F3E2DC;
  --sidebar:#1C1B17;--sidebar-ink:#E8E4D8;--sidebar-muted:#A8A398;
  --radius:16px;--radius-sm:9px;--sidebar-w:250px;
  --shadow-sm:0 1px 2px rgba(28,27,23,.04);
  --shadow-md:0 10px 26px rgba(28,27,23,.08);
  --ease:cubic-bezier(.22,1,.36,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15.3px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
button{border:0;background:transparent;cursor:pointer}
h1,h2,h3,.km-serif{font-family:'Fraunces',Georgia,serif}
svg{display:block}
@media(prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}

/* ── APP LAYOUT ── */
.km-app{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.km-sidebar{
  width:var(--sidebar-w);flex:0 0 var(--sidebar-w);position:fixed;inset:0 auto 0 0;z-index:80;
  display:flex;flex-direction:column;background:var(--sidebar);color:var(--sidebar-ink);
  box-shadow:12px 0 34px rgba(20,20,17,.07);transition:transform .28s var(--ease);
}
.km-sb-brand{padding:26px 24px 6px}
.km-sb-brand a{font-family:'Fraunces',serif;font-weight:700;font-size:24px;letter-spacing:-.03em;color:#fffdf7}
.km-sb-brand span{color:var(--amber)}
.km-sb-tag{margin-top:6px;color:#7d7a6d;font-size:9.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase}
.km-sb-scroll{flex:1;padding:22px 14px;overflow-y:auto}
.km-sb-group{margin-bottom:24px}
.km-sb-label{display:block;padding:0 12px 9px;color:#7d7a6d;font-size:10px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}
.km-sb-link{
  position:relative;display:flex;align-items:center;gap:12px;width:100%;min-height:44px;padding:0 13px;
  color:var(--sidebar-muted);border-radius:10px;font-size:13.5px;font-weight:500;
  transition:background .18s ease,color .18s ease,transform .18s ease;
}
.km-sb-link:hover{color:#fff;background:rgba(255,255,255,.06);transform:translateX(2px)}
.km-sb-link.active{color:#f6e2cb;background:linear-gradient(90deg,rgba(187,108,45,.24),rgba(187,108,45,.07));font-weight:650}
.km-sb-link.active::before{content:"";position:absolute;left:0;top:22%;bottom:22%;width:2px;border-radius:3px;background:var(--amber)}
.km-sb-badge{margin-left:auto;display:grid;place-items:center;min-width:20px;height:20px;padding:0 6px;color:#241f18;background:var(--amber);border-radius:30px;font-size:10px;font-weight:800}
.km-sb-bottom{padding:14px 18px 22px}
.km-sb-wallet{
  display:block;padding:15px 16px;margin-bottom:12px;background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.1);border-radius:13px;transition:border-color .18s ease,background .18s ease;
}
.km-sb-wallet:hover{border-color:var(--amber);background:rgba(187,108,45,.08)}
.km-sb-wallet .w-label{color:#98988c;font-size:10px;font-weight:600;letter-spacing:.02em}
.km-sb-wallet .w-solde{margin-top:6px;font-family:'Fraunces',serif;font-weight:600;font-size:19px;color:#fff}
.km-sb-progress{margin-top:11px;height:4px;border-radius:4px;background:rgba(255,255,255,.12);overflow:hidden}
.km-sb-progress i{display:block;height:100%;background:var(--amber);border-radius:4px}
.km-sb-progress-row{display:flex;justify-content:space-between;margin-top:7px;color:#94948a;font-size:9.5px}
.km-sb-note{display:flex;align-items:flex-start;gap:7px;color:#79776b;font-size:10.5px;line-height:1.5}
.km-sb-note svg{flex:0 0 auto;margin-top:2px;color:var(--amber)}
.km-sidebar-backdrop{display:none}

/* ── MAIN ── */
.km-main{flex:1;min-width:0;margin-left:var(--sidebar-w)}
.km-topbar{
  position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:18px;
  height:76px;padding:0 40px;border-bottom:1px solid rgba(213,210,199,.75);
  background:rgba(245,243,238,.88);backdrop-filter:blur(16px);
}
.km-menu-toggle{display:none;color:var(--ink)}
.km-breadcrumb{display:flex;align-items:center;gap:9px;color:var(--ink-faint);font-size:12.5px}
.km-breadcrumb strong{color:var(--ink);font-weight:650}
.km-topbar-search{display:flex;align-items:center;gap:9px;width:340px;height:41px;padding:0 14px;color:var(--ink-faint);background:var(--surface);border:1px solid var(--line);border-radius:10px;transition:border-color .18s ease,box-shadow .18s ease}
.km-topbar-search:focus-within{border-color:var(--amber);box-shadow:0 0 0 3px rgba(187,108,45,.1)}
.km-topbar-search input{flex:1;min-width:0;color:var(--ink);background:transparent;border:0;outline:0;font-size:13px}
.km-topbar-search input::placeholder{color:var(--ink-faint)}
.km-topbar-actions{display:flex;align-items:center;gap:16px}
.km-icon-btn{position:relative;display:grid;place-items:center;width:38px;height:38px;color:var(--ink-soft);border:1px solid transparent;border-radius:10px;transition:border-color .18s ease,background .18s ease}
.km-icon-btn:hover{border-color:var(--line);background:var(--surface)}
.km-icon-btn i{position:absolute;top:8px;right:8px;width:6px;height:6px;border-radius:50%;background:var(--amber);box-shadow:0 0 0 2px var(--paper);font-style:normal}
.km-profile{display:flex;align-items:center;gap:11px}
.km-avatar{display:grid;place-items:center;width:38px;height:38px;color:#7c481c;background:var(--amber-tint);border:1px solid rgba(187,108,45,.14);border-radius:50%;font-size:12px;font-weight:750}
.km-profile-copy strong,.km-profile-copy span{display:block}
.km-profile-copy strong{color:var(--ink);font-size:13px;font-weight:700}
.km-profile-copy span{margin-top:1px;color:var(--ink-faint);font-size:10.5px}
.km-profile-chevron{color:#b7b6ad}

.km-content{width:min(1420px,100%);margin:0 auto;padding:44px 40px 70px}

/* ── Alerts ── */
.km-alert{display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;border-left:2px solid;border-radius:0 10px 10px 0;font-size:13px;margin-bottom:1.4rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

/* ── OVERVIEW ── */
.km-ov-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:28px;flex-wrap:wrap}
.km-eyebrow{color:var(--ink-faint);font-size:10.5px;font-weight:700;letter-spacing:.14em}
.km-ov-head h1{margin-top:9px;font-size:clamp(30px,3.4vw,42px);font-weight:600;letter-spacing:-.03em}
.km-ov-head p{margin-top:8px;color:var(--ink-soft);font-size:13.5px;max-width:46ch}
.km-btn{display:inline-flex;align-items:center;gap:9px;height:44px;padding:0 20px;border-radius:11px;font-size:13px;font-weight:700;transition:transform .16s var(--ease),background .18s ease,box-shadow .18s ease}
.km-btn:active{transform:scale(.97)}
.km-btn-dark{color:#fff;background:var(--ink)}
.km-btn-dark:hover{background:var(--amber-deep);box-shadow:0 10px 24px rgba(141,78,31,.25)}
.km-btn-outline{color:var(--ink);background:var(--surface);border:1px solid var(--line)}
.km-btn-outline:hover{border-color:var(--ink)}

.km-hero{
  position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:30px;
  padding:42px 46px;margin-bottom:26px;color:#fbecd9;border-radius:22px;
  background:linear-gradient(120deg,#a85f27,#8a4c1e 60%,#6c3a17);
}
.km-hero::before{content:"";position:absolute;top:-40%;right:-8%;width:420px;height:420px;border-radius:50%;border:1px solid rgba(255,255,255,.12);pointer-events:none}
.km-hero::after{content:"";position:absolute;bottom:-50%;right:14%;width:280px;height:280px;border-radius:50%;border:1px solid rgba(255,255,255,.08);pointer-events:none}
.km-hero-eyebrow{position:relative;display:flex;align-items:center;gap:8px;color:#f0d3ac;font-size:10.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:16px}
.km-hero h2{position:relative;font-size:clamp(26px,3vw,36px);font-weight:600;line-height:1.15;letter-spacing:-.02em;max-width:16ch}
.km-hero h2 em{font-style:normal;color:#f3d9b0}
.km-hero p{position:relative;margin-top:12px;max-width:44ch;color:#eccfa9;font-size:13.5px}
.km-hero a{position:relative;display:inline-flex;align-items:center;gap:7px;margin-top:18px;color:#fff;font-weight:700;font-size:13px}
.km-hero a:hover svg{transform:translateX(3px)}
.km-hero a svg{transition:transform .18s ease}
.km-hero-float{
  position:relative;flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:14px 17px;
  background:rgba(30,20,12,.35);border:1px solid rgba(255,255,255,.16);border-radius:14px;backdrop-filter:blur(6px);
}
.km-hero-float-icon{display:grid;place-items:center;width:34px;height:34px;color:#fff;background:rgba(255,255,255,.14);border-radius:9px}
.km-hero-float strong,.km-hero-float span{display:block}
.km-hero-float strong{font-size:12.5px;font-weight:700;color:#fff}
.km-hero-float span{margin-top:2px;color:#e6c9a2;font-size:10.5px}
@media(max-width:900px){.km-hero{flex-direction:column;align-items:flex-start}}

.km-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.km-stat{
  min-height:150px;padding:20px 21px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow-sm);transition:transform .2s var(--ease),box-shadow .2s ease;
}
.km-stat:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.km-stat-icon{display:grid;place-items:center;width:34px;height:34px;color:var(--amber-deep);background:var(--amber-tint);border-radius:9px}
.km-stat.teal .km-stat-icon{color:var(--teal);background:var(--teal-tint)}
.km-stat.ink .km-stat-icon{color:var(--ink-soft);background:var(--paper-deep)}
.km-stat-val{margin-top:20px;font-family:'Fraunces',serif;font-size:31px;font-weight:600;letter-spacing:-.03em}
.km-stat-val small{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;color:var(--ink-faint)}
.km-stat-label{margin-top:6px;color:var(--ink-soft);font-size:12px}

.km-two-col{display:grid;grid-template-columns:1.5fr 1fr;gap:18px}
.km-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-sm)}
.km-panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:19px 22px;border-bottom:1px solid var(--line)}
.km-panel-eyebrow{color:var(--amber-deep);font-size:10px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.km-panel-head h3{margin-top:4px;font-size:19px;font-weight:600}
.km-panel-link{display:inline-flex;align-items:center;gap:6px;color:var(--amber-deep);font-size:12px;font-weight:750;transition:gap .18s ease}
.km-panel-link:hover{gap:9px;color:var(--ink)}

.km-activity-list{padding:2px 22px 8px}
.km-activity{display:flex;align-items:center;gap:13px;padding:14px 0;border-bottom:1px solid rgba(227,224,214,.8)}
.km-activity:last-child{border-bottom:0}
.km-activity-icon{display:grid;place-items:center;flex:0 0 auto;width:34px;height:34px;color:var(--amber-deep);background:var(--amber-tint);border-radius:10px}
.km-activity-body{flex:1;min-width:0}
.km-activity-body strong{display:block;font-size:13px;font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.km-activity-body span{display:block;margin-top:2px;color:var(--ink-faint);font-size:11px}
.km-badge{display:inline-flex;align-items:center;gap:4px;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:750;white-space:nowrap}
.km-badge-teal{color:var(--teal);background:var(--teal-tint)}
.km-badge-amber{color:var(--amber-deep);background:var(--amber-tint)}
.km-badge-neutral{color:var(--ink-soft);background:var(--paper-deep)}
.km-activity-amount{font-family:'Fraunces',serif;font-weight:600;font-size:13.5px;white-space:nowrap}

.km-quick-list{display:flex;flex-direction:column;gap:8px;padding:18px 20px}
.km-quick-btn{
  display:flex;align-items:center;gap:12px;width:100%;padding:13px 15px;border:1px solid var(--line);
  border-radius:11px;font-size:12.5px;font-weight:650;color:var(--ink);text-align:left;
  transition:border-color .18s ease,background .18s ease,transform .18s ease;
}
.km-quick-btn:hover{border-color:var(--amber-deep);background:var(--amber-tint);transform:translateX(2px)}
.km-quick-btn .qi{display:grid;place-items:center;flex:0 0 auto;width:32px;height:32px;color:var(--amber-deep);background:var(--surface);border-radius:9px}
.km-quick-btn span.ql{display:block;flex:1}
.km-quick-btn small{display:block;margin-top:2px;color:var(--ink-faint);font-size:10.5px;font-weight:500}
.km-quick-btn svg.chev{color:var(--ink-faint)}

.km-trust{display:flex;align-items:stretch;gap:0;margin-top:20px;padding:0;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-sm)}
.km-trust-item{display:flex;align-items:center;gap:11px;flex:1;padding:18px 22px}
.km-trust-item + .km-trust-item{border-left:1px solid var(--line)}
.km-trust-icon{display:grid;place-items:center;width:32px;height:32px;color:var(--teal);background:var(--teal-tint);border-radius:9px;flex:0 0 auto}
.km-trust-item strong{display:block;font-size:12.5px;font-weight:700}
.km-trust-item span{display:block;margin-top:2px;color:var(--ink-faint);font-size:10.5px}
.km-trust-help{margin-left:auto;display:flex;align-items:center;gap:6px;padding:0 22px;color:var(--amber-deep);font-size:12px;font-weight:750}

/* ── CATALOGUE ── */
.km-cat-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:24px;flex-wrap:wrap}
.km-cat-count{color:var(--ink-faint);font-size:12px;font-weight:600}
.km-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px}
.km-pill{padding:9px 16px;border-radius:30px;font-size:12.5px;font-weight:650;color:var(--ink-soft);background:var(--surface);border:1px solid var(--line);transition:all .18s ease}
.km-pill:hover{border-color:var(--ink)}
.km-pill.active{background:var(--ink);color:#fff;border-color:var(--ink)}

.km-prest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.km-prest-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;transition:transform .2s var(--ease),box-shadow .2s ease,border-color .2s ease}
.km-prest-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:#d6c9ae}
.km-prest-thumb{position:relative;height:100px;display:flex;align-items:flex-start;justify-content:space-between;padding:15px}
.km-prest-thumb-icon{display:grid;place-items:center;width:38px;height:38px;background:rgba(255,255,255,.62);color:var(--amber-deep);border-radius:11px}
.km-prest-thumb-tag{align-self:flex-start;margin-top:2px;color:rgba(29,33,28,.55);font-size:9px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
.km-thumb-peche{background:linear-gradient(135deg,#f1d9bd,#e9c69d)}
.km-thumb-sauge{background:linear-gradient(135deg,#d6e6dc,#bcd7c5)}
.km-thumb-ble{background:linear-gradient(135deg,#f0e3bd,#e5d29a)}
.km-thumb-lilas{background:linear-gradient(135deg,#e2ddf1,#cbc2e8)}
.km-thumb-corail{background:linear-gradient(135deg,#f2d6cb,#e7b7a5)}
.km-thumb-menthe{background:linear-gradient(135deg,#d8ece2,#b9dccb)}
.km-thumb-ciel{background:linear-gradient(135deg,#d9e6ef,#bcd6e6)}
.km-thumb-rose{background:linear-gradient(135deg,#f2dbe4,#e7bccd)}
.km-prest-body{padding:16px 18px 18px}
.km-prest-service{color:var(--amber-deep);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.km-prest-body h3{margin-top:7px;font-size:16.5px;font-weight:600;line-height:1.25}
.km-prest-desc{margin-top:7px;color:var(--ink-soft);font-size:12.5px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px}
.km-prest-by{display:flex;align-items:center;justify-content:space-between;margin-top:13px;padding-top:13px;border-top:1px solid var(--line)}
.km-prest-person{display:flex;align-items:center;gap:8px;min-width:0}
.km-prest-avatar{display:grid;place-items:center;flex:0 0 auto;width:26px;height:26px;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%;font-size:10px;font-weight:800}
.km-prest-person span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;font-weight:600}
.km-prest-rating{display:flex;align-items:center;gap:4px;color:var(--amber-deep);font-size:12px;font-weight:700;white-space:nowrap}
.km-prest-rating small{color:var(--ink-faint);font-weight:500}
.km-prest-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}
.km-price{font-family:'Fraunces',serif;font-weight:650;font-size:18px}
.km-price small{font-family:'Inter',sans-serif;font-size:10.5px;font-weight:600;color:var(--ink-faint)}
.km-btn-cmd{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 15px;border-radius:9px;background:var(--ink);color:#fff;font-size:12px;font-weight:700;transition:background .18s ease}
.km-btn-cmd:hover{background:var(--amber-deep)}

.km-empty{text-align:center;padding:70px 20px;color:var(--ink-soft)}
.km-empty-icon{width:46px;height:46px;margin:0 auto 14px;display:grid;place-items:center;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%}
.km-empty p{font-size:13px}

.km-pagination{display:flex;justify-content:center;gap:6px;margin-top:26px;flex-wrap:wrap}
.km-page-link{display:grid;place-items:center;min-width:34px;height:34px;padding:0 10px;border:1px solid var(--line);border-radius:9px;color:var(--ink-soft);font-size:12.5px;font-weight:700;transition:all .18s ease}
.km-page-link:hover{border-color:var(--ink);color:var(--ink)}
.km-page-link.active{background:var(--ink);color:#fff;border-color:var(--ink)}

/* ── COMMANDES ── */
.km-order-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;box-shadow:var(--shadow-sm)}
.km-order-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 22px;background:var(--paper-deep);flex-wrap:wrap}
.km-order-id{font-family:'Fraunces',serif;font-weight:650;font-size:16px}
.km-order-date{color:var(--ink-soft);font-size:11.5px}
.km-order-total{font-family:'Fraunces',serif;font-weight:650;font-size:18px}
.km-order-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.km-order-detail{padding:16px 22px;border-right:1px solid var(--line)}
.km-order-detail:last-child{border-right:0}
.km-order-label{color:var(--ink-faint);font-size:9.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.km-order-val{margin-top:5px;font-size:13.5px;font-weight:600}
.km-order-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:15px 22px;border-top:1px solid var(--line)}

.km-stars{display:inline-flex;gap:2px;align-items:center}
.km-star{width:27px;height:27px;color:var(--line);transition:color .15s ease,transform .15s ease}
.km-star svg{width:100%;height:100%;fill:currentColor;stroke:none}
.km-star:hover,.km-star.is-hover,.km-star.is-active{color:var(--amber)}
.km-star:hover{transform:scale(1.1)}
.km-stars-readonly .km-star{cursor:default;width:15px;height:15px}
.km-stars-readonly .km-star.is-active{color:var(--amber)}
.km-rate-input{flex:1;min-width:130px;padding:.5rem .8rem;border:1px solid var(--line);border-radius:8px;background:var(--paper);font-size:12.5px;color:var(--ink)}

/* ── Modal ── */
.km-modal-overlay{display:none;position:fixed;inset:0;z-index:200;place-items:center;padding:1rem;background:rgba(24,25,21,.55);backdrop-filter:blur(4px)}
.km-modal-overlay.open{display:grid;animation:km-fade .2s ease}
@keyframes km-fade{from{opacity:0}to{opacity:1}}
.km-modal{width:100%;max-width:440px;max-height:90vh;overflow-y:auto;padding:26px;background:var(--surface);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-md);animation:km-modal-in .25s var(--ease)}
@keyframes km-modal-in{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.km-modal-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:15px;margin-bottom:18px;border-bottom:1px solid var(--line)}
.km-modal-head h3{font-size:19px;font-weight:600}
.km-modal-close{display:grid;place-items:center;width:30px;height:30px;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:8px}
.km-mfield{margin-bottom:15px}
.km-mfield label{display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10.5px;font-weight:750;letter-spacing:.06em;text-transform:uppercase}
.km-mfield input,.km-mfield select{width:100%;height:42px;padding:0 12px;border:1px solid var(--line);border-radius:9px;background:var(--paper);color:var(--ink);font-size:13px;outline:0;transition:border-color .18s ease}
.km-mfield input:focus,.km-mfield select:focus{border-color:var(--amber)}
.km-mfield input[readonly]{color:var(--ink-soft)}
.km-btn-block{width:100%;justify-content:center}

@media(max-width:1150px){
  .km-content{padding-left:28px;padding-right:28px}
  .km-topbar{padding-left:28px;padding-right:28px}
  .km-stats{grid-template-columns:repeat(2,1fr)}
  .km-two-col{grid-template-columns:1fr}
  .km-trust{flex-wrap:wrap}
  .km-trust-item + .km-trust-item{border-left:0;border-top:1px solid var(--line)}
  .km-trust-help{width:100%;padding:14px 22px;border-top:1px solid var(--line)}
}
@media(max-width:860px){
  .km-sidebar{transform:translateX(-102%)}
  .km-sidebar.open{transform:translateX(0)}
  .km-main{margin-left:0}
  .km-menu-toggle{display:grid;place-items:center}
  .km-breadcrumb{display:none}
  .km-topbar-search{display:none}
  .km-sidebar-backdrop{display:block;position:fixed;inset:0;z-index:70;background:rgba(20,20,17,.4)}
}
@media(max-width:700px){
  .km-content{padding:30px 18px 55px}
  .km-topbar{height:64px;padding:0 18px}
  .km-profile-copy{display:none}
  .km-stats{grid-template-columns:1fr 1fr}
  .km-order-detail{border-right:0;border-bottom:1px solid var(--line)}
  .km-hero{padding:30px 24px}
}
</style>
</head>
<body>

<div class="km-app">
  <button class="km-sidebar-backdrop" id="sb-backdrop" onclick="document.getElementById('km-sidebar').classList.remove('open'); this.style.display='none';" style="display:none" aria-label="Fermer le menu"></button>

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand">
      <a href="index.php">Koud<span>Main</span></a>
    </div>

    <nav class="km-sb-scroll">
      <div class="km-sb-group">
        <span class="km-sb-label">Espace personnel</span>
        <a href="?tab=overview" class="km-sb-link <?= $onglet === 'overview' ? 'active' : '' ?>"><?= icon('home') ?><span>Vue d'ensemble</span></a>
        <a href="?tab=catalogue" class="km-sb-link <?= $onglet === 'catalogue' ? 'active' : '' ?>">
          <?= icon('search') ?><span>Catalogue</span>
          <?php if ($total_prest > 0): ?><span class="km-sb-badge"><?= $total_prest ?></span><?php endif; ?>
        </a>
        <a href="?tab=commandes" class="km-sb-link <?= $onglet === 'commandes' ? 'active' : '' ?>">
          <?= icon('package') ?><span>Mes commandes</span>
          <?php if ($nb_attente > 0): ?><span class="km-sb-badge"><?= $nb_attente ?></span><?php endif; ?>
        </a>
      </div>

      <div class="km-sb-group">
        <span class="km-sb-label">Finance</span>
        <a href="wallet.php" class="km-sb-link"><?= icon('wallet') ?><span>Mon Wallet</span></a>
      </div>

      <div class="km-sb-group">
        <span class="km-sb-label">Compte</span>
        <a href="?tab=overview" class="km-sb-link"><?= icon('user') ?><span>Mon profil</span></a>
        <a href="connexion.php?action=logout" class="km-sb-link"><?= icon('logout') ?><span>Déconnexion</span></a>
      </div>
    </nav>

    <div class="km-sb-bottom">
      <a href="wallet.php" class="km-sb-wallet">
        <div class="w-label">Solde disponible</div>
        <div class="w-solde"><?= number_format($solde_cli, 0, ',', ' ') ?> FCFA</div>
        <div class="km-sb-progress"><i style="width:<?= $activite_pct ?>%"></i></div>
        <div class="km-sb-progress-row"><span>Commandes terminées</span><span><?= $activite_pct ?>%</span></div>
      </a>
      <p class="km-sb-note"><?= icon('sparkles', 13) ?> Des services de confiance, près de chez vous.</p>
    </div>
  </aside>

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle km-icon-btn" onclick="document.getElementById('km-sidebar').classList.add('open'); document.getElementById('sb-backdrop').style.display='block';" aria-label="Ouvrir le menu"><?= icon('menu', 20) ?></button>
      <div class="km-breadcrumb"><span>Mon espace</span><?= icon('chevron-right', 13) ?><strong><?= htmlspecialchars($tab_label_courant) ?></strong></div>

      <form class="km-topbar-search" method="GET" action="">
        <input type="hidden" name="tab" value="catalogue">
        <?= icon('search', 15) ?>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher un service…">
      </form>

      <div class="km-topbar-actions">
        <button type="button" class="km-icon-btn" aria-label="Notifications" title="<?= $nb_attente > 0 ? $nb_attente . ' commande(s) en attente' : 'Tout est à jour' ?>">
          <?= icon('bell', 18) ?>
          <?php if ($nb_attente > 0): ?><i></i><?php endif; ?>
        </button>
        <div class="km-profile">
          <div class="km-avatar"><?= htmlspecialchars($initiales) ?></div>
          <div class="km-profile-copy"><strong><?= htmlspecialchars($nomFull ?: $prenom) ?></strong><span>Client</span></div>
          <span class="km-profile-chevron"><?= icon('chevron-right', 14) ?></span>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= icon('check', 15) ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= icon('x', 15) ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

      <!-- ════════ VUE D'ENSEMBLE ════════ -->
      <?php if ($onglet === 'overview'): ?>

      <div class="km-ov-head">
        <div>
          <div class="km-eyebrow"><?= htmlspecialchars($eyebrowDate) ?></div>
          <h1>Bonjour, <?= $prenom ?></h1>
          <p>Retrouvez les services qui rendent vos journées plus simples.</p>
        </div>
        <a href="?tab=catalogue" class="km-btn km-btn-dark"><?= icon('search', 15) ?> Trouver un service</a>
      </div>

      <div class="km-hero">
        <div>
          <div class="km-hero-eyebrow"><?= icon('sparkles', 13) ?> Votre confort commence ici</div>
          <h2>Un coup de main,<br><em>quand vous en avez besoin.</em></h2>
          <p>Des prestataires locaux vérifiés pour prendre soin de votre quotidien.</p>
          <a href="?tab=catalogue">Explorer les services <?= icon('arrow-right', 15) ?></a>
        </div>
        <?php if (!empty($recent_cmd)): $dernier = $recent_cmd[0]; ?>
        <div class="km-hero-float">
          <div class="km-hero-float-icon"><?= icon($dernier['statut'] === 'Terminé' ? 'check' : 'clock', 17) ?></div>
          <div>
            <strong><?= $dernier['statut'] === 'Terminé' ? 'Service terminé' : 'Service réservé' ?></strong>
            <span><?= htmlspecialchars($dernier['nom_quartier']) ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="km-stats">
        <div class="km-stat">
          <div class="km-stat-icon"><?= icon('wallet', 17) ?></div>
          <div class="km-stat-val"><?= number_format($solde_cli, 0, ',', ' ') ?></div>
          <div class="km-stat-label">Solde wallet (FCFA)</div>
        </div>
        <div class="km-stat teal">
          <div class="km-stat-icon"><?= icon('package', 17) ?></div>
          <div class="km-stat-val"><?= $nb_cmd ?></div>
          <div class="km-stat-label">Commandes passées</div>
        </div>
        <div class="km-stat teal">
          <div class="km-stat-icon"><?= icon('check', 17) ?></div>
          <div class="km-stat-val"><?= $nb_termine ?></div>
          <div class="km-stat-label">Prestations terminées</div>
        </div>
        <div class="km-stat ink">
          <div class="km-stat-icon"><?= icon('clock', 17) ?></div>
          <div class="km-stat-val"><?= $nb_attente ?></div>
          <div class="km-stat-label">En attente</div>
        </div>
      </div>

      <div class="km-two-col">
        <section class="km-panel">
          <div class="km-panel-head">
            <div><div class="km-panel-eyebrow">Votre activité</div><h3>Commandes récentes</h3></div>
            <a href="?tab=commandes" class="km-panel-link">Voir tout <?= icon('arrow-right', 13) ?></a>
          </div>
          <?php if (empty($recent_cmd)): ?>
            <div class="km-empty" style="padding:44px 20px">
              <div class="km-empty-icon"><?= icon('package', 19) ?></div>
              <p>Aucune commande pour le moment.<br><a href="?tab=catalogue" style="color:var(--amber-deep);font-weight:700">Parcourir le catalogue →</a></p>
            </div>
          <?php else: ?>
          <div class="km-activity-list">
            <?php foreach ($recent_cmd as $c):
              $isDone = $c['statut'] === 'Terminé'; $isPending = $c['statut'] === 'En attente';
              $badgeClass = $isDone ? 'km-badge-teal' : ($isPending ? 'km-badge-amber' : 'km-badge-neutral');
            ?>
            <div class="km-activity">
              <div class="km-activity-icon"><?= icon($isDone ? 'check' : 'package', 16) ?></div>
              <div class="km-activity-body">
                <strong><?= htmlspecialchars($c['titre_prestation']) ?></strong>
                <span><?= htmlspecialchars($c['prest_prenom'] . ' ' . $c['prest_nom']) ?> · <?= date('d/m, H:i', strtotime($c['date_commande'])) ?></span>
              </div>
              <span class="km-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span>
              <span class="km-activity-amount"><?= number_format($c['montant_total'], 0, ',', ' ') ?> F</span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="km-panel">
          <div class="km-panel-head"><div><div class="km-panel-eyebrow">Gagnez du temps</div><h3>Actions rapides</h3></div><span style="color:var(--amber-deep)"><?= icon('zap', 18) ?></span></div>
          <div class="km-quick-list">
            <a href="?tab=catalogue" class="km-quick-btn"><span class="qi"><?= icon('search', 15) ?></span><span class="ql"><strong>Parcourir les services</strong><small>Choisir un prestataire local</small></span><?= icon('chevron-right', 15) ?></a>
            <a href="wallet.php" class="km-quick-btn"><span class="qi"><?= icon('credit-card', 15) ?></span><span class="ql"><strong>Recharger mon wallet</strong><small>Garder l'esprit tranquille</small></span><?= icon('chevron-right', 15) ?></a>
            <a href="?tab=commandes" class="km-quick-btn"><span class="qi"><?= icon('package', 15) ?></span><span class="ql"><strong>Suivre mes commandes</strong><small><?= $nb_attente ?> commande<?= $nb_attente > 1 ? 's' : '' ?> en attente</small></span><?= icon('chevron-right', 15) ?></a>
          </div>
        </section>
      </div>

      <div class="km-trust">
        <div class="km-trust-item"><span class="km-trust-icon"><?= icon('check', 16) ?></span><div><strong>Prestataires vérifiés</strong><span>Choisis avec soin par notre équipe</span></div></div>
        <div class="km-trust-item"><span class="km-trust-icon"><?= icon('zap', 16) ?></span><div><strong>Réponse rapide</strong><span>Une prise en charge sans attendre</span></div></div>
        <div class="km-trust-item"><span class="km-trust-icon"><?= icon('help', 16) ?></span><div><strong>On reste disponibles</strong><span>Une équipe à votre écoute</span></div></div>
        <span class="km-trust-help">Besoin d'aide ? <?= icon('arrow-right', 13) ?></span>
      </div>

      <!-- ════════ CATALOGUE ════════ -->
      <?php elseif ($onglet === 'catalogue'): ?>

      <div class="km-cat-head">
        <div>
          <div class="km-eyebrow">KOUDMAIN / CATALOGUE</div>
          <h1 style="margin-top:9px;font-size:clamp(26px,3vw,36px)">Les services près de vous</h1>
          <p style="margin-top:8px;color:var(--ink-soft);font-size:13.5px">Choisissez une prestation et commandez en quelques clics.</p>
        </div>
        <div class="km-cat-count"><?= $total_prest ?> service<?= $total_prest > 1 ? 's' : '' ?> disponible<?= $total_prest > 1 ? 's' : '' ?></div>
      </div>

      <div class="km-pills">
        <a href="?tab=catalogue<?= $search ? '&q='.urlencode($search) : '' ?>" class="km-pill <?= $cat_filter === 0 ? 'active' : '' ?>">Toutes</a>
        <?php foreach ($categories as $cat): ?>
          <a href="?tab=catalogue&cat=<?= $cat['id_categorie'] ?><?= $search ? '&q='.urlencode($search) : '' ?>"
             class="km-pill <?= $cat_filter === (int)$cat['id_categorie'] ? 'active' : '' ?>"><?= htmlspecialchars($cat['nom_categorie']) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($prestations)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><?= icon('search', 20) ?></div>
          <p>Aucune prestation trouvée<?= $search ? ' pour « '.htmlspecialchars($search).' »' : '' ?>.</p>
        </div>
      <?php else: ?>
      <div class="km-prest-grid">
        <?php foreach ($prestations as $p):
          $teinte = teinteCategorie((int)$p['id_categorie']);
          $noteAffichee = $p['nb_avis'] > 0 ? number_format((float)$p['note_moy'], 1) : '—';
        ?>
        <div class="km-prest-card">
          <div class="km-prest-thumb km-thumb-<?= $teinte ?>">
            <div class="km-prest-thumb-icon"><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?= iconeCategorie($p['nom_categorie']) ?></svg></div>
            <span class="km-prest-thumb-tag"><?= htmlspecialchars($p['nom_categorie']) ?></span>
          </div>
          <div class="km-prest-body">
            <span class="km-prest-service"><?= htmlspecialchars($p['nom_service']) ?></span>
            <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
            <p class="km-prest-desc"><?= htmlspecialchars($p['description_prestation'] ?: 'Aucune description fournie.') ?></p>
            <div class="km-prest-by">
              <div class="km-prest-person">
                <span class="km-prest-avatar"><?= strtoupper(mb_substr($p['prenom_utilisateur'],0,1).mb_substr($p['nom_utilisateur'],0,1)) ?></span>
                <span><?= htmlspecialchars($p['prenom_utilisateur'] . ' ' . $p['nom_utilisateur']) ?></span>
              </div>
              <span class="km-prest-rating">★ <?= $noteAffichee ?> <small>(<?= (int)$p['nb_avis'] ?>)</small></span>
            </div>
            <div class="km-prest-foot">
              <span class="km-price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> <small>FCFA</small></span>
              <button type="button" class="km-btn-cmd"
                      onclick="openOrderModal(<?= (int)$p['id_prestation'] ?>, '<?= htmlspecialchars(addslashes($p['titre_prestation'])) ?>', <?= (float)$p['prix_prestation'] ?>)">
                Commander <?= icon('arrow-right', 13) ?>
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($nb_pages_prest > 1): ?>
      <div class="km-pagination">
        <?php for ($i = 1; $i <= $nb_pages_prest; $i++): ?>
          <a href="?tab=catalogue&p=<?= $i ?><?= $cat_filter ? '&cat='.$cat_filter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>"
             class="km-page-link <?= $i === $page_prest ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; endif; ?>

      <!-- ════════ COMMANDES ════════ -->
      <?php elseif ($onglet === 'commandes'): ?>

      <div class="km-cat-head">
        <div>
          <div class="km-eyebrow">KOUDMAIN / SUIVI</div>
          <h1 style="margin-top:9px;font-size:clamp(26px,3vw,36px)">Mes commandes</h1>
          <p style="margin-top:8px;color:var(--ink-soft);font-size:13.5px">Suivez l'état de vos demandes de service.</p>
        </div>
        <div class="km-cat-count"><?= $nb_cmd ?> commande<?= $nb_cmd > 1 ? 's' : '' ?> au total</div>
      </div>

      <?php if (empty($commandes)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><?= icon('package', 20) ?></div>
          <p>Vous n'avez pas encore passé de commande.</p>
          <a href="?tab=catalogue" class="km-btn km-btn-dark" style="margin-top:18px">Parcourir le catalogue</a>
        </div>
      <?php else: ?>
      <?php foreach ($commandes as $c):
        $badgeClass = match($c['statut']) { 'Terminé' => 'km-badge-teal', 'Acceptée' => 'km-badge-amber', default => 'km-badge-neutral' };
      ?>
      <div class="km-order-card">
        <div class="km-order-head">
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span class="km-order-id">#<?= $c['id_commande'] ?></span>
            <span class="km-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span>
            <span class="km-order-date"><?= date('d/m/Y, H:i', strtotime($c['date_commande'])) ?></span>
          </div>
          <span class="km-order-total"><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</span>
        </div>
        <div class="km-order-body">
          <div class="km-order-detail"><div class="km-order-label">Prestation</div><div class="km-order-val"><?= htmlspecialchars($c['titre_prestation']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Prestataire</div><div class="km-order-val"><?= htmlspecialchars($c['prest_prenom'] . ' ' . $c['prest_nom']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Téléphone</div><div class="km-order-val"><?= htmlspecialchars($c['prest_tel']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Lieu d'intervention</div><div class="km-order-val"><?= htmlspecialchars($c['nom_quartier']) ?></div></div>
        </div>
        <div class="km-order-foot">
          <?php if ($c['statut'] === 'Terminé' && $c['evaluation'] === null): ?>
            <form method="POST" class="km-rate-form" style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;width:100%">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="noter">
              <input type="hidden" name="id_commande" value="<?= $c['id_commande'] ?>">
              <input type="hidden" name="id_prestation" value="<?= $c['id_prestation'] ?>">
              <input type="hidden" name="evaluation" value="" required class="km-rate-value">
              <span style="font-size:12.5px;color:var(--ink-soft)">Votre note :</span>
              <div class="km-stars" role="radiogroup" aria-label="Note de 1 à 5">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <button type="button" class="km-star" data-value="<?= $n ?>" aria-label="<?= $n ?> étoile<?= $n > 1 ? 's' : '' ?>" role="radio" aria-checked="false">
                  <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                </button>
                <?php endfor; ?>
              </div>
              <input type="text" name="commentaire" placeholder="Commentaire (optionnel)" class="km-rate-input">
              <button type="submit" class="km-btn km-btn-dark" style="height:38px;padding:0 16px">Envoyer</button>
            </form>
          <?php elseif ($c['evaluation'] !== null): ?>
            <span style="font-size:13px;color:var(--ink-soft);display:inline-flex;align-items:center;gap:.5rem">
              Votre note :
              <span class="km-stars km-stars-readonly" aria-label="<?= (int)$c['evaluation'] ?> sur 5">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <span class="km-star <?= $n <= (int)$c['evaluation'] ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                <?php endfor; ?>
              </span>
              <?php if ($c['commentaire']): ?> — <em><?= htmlspecialchars(mb_substr($c['commentaire'], 0, 60)) ?></em><?php endif; ?>
            </span>
          <?php else: ?>
            <span style="font-size:13px;color:var(--ink-soft)">
              <?= $c['statut'] === 'En attente' ? 'En attente de confirmation du prestataire…' : 'Votre prestation est en cours.' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($nb_pages_cmd > 1): ?>
      <div class="km-pagination">
        <?php for ($i = 1; $i <= $nb_pages_cmd; $i++): ?>
          <a href="?tab=commandes&p_cmd=<?= $i ?>" class="km-page-link <?= $i === $page_cmd ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Commander -->
<div class="km-modal-overlay" id="modal-order">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Commander une prestation</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-order').classList.remove('open')" aria-label="Fermer"><?= icon('x', 16) ?></button>
    </div>
    <form method="POST" action="?tab=catalogue">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="commander">
      <input type="hidden" name="id_prestation" id="order-id-prest" value="">
      <div class="km-mfield"><label>Prestation</label><input type="text" id="order-titre" readonly></div>
      <div class="km-mfield"><label>Prix unitaire</label><input type="text" id="order-prix" readonly></div>
      <div class="km-mfield"><label>Quantité</label><input type="number" name="quantite" value="1" min="1" max="20" required></div>
      <div class="km-mfield">
        <label>Lieu d'intervention</label>
        <select name="id_quartier">
          <option value="0">— Mon quartier par défaut —</option>
          <?php foreach ($quartiers as $q): ?>
            <option value="<?= $q['id_quartier'] ?>"><?= htmlspecialchars($q['nom_ville'] . ' · ' . $q['nom_quartier']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="km-btn km-btn-dark km-btn-block">Confirmer la commande</button>
    </form>
  </div>
</div>

<script>
function openOrderModal(id, titre, prix) {
  document.getElementById('order-id-prest').value = id;
  document.getElementById('order-titre').value = titre;
  document.getElementById('order-prix').value = new Intl.NumberFormat('fr-FR').format(prix) + ' FCFA';
  document.getElementById('modal-order').classList.add('open');
}
document.getElementById('modal-order').addEventListener('mousedown', function(e){ if (e.target === this) this.classList.remove('open'); });
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') {
    document.querySelectorAll('.km-modal-overlay.open').forEach(function(m){ m.classList.remove('open'); });
    document.getElementById('km-sidebar').classList.remove('open');
    document.getElementById('sb-backdrop').style.display = 'none';
  }
});
document.addEventListener('click', function(e){
  var sb = document.getElementById('km-sidebar');
  if (window.innerWidth <= 860 && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.km-menu-toggle')) {
    sb.classList.remove('open');
    document.getElementById('sb-backdrop').style.display = 'none';
  }
});

// Notation par étoiles
document.querySelectorAll('.km-rate-form').forEach(function (form) {
  const stars = form.querySelectorAll('.km-star');
  const hidden = form.querySelector('.km-rate-value');
  function paint(val, hover) {
    stars.forEach(function (s) {
      const v = +s.dataset.value;
      s.classList.toggle('is-active', v <= val && !hover);
      s.classList.toggle('is-hover', hover && v <= hover);
      s.setAttribute('aria-checked', v === val ? 'true' : 'false');
    });
  }
  stars.forEach(function (star) {
    star.addEventListener('mouseenter', function () { paint(+hidden.value || 0, +star.dataset.value); });
    star.addEventListener('mouseleave', function () { paint(+hidden.value || 0, 0); });
    star.addEventListener('click', function () { hidden.value = star.dataset.value; paint(+star.dataset.value, 0); });
  });
  form.addEventListener('submit', function (e) {
    if (!hidden.value) { e.preventDefault(); alert('Choisissez une note de 1 à 5 étoiles.'); }
  });
});
</script>

</body>
</html>
