<?php
require_once "config.php";
requireConnexion();

// Rediriger si prestataire ou admin
if (estAdmin()) rediriger("admin_dashboard.php");
if (estPrestataire() && !estClient()) rediriger("prestataire_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

// ── Passer une commande ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'commander') {
        $id_prest   = (int)$_POST['id_prestation'];
        $quantite   = max(1, (int)($_POST['quantite'] ?? 1));
        $id_quartier = (int)($_POST['id_quartier'] ?? 0);

        // Récupérer le prix de la prestation
        $sp = $pdo->prepare("SELECT prix_prestation FROM Prestation WHERE id_prestation = ?");
        $sp->execute([$id_prest]);
        $prest = $sp->fetch();

        if ($prest && $id_quartier > 0) {
            $prix_unit = $prest['prix_prestation'];
            $montant   = $prix_unit * $quantite;

            // Créer la commande
            $ins = $pdo->prepare("
                INSERT INTO Commande (montant_total, statut, id_quartier, id_utilisateur)
                VALUES (?, 'En attente', ?, ?)
            ");
            $ins->execute([$montant, $id_quartier, $idUser]);
            $id_commande = $pdo->lastInsertId();

            // Ajouter le détail (Cibler)
            $ic = $pdo->prepare("
                INSERT INTO Cibler (id_prestation, id_commande, prix_unitaire, quantite)
                VALUES (?, ?, ?, ?)
            ");
            $ic->execute([$id_prest, $id_commande, $prix_unit, $quantite]);

            $msg = "Commande passée avec succès ! Référence : #$id_commande";
        } else {
            $err = "Erreur lors de la commande. Vérifiez les informations.";
        }
    }

    // ── Laisser un avis ────────────────────────────────────
    elseif ($_POST['action'] === 'avis') {
        $id_prest    = (int)$_POST['id_prestation'];
        $id_commande = (int)$_POST['id_commande'];
        $note        = min(5, max(0, (int)$_POST['note']));
        $commentaire = trim($_POST['commentaire'] ?? '');

        $upd = $pdo->prepare("
            UPDATE Cibler
            SET evaluation = ?, commentaire = ?
            WHERE id_prestation = ? AND id_commande = ?
        ");
        $upd->execute([$note, $commentaire, $id_prest, $id_commande]);
        $msg = "Votre avis a été enregistré. Merci !";
    }
}

// ── Données ───────────────────────────────────────────────

// Onglet actif
$onglet = $_GET['tab'] ?? 'services';

// Stats
$nb_cmd = $pdo->prepare("SELECT COUNT(*) FROM Commande WHERE id_utilisateur = ?");
$nb_cmd->execute([$idUser]); $nb_cmd = $nb_cmd->fetchColumn();

$nb_term = $pdo->prepare("SELECT COUNT(*) FROM Commande WHERE id_utilisateur = ? AND statut = 'Terminé'");
$nb_term->execute([$idUser]); $nb_term = $nb_term->fetchColumn();

// Services disponibles
$search  = trim($_GET['search'] ?? '');
$cat_fil = (int)($_GET['categorie'] ?? 0);

$sql = "
    SELECT p.*, u.nom_utilisateur, u.prenom_utilisateur, u.num_utilisateur,
           s.nom_service, c.nom_categorie,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy,
           COUNT(ci.evaluation) AS nb_avis
    FROM Prestation p
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation
    WHERE u.est_valide = 1 AND u.est_prestataire = 1
";
$params = [];
if ($search) {
    $sql .= " AND (p.titre_prestation LIKE ? OR p.description_prestation LIKE ? OR s.nom_service LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($cat_fil) {
    $sql .= " AND c.id_categorie = ?";
    $params[] = $cat_fil;
}
$sql .= " GROUP BY p.id_prestation ORDER BY p.datecrea_prestation DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prestations = $stmt->fetchAll();

// Catégories pour le filtre
$cats = $pdo->query("SELECT * FROM Categorie ORDER BY nom_categorie")->fetchAll();

// Mes commandes
$stmt_cmd = $pdo->prepare("
    SELECT cm.*, ci.id_prestation, ci.prix_unitaire, ci.quantite,
           ci.evaluation, ci.commentaire,
           p.titre_prestation, u.nom_utilisateur, u.prenom_utilisateur
    FROM Commande cm
    JOIN Cibler ci ON cm.id_commande = ci.id_commande
    JOIN Prestation p ON ci.id_prestation = p.id_prestation
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    WHERE cm.id_utilisateur = ?
    ORDER BY cm.date_commande DESC
");
$stmt_cmd->execute([$idUser]);
$commandes = $stmt_cmd->fetchAll();

// Quartiers pour la commande
$quartiers = $pdo->query("
    SELECT q.id_quartier, q.nom_quartier, v.nom_ville
    FROM Quartier q JOIN Ville v ON q.id_ville = v.id_ville
    ORDER BY v.nom_ville, q.nom_quartier
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Koudmain | Espace Client</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav>
    <a class="nav-brand" href="index.php">Koud<span>Main</span></a>
    <div class="nav-links">
      <a href="client_dashboard.php">Mon espace</a>
      <a href="connexion.php?action=logout" class="btn-nav">Déconnexion</a>
    </div>
  </nav>

  <div class="page">

    <!-- ── En-tête ── -->
    <div class="dash-header">
      <div>
        <div class="dash-title">Espace Client</div>
        <h1 style="font-size:1.8rem;">
          Bonjour, <?= htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']) ?> 👋
        </h1>
      </div>
    </div>

    <!-- ── Alertes ── -->
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- ── Stats ── -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-num"><?= $nb_cmd ?></div>
        <div class="stat-label">Commandes passées</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $nb_term ?></div>
        <div class="stat-label">Services terminés</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= count($prestations) ?></div>
        <div class="stat-label">Services disponibles</div>
      </div>
    </div>

    <!-- ── Onglets ── -->
    <div class="tabs">
      <a href="?tab=services" class="tab <?= $onglet === 'services' ? 'active' : '' ?>">🔍 Parcourir les services</a>
      <a href="?tab=commandes" class="tab <?= $onglet === 'commandes' ? 'active' : '' ?>">📋 Mes commandes (<?= $nb_cmd ?>)</a>
    </div>

    <!-- ══════════════ ONGLET SERVICES ══════════════ -->
    <?php if ($onglet === 'services'): ?>

    <!-- Filtres -->
    <form method="GET" action="client_dashboard.php" style="display:flex;gap:0.8rem;flex-wrap:wrap;margin-bottom:1.5rem;">
      <input type="hidden" name="tab" value="services">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
            placeholder="🔍 Rechercher un service..." style="flex:1;min-width:200px;padding:0.7rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;">
      <select name="categorie" style="padding:0.7rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;">
        <option value="0">Toutes catégories</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= $c['id_categorie'] ?>" <?= $cat_fil == $c['id_categorie'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['nom_categorie']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <?php if ($search || $cat_fil): ?>
        <a href="?tab=services" class="btn btn-secondary">✕ Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if (empty($prestations)): ?>
      <div class="empty">
        <div class="empty-icon">🔍</div>
        <p>Aucun service trouvé pour ces critères.</p>
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
          <?= nl2br(htmlspecialchars(mb_substr($p['description_prestation'], 0, 120))) ?>
          <?= strlen($p['description_prestation']) > 120 ? '...' : '' ?>
        </p>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span class="price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> FCFA</span>
          <span class="stars" title="<?= round($p['note_moy'],1) ?>/5">
            <?= $etoiles ?>
            <small style="color:var(--text-muted);font-size:0.75rem;">(<?= $p['nb_avis'] ?>)</small>
          </span>
        </div>
        <div style="color:var(--text-muted);font-size:0.82rem;">
          🛠️ <?= htmlspecialchars($p['prenom_utilisateur'] . ' ' . $p['nom_utilisateur']) ?>
          &nbsp;|&nbsp; 📞 <?= htmlspecialchars($p['num_utilisateur']) ?>
        </div>
        <button class="btn btn-primary btn-sm" onclick="ouvrirCommande(<?= $p['id_prestation'] ?>, '<?= addslashes($p['titre_prestation']) ?>', <?= $p['prix_prestation'] ?>)">
          Commander
        </button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════ ONGLET COMMANDES ══════════════ -->
    <?php elseif ($onglet === 'commandes'): ?>

    <?php if (empty($commandes)): ?>
      <div class="empty">
        <div class="empty-icon">📋</div>
        <p>Vous n'avez pas encore passé de commande.</p>
        <a href="?tab=services" class="btn btn-primary" style="margin-top:1rem;">Parcourir les services</a>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Prestation</th>
            <th>Prestataire</th>
            <th>Qté</th>
            <th>Total</th>
            <th>Statut</th>
            <th>Date</th>
            <th>Avis</th>
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
            <td><?= $c['quantite'] ?></td>
            <td><?= number_format($c['montant_total'], 0, ',', ' ') ?> FCFA</td>
            <td><span class="badge <?= $badge ?>"><?= $c['statut'] ?></span></td>
            <td style="font-size:0.82rem;color:var(--text-muted);">
              <?= date('d/m/Y', strtotime($c['date_commande'])) ?>
            </td>
            <td>
              <?php if ($c['statut'] === 'Terminé' && $c['evaluation'] === null): ?>
                <button class="btn btn-sm btn-secondary"
                  onclick="ouvrirAvis(<?= $c['id_prestation'] ?>, <?= $c['id_commande'] ?>)">
                  ⭐ Évaluer
                </button>
              <?php elseif ($c['evaluation'] !== null): ?>
                <span class="stars" style="font-size:0.8rem;">
                  <?= str_repeat('★', $c['evaluation']) . str_repeat('☆', 5 - $c['evaluation']) ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:0.82rem;">En attente</span>
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

  <!-- ── Modal Commande ── -->
  <div class="modal-overlay" id="modal-commande">
    <div class="modal">
      <div class="modal-header">
        <h3>Passer une commande</h3>
        <button class="modal-close" onclick="fermerModal('modal-commande')">✕</button>
      </div>
      <form method="POST" action="?tab=services">
        <input type="hidden" name="action" value="commander">
        <input type="hidden" name="id_prestation" id="cmd-prest-id">

        <div class="form-group">
          <label>Service sélectionné</label>
          <input type="text" id="cmd-titre" readonly style="opacity:0.7;">
        </div>
        <div class="form-group">
          <label>Prix unitaire</label>
          <input type="text" id="cmd-prix-affiche" readonly style="opacity:0.7;">
        </div>
        <div class="form-group">
          <label>Quantité</label>
          <input type="number" name="quantite" id="cmd-qte" min="1" value="1"
                oninput="updateTotal()">
        </div>
        <div class="form-group">
          <label>Lieu d'intervention *</label>
          <select name="id_quartier" required>
            <option value="">— Sélectionnez —</option>
            <?php foreach ($quartiers as $q): ?>
              <option value="<?= $q['id_quartier'] ?>">
                <?= htmlspecialchars($q['nom_ville'] . ' — ' . $q['nom_quartier']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.8rem;background:var(--surface2);border-radius:8px;margin-bottom:1rem;">
          <span style="color:var(--text-muted);">Total estimé :</span>
          <span class="price" id="cmd-total">—</span>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Confirmer la commande</button>
      </form>
    </div>
  </div>

  <!-- ── Modal Avis ── -->
  <div class="modal-overlay" id="modal-avis">
    <div class="modal">
      <div class="modal-header">
        <h3>Laisser un avis</h3>
        <button class="modal-close" onclick="fermerModal('modal-avis')">✕</button>
      </div>
      <form method="POST" action="?tab=commandes">
        <input type="hidden" name="action" value="avis">
        <input type="hidden" name="id_prestation" id="avis-prest-id">
        <input type="hidden" name="id_commande"   id="avis-cmd-id">

        <div class="form-group">
          <label>Note (sur 5)</label>
          <div style="display:flex;gap:0.5rem;font-size:2rem;">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span style="cursor:pointer;" onclick="setNote(<?= $i ?>)" id="star-<?= $i ?>">☆</span>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="note" id="note-val" value="0">
        </div>
        <div class="form-group">
          <label>Commentaire</label>
          <textarea name="commentaire" placeholder="Décrivez votre expérience..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Publier l'avis</button>
      </form>
    </div>
  </div>

  <script>
  let cmdPrix = 0;

  function ouvrirCommande(id, titre, prix) {
    cmdPrix = prix;
    document.getElementById('cmd-prest-id').value = id;
    document.getElementById('cmd-titre').value = titre;
    document.getElementById('cmd-prix-affiche').value = new Intl.NumberFormat('fr-FR').format(prix) + ' FCFA';
    updateTotal();
    document.getElementById('modal-commande').classList.add('open');
  }

  function updateTotal() {
    const qte = parseInt(document.getElementById('cmd-qte').value) || 1;
    document.getElementById('cmd-total').textContent =
      new Intl.NumberFormat('fr-FR').format(cmdPrix * qte) + ' FCFA';
  }

  function ouvrirAvis(idPrest, idCmd) {
    document.getElementById('avis-prest-id').value = idPrest;
    document.getElementById('avis-cmd-id').value = idCmd;
    setNote(0);
    document.getElementById('modal-avis').classList.add('open');
  }

  function fermerModal(id) {
    document.getElementById(id).classList.remove('open');
  }

  function setNote(n) {
    document.getElementById('note-val').value = n;
    for (let i = 1; i <= 5; i++) {
      document.getElementById('star-' + i).textContent = i <= n ? '★' : '☆';
      document.getElementById('star-' + i).style.color = i <= n ? 'var(--accent)' : 'var(--text-muted)';
    }
  }

  // Fermer modal en cliquant l'overlay
  document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
  });
  </script>

</body>
</html>
