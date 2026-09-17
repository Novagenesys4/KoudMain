<?php
require_once "config.php";
requireConnexion();

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];

$conversations = listerConversations($pdo, $idUser);
$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Utilisateur');

function icon(string $name, int $size = 18): string {
    $paths = [
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'message'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'chevron-right'=> '<path d="m9 18 6-6-6-6"/>',
        'inbox'        => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
    ];
    $body = $paths[$name] ?? $paths['message'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
function initiales(string $p, string $n): string {
    return mb_strtoupper(mb_substr($p, 0, 1) . mb_substr($n, 0, 1));
}
function tempsEcoule(?string $date): string {
    if (!$date) return '';
    $diff = time() - strtotime($date);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    if ($diff < 604800) return floor($diff / 86400) . ' j';
    return date('d/m/Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--surface:#FFFEFA;--ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;--shadow-sm:0 1px 2px rgba(28,27,23,.04);--shadow-md:0 10px 26px rgba(28,27,23,.08);--ease:cubic-bezier(.22,1,.36,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:15px;line-height:1.6}
a{color:inherit;text-decoration:none}
svg{display:block}
h1,.km-serif{font-family:'Fraunces',Georgia,serif}

.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;height:72px;padding:0 32px;background:rgba(245,244,240,.9);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.back-link{display:inline-flex;align-items:center;gap:7px;color:var(--ink-soft);font-size:13px;font-weight:650}
.back-link:hover{color:var(--ink)}
.brand{font-family:'Fraunces',serif;font-weight:700;font-size:19px;letter-spacing:-.02em}
.brand span{color:var(--amber)}

.wrap{max-width:720px;margin:0 auto;padding:44px 24px 70px}
.eyebrow{color:var(--amber-deep);font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
h1{margin-top:8px;font-size:clamp(26px,3.4vw,34px);font-weight:600;letter-spacing:-.03em;margin-bottom:24px}

.conv-list{display:flex;flex-direction:column;gap:9px}
.conv-card{display:grid;grid-template-columns:44px 1fr auto;align-items:center;gap:13px;padding:15px 16px;background:var(--surface);border:1px solid var(--line);border-radius:13px;box-shadow:var(--shadow-sm);transition:transform .18s var(--ease),box-shadow .18s ease}
.conv-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.conv-card.unread{border-color:rgba(187,108,45,.35)}
.avatar{display:grid;place-items:center;width:44px;height:44px;color:#7c481c;background:var(--amber-tint);border-radius:50%;font-size:13px;font-weight:750}
.conv-body strong{display:block;font-size:13.5px;font-weight:700}
.conv-body p{margin-top:2px;color:var(--ink-soft);font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.conv-tag{display:inline-block;margin-top:3px;color:var(--ink-faint);font-size:10.5px}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.conv-time{color:var(--ink-faint);font-size:10.5px}
.unread-badge{display:grid;place-items:center;min-width:19px;height:19px;padding:0 5px;background:var(--amber);color:#fff;border-radius:20px;font-size:10px;font-weight:800}

.empty-state{display:flex;flex-direction:column;align-items:center;gap:10px;padding:70px 20px;color:var(--ink-soft);text-align:center}
.empty-icon{display:grid;place-items:center;width:48px;height:48px;color:var(--amber-deep);background:var(--amber-tint);border-radius:50%}
.empty-state strong{color:var(--ink);font:600 18px/1.1 'Fraunces',Georgia,serif}
</style>
</head>
<body>

<header class="topbar">
  <a href="<?= htmlspecialchars($dashboard) ?>" class="back-link"><?= icon('chevron-left', 16) ?> Retour au tableau de bord</a>
  <a href="index.php" class="brand">Koud<span>Main</span></a>
</header>

<div class="wrap">
  <div class="eyebrow">Messagerie</div>
  <h1>Bonjour, <?= $prenom ?></h1>

  <?php if (empty($conversations)): ?>
    <div class="empty-state">
      <div class="empty-icon"><?= icon('inbox', 22) ?></div>
      <strong>Aucune conversation</strong>
      <span>Vos échanges liés à vos commandes apparaîtront ici.</span>
    </div>
  <?php else: ?>
  <div class="conv-list">
    <?php foreach ($conversations as $c): ?>
    <a href="conversation.php?id_commande=<?= (int)$c['id_commande'] ?>" class="conv-card <?= $c['nb_non_lus'] > 0 ? 'unread' : '' ?>">
      <div class="avatar"><?= initiales($c['interlocuteur_prenom'], $c['interlocuteur_nom']) ?></div>
      <div class="conv-body">
        <strong><?= htmlspecialchars($c['interlocuteur_prenom'] . ' ' . $c['interlocuteur_nom']) ?></strong>
        <p><?= $c['dernier_message'] ? htmlspecialchars(mb_substr($c['dernier_message'], 0, 70)) : 'Aucun message échangé.' ?></p>
        <span class="conv-tag">Commande #<?= (int)$c['id_commande'] ?> · <?= htmlspecialchars($c['titre_prestation']) ?></span>
      </div>
      <div class="conv-meta">
        <span class="conv-time"><?= tempsEcoule($c['derniere_activite']) ?></span>
        <?php if ($c['nb_non_lus'] > 0): ?><span class="unread-badge"><?= (int)$c['nb_non_lus'] ?></span><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</body>
</html>
