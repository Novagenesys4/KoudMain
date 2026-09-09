<?php
require_once "config.php";
requireAdmin();

$pdo = getConnexion();
$msg = "";
$err = "";

// --- Actions admin ---
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

// --- Données ---
$onglet = $_GET['tab'] ?? 'tableau_bord';

$nb_users       = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_admin = false")->fetchColumn();
$nb_clients     = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_client = true AND est_admin = false")->fetchColumn();
$nb_prest       = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = true")->fetchColumn();
$nb_attente     = (int)$pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = true AND est_valide = false")->fetchColumn();
$nb_commandes   = (int)$pdo->query("SELECT COUNT(*) FROM Commande")->fetchColumn();
$nb_cats        = (int)$pdo->query("SELECT COUNT(*) FROM Categorie")->fetchColumn();
$nb_prestations = (int)$pdo->query("SELECT COUNT(*) FROM Prestation")->fetchColumn();

$prest_attente = $pdo->query("
    SELECT u.*, q.nom_quartier, v.nom_ville
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    JOIN Ville v ON q.id_ville = v.id_ville
    WHERE u.est_prestataire = 1 AND u.est_valide = 0
    ORDER BY u.datecrea_utilisateur DESC
")->fetchAll();

$limit_users    = 15;
$page_users     = max(1, (int)($_GET['p_u'] ?? 1));
$offset_users   = ($page_users - 1) * $limit_users;
$nb_pages_users = max(1, (int)ceil($nb_users / $limit_users));

$stmt_u = $pdo->prepare("
    SELECT u.*, q.nom_quartier
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    WHERE u.est_admin = false
    ORDER BY u.datecrea_utilisateur DESC
    LIMIT $limit_users OFFSET $offset_users
");
$stmt_u->execute();
$tous_users = $stmt_u->fetchAll();

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
    GROUP BY s.id_service
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();

$limit_cmd_adm    = 15;
$page_cmd_adm     = max(1, (int)($_GET['p_c'] ?? 1));
$offset_cmd_adm   = ($page_cmd_adm - 1) * $limit_cmd_adm;
$nb_pages_cmd_adm = max(1, (int)ceil($nb_commandes / $limit_cmd_adm));

$stmt_c = $pdo->prepare("
    SELECT cm.*, u.nom_utilisateur, u.prenom_utilisateur,
           ci.id_prestation, p.titre_prestation,
           pu.nom_utilisateur AS prest_nom
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

$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administration — KoudMain</title>
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
.km-sb-foot{padding:1rem .9rem 1.3rem;border-top:1px solid rgba(255,255,255,.1);font-size:.78rem;color:#8B8778}

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

.km-alert{padding:.75rem 1rem;border-left:2px solid;font-size:.86rem;margin-bottom:1.2rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

.km-page-head{margin-bottom:1.6rem}
.km-page-head h1{font-size:1.7rem;font-weight:600;letter-spacing:-.01em}
.km-page-head p{color:var(--ink-soft);margin-top:.3rem;font-size:.94rem}

.km-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.8rem}
.km-stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1.15rem 1.25rem}
.km-stat-val{font-family:'Fraunces',serif;font-size:1.55rem;font-weight:600;color:var(--ink)}
.km-stat-label{font-size:.78rem;color:var(--ink-soft);margin-top:.25rem}
.km-stat-val.warn{color:var(--amber-deep)}

.km-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:1.4rem}
.km-panel-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--line);gap:1rem;flex-wrap:wrap}
.km-panel-head h3{font-size:.95rem;font-weight:600;font-family:'Inter',sans-serif}

.km-table-wrap{overflow-x:auto}
.km-table{width:100%;border-collapse:collapse;font-size:.88rem}
.km-table th{
  text-align:left;padding:.75rem 1.1rem;font-size:.72rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint);
  border-bottom:1px solid var(--line);background:var(--paper-deep);white-space:nowrap;
}
.km-table td{padding:.85rem 1.1rem;border-bottom:1px solid var(--line);vertical-align:middle}
.km-table tr:last-child td{border-bottom:none}
.km-table tr:hover td{background:rgba(245,244,240,.6)}

.km-badge{display:inline-flex;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700}
.km-badge-teal{background:var(--teal-tint);color:var(--teal)}
.km-badge-amber{background:var(--amber-tint);color:var(--amber-deep)}
.km-badge-neutral{background:var(--paper-deep);color:var(--ink-soft)}
.km-badge-danger{background:var(--danger-tint);color:var(--danger)}

.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.55rem .95rem;border-radius:8px;border:1px solid transparent;font-weight:600;
  font-size:.84rem;cursor:pointer;transition:transform .16s ease,background .16s ease;
  font-family:'Inter',sans-serif;
}
.km-btn:active{transform:scale(.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep)}
.km-btn-outline{background:transparent;border-color:var(--line);color:var(--ink)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-teal{background:var(--teal-tint);color:var(--teal)}
.km-btn-teal:hover{background:var(--teal);color:#fff}
.km-btn-danger{background:var(--danger-tint);color:var(--danger)}
.km-btn-danger:hover{background:var(--danger);color:#fff}
.km-btn-sm{padding:.38rem .7rem;font-size:.76rem}
.km-btn-block{width:100%}

.km-actions{display:flex;gap:.4rem;flex-wrap:wrap}

.km-empty{text-align:center;padding:2.5rem 1rem;color:var(--ink-soft);font-size:.92rem}

.km-pagination{display:flex;justify-content:center;gap:.4rem;margin-top:1.4rem;flex-wrap:wrap}
.km-page-link{
  padding:.4rem .8rem;border-radius:8px;font-size:.83rem;font-weight:600;
  border:1px solid var(--line);color:var(--ink-soft);
}
.km-page-link.active{background:var(--ink);color:var(--paper);border-color:var(--ink)}

.km-two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.4rem;align-items:start}

.km-modal-overlay{
  display:none;position:fixed;inset:0;background:rgba(28,27,23,.55);z-index:200;
  align-items:center;justify-content:center;padding:1rem;
}
.km-modal-overlay.open{display:flex}
.km-modal{
  background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;width:100%;max-width:420px;
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
}
</style>
</head>
<body>

<div class="km-app">

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand"><a href="index.php">Koud<em>Main</em></a></div>

    <nav class="km-sb-nav">
      <a href="?tab=tableau_bord" class="km-sb-link <?= $onglet === 'tableau_bord' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Tableau de bord
      </a>
      <a href="?tab=prestataires" class="km-sb-link <?= $onglet === 'prestataires' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><circle cx="10" cy="7" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg></span>
        Prestataires
        <?php if ($nb_attente > 0): ?><span class="km-sb-badge"><?= $nb_attente ?></span><?php endif; ?>
      </a>
      <a href="?tab=utilisateurs" class="km-sb-link <?= $onglet === 'utilisateurs' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M7 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM13 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3 17c0-2.8 2.2-5 5-5h1c.5 0 1 .1 1.4.3M12 12h1c2.8 0 5 2.2 5 5"/></svg></span>
        Utilisateurs
      </a>
      <a href="?tab=categories" class="km-sb-link <?= $onglet === 'categories' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="3" width="6" height="6" rx="1"/><rect x="11" y="3" width="6" height="6" rx="1"/><rect x="3" y="11" width="6" height="6" rx="1"/><rect x="11" y="11" width="6" height="6" rx="1"/></svg></span>
        Catalogue
      </a>
      <a href="?tab=commandes" class="km-sb-link <?= $onglet === 'commandes' ? 'active' : '' ?>">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></span>
        Commandes
      </a>

      <div class="km-sb-section">Navigation</div>
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
      Administration · <?= $nb_users ?> utilisateurs
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
          <div class="km-topbar-user-role">Administrateur</div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- ════════ TABLEAU DE BORD ════════ -->
      <?php if ($onglet === 'tableau_bord'): ?>

      <div class="km-page-head">
        <h1>Panneau de contrôle</h1>
        <p>Validez les prestataires, surveillez l'activité et gérez le catalogue de services.</p>
      </div>

      <div class="km-stats">
        <div class="km-stat">
          <div class="km-stat-val"><?= $nb_users ?></div>
          <div class="km-stat-label">Utilisateurs</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val"><?= $nb_prest ?></div>
          <div class="km-stat-label">Prestataires</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val <?= $nb_attente > 0 ? 'warn' : '' ?>"><?= $nb_attente ?></div>
          <div class="km-stat-label">En attente de validation</div>
        </div>
        <div class="km-stat">
          <div class="km-stat-val"><?= $nb_commandes ?></div>
          <div class="km-stat-label">Commandes totales</div>
        </div>
      </div>

      <div class="km-panel">
        <div class="km-panel-head">
          <h3>Prestataires en attente de validation</h3>
          <?php if ($nb_attente > 0): ?>
            <a href="?tab=prestataires" style="font-size:.8rem;color:var(--amber-deep);font-weight:600">Voir tous →</a>
          <?php endif; ?>
        </div>
        <?php if (empty($prest_attente)): ?>
          <div class="km-empty">Aucun compte prestataire en attente.</div>
        <?php else: ?>
          <div class="km-table-wrap">
            <table class="km-table">
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Email</th>
                  <th>Téléphone</th>
                  <th>Localisation</th>
                  <th>Inscription</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($prest_attente as $u): ?>
                <tr>
                  <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
                  <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
                  <td><?= htmlspecialchars($u['num_utilisateur']) ?></td>
                  <td><?= htmlspecialchars($u['nom_ville'] . ' — ' . $u['nom_quartier']) ?></td>
                  <td style="font-size:.82rem;color:var(--ink-soft)"><?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?></td>
                  <td>
                    <div class="km-actions">
                      <form method="POST">
                        <?= champCSRF() ?>
                        <input type="hidden" name="action" value="valider_prestataire">
                        <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                        <button type="submit" class="km-btn km-btn-teal km-btn-sm">Valider</button>
                      </form>
                      <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                        <?= champCSRF() ?>
                        <input type="hidden" name="action" value="supprimer_compte">
                        <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                        <button type="submit" class="km-btn km-btn-danger km-btn-sm">Refuser</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- ════════ PRESTATAIRES ════════ -->
      <?php elseif ($onglet === 'prestataires'): ?>

      <div class="km-page-head">
        <h1>Gestion des prestataires</h1>
        <p>Validez, suspendez ou supprimez les comptes prestataires.</p>
      </div>

      <div class="km-panel">
        <div class="km-table-wrap">
          <table class="km-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Téléphone</th>
                <th>Quartier</th>
                <th>Statut</th>
                <th>Inscrit le</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $prest_tous = array_filter($tous_users, fn($u) => $u['est_prestataire']);
              if (empty($prest_tous)):
              ?>
                <tr><td colspan="7"><div class="km-empty">Aucun prestataire sur cette page.</div></td></tr>
              <?php else: foreach ($prest_tous as $u): ?>
              <tr>
                <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
                <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
                <td><?= htmlspecialchars($u['num_utilisateur']) ?></td>
                <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
                <td>
                  <?php if ($u['est_valide']): ?>
                    <span class="km-badge km-badge-teal">Validé</span>
                  <?php else: ?>
                    <span class="km-badge km-badge-amber">En attente</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem;color:var(--ink-soft)"><?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?></td>
                <td>
                  <div class="km-actions">
                    <?php if (!$u['est_valide']): ?>
                    <form method="POST">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="valider_prestataire">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-btn km-btn-teal km-btn-sm">Valider</button>
                    </form>
                    <?php else: ?>
                    <form method="POST">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="suspendre">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-btn km-btn-outline km-btn-sm">Suspendre</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Supprimer ce compte définitivement ?')">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="supprimer_compte">
                      <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                      <button type="submit" class="km-btn km-btn-danger km-btn-sm">Supprimer</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ════════ UTILISATEURS ════════ -->
      <?php elseif ($onglet === 'utilisateurs'): ?>

      <div class="km-page-head">
        <h1>Tous les utilisateurs</h1>
        <p><?= $nb_users ?> comptes · <?= $nb_clients ?> clients · <?= $nb_prest ?> prestataires</p>
      </div>

      <div class="km-panel">
        <div class="km-table-wrap">
          <table class="km-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Rôle</th>
                <th>Quartier</th>
                <th>Statut</th>
                <th>Inscrit le</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tous_users as $u): ?>
              <tr>
                <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
                <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
                <td>
                  <?php if ($u['est_prestataire']): ?>
                    <span class="km-badge km-badge-neutral">Prestataire</span>
                  <?php else: ?>
                    <span class="km-badge km-badge-amber">Client</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
                <td>
                  <span class="km-badge <?= $u['est_valide'] ? 'km-badge-teal' : 'km-badge-danger' ?>">
                    <?= $u['est_valide'] ? 'Actif' : 'Inactif' ?>
                  </span>
                </td>
                <td style="font-size:.82rem;color:var(--ink-soft)"><?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                    <?= champCSRF() ?>
                    <input type="hidden" name="action" value="supprimer_compte">
                    <input type="hidden" name="id_utilisateur" value="<?= (int)$u['id_utilisateur'] ?>">
                    <button type="submit" class="km-btn km-btn-danger km-btn-sm">Supprimer</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($nb_pages_users > 1): ?>
      <div class="km-pagination">
        <?php for ($i = 1; $i <= $nb_pages_users; $i++): ?>
          <a href="?tab=utilisateurs&p_u=<?= $i ?>" class="km-page-link <?= $i === $page_users ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

      <!-- ════════ CATÉGORIES & SERVICES ════════ -->
      <?php elseif ($onglet === 'categories'): ?>

      <div class="km-page-head">
        <h1>Catalogue</h1>
        <p><?= $nb_cats ?> catégories · <?= $nb_prestations ?> prestations publiées</p>
      </div>

      <div class="km-two-col">
        <div class="km-panel">
          <div class="km-panel-head">
            <h3>Catégories</h3>
            <button type="button" class="km-btn km-btn-primary km-btn-sm" onclick="document.getElementById('modal-cat').classList.add('open')">Ajouter</button>
          </div>
          <div class="km-table-wrap">
            <table class="km-table">
              <thead>
                <tr><th>Nom</th><th>Services</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($categories as $c): ?>
                <tr>
                  <td><?= htmlspecialchars($c['nom_categorie']) ?></td>
                  <td><span class="km-badge km-badge-neutral"><?= (int)$c['nb_services'] ?></span></td>
                  <td>
                    <form method="POST" onsubmit="return confirm('Supprimer cette catégorie ?')">
                      <?= champCSRF() ?>
                      <input type="hidden" name="action" value="supprimer_categorie">
                      <input type="hidden" name="id_categorie" value="<?= (int)$c['id_categorie'] ?>">
                      <button type="submit" class="km-btn km-btn-danger km-btn-sm">Supprimer</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="km-panel">
          <div class="km-panel-head">
            <h3>Services</h3>
            <button type="button" class="km-btn km-btn-primary km-btn-sm" onclick="document.getElementById('modal-serv').classList.add('open')">Ajouter</button>
          </div>
          <div class="km-table-wrap">
            <table class="km-table">
              <thead>
                <tr><th>Nom</th><th>Catégorie</th><th>Prestations</th></tr>
              </thead>
              <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                  <td><?= htmlspecialchars($s['nom_service']) ?></td>
                  <td><span class="km-badge km-badge-amber"><?= htmlspecialchars($s['nom_categorie']) ?></span></td>
                  <td><span class="km-badge km-badge-neutral"><?= (int)$s['nb_prestations'] ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ════════ COMMANDES ════════ -->
      <?php elseif ($onglet === 'commandes'): ?>

      <div class="km-page-head">
        <h1>Toutes les commandes</h1>
        <p>Vue globale des transactions sur la plateforme.</p>
      </div>

      <div class="km-panel">
        <div class="km-table-wrap">
          <table class="km-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Prestation</th>
                <th>Client</th>
                <th>Prestataire</th>
                <th>Total</th>
                <th>Statut</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($commandes as $c):
                $badgeClass = match($c['statut']) {
                  'Terminé'  => 'km-badge-teal',
                  'Acceptée' => 'km-badge-neutral',
                  default    => 'km-badge-amber'
                };
              ?>
              <tr>
                <td style="font-family:'Fraunces',serif;font-weight:600;color:var(--ink-soft)">#<?= (int)$c['id_commande'] ?></td>
                <td><?= htmlspecialchars($c['titre_prestation']) ?></td>
                <td><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></td>
                <td><?= htmlspecialchars($c['prest_nom']) ?></td>
                <td style="font-family:'Fraunces',serif;font-weight:600"><?= number_format($c['montant_total'], 0, ',', ' ') ?> F</td>
                <td><span class="km-badge <?= $badgeClass ?>"><?= htmlspecialchars($c['statut']) ?></span></td>
                <td style="font-size:.82rem;color:var(--ink-soft)"><?= date('d/m/Y H:i', strtotime($c['date_commande'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($nb_pages_cmd_adm > 1): ?>
      <div class="km-pagination">
        <?php for ($i = 1; $i <= $nb_pages_cmd_adm; $i++): ?>
          <a href="?tab=commandes&p_c=<?= $i ?>" class="km-page-link <?= $i === $page_cmd_adm ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal catégorie -->
<div class="km-modal-overlay" id="modal-cat">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Nouvelle catégorie</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-cat').classList.remove('open')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="?tab=categories">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_categorie">
      <div class="km-mfield">
        <label for="nom_categorie">Nom de la catégorie</label>
        <input type="text" id="nom_categorie" name="nom_categorie" placeholder="Ex. Informatique" required>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block">Ajouter</button>
    </form>
  </div>
</div>

<!-- Modal service -->
<div class="km-modal-overlay" id="modal-serv">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Nouveau service</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-serv').classList.remove('open')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="?tab=categories">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_service">
      <div class="km-mfield">
        <label for="nom_service">Nom du service</label>
        <input type="text" id="nom_service" name="nom_service" placeholder="Ex. Réparation PC" required>
      </div>
      <div class="km-mfield">
        <label for="id_categorie">Catégorie</label>
        <select id="id_categorie" name="id_categorie" required>
          <option value="">— Sélectionnez —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id_categorie'] ?>"><?= htmlspecialchars($c['nom_categorie']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block">Ajouter</button>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.km-modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
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
