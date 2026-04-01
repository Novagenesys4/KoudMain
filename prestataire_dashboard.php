<?php
require_once "config.php";
requireConnexion();

if (estAdmin()) rediriger("admin_dashboard.php");
if (!estPrestataire()) rediriger("client_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

// ── Créer une prestation ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

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
            $msg = "Prestation « $titre » créée avec succès !";
        }
    }

    elseif ($_POST['action'] === 'supprimer_prestation') {
        $id = (int)$_POST['id_prestation'];
        // Vérifier que c'est bien la prestation du prestataire connecté
        $del = $pdo->prepare("DELETE FROM Prestation WHERE id_prestation = ? AND id_utilisateur = ?");
        $del->execute([$id, $idUser]);
        $msg = "Prestation supprimée.";
    }

    elseif ($_POST['action'] === 'changer_statut') {
        $id_cmd = (int)$_POST['id_commande'];
        $statut = $_POST['statut'];
        $statuts_valides = ['Acceptée', 'En attente', 'Terminé'];
        if (in_array($statut, $statuts_valides)) {
            // Vérifier que cette commande concerne bien ce prestataire
            $upd = $pdo->prepare("
                UPDATE Commande cm
                JOIN Cibler ci ON cm.id_commande = ci.id_commande
                JOIN Prestation p ON ci.id_prestation = p.id_prestation
                SET cm.statut = ?
                WHERE cm.id_commande = ? AND p.id_utilisateur = ?
            ");
            $upd->execute([$statut, $id_cmd, $idUser]);
            $msg = "Statut de la commande mis à jour.";
        }
    }
}

// ── Données ──────────────────────────────────────────────

$onglet = $_GET['tab'] ?? 'prestations';

// Stats
$nb_prest = $pdo->prepare("SELECT COUNT(*) FROM Prestation WHERE id_utilisateur = ?");
$nb_prest->execute([$idUser]); $nb_prest = $nb_prest->fetchColumn();

$nb_cmd = $pdo->prepare("
    SELECT COUNT(DISTINCT cm.id_commande)
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ?
");
$nb_cmd->execute([$idUser]); $nb_cmd = $nb_cmd->fetchColumn();

$note_moy = $pdo->prepare("
    SELECT COALESCE(ROUND(AVG(ci.evaluation), 1), 0)
    FROM Cibler ci
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    WHERE p.id_utilisateur = ? AND ci.evaluation IS NOT NULL
");
$note_moy->execute([$idUser]); $note_moy = $note_moy->fetchColumn();

// Mes prestations
$stmt_p = $pdo->prepare("
    SELECT p.*, s.nom_service, c.nom_categorie,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy,
           COUNT(ci.evaluation) AS nb_avis
    FROM Prestation p
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation
    WHERE p.id_utilisateur = ?
    GROUP BY p.id_prestation
    ORDER BY p.datecrea_prestation DESC
");
$stmt_p->execute([$idUser]);
$prestations = $stmt_p->fetchAll();

// Commandes reçues
$stmt_c = $pdo->prepare("
    SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite,
           ci.evaluation, ci.commentaire,
           p.titre_prestation,
           u.nom_utilisateur, u.prenom_utilisateur, u.num_utilisateur,
           q.nom_quartier
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON cm.id_utilisateur = u.id_utilisateur
    JOIN Quartier q ON cm.id_quartier = q.id_quartier
    WHERE p.id_utilisateur = ?
    ORDER BY cm.date_commande DESC
");
$stmt_c->execute([$idUser]);
$commandes = $stmt_c->fetchAll();

// Services pour le formulaire
$services = $pdo->query("
    SELECT s.id_service, s.nom_service, c.nom_categorie
    FROM Service s JOIN Categorie c ON s.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, s.nom_service
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Koudmain | Espace Prestataire</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<nav>
  <a class="nav-brand" href="index.php">Koud<span>Main</span></a>
  <div class="nav-links">
    <a href="prestataire_dashboard.php">Mon espace</a>
    <a href="connexion.php?action=logout" class="btn-nav">Déconnexion</a>
  </div>
</nav>

<div class="page">

  <div class="dash-header">
    <div>
      <div class="dash-title">Espace Prestataire</div>
      <h1 style="font-size:1.8rem;">
        <?= htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']) ?> 🛠️
      </h1>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modal-prest').classList.add('open')">
      + Nouvelle prestation
    </button>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-num"><?= $nb_prest ?></div>
      <div class="stat-label">Mes prestations</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $nb_cmd ?></div>
      <div class="stat-label">Commandes reçues</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $note_moy ?>/5</div>
      <div class="stat-label">Note moyenne</div>
    </div>
  </div>

  <!-- Onglets -->
  <div class="tabs">
    <a href="?tab=prestations" class="tab <?= $onglet === 'prestations' ? 'active' : '' ?>">
      🛠️ Mes prestations (<?= $nb_prest ?>)
    </a>
    <a href="?tab=commandes" class="tab <?= $onglet === 'commandes' ? 'active' : '' ?>">
      📦 Commandes reçues (<?= $nb_cmd ?>)
    </a>
  </div>

  <!-- ══ ONGLET PRESTATIONS ══ -->
  <?php if ($onglet === 'prestations'): ?>

  <?php if (empty($prestations)): ?>
    <div class="empty">
      <div class="empty-icon">🛠️</div>
      <p>Vous n'avez pas encore créé de prestation.</p>
      <button class="btn btn-primary" style="margin-top:1rem;"
              onclick="document.getElementById('modal-prest').classList.add('open')">
        Créer ma première prestation
      </button>
    </div>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($prestations as $p):
      $etoiles = str_repeat('★', round($p['note_moy'])) . str_repeat('☆', 5 - round($p['note_moy']));
    ?>
    <div class="card" style="display:flex;flex-direction:column;gap:0.8rem;">
      <div>
        <span class="badge badge-blue"><?= htmlspecialchars($p['nom_categorie']) ?></span>
        <span class="badge badge-orange" style="margin-left:0.3rem;"><?= htmlspecialchars($p['nom_service']) ?></span>
      </div>
      <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
      <p style="color:var(--text-muted);font-size:0.88rem;flex:1;">
        <?= nl2br(htmlspecialchars(mb_substr($p['description_prestation'], 0, 100))) ?>
        <?= strlen($p['description_prestation']) > 100 ? '...' : '' ?>
      </p>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span class="price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> FCFA</span>
        <span class="stars" style="font-size:0.85rem;" title="<?= $p['nb_avis'] ?> avis">
          <?= $etoiles ?>
          <small style="color:var(--text-muted);">(<?= $p['nb_avis'] ?>)</small>
        </span>
      </div>
      <div style="font-size:0.78rem;color:var(--text-muted);">
        Créée le <?= date('d/m/Y', strtotime($p['datecrea_prestation'])) ?>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="supprimer_prestation">
        <input type="hidden" name="id_prestation" value="<?= $p['id_prestation'] ?>">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Supprimer cette prestation ?')">
          🗑 Supprimer
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══ ONGLET COMMANDES ══ -->
  <?php elseif ($onglet === 'commandes'): ?>

  <?php if (empty($commandes)): ?>
    <div class="empty">
      <div class="empty-icon">📦</div>
      <p>Aucune commande reçue pour l'instant.</p>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Prestation</th><th>Client</th><th>Téléphone</th>
          <th>Lieu</th><th>Total</th><th>Statut</th><th>Date</th><th>Avis</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commandes as $c):
          $badge = match($c['statut']) {
            'Terminé'    => 'badge-green',
            'Acceptée'   => 'badge-blue',
            default      => 'badge-orange'
          };
        ?>
        <tr>
          <td>#<?= $c['id_commande'] ?></td>
          <td><?= htmlspecialchars($c['titre_prestation']) ?></td>
          <td><?= htmlspecialchars($c['prenom_utilisateur'] . ' ' . $c['nom_utilisateur']) ?></td>
          <td style="font-size:0.82rem;"><?= htmlspecialchars($c['num_utilisateur']) ?></td>
          <td style="font-size:0.82rem;"><?= htmlspecialchars($c['nom_quartier']) ?></td>
          <td><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</td>
          <td><span class="badge <?= $badge ?>"><?= $c['statut'] ?></span></td>
          <td style="font-size:0.82rem;color:var(--text-muted);">
            <?= date('d/m/Y', strtotime($c['date_commande'])) ?>
          </td>
          <td>
            <?php if ($c['evaluation'] !== null): ?>
              <span class="stars" style="font-size:0.8rem;">
                <?= str_repeat('★', $c['evaluation']) . str_repeat('☆', 5 - $c['evaluation']) ?>
              </span>
              <?php if ($c['commentaire']): ?>
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px;">
                  "<?= htmlspecialchars(mb_substr($c['commentaire'], 0, 40)) ?>..."
                </div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($c['statut'] !== 'Terminé'): ?>
            <form method="POST" style="display:flex;gap:0.4rem;flex-wrap:wrap;">
              <input type="hidden" name="action" value="changer_statut">
              <input type="hidden" name="id_commande" value="<?= $c['id_commande'] ?>">
              <select name="statut" style="padding:0.3rem;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.8rem;">
                <option value="En attente" <?= $c['statut'] === 'En attente' ? 'selected' : '' ?>>En attente</option>
                <option value="Acceptée"   <?= $c['statut'] === 'Acceptée'   ? 'selected' : '' ?>>Acceptée</option>
                <option value="Terminé"    <?= $c['statut'] === 'Terminé'    ? 'selected' : '' ?>>Terminé</option>
              </select>
              <button type="submit" class="btn btn-sm btn-success">✓</button>
            </form>
            <?php else: ?>
              <span style="color:var(--success);font-size:0.82rem;">✓ Terminé</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<!-- ── Modal Nouvelle Prestation ── -->
<div class="modal-overlay" id="modal-prest">
  <div class="modal">
    <div class="modal-header">
      <h3>Nouvelle prestation</h3>
      <button class="modal-close" onclick="document.getElementById('modal-prest').classList.remove('open')">✕</button>
    </div>
    <form method="POST" action="?tab=prestations">
      <input type="hidden" name="action" value="creer_prestation">

      <div class="form-group">
        <label>Titre *</label>
        <input type="text" name="titre" placeholder="Ex : Coiffure domicile Cocody" required>
      </div>
      <div class="form-group">
        <label>Service proposé *</label>
        <select name="id_service" required>
          <option value="">— Sélectionnez un service —</option>
          <?php
          $cat_courante = '';
          foreach ($services as $s):
            if ($s['nom_categorie'] !== $cat_courante) {
              if ($cat_courante) echo '</optgroup>';
              echo '<optgroup label="' . htmlspecialchars($s['nom_categorie']) . '">';
              $cat_courante = $s['nom_categorie'];
            }
          ?>
            <option value="<?= $s['id_service'] ?>"><?= htmlspecialchars($s['nom_service']) ?></option>
          <?php endforeach; if ($cat_courante) echo '</optgroup>'; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Prix (FCFA) *</label>
        <input type="number" name="prix" min="0" step="100" placeholder="5000" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" placeholder="Décrivez votre prestation, vos spécialités..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Publier la prestation</button>
    </form>
  </div>
</div>

<script>
document.getElementById('modal-prest').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

</body>
</html>
