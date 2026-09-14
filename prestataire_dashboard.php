<?php
require_once "config.php";
requireConnexion();

if (estAdmin()) rediriger("admin_dashboard.php");
if (!estPrestataire()) rediriger("client_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

$wallet_prest = getOuCreerWallet($pdo, $idUser);
$solde_prest  = (float)$wallet_prest['solde'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    if ($_POST['action'] === 'creer_prestation') {
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $prix        = (float)str_replace(',', '.', $_POST['prix'] ?? 0);
        $id_service  = (int)$_POST['id_service'];

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
    } elseif ($_POST['action'] === 'supprimer_prestation') {
        $id = (int)$_POST['id_prestation'];
        $del = $pdo->prepare("DELETE FROM Prestation WHERE id_prestation = ? AND id_utilisateur = ?");
        $del->execute([$id, $idUser]);
        $msg = "Prestation supprimée.";
    } elseif ($_POST['action'] === 'changer_statut') {
        $id_cmd = (int)$_POST['id_commande'];
        $statut = $_POST['statut'];
        if (in_array($statut, ['Acceptée', 'En attente', 'Terminé'])) {
            $upd = $pdo->prepare("
                UPDATE Commande cm
                SET statut = ?
                WHERE cm.id_commande = ?
                  AND EXISTS (
                      SELECT 1 FROM Cibler ci
                      JOIN Prestation p ON ci.id_prestation = p.id_prestation
                      WHERE ci.id_commande = cm.id_commande
                        AND p.id_utilisateur = ?
                  )
            ");
            $upd->execute([$statut, $id_cmd, $idUser]);
            $msg = "Statut mis à jour.";
        }
    }
}

$onglet = $_GET['tab'] ?? 'overview';

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
    SELECT COALESCE(ROUND(AVG(ci.evaluation), 1), 0)
    FROM Cibler ci JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND ci.evaluation IS NOT NULL
");
$st->execute([$idUser]); $note_moy = $st->fetchColumn();

$st = $pdo->prepare("
    SELECT COALESCE(SUM(cm.montant_total), 0) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND cm.statut = 'Terminé'
");
$st->execute([$idUser]); $revenus_total = (float)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT COUNT(DISTINCT cm.id_commande) FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND cm.statut = 'En attente'
");
$st->execute([$idUser]); $nb_attente = (int)$st->fetchColumn();

$limit_prest_p = 10;
$page_prest_p  = max(1, (int)($_GET['p_prest'] ?? 1));
$offset_prest_p = ($page_prest_p - 1) * $limit_prest_p;
$nb_pages_prest_p = max(1, (int)ceil($nb_prest / $limit_prest_p));

$stmt_p = $pdo->prepare("
    SELECT p.*, s.nom_service, c.nom_categorie,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy, COUNT(ci.evaluation) AS nb_avis
    FROM Prestation p
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation
    WHERE p.id_utilisateur = ?
    GROUP BY p.id_prestation, s.nom_service, c.nom_categorie
    ORDER BY p.datecrea_prestation DESC
    LIMIT $limit_prest_p OFFSET $offset_prest_p
");
$stmt_p->execute([$idUser]);
$prestations = $stmt_p->fetchAll();

$limit_cmd_p = 10;
$page_cmd_p  = max(1, (int)($_GET['p_cmd'] ?? 1));
$offset_cmd_p = ($page_cmd_p - 1) * $limit_cmd_p;
$nb_pages_cmd_p = max(1, (int)ceil($nb_cmd / $limit_cmd_p));

$stmt_c = $pdo->prepare("
    SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite, ci.evaluation, ci.commentaire,
           p.titre_prestation, u.nom_utilisateur, u.prenom_utilisateur, u.num_utilisateur, q.nom_quartier
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    JOIN Quartier q ON cm.id_quartier = q.id_quartier
    WHERE p.id_utilisateur = ?
    ORDER BY cm.date_commande DESC
    LIMIT $limit_cmd_p OFFSET $offset_cmd_p
");
$stmt_c->execute([$idUser]);
$commandes = $stmt_c->fetchAll();
$recent_cmd = array_slice($commandes, 0, 5);

$services = $pdo->query("
    SELECT s.id_service, s.nom_service, c.nom_categorie
    FROM Service s JOIN Categorie c ON s.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();

$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Prestataire');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Espace prestataire — KoudMain</title>
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
  background:transparent;cursor:pointer;width:100%;text-align:left;color:var(--ink);font-family:inherit;
}
.km-quick-btn:hover{border-color:var(--amber-deep);background:var(--amber-tint)}

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
.km-prest-foot{display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap}
.km-price{font-family:'Fraunces',serif;font-weight:600;color:var(--amber-deep);font-size:1.1rem}
.km-stars{font-size:.8rem;color:var(--amber-deep)}

/* ── Buttons ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.6rem 1.05rem;border-radius:8px;border:1px solid transparent;font-weight:600;
  font-size:.85rem;cursor:pointer;transition:transform .16s ease,background .16s ease,box-shadow .16s ease;
  font-family:'Inter',sans-serif;
}
.km-btn:active{transform:scale(.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep)}
.km-btn-outline{background:transparent;border-color:var(--line);color:var(--ink)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-danger{background:var(--danger-tint);color:var(--danger);border-color:transparent}
.km-btn-danger:hover{background:var(--danger);color:#fff}
.km-btn-teal{background:var(--teal-tint);color:var(--teal)}
.km-btn-teal:hover{background:var(--teal);color:#fff}
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
.km-order-avis{padding:.7rem 1.2rem;border-top:1px solid var(--line);font-size:.85rem;color:var(--ink-soft)}

.km-badge{display:inline-flex;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700}
.km-badge-teal{background:var(--teal-tint);color:var(--teal)}
.km-badge-amber{background:var(--amber-tint);color:var(--amber-deep)}
.km-badge-neutral{background:var(--paper-deep);color:var(--ink-soft)}

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
  background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;width:100%;max-width:460px;
  max-height:90vh;overflow-y:auto;border:1px solid var(--line);
}
.km-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.3rem;padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.km-modal-head h3{font-size:1.1rem;font-weight:600}
.km-modal-close{background:var(--paper);border:1px solid var(--line);width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:.9rem;color:var(--ink-soft)}
.km-mfield{margin-bottom:1rem}
.km-mfield label{display:block;font-size:.75rem;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem}
.km-mfield input,.km-mfield select,.km-mfield textarea{
  width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:8px;background:var(--paper);
  font-family:'Inter',sans-serif;font-size:.9rem;color:var(--ink);outline:none;
}
.km-mfield textarea{min-height:90px;resize:vertical}

a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

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

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle" onclick="document.getElementById('km-sidebar').classList.toggle('open')" aria-label="Menu">☰</button>
      <div style="flex:1"></div>
      <div class="km-topbar-user">
        <div class="km-avatar"><?= strtoupper(substr($prenom, 0, 1)) ?></div>
        <div>
          <div class="km-topbar-user-name"><?= $prenom ?></div>
          <div class="km-topbar-user-role">Prestataire</div>
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
        <p>Gérez vos offres, suivez les demandes clients et vos encaissements.</p>
      </div>

      <div class="km-stats">
        <div class="km-stat">
          <div class="km-stat-val"><?= number_format($solde_prest, 0, ',', ' ') ?></div>
          <div class="km-stat-label">Solde wallet (FCFA)</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val"><?= number_format($revenus_total, 0, ',', ' ') ?></div>
          <div class="km-stat-label">Revenus terminés</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val"><?= $nb_prest ?></div>
          <div class="km-stat-label">Prestations actives</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val"><?= $note_moy ?><span style="font-size:.85rem;color:var(--ink-soft);font-weight:500"> / 5</span></div>
          <div class="km-stat-label">Note moyenne</div>
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
                Aucune commande pour l'instant.<br>
                <button type="button" class="km-btn km-btn-primary km-btn-sm" style="margin-top:.8rem" onclick="document.getElementById('modal-prest').classList.add('open')">Publier une prestation</button>
              </div>
            <?php else: foreach ($recent_cmd as $c):
              $isDone = $c['statut'] === 'Terminé'; $isPending = $c['statut'] === 'En attente';
            ?>
            <div class="km-activity">
              <div class="km-activity-title" style="flex:1">
                <?= htmlspecialchars($c['titre_prestation']) ?>
                <div class="km-activity-meta"><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?> · <?= date('d/m H:i', strtotime($c['date_commande'])) ?></div>
              </div>
              <span class="km-badge <?= $isDone ? 'km-badge-teal' : ($isPending ? 'km-badge-amber' : 'km-badge-neutral') ?>"><?= htmlspecialchars($c['statut']) ?></span>
              <span class="km-activity-amount"><?= number_format($c['montant_total'], 0, ',', ' ') ?> F</span>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="km-panel">
          <div class="km-panel-head"><h3>Actions rapides</h3></div>
          <div class="km-quick-list">
            <button type="button" class="km-quick-btn" onclick="document.getElementById('modal-prest').classList.add('open')">＋ Nouvelle prestation</button>
            <a href="?tab=commandes" class="km-quick-btn">📦 Voir les commandes<?= $nb_attente ? " ($nb_attente)" : '' ?></a>
            <a href="wallet.php" class="km-quick-btn">💳 Wallet &amp; retraits</a>
          </div>
        </div>
      </div>

      <!-- ════════ PRESTATIONS ════════ -->
      <?php elseif ($onglet === 'prestations'): ?>

      <div class="km-page-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
          <h1>Mes prestations</h1>
          <p>Publiez et gérez les services que vous proposez aux clients.</p>
        </div>
        <button type="button" class="km-btn km-btn-primary" onclick="document.getElementById('modal-prest').classList.add('open')">Nouvelle prestation</button>
      </div>

      <?php if (empty($prestations)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8M12 8v8"/></svg></div>
          <p>Aucune prestation publiée pour le moment.</p>
          <button type="button" class="km-btn km-btn-primary" style="margin-top:1rem" onclick="document.getElementById('modal-prest').classList.add('open')">Créer la première</button>
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
            <p class="km-prest-desc"><?= htmlspecialchars(mb_substr($p['description_prestation'] ?? '', 0, 120)) ?><?= mb_strlen($p['description_prestation'] ?? '') > 120 ? '…' : '' ?></p>
            <div class="km-prest-foot">
              <span class="km-price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> FCFA</span>
              <span class="km-stars"><?= $etoiles ?> <small style="color:var(--ink-faint)">(<?= (int)$p['nb_avis'] ?>)</small></span>
            </div>
            <form method="POST" style="margin-top:.3rem">
              <?= champCSRF() ?>
              <input type="hidden" name="action" value="supprimer_prestation">
              <input type="hidden" name="id_prestation" value="<?= (int)$p['id_prestation'] ?>">
              <button type="submit" class="km-btn km-btn-danger km-btn-sm" onclick="return confirm('Supprimer cette prestation ?')">Supprimer</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($nb_pages_prest_p > 1): ?>
        <div class="km-pagination">
          <?php for ($i = 1; $i <= $nb_pages_prest_p; $i++): ?>
            <a href="?tab=prestations&p_prest=<?= $i ?>" class="km-page-link <?= $i === $page_prest_p ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- ════════ COMMANDES ════════ -->
      <?php elseif ($onglet === 'commandes'): ?>

      <div class="km-page-head">
        <h1>Commandes reçues</h1>
        <p>Acceptez, suivez et finalisez les demandes de vos clients.</p>
      </div>

      <?php if (empty($commandes)): ?>
        <div class="km-empty">
          <div class="km-empty-icon"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="12" rx="1"/><path d="M8 7V4h8v3"/></svg></div>
          <p>Aucune commande reçue pour l'instant.</p>
        </div>
      <?php else: foreach ($commandes as $c):
        $isDone = $c['statut'] === 'Terminé';
        $isAccepted = $c['statut'] === 'Acceptée';
        $isPending = $c['statut'] === 'En attente';
        $badgeClass = $isDone ? 'km-badge-teal' : ($isPending ? 'km-badge-amber' : 'km-badge-neutral');
        $etoiles_avis = $c['evaluation'] !== null
          ? str_repeat('★', (int)$c['evaluation']) . str_repeat('☆', 5 - (int)$c['evaluation'])
          : null;
      ?>
        <div class="km-order-card">
          <div class="km-order-head">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
              <span class="km-order-id">#<?= (int)$c['id_commande'] ?></span>
              <span class="km-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span>
              <span class="km-order-date"><?= date('d/m/Y H:i', strtotime($c['date_commande'])) ?></span>
            </div>
            <span class="km-order-total"><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</span>
          </div>
          <div class="km-order-body">
            <div class="km-order-detail">
              <div class="km-order-label">Prestation</div>
              <div class="km-order-val"><?= htmlspecialchars($c['titre_prestation']) ?></div>
            </div>
            <div class="km-order-detail">
              <div class="km-order-label">Client</div>
              <div class="km-order-val"><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></div>
            </div>
            <div class="km-order-detail">
              <div class="km-order-label">Téléphone</div>
              <div class="km-order-val"><?= htmlspecialchars($c['num_utilisateur']) ?></div>
            </div>
            <div class="km-order-detail">
              <div class="km-order-label">Lieu</div>
              <div class="km-order-val"><?= htmlspecialchars($c['nom_quartier']) ?></div>
            </div>
          </div>
          <?php if ($etoiles_avis): ?>
          <div class="km-order-avis">
            Avis client : <span class="km-stars"><?= $etoiles_avis ?></span>
            <?php if ($c['commentaire']): ?> — <em><?= htmlspecialchars(mb_substr($c['commentaire'], 0, 100)) ?></em><?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="km-order-foot">
            <?php if ($isDone): ?>
              <span style="color:var(--teal);font-size:.88rem;font-weight:600">Terminée</span>
            <?php else: ?>
              <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <?= champCSRF() ?>
                <input type="hidden" name="action" value="changer_statut">
                <input type="hidden" name="id_commande" value="<?= (int)$c['id_commande'] ?>">
                <?php if ($isPending): ?>
                  <button type="submit" name="statut" value="Acceptée" class="km-btn km-btn-teal km-btn-sm">Accepter</button>
                  <button type="submit" name="statut" value="Terminé" class="km-btn km-btn-outline km-btn-sm" onclick="return confirm('Marquer comme terminé ?')">Terminer</button>
                <?php elseif ($isAccepted): ?>
                  <button type="submit" name="statut" value="Terminé" class="km-btn km-btn-primary km-btn-sm">Marquer terminé</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

        <?php if ($nb_pages_cmd_p > 1): ?>
        <div class="km-pagination">
          <?php for ($i = 1; $i <= $nb_pages_cmd_p; $i++): ?>
            <a href="?tab=commandes&p_cmd=<?= $i ?>" class="km-page-link <?= $i === $page_cmd_p ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal nouvelle prestation -->
<div class="km-modal-overlay" id="modal-prest">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Nouvelle prestation</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-prest').classList.remove('open')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="?tab=prestations">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="creer_prestation">
      <div class="km-mfield">
        <label for="titre">Titre</label>
        <input type="text" id="titre" name="titre" placeholder="Ex. Coiffure à domicile Cocody" required>
      </div>
      <div class="km-mfield">
        <label for="id_service">Service</label>
        <select id="id_service" name="id_service" required>
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
      </div>
      <div class="km-mfield">
        <label for="prix">Prix (FCFA)</label>
        <input type="number" id="prix" name="prix" min="0" step="100" placeholder="5000" required>
      </div>
      <div class="km-mfield">
        <label for="description">Description</label>
        <textarea id="description" name="description" placeholder="Spécialités, zone d'intervention, disponibilité…"></textarea>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block">Publier</button>
    </form>
  </div>
</div>

<script>
document.getElementById('modal-prest').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
document.addEventListener('click', function(e) {
  var s = document.getElementById('km-sidebar');
  if (window.innerWidth <= 860 && s.classList.contains('open') && !s.contains(e.target) && !e.target.classList.contains('km-menu-toggle')) {
    s.classList.remove('open');
  }
});
</script>
</body>
</html>
