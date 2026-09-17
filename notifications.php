<?php
require_once "config.php";
requireConnexion();

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];

$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');

// ---------------------------------------------------------------------------
// Ouvrir une notification : la marquer lue puis rediriger vers la commande liée
// ---------------------------------------------------------------------------
if (isset($_GET['ouvrir'])) {
    $idNotif = (int)$_GET['ouvrir'];
    $stmt = $pdo->prepare("SELECT * FROM Notification WHERE id_notification = ? AND id_utilisateur = ?");
    $stmt->execute([$idNotif, $idUser]);
    $notif = $stmt->fetch();

    if ($notif) {
        $pdo->prepare("
            UPDATE Notification
            SET est_lue = true, date_lecture = COALESCE(date_lecture, CURRENT_TIMESTAMP)
            WHERE id_notification = ?
        ")->execute([$idNotif]);

        if (!empty($notif['id_commande'])) {
            rediriger($dashboard . '?tab=commandes');
        }
    }
    rediriger('notifications.php');
}

// ---------------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    if ($_POST['action'] === 'marquer_tout_lu') {
        $pdo->prepare("
            UPDATE Notification
            SET est_lue = true, date_lecture = COALESCE(date_lecture, CURRENT_TIMESTAMP)
            WHERE id_utilisateur = ? AND est_lue = false
        ")->execute([$idUser]);
        rediriger('notifications.php' . (isset($_GET['filtre']) ? '?filtre=' . urlencode($_GET['filtre']) : ''));
    }

    if ($_POST['action'] === 'marquer_lu') {
        $idNotif = (int)($_POST['id_notification'] ?? 0);
        $pdo->prepare("
            UPDATE Notification
            SET est_lue = true, date_lecture = COALESCE(date_lecture, CURRENT_TIMESTAMP)
            WHERE id_notification = ? AND id_utilisateur = ?
        ")->execute([$idNotif, $idUser]);
        rediriger('notifications.php' . (isset($_GET['filtre']) ? '?filtre=' . urlencode($_GET['filtre']) : ''));
    }
}

// ---------------------------------------------------------------------------
// Liste + pagination
// ---------------------------------------------------------------------------
$filtre = ($_GET['filtre'] ?? 'toutes') === 'non_lues' ? 'non_lues' : 'toutes';
$limit  = 20;
$page   = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $limit;

$whereFiltre = $filtre === 'non_lues' ? "AND est_lue = false" : "";

$stCount = $pdo->prepare("SELECT COUNT(*) FROM Notification WHERE id_utilisateur = ? $whereFiltre");
$stCount->execute([$idUser]);
$nbTotal   = (int)$stCount->fetchColumn();
$nbPages   = max(1, (int)ceil($nbTotal / $limit));

$stmt = $pdo->prepare("
    SELECT * FROM Notification
    WHERE id_utilisateur = ? $whereFiltre
    ORDER BY date_creation DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$idUser]);
$notifications = $stmt->fetchAll();

$nbNonLues = nbNotificationsNonLues($pdo, $idUser);

$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Utilisateur');

// ---------------------------------------------------------------------------
// Icônes / libellés par type de notification
// ---------------------------------------------------------------------------
function notifIcone(string $type): string {
    return match ($type) {
        'nouvelle_commande'                          => 'package',
        'commande_acceptee'                          => 'check',
        'commande_en_cours'                          => 'zap',
        'commande_terminee'                          => 'check-circle',
        'commande_annulee'                            => 'x-circle',
        'nouveau_message'                            => 'message',
        'nouvel_avis', 'avis_modifie'                => 'star',
        'paiement_bloque', 'paiement_libere', 'remboursement' => 'wallet',
        'litige_ouvert', 'litige_resolu'             => 'alert',
        default                                      => 'bell',
    };
}
function notifCouleur(string $type): string {
    return match ($type) {
        'commande_annulee', 'litige_ouvert' => 'notif-danger',
        'commande_terminee', 'paiement_libere', 'remboursement' => 'notif-teal',
        default => 'notif-amber',
    };
}
function icon(string $name, int $size = 18): string {
    $paths = [
        'bell'         => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'package'      => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.29 7 8.71 5 8.71-5"/><path d="M12 22V12"/>',
        'check'        => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x-circle'     => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'zap'          => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'message'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'star'         => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'wallet'       => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'alert'        => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'check-all'    => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
        'inbox'        => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
    ];
    $body = $paths[$name] ?? $paths['bell'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function tempsEcoule(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    if ($diff < 172800) return 'hier';
    if ($diff < 604800) return floor($diff / 86400) . ' j';
    return date('d/m/Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--paper-deep:#EBE8DF;--surface:#FFFEFA;
  --ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;
  --teal:#2E6B5E;--teal-tint:#E3EFEA;
  --danger:#A85245;--danger-tint:#F3E2DC;
  --radius:15px;
  --shadow-sm:0 1px 2px rgba(28,27,23,.04);
  --shadow-md:0 10px 26px rgba(28,27,23,.08);
  --ease:cubic-bezier(.22,1,.36,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button{font:inherit;border:0;background:transparent;cursor:pointer}
h1,.km-serif{font-family:'Fraunces',Georgia,serif}
svg{display:block}

.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:16px;height:72px;padding:0 32px;background:rgba(245,244,240,.9);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.back-link{display:inline-flex;align-items:center;gap:7px;color:var(--ink-soft);font-size:13px;font-weight:650;transition:color .18s ease}
.back-link:hover{color:var(--ink)}
.brand{font-family:'Fraunces',serif;font-weight:700;font-size:19px;letter-spacing:-.02em}
.brand span{color:var(--amber)}

.wrap{max-width:760px;margin:0 auto;padding:44px 24px 70px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:26px;flex-wrap:wrap}
.eyebrow{color:var(--amber-deep);font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
h1{margin-top:8px;font-size:clamp(26px,3.4vw,34px);font-weight:600;letter-spacing:-.03em}
.page-head p{margin-top:6px;color:var(--ink-soft);font-size:13px}

.tabs{display:flex;gap:8px;margin-bottom:20px}
.tab-link{padding:8px 15px;border-radius:30px;font-size:12.5px;font-weight:650;color:var(--ink-soft);background:var(--surface);border:1px solid var(--line)}
.tab-link.active{background:var(--ink);color:#fff;border-color:var(--ink)}
.mark-all{margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;color:var(--amber-deep);background:var(--amber-tint);font-size:12px;font-weight:700}
.mark-all:hover{background:#ecd5b4}

.notif-list{display:flex;flex-direction:column;gap:9px}
.notif-card{display:grid;grid-template-columns:38px 1fr auto;align-items:flex-start;gap:13px;padding:15px 16px;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow-sm);transition:transform .18s var(--ease),box-shadow .18s ease,border-color .18s ease}
.notif-card.unread{border-color:rgba(187,108,45,.35);background:linear-gradient(120deg,rgba(244,228,212,.4),var(--surface) 55%)}
.notif-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.notif-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:10px}
.notif-amber{color:var(--amber-deep);background:var(--amber-tint)}
.notif-teal{color:var(--teal);background:var(--teal-tint)}
.notif-danger{color:var(--danger);background:var(--danger-tint)}
.notif-body strong{display:block;font-size:13.5px;font-weight:700}
.notif-body p{margin-top:3px;color:var(--ink-soft);font-size:12.5px;line-height:1.5}
.notif-meta{display:flex;flex-direction:column;align-items:flex-end;gap:8px;white-space:nowrap}
.notif-time{color:var(--ink-faint);font-size:10.5px}
.unread-dot{width:8px;height:8px;border-radius:50%;background:var(--amber)}
.notif-mark{font-size:10.5px;color:var(--ink-faint);font-weight:650}
.notif-mark:hover{color:var(--ink)}

.empty-state{display:flex;flex-direction:column;align-items:center;gap:10px;padding:70px 20px;color:var(--ink-soft);text-align:center}
.empty-icon{display:grid;place-items:center;width:48px;height:48px;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%}
.empty-state strong{color:var(--ink);font:600 18px/1.1 'Fraunces',Georgia,serif}

.pagination{display:flex;justify-content:center;gap:6px;margin-top:24px}
.page-button{display:grid;place-items:center;min-width:30px;height:30px;padding:0 9px;color:var(--ink-soft);background:var(--surface);border:1px solid var(--line);border-radius:8px;font-size:11px;font-weight:700}
.page-button.active{color:#fff;background:var(--ink);border-color:var(--ink)}

@media(max-width:600px){
  .topbar{padding:0 18px}
  .wrap{padding:30px 16px 55px}
  .notif-card{grid-template-columns:34px 1fr;gap:10px}
  .notif-meta{grid-column:1/-1;flex-direction:row;justify-content:space-between;align-items:center;margin-top:4px}
}
</style>
</head>
<body>

<header class="topbar">
  <a href="<?= htmlspecialchars($dashboard) ?>" class="back-link"><?= icon('chevron-left', 16) ?> Retour au tableau de bord</a>
  <a href="index.php" class="brand">Koud<span>Main</span></a>
</header>

<div class="wrap">
  <div class="page-head">
    <div>
      <div class="eyebrow">Centre de notifications</div>
      <h1>Bonjour, <?= $prenom ?></h1>
      <p><?= $nbNonLues > 0 ? "$nbNonLues notification" . ($nbNonLues > 1 ? 's' : '') . ' non lue' . ($nbNonLues > 1 ? 's' : '') : 'Tout est à jour.' ?></p>
    </div>
  </div>

  <div class="tabs">
    <a href="?filtre=toutes" class="tab-link <?= $filtre === 'toutes' ? 'active' : '' ?>">Toutes</a>
    <a href="?filtre=non_lues" class="tab-link <?= $filtre === 'non_lues' ? 'active' : '' ?>">Non lues<?= $nbNonLues > 0 ? " ($nbNonLues)" : '' ?></a>
    <?php if ($nbNonLues > 0): ?>
    <form method="POST" style="margin-left:auto">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="marquer_tout_lu">
      <button type="submit" class="mark-all"><?= icon('check-all', 14) ?> Tout marquer comme lu</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="empty-state">
      <div class="empty-icon"><?= icon('inbox', 22) ?></div>
      <strong><?= $filtre === 'non_lues' ? 'Aucune notification non lue' : 'Aucune notification' ?></strong>
      <span>Vous serez prévenu ici de toute activité sur vos commandes.</span>
    </div>
  <?php else: ?>
  <div class="notif-list">
    <?php foreach ($notifications as $n): ?>
    <div class="notif-card <?= $n['est_lue'] ? '' : 'unread' ?>">
      <div class="notif-icon <?= notifCouleur($n['type_notification']) ?>"><?= icon(notifIcone($n['type_notification']), 17) ?></div>
      <a href="<?= $n['id_commande'] ? 'notifications.php?ouvrir=' . (int)$n['id_notification'] : '#' ?>" class="notif-body">
        <strong><?= htmlspecialchars($n['titre']) ?></strong>
        <p><?= htmlspecialchars($n['contenu']) ?></p>
      </a>
      <div class="notif-meta">
        <span class="notif-time"><?= tempsEcoule($n['date_creation']) ?></span>
        <?php if (!$n['est_lue']): ?>
          <form method="POST">
            <?= champCSRF() ?>
            <input type="hidden" name="action" value="marquer_lu">
            <input type="hidden" name="id_notification" value="<?= (int)$n['id_notification'] ?>">
            <button type="submit" class="notif-mark">Marquer comme lu</button>
          </form>
        <?php else: ?>
          <span class="notif-time">Lue</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($nbPages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $nbPages; $i++): ?>
      <a href="?filtre=<?= $filtre ?>&p=<?= $i ?>" class="page-button <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; endif; ?>
</div>

</body>
</html>
