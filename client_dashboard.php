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
                // Quartier par défaut = celui de l'utilisateur
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
            // Vérifier que la commande appartient au client et est terminée
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
$recent_cmd = array_slice($commandes, 0, 5);

// Quartiers pour formulaire
$quartiers = $pdo->query("
    SELECT q.id_quartier, q.nom_quartier, v.nom_ville
    FROM Quartier q JOIN Ville v ON q.id_ville = v.id_ville
    ORDER BY v.nom_ville, q.nom_quartier
")->fetchAll();

$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Client');

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon espace — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--paper-deep:#EBE8DF;--surface:#FFFEFA;
  --ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;
  --teal:#2E6B5E;--teal-tint:#E3EFEA;
  --danger:#A85245;--danger-tint:#F3E2DC;
  --radius:15px;--radius-sm:8px;--sidebar-w:250px;
  --shadow-sm:0 1px 2px rgba(28,27,23,.04);
  --shadow-md:0 8px 24px rgba(28,27,23,.07);
  --ease:cubic-bezier(.22,1,.36,1);
}
html.dark{
  --paper:#161512;--paper-deep:#1C1A16;--surface:#22201B;
  --ink:#F0EDE5;--ink-soft:#A8A398;--ink-faint:#7A766C;--line:#333029;
  --amber:#D4894A;--amber-deep:#E0A06A;--amber-tint:#3A2A1C;
  --teal:#5BA894;--teal-tint:#1E322C;
  --danger:#E07A68;--danger-tint:#3A221C;
  --shadow-sm:0 2px 8px rgba(0,0,0,.25);
  --shadow-md:0 12px 28px rgba(0,0,0,.35);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15.5px;line-height:1.6;-webkit-font-smoothing:antialiased;transition:background .3s var(--ease),color .3s var(--ease)}
a{color:inherit;text-decoration:none}
h1,h2,h3,.km-serif{font-family:'Fraunces',serif}
@media(prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}

/* ── SIDEBAR ── */
.km-app{display:flex;min-height:100vh}
.km-sidebar{
  width:var(--sidebar-w);background:#1C1B17;color:#E8E4D8;
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s var(--ease),width .3s var(--ease);
}
html.dark .km-sidebar{background:#0E0D0B;border-right:1px solid #2A2823}
.km-sidebar.collapsed{width:72px}
.km-sidebar.collapsed .km-sb-brand a span,
.km-sidebar.collapsed .km-sb-link span:not(.km-sb-icon),
.km-sidebar.collapsed .km-sb-badge,
.km-sidebar.collapsed .km-sb-section,
.km-sidebar.collapsed .km-wallet-mini .w-label,
.km-sidebar.collapsed .km-wallet-mini .w-solde{opacity:0;width:0;overflow:hidden;pointer-events:none}
.km-sidebar.collapsed .km-sb-link{justify-content:center;padding:.68rem}
.km-sidebar.collapsed .km-sb-brand{text-align:center;padding:1.2rem .5rem}
.km-sb-brand{padding:1.5rem 1.4rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.km-sb-brand a{font-family:'Fraunces',serif;font-weight:600;font-size:1.3rem;color:#fff;letter-spacing:-.02em}
.km-sb-brand em{font-style:normal;color:var(--amber)}
.km-sb-collapse{background:rgba(255,255,255,.06);border:0;color:#B9B4A5;width:28px;height:28px;border-radius:6px;cursor:pointer;display:grid;place-items:center;flex-shrink:0;transition:background .18s}
.km-sb-collapse:hover{background:rgba(255,255,255,.12);color:#fff}
.km-sb-nav{flex:1;padding:1.1rem .8rem;display:flex;flex-direction:column;gap:.15rem;overflow-y:auto}
.km-sb-link{
  display:flex;align-items:center;gap:.8rem;padding:.68rem .85rem;border-radius:8px;
  font-size:.9rem;color:#B9B4A5;position:relative;transition:background .18s ease,color .18s ease;
}
.km-sb-link:hover{background:rgba(255,255,255,.06);color:#fff}
.km-sb-link.active{background:rgba(185,107,42,.18);color:#F0DFC7;font-weight:600}
.km-sb-link.active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:2px;background:var(--amber);border-radius:0 2px 2px 0}
.km-sb-icon{width:17px;height:17px;flex-shrink:0}
.km-sb-icon svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.km-sb-badge{margin-left:auto;background:var(--amber);color:#1C1B17;font-size:.68rem;font-weight:700;padding:.1rem .45rem;border-radius:10px}
.km-sb-section{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#8B8778;padding:1rem .85rem .3rem}
.km-sb-foot{padding:1rem .9rem 1.3rem;border-top:1px solid rgba(255,255,255,.1)}
.km-wallet-mini{
  display:block;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);
  border-radius:12px;padding:.9rem 1rem;transition:border-color .18s ease,background .18s ease;
}
.km-wallet-mini:hover{border-color:var(--amber);background:rgba(185,107,42,.08)}
.km-wallet-mini .w-label{font-size:.7rem;color:#A8A398}
.km-wallet-mini .w-solde{font-family:'Fraunces',serif;font-weight:600;color:#fff;font-size:1.15rem;margin-top:.2rem}

/* ── MAIN ── */
.km-main{flex:1;margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s var(--ease)}
.km-sidebar.collapsed ~ .km-main{margin-left:72px}
.km-topbar{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 1.8rem;background:rgba(245,244,240,.88);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);
  position:sticky;top:0;z-index:50;transition:background .3s var(--ease);
}
html.dark .km-topbar{background:rgba(22,21,18,.9)}
.km-menu-toggle{display:none;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--ink)}
.km-topbar-search{flex:1;max-width:380px;position:relative}
.km-topbar-search input{
  width:100%;padding:.55rem 1rem .55rem 2.3rem;background:var(--surface);
  border:1px solid var(--line);border-radius:20px;font-size:.86rem;color:var(--ink);outline:none;
  transition:border-color .18s ease,box-shadow .18s ease;
}
.km-topbar-search input:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(185,107,42,.12)}
.km-topbar-search .s-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.82rem;opacity:.5}
.km-theme-toggle{
  width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:var(--surface);
  display:grid;place-items:center;cursor:pointer;color:var(--ink-soft);transition:all .2s var(--ease);
}
.km-theme-toggle:hover{border-color:var(--amber);color:var(--amber-deep);background:var(--amber-tint)}
.km-theme-toggle svg{width:18px;height:18px}
.km-avatar{
  width:36px;height:36px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.92rem;
}
.km-topbar-user{display:flex;align-items:center;gap:.6rem}
.km-topbar-user-name{font-size:.86rem;font-weight:600}
.km-topbar-user-role{font-size:.72rem;color:var(--ink-soft)}

.km-content{padding:1.8rem 1.8rem 3rem;flex:1}

/* ── Alerts ── */
.km-alert{padding:.75rem 1rem;border-left:2px solid;font-size:.86rem;margin-bottom:1.2rem;border-radius:0 8px 8px 0}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:var(--teal)}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:var(--danger)}

/* ── Header ── */
.km-page-head{margin-bottom:1.6rem}
.km-page-head h1{font-size:1.7rem;font-weight:600;letter-spacing:-.02em}
.km-page-head p{color:var(--ink-soft);margin-top:.3rem;font-size:.94rem}

/* ── Stat tiles ── */
.km-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.8rem}
.km-stat{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.15rem 1.25rem;position:relative;overflow:hidden;
  transition:transform .22s var(--ease),box-shadow .22s var(--ease),border-color .22s;
  box-shadow:var(--shadow-sm);
}
.km-stat:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:rgba(185,107,42,.25)}
.km-stat-icon{
  width:36px;height:36px;border-radius:10px;display:grid;place-items:center;margin-bottom:.75rem;
  background:var(--amber-tint);color:var(--amber-deep);
}
.km-stat-icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.6}
.km-stat-val{font-family:'Fraunces',serif;font-size:1.55rem;font-weight:600;color:var(--ink);letter-spacing:-.02em}
.km-stat-label{font-size:.78rem;color:var(--ink-soft);margin-top:.25rem}
.km-stat-trend{font-size:.72rem;font-weight:600;margin-top:.4rem;display:inline-flex;align-items:center;gap:.25rem}
.km-stat-trend.up{color:var(--teal)}.km-stat-trend.down{color:var(--danger)}

/* ── Panels ── */
.km-two-col{display:grid;grid-template-columns:1.4fr 1fr;gap:1.3rem}
.km-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.km-panel-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--line)}
.km-panel-head h3{font-size:.92rem;font-weight:600;font-family:'Inter',sans-serif}
.km-panel-head a{font-size:.8rem;color:var(--amber-deep);font-weight:600}
.km-panel-body{padding:.4rem 1.25rem}

.km-activity{display:flex;align-items:center;gap:.85rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
.km-activity:last-child{border-bottom:none}
.km-activity-title{font-weight:600;font-size:.88rem}
.km-activity-meta{font-size:.76rem;color:var(--ink-soft);margin-top:.1rem}
.km-activity-amount{font-weight:700;font-size:.9rem;margin-left:auto;white-space:nowrap;font-family:'Fraunces',serif}

.km-quick-list{display:flex;flex-direction:column;gap:.6rem;padding:1.1rem 1.25rem}
.km-quick-btn{
  display:flex;align-items:center;gap:.7rem;padding:.75rem .9rem;border:1px solid var(--line);
  border-radius:10px;font-size:.86rem;font-weight:600;transition:border-color .18s ease,background .18s ease;
}
.km-quick-btn:hover{border-color:var(--amber-deep);background:var(--amber-tint)}

/* ── Category pills ── */
.km-pills{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.4rem}
.km-pill{
  padding:.4rem .9rem;border-radius:20px;font-size:.82rem;font-weight:600;
  border:1px solid var(--line);color:var(--ink-soft);transition:all .18s ease;
}
.km-pill:hover{border-color:var(--ink)}
.km-pill.active{background:var(--ink);color:var(--paper);border-color:var(--ink)}

/* ── Prestation grid ── */
.km-prest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1.1rem}
.km-prest-card{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.2rem;display:flex;flex-direction:column;gap:.65rem;transition:border-color .18s ease,transform .18s ease;
}
.km-prest-card:hover{border-color:var(--amber-deep);transform:translateY(-2px)}
.km-tag{display:inline-flex;font-size:.7rem;font-weight:700;padding:.18rem .55rem;border-radius:20px}
.km-tag-cat{background:var(--teal-tint);color:var(--teal)}
.km-tag-service{background:var(--amber-tint);color:var(--amber-deep);margin-left:.3rem}
.km-prest-card h3{font-size:.98rem;font-weight:600;font-family:'Inter',sans-serif}
.km-prest-desc{color:var(--ink-soft);font-size:.84rem;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.km-prest-by{font-size:.78rem;color:var(--ink-faint)}
.km-prest-foot{display:flex;justify-content:space-between;align-items:center;gap:.5rem}
.km-price{font-family:'Fraunces',serif;font-weight:600;color:var(--amber-deep);font-size:1.1rem}

/* ── Buttons ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.6rem 1.05rem;border-radius:8px;border:1px solid transparent;font-weight:600;
  font-size:.85rem;cursor:pointer;transition:transform .16s ease,background .16s ease,box-shadow .16s ease;
}
.km-btn:active{transform:scale(.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep)}
.km-btn-outline{background:transparent;border-color:var(--line);color:var(--ink)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-block{width:100%}
.km-btn-sm{padding:.42rem .8rem;font-size:.78rem}

/* ── Orders ── */
.km-order-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:1rem}
.km-order-head{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.2rem;background:var(--paper-deep);flex-wrap:wrap;gap:.5rem}
.km-order-id{font-family:'Fraunces',serif;font-weight:600;font-size:.86rem;color:var(--ink-soft)}
.km-order-date{font-size:.76rem;color:var(--ink-soft)}
.km-order-total{font-family:'Fraunces',serif;font-weight:600;font-size:1.05rem}
.km-order-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}
.km-order-detail{padding:.85rem 1.2rem;border-right:1px solid var(--line)}
.km-order-detail:last-child{border-right:none}
.km-order-label{font-size:.68rem;font-weight:700;color:var(--ink-faint);text-transform:uppercase;letter-spacing:.05em}
.km-order-val{font-size:.88rem;margin-top:.15rem}
.km-order-foot{padding:.85rem 1.2rem;border-top:1px solid var(--line);display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}

.km-badge{display:inline-flex;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700}
.km-badge-teal{background:var(--teal-tint);color:var(--teal)}
.km-badge-amber{background:var(--amber-tint);color:var(--amber-deep)}
.km-badge-neutral{background:var(--paper-deep);color:var(--ink-soft)}

.km-rate-select,.km-rate-input{
  padding:.4rem .6rem;border-radius:8px;border:1px solid var(--line);background:var(--paper);
  font-family:'Inter',sans-serif;font-size:.84rem;color:var(--ink);
}
.km-rate-input{flex:1;min-width:120px}

/* ── Star rating ── */
.km-stars{display:inline-flex;gap:2px;align-items:center}
.km-star{
  width:28px;height:28px;border:0;background:transparent;cursor:pointer;padding:0;
  color:var(--line);transition:color .15s ease,transform .15s ease;
}
.km-star svg{width:100%;height:100%;fill:currentColor;stroke:none}
.km-star:hover,.km-star.is-hover,.km-star.is-active{color:var(--amber)}
.km-star:hover{transform:scale(1.12)}
.km-star:focus-visible{outline:2px solid var(--amber);outline-offset:2px;border-radius:4px}
.km-stars-readonly .km-star{cursor:default;width:16px;height:16px}
.km-stars-readonly .km-star.is-active{color:var(--amber)}

.km-empty{text-align:center;padding:3.5rem 1rem;color:var(--ink-soft)}
.km-empty-icon{width:44px;height:44px;margin:0 auto 1rem;opacity:.4}
.km-empty-icon svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.3}

/* ── Pagination ── */
.km-pagination{display:flex;justify-content:center;gap:.4rem;margin-top:1.6rem;flex-wrap:wrap}
.km-page-link{
  padding:.4rem .8rem;border-radius:8px;font-size:.83rem;font-weight:600;
  border:1px solid var(--line);color:var(--ink-soft);transition:all .18s;
}
.km-page-link:hover{border-color:var(--ink);color:var(--ink)}
.km-page-link.active{background:var(--ink);color:var(--paper);border-color:var(--ink)}

/* ── Modal ── */
.km-modal-overlay{
  display:none;position:fixed;inset:0;background:rgba(28,27,23,.55);backdrop-filter:blur(4px);z-index:200;
  align-items:center;justify-content:center;padding:1rem;
}
.km-modal-overlay.open{display:flex;animation:km-fade-in .2s var(--ease)}
@keyframes km-fade-in{from{opacity:0}to{opacity:1}}
.km-modal{
  background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;width:100%;max-width:440px;
  max-height:90vh;overflow-y:auto;border:1px solid var(--line);box-shadow:var(--shadow-md);
  animation:km-modal-in .28s var(--ease);
}
@keyframes km-modal-in{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.km-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.3rem;padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.km-modal-head h3{font-size:1.1rem;font-weight:600}
.km-modal-close{background:var(--paper);border:1px solid var(--line);width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:.9rem;color:var(--ink-soft);transition:background .15s}
.km-modal-close:hover{background:var(--paper-deep)}
.km-mfield{margin-bottom:1rem}
.km-mfield label{display:block;font-size:.75rem;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem}
.km-mfield input,.km-mfield select{
  width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:8px;background:var(--paper);
  font-family:'Inter',sans-serif;font-size:.9rem;color:var(--ink);outline:none;transition:border-color .18s;
}
.km-mfield input:focus,.km-mfield select:focus{border-color:var(--amber)}
.km-mfield input[readonly]{color:var(--ink-soft)}

a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:1080px){.km-stats{grid-template-columns:repeat(2,1fr)}.km-two-col{grid-template-columns:1fr}}
@media(max-width:860px){
  .km-sidebar{transform:translateX(-100%)}
  .km-sidebar.open{transform:translateX(0)}
  .km-sidebar.collapsed{width:var(--sidebar-w)}
  .km-main{margin-left:0!important}
  .km-menu-toggle{display:block}
  .km-sb-collapse{display:none}
}
@media(max-width:560px){
  .km-stats{grid-template-columns:1fr 1fr}
  .km-content{padding:1.2rem 1rem 2.5rem}
  .km-topbar{padding:.8rem 1rem}
  .km-order-body{grid-template-columns:1fr}
  .km-order-detail{border-right:none;border-bottom:1px solid var(--line)}
  .km-topbar-search{display:none}
}
</style>
</head>
<body>

<div class="km-app">

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand">
      <a href="index.php">Koud<em>Main</em></a>
      <button type="button" class="km-sb-collapse" id="km-sb-collapse" aria-label="Réduire le menu" title="Réduire">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 4l-6 6 6 6"/></svg>
      </button>
    </div>

    <nav class="km-sb-nav">
      <a href="?tab=overview" class="km-sb-link <?= $onglet === 'overview' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Vue d'ensemble
      </a>
      <a href="?tab=catalogue" class="km-sb-link <?= $onglet === 'catalogue' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M17 17l-4-4"/></svg></span>
        Catalogue
        <?php if ($total_prest > 0): ?><span class="km-sb-badge"><?= $total_prest ?></span><?php endif; ?>
      </a>
      <a href="?tab=commandes" class="km-sb-link <?= $onglet === 'commandes' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></span>
        Mes commandes
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
        <div class="w-solde"><?= number_format($solde_cli, 0, ',', ' ') ?> FCFA</div>
      </a>
    </div>
  </aside>

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle" onclick="document.getElementById('km-sidebar').classList.toggle('open')" aria-label="Menu">☰</button>
      <form class="km-topbar-search" method="GET" action="">
        <input type="hidden" name="tab" value="catalogue">
        <span class="s-icon">⌕</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher un service…">
      </form>
      <button type="button" class="km-theme-toggle" id="km-theme-toggle" aria-label="Changer de thème" title="Thème">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="display:none"><path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5Z"/></svg>
      </button>
      <div class="km-topbar-user">
        <div class="km-avatar"><?= strtoupper(substr($prenom, 0, 1)) ?></div>
        <div>
          <div class="km-topbar-user-name"><?= $prenom ?></div>
          <div class="km-topbar-user-role">Client</div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- ════════ OVERVIEW ════════ -->
      <?php if ($onglet === 'overview'): ?>

      <div class="km-page-head">
        <h1>Bonjour, <?= $prenom ?></h1>
        <p>Trouvez un prestataire près de chez vous — coiffure, plomberie, laverie, garde d'enfants…</p>
      </div>

      <div class="km-stats">
        <div class="km-stat">
          <div class="km-stat-icon"><svg viewBox="0 0 20 20"><rect x="2" y="5" width="16" height="11" rx="2"/><path d="M2 9h16"/></svg></div>
          <div class="km-stat-val"><?= number_format($solde_cli, 0, ',', ' ') ?></div>
          <div class="km-stat-label">Solde wallet (FCFA)</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-icon" style="background:var(--teal-tint);color:var(--teal)"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></div>
          <div class="km-stat-val"><?= $nb_cmd ?></div>
          <div class="km-stat-label">Commandes passées</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-icon" style="background:var(--teal-tint);color:var(--teal)"><svg viewBox="0 0 20 20"><path d="M4 11l4 4 8-9"/></svg></div>
          <div class="km-stat-val"><?= $nb_termine ?></div>
          <div class="km-stat-label">Terminées</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-icon"><svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 1.5"/></svg></div>
          <div class="km-stat-val"><?= $nb_attente ?></div>
          <div class="km-stat-label">En attente</div>
        </div>
      </div>

      <div class="km-two-col">
        <div class="km-panel">
          <div class="km-panel-head">
            <h3>Activité récente</h3>
            <a href="?tab=commandes">Voir tout →</a>
          </div>
          <div class="km-panel-body">
            <?php if (empty($recent_cmd)): ?>
              <div class="km-empty" style="padding:2rem 1rem">
                Aucune commande pour le moment.<br>
                <a href="?tab=catalogue" style="color:var(--amber-deep);font-weight:600">Parcourir le catalogue →</a>
              </div>
            <?php else: foreach ($recent_cmd as $c):
              $isDone = $c['statut'] === 'Terminé'; $isPending = $c['statut'] === 'En attente';
            ?>
            <div class="km-activity">
              <div class="km-activity-title" style="flex:1">
                <?= htmlspecialchars($c['titre_prestation']) ?>
                <div class="km-activity-meta"><?= htmlspecialchars($c['prest_prenom'] . ' ' . $c['prest_nom']) ?> · <?= date('d/m H:i', strtotime($c['date_commande'])) ?></div>
              </div>
              <span class="km-badge <?= $isDone ? 'km-badge-teal' : ($isPending ? 'km-badge-amber' : 'km-badge-neutral') ?>"><?= $c['statut'] ?></span>
              <span class="km-activity-amount"><?= number_format($c['montant_total'], 0, ',', ' ') ?> F</span>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="km-panel">
          <div class="km-panel-head"><h3>Actions rapides</h3></div>
          <div class="km-quick-list">
            <a href="?tab=catalogue" class="km-quick-btn">🔎 Parcourir les services</a>
            <a href="wallet.php" class="km-quick-btn">💳 Recharger mon wallet</a>
            <a href="?tab=commandes" class="km-quick-btn">📦 Suivre mes commandes</a>
          </div>
        </div>
      </div>

      <!-- ════════ CATALOGUE ════════ -->
      <?php elseif ($onglet === 'catalogue'): ?>

      <div class="km-page-head">
        <h1>Catalogue des services</h1>
        <p>Choisissez une prestation et commandez en quelques clics.</p>
      </div>

      <div class="km-pills">
        <a href="?tab=catalogue" class="km-pill <?= $cat_filter === 0 ? 'active' : '' ?>">Toutes</a>
        <?php foreach ($categories as $cat): ?>
          <a href="?tab=catalogue&cat=<?= $cat['id_categorie'] ?><?= $search ? '&q='.urlencode($search) : '' ?>"
             class="km-pill <?= $cat_filter === (int)$cat['id_categorie'] ? 'active' : '' ?>"><?= htmlspecialchars($cat['nom_categorie']) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($prestations)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><svg viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="M20 20l-5-5"/></svg></div>
          <p>Aucune prestation trouvée<?= $search ? ' pour « '.htmlspecialchars($search).' »' : '' ?>.</p>
        </div>
      <?php else: ?>
      <div class="km-prest-grid">
        <?php foreach ($prestations as $p):
          $etoiles = str_repeat('★', (int)round($p['note_moy'])) . str_repeat('☆', 5 - (int)round($p['note_moy']));
        ?>
        <div class="km-prest-card">
          <div>
            <span class="km-tag km-tag-cat"><?= htmlspecialchars($p['nom_categorie']) ?></span>
            <span class="km-tag km-tag-service"><?= htmlspecialchars($p['nom_service']) ?></span>
          </div>
          <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
          <p class="km-prest-desc"><?= htmlspecialchars($p['description_prestation'] ?: 'Aucune description.') ?></p>
          <div class="km-prest-by">
            Par <?= htmlspecialchars($p['prenom_utilisateur'] . ' ' . $p['nom_utilisateur']) ?>
            · <?= $etoiles ?> <span style="color:var(--ink-faint)">(<?= (int)$p['nb_avis'] ?>)</span>
          </div>
          <div class="km-prest-foot">
            <span class="km-price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> FCFA</span>
            <button class="km-btn km-btn-primary km-btn-sm"
                    onclick="openOrderModal(<?= (int)$p['id_prestation'] ?>, '<?= htmlspecialchars(addslashes($p['titre_prestation'])) ?>', <?= (float)$p['prix_prestation'] ?>)">
              Commander
            </button>
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

      <div class="km-page-head">
        <h1>Mes commandes</h1>
        <p>Suivez l'état de vos demandes de service.</p>
      </div>

      <?php if (empty($commandes)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="1"/><path d="M8 7V4h8v3"/></svg></div>
          <p>Vous n'avez pas encore passé de commande.</p>
          <a href="?tab=catalogue" class="km-btn km-btn-primary" style="margin-top:1rem">Parcourir le catalogue</a>
        </div>
      <?php else: ?>
      <?php foreach ($commandes as $c):
        $badgeClass = match($c['statut']) { 'Terminé' => 'km-badge-teal', 'Acceptée' => 'km-badge-amber', default => 'km-badge-neutral' };
      ?>
      <div class="km-order-card">
        <div class="km-order-head">
          <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
            <span class="km-order-id">#<?= $c['id_commande'] ?></span>
            <span class="km-badge <?= $badgeClass ?>"><?= $c['statut'] ?></span>
            <span class="km-order-date">📅 <?= date('d/m/Y à H:i', strtotime($c['date_commande'])) ?></span>
          </div>
          <span class="km-order-total"><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</span>
        </div>
        <div class="km-order-body">
          <div class="km-order-detail"><div class="km-order-label">Prestation</div><div class="km-order-val"><?= htmlspecialchars($c['titre_prestation']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Prestataire</div><div class="km-order-val"><?= htmlspecialchars($c['prest_prenom'] . ' ' . $c['prest_nom']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Téléphone</div><div class="km-order-val"><?= htmlspecialchars($c['prest_tel']) ?></div></div>
          <div class="km-order-detail"><div class="km-order-label">Lieu</div><div class="km-order-val"><?= htmlspecialchars($c['nom_quartier']) ?></div></div>
        </div>
        <div class="km-order-foot">
          <?php if ($c['statut'] === 'Terminé' && $c['evaluation'] === null): ?>
            <form method="POST" class="km-rate-form" style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;width:100%">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="noter">
              <input type="hidden" name="id_commande" value="<?= $c['id_commande'] ?>">
              <input type="hidden" name="id_prestation" value="<?= $c['id_prestation'] ?>">
              <input type="hidden" name="evaluation" value="" required class="km-rate-value">
              <span style="font-size:.83rem;color:var(--ink-soft)">Noter :</span>
              <div class="km-stars" role="radiogroup" aria-label="Note de 1 à 5">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <button type="button" class="km-star" data-value="<?= $n ?>" aria-label="<?= $n ?> étoile<?= $n > 1 ? 's' : '' ?>" role="radio" aria-checked="false">
                  <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                </button>
                <?php endfor; ?>
              </div>
              <input type="text" name="commentaire" placeholder="Commentaire (optionnel)" class="km-rate-input">
              <button type="submit" class="km-btn km-btn-primary km-btn-sm">Envoyer</button>
            </form>
          <?php elseif ($c['evaluation'] !== null): ?>
            <span style="font-size:.86rem;color:var(--ink-soft);display:inline-flex;align-items:center;gap:.5rem">
              Votre note :
              <span class="km-stars km-stars-readonly" aria-label="<?= (int)$c['evaluation'] ?> sur 5">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <span class="km-star <?= $n <= (int)$c['evaluation'] ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                <?php endfor; ?>
              </span>
              <?php if ($c['commentaire']): ?> — <em><?= htmlspecialchars(mb_substr($c['commentaire'], 0, 60)) ?></em><?php endif; ?>
            </span>
          <?php else: ?>
            <span style="font-size:.86rem;color:var(--ink-soft)">
              <?= $c['statut'] === 'En attente' ? 'En attente de confirmation du prestataire…' : 'Prestation en cours…' ?>
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
      <button class="km-modal-close" onclick="document.getElementById('modal-order').classList.remove('open')">✕</button>
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
      <button type="submit" class="km-btn km-btn-primary km-btn-block">Confirmer la commande</button>
    </form>
  </div>
</div>

<script>
(function(){
  // Dark mode
  const root = document.documentElement;
  const themeBtn = document.getElementById('km-theme-toggle');
  const saved = localStorage.getItem('km-theme');
  if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    root.classList.add('dark');
  }
  function syncThemeIcons() {
    if (!themeBtn) return;
    const isDark = root.classList.contains('dark');
    const sun = themeBtn.querySelector('.icon-sun');
    const moon = themeBtn.querySelector('.icon-moon');
    if (sun) sun.style.display = isDark ? 'none' : 'block';
    if (moon) moon.style.display = isDark ? 'block' : 'none';
  }
  syncThemeIcons();
  if (themeBtn) {
    themeBtn.addEventListener('click', function() {
      root.classList.toggle('dark');
      localStorage.setItem('km-theme', root.classList.contains('dark') ? 'dark' : 'light');
      syncThemeIcons();
    });
  }

  // Sidebar collapse
  const sidebar = document.getElementById('km-sidebar');
  const collapseBtn = document.getElementById('km-sb-collapse');
  if (localStorage.getItem('km-sidebar') === 'collapsed' && window.innerWidth > 860) {
    sidebar.classList.add('collapsed');
  }
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function() {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('km-sidebar', sidebar.classList.contains('collapsed') ? 'collapsed' : 'open');
    });
  }

  // Star rating
  document.querySelectorAll('.km-rate-form').forEach(function(form) {
    const stars = form.querySelectorAll('.km-star');
    const hidden = form.querySelector('.km-rate-value');
    function paint(val, hover) {
      stars.forEach(function(s) {
        const v = +s.dataset.value;
        s.classList.toggle('is-active', v <= val && !hover);
        s.classList.toggle('is-hover', hover && v <= hover);
        s.setAttribute('aria-checked', v === val ? 'true' : 'false');
      });
    }
    stars.forEach(function(star) {
      star.addEventListener('mouseenter', function() { paint(+hidden.value || 0, +star.dataset.value); });
      star.addEventListener('mouseleave', function() { paint(+hidden.value || 0, 0); });
      star.addEventListener('click', function() {
        hidden.value = star.dataset.value;
        paint(+star.dataset.value, 0);
      });
      star.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); star.click(); }
        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
          e.preventDefault();
          const next = star.nextElementSibling;
          if (next) next.focus();
        }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
          e.preventDefault();
          const prev = star.previousElementSibling;
          if (prev) prev.focus();
        }
      });
    });
    form.addEventListener('submit', function(e) {
      if (!hidden.value) { e.preventDefault(); alert('Choisissez une note de 1 à 5 étoiles.'); }
    });
  });
})();

function openOrderModal(id, titre, prix) {
  document.getElementById('order-id-prest').value = id;
  document.getElementById('order-titre').value = titre;
  document.getElementById('order-prix').value = new Intl.NumberFormat('fr-FR').format(prix) + ' FCFA';
  document.getElementById('modal-order').classList.add('open');
}
document.getElementById('modal-order').addEventListener('click', function(e){ if (e.target === this) this.classList.remove('open'); });
document.addEventListener('click', function(e){
  const sb = document.getElementById('km-sidebar');
  if (window.innerWidth <= 860 && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.classList.contains('km-menu-toggle')) {
    sb.classList.remove('open');
  }
});
</script>

</body>
</html>