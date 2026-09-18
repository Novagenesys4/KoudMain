<?php
require_once "config.php";
requireConnexion();

if (estAdmin()) rediriger("admin_dashboard.php");
if (estPrestataire()) rediriger("prestataire_dashboard.php");

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commander') {
    verifierTokenCSRF();
    $id_prest    = (int)($_POST['id_prestation'] ?? 0);
    $qty         = max(1, (int)($_POST['quantite'] ?? 1));
    $id_quartier = (int)($_POST['id_quartier'] ?? 0);
    $res = creerCommandeAvecEscrow($pdo, $idUser, $id_prest, $qty, $id_quartier);
    $msg = $res['ok'] ? $res['message'] : '';
    $err = $res['ok'] ? '' : $res['message'];
} else {
    $msg = $err = '';
}

$stmt = $pdo->prepare("
    SELECT p.*, s.nom_service, c.nom_categorie,
           u.prenom_utilisateur, u.nom_utilisateur,
           COALESCE(AVG(ci.evaluation), 0) AS note_moy,
           COUNT(ci.evaluation) AS nb_avis,
           f.date_creation AS date_ajout_favori
    FROM Favori f
    JOIN Prestation p ON p.id_prestation = f.id_prestation
    JOIN Service s ON p.id_service = s.id_service
    JOIN Categorie c ON s.id_categorie = c.id_categorie
    JOIN Utilisateur u ON p.id_utilisateur = u.id_utilisateur
    LEFT JOIN Cibler ci ON p.id_prestation = ci.id_prestation AND ci.evaluation IS NOT NULL
    WHERE f.id_utilisateur = ?
    GROUP BY p.id_prestation, s.nom_service, c.nom_categorie, u.prenom_utilisateur, u.nom_utilisateur, f.date_creation
    ORDER BY f.date_creation DESC
");
$stmt->execute([$idUser]);
$favoris = $stmt->fetchAll();

$quartiers = $pdo->query("
    SELECT q.id_quartier, q.nom_quartier, v.nom_ville
    FROM Quartier q JOIN Ville v ON q.id_ville = v.id_ville
    ORDER BY v.nom_ville, q.nom_quartier
")->fetchAll();

$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Client');

function icon(string $name, int $size = 18): string {
    $paths = [
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'arrow-right'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'heart'        => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/>',
        'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'        => '<polyline points="20 6 9 17 4 12"/>',
    ];
    $body = $paths[$name] ?? $paths['heart'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes favoris — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--paper-deep:#EBE8DF;--surface:#FFFEFA;--ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;--teal:#2E6B5E;--teal-tint:#E3EFEA;
  --danger:#A85245;--danger-tint:#F3E2DC;--radius:15px;--shadow-sm:0 1px 2px rgba(28,27,23,.04);--shadow-md:0 12px 28px rgba(28,27,23,.09);--ease:cubic-bezier(.22,1,.36,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15px;line-height:1.6}
a{color:inherit;text-decoration:none}
button{font:inherit;border:0;background:transparent;cursor:pointer}
svg{display:block}
h1,.km-serif{font-family:'Fraunces',Georgia,serif}

.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;height:72px;padding:0 32px;background:rgba(245,244,240,.9);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.back-link{display:inline-flex;align-items:center;gap:7px;color:var(--ink-soft);font-size:13px;font-weight:650}
.back-link:hover{color:var(--ink)}
.brand{font-family:'Fraunces',serif;font-weight:700;font-size:19px;letter-spacing:-.02em}
.brand span{color:var(--amber)}

.wrap{max-width:1080px;margin:0 auto;padding:44px 24px 70px}
.eyebrow{color:var(--amber-deep);font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
h1{margin-top:8px;font-size:clamp(26px,3.4vw,34px);font-weight:600;letter-spacing:-.03em}
.wrap>p{margin-top:6px;color:var(--ink-soft);font-size:13px;margin-bottom:26px}

.km-alert{display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;border-left:2px solid;border-radius:0 10px 10px 0;font-size:13px;margin-bottom:1.4rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;transition:transform .2s var(--ease),box-shadow .2s ease}
.card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md)}
.card-top{position:relative;height:88px;background:linear-gradient(135deg,var(--amber-tint),#e9c69d);display:flex;align-items:flex-start;justify-content:flex-end;padding:12px}
.remove-btn{display:grid;place-items:center;width:30px;height:30px;color:#c1425e;background:rgba(255,255,255,.8);border-radius:50%}
.card-body{padding:16px 18px 18px}
.card-service{color:var(--amber-deep);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.card-body h3{margin-top:6px;font-size:15.5px;font-weight:600}
.card-by{margin-top:8px;color:var(--ink-soft);font-size:12px}
.card-foot{display:flex;align-items:center;justify-content:space-between;margin-top:13px;padding-top:12px;border-top:1px solid var(--line)}
.price{font-family:'Fraunces',serif;font-weight:650;font-size:17px}
.price small{font-family:'Inter',sans-serif;font-size:10px;font-weight:600;color:var(--ink-faint)}
.btn-cmd{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 13px;border-radius:9px;background:var(--ink);color:#fff;font-size:11.5px;font-weight:700}
.btn-cmd:hover{background:var(--amber-deep)}

.empty-state{display:flex;flex-direction:column;align-items:center;gap:10px;padding:80px 20px;color:var(--ink-soft);text-align:center}
.empty-icon{display:grid;place-items:center;width:50px;height:50px;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%}
.empty-state strong{color:var(--ink);font:600 19px/1.1 'Fraunces',Georgia,serif}

.modal-overlay{position:fixed;inset:0;z-index:100;display:none;place-items:center;padding:20px;background:rgba(26,28,24,.55);backdrop-filter:blur(5px)}
.modal-overlay.open{display:grid}
.modal-card{width:min(100%,420px);padding:24px;background:var(--surface);border-radius:17px;box-shadow:var(--shadow-md)}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:14px;margin-bottom:16px;border-bottom:1px solid var(--line)}
.modal-head h2{font-size:19px}
.mfield{margin-bottom:14px}
.mfield label{display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10px;text-transform:uppercase;letter-spacing:.07em;font-weight:750}
.mfield input,.mfield select{width:100%;height:41px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--paper);font-size:12.5px;outline:0}
.full-btn{width:100%;justify-content:center;height:42px}
</style>
</head>
<body>

<header class="topbar">
  <a href="client_dashboard.php" class="back-link"><?= icon('chevron-left', 16) ?> Retour au tableau de bord</a>
  <a href="index.php" class="brand">Koud<span>Main</span></a>
</header>

<div class="wrap">
  <div class="eyebrow">Vos coups de cœur</div>
  <h1>Mes favoris</h1>
  <p>Retrouvez ici les prestations que vous avez mises de côté.</p>

  <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= icon('check', 15) ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="km-alert km-alert-err"><?= icon('x', 15) ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <?php if (empty($favoris)): ?>
    <div class="empty-state">
      <div class="empty-icon"><?= icon('heart', 22) ?></div>
      <strong>Aucun favori pour l'instant</strong>
      <span>Cliquez sur le cœur d'une prestation dans le catalogue pour la retrouver ici.</span>
    </div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($favoris as $p):
      $noteAffichee = $p['nb_avis'] > 0 ? number_format((float)$p['note_moy'], 1) : '—';
    ?>
    <div class="card">
      <div class="card-top">
        <form method="POST" action="favoris_action.php">
          <?= champCSRF() ?>
          <input type="hidden" name="action" value="retirer">
          <input type="hidden" name="id_prestation" value="<?= (int)$p['id_prestation'] ?>">
          <input type="hidden" name="retour" value="mes_favoris.php">
          <button type="submit" class="remove-btn" aria-label="Retirer des favoris"><?= icon('x', 15) ?></button>
        </form>
      </div>
      <div class="card-body">
        <span class="card-service"><?= htmlspecialchars($p['nom_service']) ?></span>
        <h3><?= htmlspecialchars($p['titre_prestation']) ?></h3>
        <p class="card-by"><?= htmlspecialchars($p['prenom_utilisateur'] . ' ' . $p['nom_utilisateur']) ?> · ★ <?= $noteAffichee ?> (<?= (int)$p['nb_avis'] ?>)</p>
        <div class="card-foot">
          <span class="price"><?= number_format($p['prix_prestation'], 0, ',', ' ') ?> <small>FCFA</small></span>
          <button type="button" class="btn-cmd" onclick="openOrderModal(<?= (int)$p['id_prestation'] ?>, '<?= htmlspecialchars(addslashes($p['titre_prestation'])) ?>', <?= (float)$p['prix_prestation'] ?>)">Commander <?= icon('arrow-right', 13) ?></button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="modal-overlay" id="modal-order">
  <div class="modal-card">
    <div class="modal-head">
      <h2>Commander</h2>
      <button type="button" onclick="document.getElementById('modal-order').classList.remove('open')" aria-label="Fermer"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" action="mes_favoris.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="commander">
      <input type="hidden" name="id_prestation" id="order-id-prest" value="">
      <div class="mfield"><label>Prestation</label><input type="text" id="order-titre" readonly></div>
      <div class="mfield"><label>Prix unitaire</label><input type="text" id="order-prix" readonly></div>
      <div class="mfield"><label>Quantité</label><input type="number" name="quantite" value="1" min="1" max="20" required></div>
      <div class="mfield">
        <label>Lieu d'intervention</label>
        <select name="id_quartier">
          <option value="0">— Mon quartier par défaut —</option>
          <?php foreach ($quartiers as $q): ?>
            <option value="<?= $q['id_quartier'] ?>"><?= htmlspecialchars($q['nom_ville'] . ' · ' . $q['nom_quartier']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-cmd full-btn">Confirmer la commande</button>
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
document.getElementById('modal-order').addEventListener('mousedown', function (e) { if (e.target === this) this.classList.remove('open'); });
</script>

</body>
</html>
