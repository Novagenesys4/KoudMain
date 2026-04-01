<?php
require_once "config.php";
requireAdmin();

$pdo = getConnexion();
$msg = "";
$err = "";

// ── Actions admin ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Valider un prestataire
    if ($_POST['action'] === 'valider_prestataire') {
        $id = (int)$_POST['id_utilisateur'];
        $pdo->prepare("UPDATE Utilisateur SET est_valide = 1 WHERE id_utilisateur = ?")
            ->execute([$id]);
        $msg = "Compte prestataire validé avec succès.";
    }

    // Refuser / suspendre un compte
    elseif ($_POST['action'] === 'suspendre') {
        $id = (int)$_POST['id_utilisateur'];
        $pdo->prepare("UPDATE Utilisateur SET est_valide = 0 WHERE id_utilisateur = ? AND est_admin = 0")
            ->execute([$id]);
        $msg = "Compte suspendu.";
    }

    // Supprimer un compte
    elseif ($_POST['action'] === 'supprimer_compte') {
        $id = (int)$_POST['id_utilisateur'];
        // Sécurité : ne pas supprimer un admin
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

    // Ajouter une catégorie
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

    // Supprimer une catégorie
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

    // Ajouter un service
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

// ── Données ──────────────────────────────────────────────
$onglet = $_GET['tab'] ?? 'tableau_bord';

// Stats globales
$nb_users      = $pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_admin = 0")->fetchColumn();
$nb_clients    = $pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_client = 1 AND est_admin = 0")->fetchColumn();
$nb_prest      = $pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = 1")->fetchColumn();
$nb_attente    = $pdo->query("SELECT COUNT(*) FROM Utilisateur WHERE est_prestataire = 1 AND est_valide = 0")->fetchColumn();
$nb_commandes  = $pdo->query("SELECT COUNT(*) FROM Commande")->fetchColumn();
$nb_cats       = $pdo->query("SELECT COUNT(*) FROM Categorie")->fetchColumn();
$nb_prestations= $pdo->query("SELECT COUNT(*) FROM Prestation")->fetchColumn();

// Prestataires en attente de validation
$prest_attente = $pdo->query("
    SELECT u.*, q.nom_quartier, v.nom_ville
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    JOIN Ville v ON q.id_ville = v.id_ville
    WHERE u.est_prestataire = 1 AND u.est_valide = 0
    ORDER BY u.datecrea_utilisateur DESC
")->fetchAll();

// Tous les utilisateurs (non admin)
$tous_users = $pdo->query("
    SELECT u.*, q.nom_quartier
    FROM Utilisateur u
    JOIN Quartier q ON u.id_quartier = q.id_quartier
    WHERE u.est_admin = 0
    ORDER BY u.datecrea_utilisateur DESC
")->fetchAll();

// Catégories
$categories = $pdo->query("
    SELECT c.*, COUNT(s.id_service) AS nb_services
    FROM Categorie c
    LEFT JOIN Service s ON c.id_categorie = s.id_categorie
    GROUP BY c.id_categorie
    ORDER BY c.nom_categorie
")->fetchAll();

// Services
$services = $pdo->query("
    SELECT s.*, c.nom_categorie, COUNT(p.id_prestation) AS nb_prestations
    FROM Service s
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Prestation p ON s.id_service = p.id_service
    GROUP BY s.id_service
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();

// Toutes les commandes
$commandes = $pdo->query("
    SELECT cm.*, u.nom_utilisateur, u.prenom_utilisateur,
           ci.id_prestation, p.titre_prestation,
           pu.nom_utilisateur AS prest_nom
    FROM Commande cm
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur pu ON p.id_utilisateur = pu.id_utilisateur
    ORDER BY cm.date_commande DESC
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KoudMain | Administration</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<nav>
  <a class="nav-brand" href="index.php">Koud<span>Main</span></a>
  <div class="nav-links">
    <span style="color:var(--text-muted);font-size:0.85rem;">
      🔐 Admin : <?= htmlspecialchars($_SESSION['prenom']) ?>
    </span>
    <a href="connexion.php?action=logout" class="btn-nav">Déconnexion</a>
  </div>
</nav>

<div class="page">

  <div class="dash-header">
    <div>
      <div class="dash-title">Tableau de bord</div>
      <h1 style="font-size:1.8rem;">Administration KoudMain</h1>
    </div>
    <?php if ($nb_attente > 0): ?>
    <div class="badge badge-orange" style="font-size:0.9rem;padding:0.5rem 1rem;">
      ⏳ <?= $nb_attente ?> prestataire<?= $nb_attente > 1 ? 's' : '' ?> en attente
    </div>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-num"><?= $nb_users ?></div>
      <div class="stat-label">Utilisateurs</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $nb_prest ?></div>
      <div class="stat-label">Prestataires</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:<?= $nb_attente > 0 ? 'var(--accent)' : 'var(--success)' ?>;">
        <?= $nb_attente ?>
      </div>
      <div class="stat-label">En attente validation</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $nb_commandes ?></div>
      <div class="stat-label">Commandes</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $nb_cats ?></div>
      <div class="stat-label">Catégories</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $nb_prestations ?></div>
      <div class="stat-label">Prestations actives</div>
    </div>
  </div>

  <!-- Onglets -->
  <div class="tabs" style="flex-wrap:wrap;">
    <a href="?tab=tableau_bord" class="tab <?= $onglet === 'tableau_bord' ? 'active' : '' ?>">📊 Tableau de bord</a>
    <a href="?tab=prestataires" class="tab <?= $onglet === 'prestataires' ? 'active' : '' ?>">
      🛠️ Prestataires <?php if ($nb_attente): ?><span class="badge badge-orange" style="margin-left:4px;"><?= $nb_attente ?></span><?php endif; ?>
    </a>
    <a href="?tab=utilisateurs" class="tab <?= $onglet === 'utilisateurs' ? 'active' : '' ?>">👥 Utilisateurs</a>
    <a href="?tab=categories"   class="tab <?= $onglet === 'categories'   ? 'active' : '' ?>">🏷️ Catégories & Services</a>
    <a href="?tab=commandes"    class="tab <?= $onglet === 'commandes'    ? 'active' : '' ?>">📦 Commandes</a>
  </div>

  <!-- ══ TABLEAU DE BORD ══ -->
  <?php if ($onglet === 'tableau_bord'): ?>

  <?php if (!empty($prest_attente)): ?>
  <div class="section-header"><h2>⏳ Prestataires en attente de validation</h2></div>
  <div class="table-wrap" style="margin-bottom:2rem;">
    <table>
      <thead>
        <tr><th>Nom</th><th>Email</th><th>Téléphone</th><th>Localisation</th><th>Inscription</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($prest_attente as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['num_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['nom_ville'] . ' — ' . $u['nom_quartier']) ?></td>
          <td style="font-size:0.82rem;color:var(--text-muted);">
            <?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?>
          </td>
          <td style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <form method="POST">
              <input type="hidden" name="action" value="valider_prestataire">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button type="submit" class="btn btn-success btn-sm">✓ Valider</button>
            </form>
            <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
              <input type="hidden" name="action" value="supprimer_compte">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">✕ Refuser</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="alert alert-success">✓ Aucun compte prestataire en attente de validation.</div>
  <?php endif; ?>

  <!-- ══ PRESTATAIRES ══ -->
  <?php elseif ($onglet === 'prestataires'): ?>
  <div class="section-header">
    <h2>Gestion des prestataires</h2>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nom</th><th>Email</th><th>Téléphone</th><th>Quartier</th><th>Statut</th><th>Inscrit le</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php
        $prest_tous = array_filter($tous_users, fn($u) => $u['est_prestataire']);
        foreach ($prest_tous as $u):
        ?>
        <tr>
          <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['num_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
          <td>
            <?php if ($u['est_valide']): ?>
              <span class="badge badge-green">✓ Validé</span>
            <?php else: ?>
              <span class="badge badge-orange">⏳ En attente</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.82rem;color:var(--text-muted);">
            <?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?>
          </td>
          <td style="display:flex;gap:0.4rem;flex-wrap:wrap;">
            <?php if (!$u['est_valide']): ?>
            <form method="POST">
              <input type="hidden" name="action" value="valider_prestataire">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button class="btn btn-success btn-sm">✓ Valider</button>
            </form>
            <?php else: ?>
            <form method="POST">
              <input type="hidden" name="action" value="suspendre">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button class="btn btn-secondary btn-sm">⏸ Suspendre</button>
            </form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Supprimer ce compte définitivement ?')">
              <input type="hidden" name="action" value="supprimer_compte">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ══ UTILISATEURS ══ -->
  <?php elseif ($onglet === 'utilisateurs'): ?>
  <div class="section-header"><h2>Tous les utilisateurs</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Quartier</th><th>Statut</th><th>Inscrit le</th><th>Supprimer</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tous_users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['prenom_utilisateur'] . ' ' . $u['nom_utilisateur']) ?></td>
          <td><?= htmlspecialchars($u['email_utilisateur']) ?></td>
          <td>
            <?php if ($u['est_prestataire']): ?>
              <span class="badge badge-blue">Prestataire</span>
            <?php else: ?>
              <span class="badge badge-orange">Client</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($u['nom_quartier']) ?></td>
          <td>
            <span class="badge <?= $u['est_valide'] ? 'badge-green' : 'badge-red' ?>">
              <?= $u['est_valide'] ? 'Actif' : 'Inactif' ?>
            </span>
          </td>
          <td style="font-size:0.82rem;color:var(--text-muted);">
            <?= date('d/m/Y', strtotime($u['datecrea_utilisateur'])) ?>
          </td>
          <td>
            <form method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
              <input type="hidden" name="action" value="supprimer_compte">
              <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ══ CATÉGORIES & SERVICES ══ -->
  <?php elseif ($onglet === 'categories'): ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-items:start;flex-wrap:wrap;">

    <!-- Catégories -->
    <div>
      <div class="section-header">
        <h2>Catégories</h2>
        <button class="btn btn-primary btn-sm"
                onclick="document.getElementById('modal-cat').classList.add('open')">
          + Ajouter
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Nom</th><th>Services</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['nom_categorie']) ?></td>
              <td><span class="badge badge-blue"><?= $c['nb_services'] ?></span></td>
              <td>
                <form method="POST" onsubmit="return confirm('Supprimer cette catégorie ?')">
                  <input type="hidden" name="action" value="supprimer_categorie">
                  <input type="hidden" name="id_categorie" value="<?= $c['id_categorie'] ?>">
                  <button class="btn btn-danger btn-sm">🗑</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Services -->
    <div>
      <div class="section-header">
        <h2>Services</h2>
        <button class="btn btn-primary btn-sm"
                onclick="document.getElementById('modal-serv').classList.add('open')">
          + Ajouter
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Nom</th><th>Catégorie</th><th>Prestations</th></tr></thead>
          <tbody>
            <?php foreach ($services as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['nom_service']) ?></td>
              <td><span class="badge badge-orange"><?= htmlspecialchars($s['nom_categorie']) ?></span></td>
              <td><span class="badge badge-blue"><?= $s['nb_prestations'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ COMMANDES ══ -->
  <?php elseif ($onglet === 'commandes'): ?>
  <div class="section-header"><h2>Toutes les commandes</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Prestation</th><th>Client</th><th>Prestataire</th><th>Total</th><th>Statut</th><th>Date</th></tr>
      </thead>
      <tbody>
        <?php foreach ($commandes as $c):
          $badge = match($c['statut']) {
            'Terminé'  => 'badge-green',
            'Acceptée' => 'badge-blue',
            default    => 'badge-orange'
          };
        ?>
        <tr>
          <td>#<?= $c['id_commande'] ?></td>
          <td><?= htmlspecialchars($c['titre_prestation']) ?></td>
          <td><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></td>
          <td><?= htmlspecialchars($c['prest_nom']) ?></td>
          <td><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</td>
          <td><span class="badge <?= $badge ?>"><?= $c['statut'] ?></span></td>
          <td style="font-size:0.82rem;color:var(--text-muted);">
            <?= date('d/m/Y H:i', strtotime($c['date_commande'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>

<!-- ── Modal Catégorie ── -->
<div class="modal-overlay" id="modal-cat">
  <div class="modal">
    <div class="modal-header">
      <h3>Nouvelle catégorie</h3>
      <button class="modal-close" onclick="document.getElementById('modal-cat').classList.remove('open')">✕</button>
    </div>
    <form method="POST" action="?tab=categories">
      <input type="hidden" name="action" value="ajouter_categorie">
      <div class="form-group">
        <label>Nom de la catégorie *</label>
        <input type="text" name="nom_categorie" placeholder="Ex : Informatique" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Ajouter</button>
    </form>
  </div>
</div>

<!-- ── Modal Service ── -->
<div class="modal-overlay" id="modal-serv">
  <div class="modal">
    <div class="modal-header">
      <h3>Nouveau service</h3>
      <button class="modal-close" onclick="document.getElementById('modal-serv').classList.remove('open')">✕</button>
    </div>
    <form method="POST" action="?tab=categories">
      <input type="hidden" name="action" value="ajouter_service">
      <div class="form-group">
        <label>Nom du service *</label>
        <input type="text" name="nom_service" placeholder="Ex : Réparation PC" required>
      </div>
      <div class="form-group">
        <label>Catégorie *</label>
        <select name="id_categorie" required>
          <option value="">— Sélectionnez —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id_categorie'] ?>"><?= htmlspecialchars($c['nom_categorie']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Ajouter</button>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
</script>

</body>
</html>
