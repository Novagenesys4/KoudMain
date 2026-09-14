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
  --paper:#F5F4F0;--paper-deep:#EAE7DF;--surface:#FFFFFF;
  --ink:#1C1B17;--ink-soft:#6C675C;--ink-faint:#9B9788;--line:#DAD6CB;
  --amber:#B96B2A;--amber-deep:#8A4E1B;--amber-tint:#F1E3D2;
  --teal:#2E6B5E;--teal-tint:#E4EDE9;
  --danger:#A6412B;--danger-tint:#F3E2DC;
  --radius:14px;--radius-sm:8px;--sidebar-w:250px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15.5px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
h1,h2,h3,.km-serif{font-family:'Fraunces',serif}
@media(prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}

/* ── SIDEBAR ── */
.km-app{display:flex;min-height:100vh}
.km-sidebar{
  width:var(--sidebar-w);background:var(--ink);color:#E8E4D8;
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease;
}
.km-sb-brand{padding:1.5rem 1.4rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.1)}
.km-sb-brand a{font-family:'Fraunces',serif;font-weight:600;font-size:1.3rem;color:#fff}
.km-sb-brand em{font-style:normal;color:var(--amber)}
.km-sb-nav{flex:1;padding:1.1rem .8rem;display:flex;flex-direction:column;gap:.15rem}
.km-sb-link{
  display:flex;align-items:center;gap:.8rem;padding:.68rem .85rem;border-radius:8px;
  font-size:.9rem;color:#B9B4A5;position:relative;transition:background .18s ease,color .18s ease;
}
.km-sb-link:hover{background:rgba(255,255,255,.06);color:#fff}
.km-sb-link.active{background:rgba(185,107,42,.18);color:#F0DFC7;font-weight:600}
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

/* ── MAIN ── */
.km-main{flex:1;margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.km-topbar{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 1.8rem;background:var(--paper);border-bottom:1px solid var(--line);
  position:sticky;top:0;z-index:50;
}
.km-menu-toggle{display:none;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--ink)}
.km-topbar-search{flex:1;max-width:380px;position:relative}
.km-topbar-search input{
  width:100%;padding:.55rem 1rem .55rem 2.3rem;background:var(--surface);
  border:1px solid var(--line);border-radius:20px;font-size:.86rem;color:var(--ink);outline:none;
  transition:border-color .18s ease;
}
.km-topbar-search input:focus{border-color:var(--ink)}
.km-topbar-search .s-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.82rem;opacity:.5}
.km-avatar{
  width:36px;height:36px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.92rem;
}
.km-topbar-user{display:flex;align-items:center;gap:.6rem}
.km-topbar-user-name{font-size:.86rem;font-weight:600}
.km-topbar-user-role{font-size:.72rem;color:var(--ink-soft)}

.km-content{padding:1.8rem 1.8rem 3rem;flex:1}

/* ── Alerts ── */
.km-alert{padding:.75rem 1rem;border-left:2px solid;font-size:.86rem;margin-bottom:1.2rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

/* ── Header ── */
.km-page-head{margin-bottom:1.6rem}
.km-page-head h1{font-size:1.7rem;font-weight:600;letter-spacing:-.01em}
.km-page-head p{color:var(--ink-soft);margin-top:.3rem;font-size:.94rem}

/* ── Stat tiles ── */
.km-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.8rem}
.km-stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1.15rem 1.25rem}
.km-stat-val{font-family:'Fraunces',serif;font-size:1.55rem;font-weight:600;color:var(--ink)}
.km-stat-label{font-size:.78rem;color:var(--ink-soft);margin-top:.25rem}

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

.km-empty{text-align:center;padding:3.5rem 1rem;color:var(--ink-soft)}
.km-empty-icon{width:44px;height:44px;margin:0 auto 1rem;opacity:.4}
.km-empty-icon svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.3}

/* ── Pagination ── */
.km-pagination{display:flex;justify-content:center;gap:.4rem;margin-top:1.6rem;flex-wrap:wrap}
.km-page-link{
  padding:.4rem .8rem;border-radius:8px;font-size:.83rem;font-weight:600;
  border:1px solid var(--line);color:var(--ink-soft);
}
.km-page-link.active{background:var(--ink);color:var(--paper);border-color:var(--ink)}

/* ── Modal ── */
.km-modal-overlay{
  display:none;position:fixed;inset:0;background:rgba(28,27,23,.55);z-index:200;
  align-items:center;justify-content:center;padding:1rem;
}
.km-modal-overlay.open{display:flex}
.km-modal{
  background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;width:100%;max-width:440px;
  max-height:90vh;overflow-y:auto;border:1px solid var(--line);
}
.km-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.3rem;padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.km-modal-head h3{font-size:1.1rem;font-weight:600}
.km-modal-close{background:var(--paper);border:1px solid var(--line);width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:.9rem;color:var(--ink-soft)}
.km-mfield{margin-bottom:1rem}
.km-mfield label{display:block;font-size:.75rem;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem}
.km-mfield input,.km-mfield select{
  width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:8px;background:var(--paper);
  font-family:'Inter',sans-serif;font-size:.9rem;color:var(--ink);outline:none;
}
.km-mfield input[readonly]{color:var(--ink-soft)}

a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:1080px){.km-stats{grid-template-columns:repeat(2,1fr)}.km-two-col{grid-template-columns:1fr}}
@media(max-width:860px){
  .km-sidebar{transform:translateX(-100%)}
  .km-sidebar.open{transform:translateX(0)}
  .km-main{margin-left:0}
  .km-menu-toggle{display:block}
}
@media(max-width:560px){
  .km-stats{grid-template-columns:1fr 1fr}
  .km-content{padding:1.2rem 1rem 2.5rem}
  .km-topbar{padding:.8rem 1rem}
  .km-order-body{grid-template-columns:1fr}
  .km-order-detail{border-right:none;border-bottom:1px solid var(--line)}
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

<div class="km-app">

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand"><a href="index.php">Koud<em>Main</em></a></div>

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
      <button class="km-menu-toggle" onclick="document.getElementById('km-sidebar').classList.toggle('open')">☰</button>
      <form class="km-topbar-search" method="GET" action="">
        <input type="hidden" name="tab" value="catalogue">
        <span class="s-icon">⌕</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher un service…">
      </form>
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
        <div class="km-stat"><div class="km-stat-val"><?= number_format($solde_cli, 0, ',', ' ') ?></div><div class="km-stat-label">Solde wallet (FCFA)</div></div>
        <div class="km-stat"><div class="km-stat-val"><?= $nb_cmd ?></div><div class="km-stat-label">Commandes passées</div></div>
        <div class="km-stat"><div class="km-stat-val"><?= $nb_termine ?></div><div class="km-stat-label">Terminées</div></div>
        <div class="km-stat"><div class="km-stat-val"><?= $nb_attente ?></div><div class="km-stat-label">En attente</div></div>
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
            <form method="POST" style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;width:100%">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="noter">
              <input type="hidden" name="id_commande" value="<?= $c['id_commande'] ?>">
              <input type="hidden" name="id_prestation" value="<?= $c['id_prestation'] ?>">
              <span style="font-size:.83rem;color:var(--ink-soft)">Noter :</span>
              <select name="evaluation" required class="km-rate-select">
                <option value="">★</option>
                <?php for ($n = 5; $n >= 1; $n--): ?><option value="<?= $n ?>"><?= $n ?> ★</option><?php endfor; ?>
              </select>
              <input type="text" name="commentaire" placeholder="Commentaire (optionnel)" class="km-rate-input">
              <button type="submit" class="km-btn km-btn-primary km-btn-sm">Envoyer</button>
            </form>
          <?php elseif ($c['evaluation'] !== null): ?>
            <span style="font-size:.86rem;color:var(--ink-soft)">
              Votre note : <span style="color:var(--amber-deep)"><?= str_repeat('★', (int)$c['evaluation']) . str_repeat('☆', 5 - (int)$c['evaluation']) ?></span>
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