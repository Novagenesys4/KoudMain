<?php
require_once "config.php";
requireConnexion();

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];

$idCommande = (int)($_GET['id_commande'] ?? 0);
$cmd = commandeAccessibleA($pdo, $idCommande, $idUser);

if (!$cmd) {
    http_response_code(403);
    die("<div style='font-family:system-ui,sans-serif;padding:3rem;max-width:480px;margin:4rem auto;background:#F3E2DC;border-radius:12px;color:#7A2E1D'>
         <b>Accès refusé</b><br>Vous n'êtes pas autorisé à consulter cette conversation.
         <br><a href='javascript:history.back()' style='color:#8D4E1F;font-weight:700'>← Retour</a></div>");
}

$conv = getOuCreerConversation($pdo, $idCommande);
marquerMessagesLus($pdo, $conv['id_conversation'], $idUser);

$stmtMsg = $pdo->prepare("
    SELECT m.*, u.prenom_utilisateur, u.nom_utilisateur
    FROM Message m
    JOIN Utilisateur u ON u.id_utilisateur = m.id_expediteur
    WHERE m.id_conversation = ?
    ORDER BY m.date_envoi ASC
");
$stmtMsg->execute([$conv['id_conversation']]);
$messages = $stmtMsg->fetchAll();

$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');

$idClientReel      = (int)$cmd['id_utilisateur'];
$idPrestataireReel = (int)$cmd['id_prestataire_reel'];
$roleActeur         = $cmd['role_acteur'];

// Nom de l'interlocuteur affiché dans l'en-tête
$stmtInterloc = $pdo->prepare("
    SELECT prenom_utilisateur, nom_utilisateur FROM Utilisateur WHERE id_utilisateur = ?
");
if ($roleActeur === 'prestataire') {
    $stmtInterloc->execute([$idClientReel]);
} else {
    $stmtInterloc->execute([$idPrestataireReel]);
}
$interlocuteur = $stmtInterloc->fetch();
$nomInterlocuteur = $interlocuteur ? htmlspecialchars($interlocuteur['prenom_utilisateur'] . ' ' . $interlocuteur['nom_utilisateur']) : 'Interlocuteur';

$err = $_GET['err'] ?? '';

function icon(string $name, int $size = 18): string {
    $paths = [
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'send'         => '<path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/>',
        'package'      => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.29 7 8.71 5 8.71-5"/><path d="M12 22V12"/>',
        'x'            => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    ];
    $body = $paths[$name] ?? $paths['send'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
function initiales(string $p, string $n): string {
    return mb_strtoupper(mb_substr($p, 0, 1) . mb_substr($n, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conversation — Commande #<?= $idCommande ?> — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--surface:#FFFEFA;--ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;--teal:#2E6B5E;--teal-tint:#E3EFEA;
  --danger:#A85245;--danger-tint:#F3E2DC;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:'Inter',sans-serif;font-size:14.5px;line-height:1.55;display:flex;flex-direction:column;height:100vh;overflow:hidden}
a{color:inherit;text-decoration:none}
button{font:inherit;border:0;cursor:pointer}
svg{display:block}

.topbar{display:flex;align-items:center;gap:14px;height:68px;padding:0 22px;background:var(--surface);border-bottom:1px solid var(--line);flex:0 0 auto}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--ink-soft);font-size:12.5px;font-weight:650}
.back-link:hover{color:var(--ink)}
.topbar-person{display:flex;align-items:center;gap:10px;margin-left:8px}
.avatar{display:grid;place-items:center;width:36px;height:36px;color:#7c481c;background:var(--amber-tint);border-radius:50%;font-size:12px;font-weight:750}
.topbar-person strong{display:block;font-size:13.5px}
.topbar-person span{display:block;color:var(--ink-faint);font-size:11px;margin-top:1px}
.cmd-chip{margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:7px 13px;background:var(--paper);border:1px solid var(--line);border-radius:30px;font-size:11.5px;color:var(--ink-soft)}

.err-bar{padding:10px 22px;background:var(--danger-tint);color:#7A2E1D;font-size:12.5px}

.thread{flex:1;overflow-y:auto;padding:26px 22px;display:flex;flex-direction:column;gap:12px}
.empty-thread{margin:auto;text-align:center;color:var(--ink-faint);font-size:13px}
.bubble-row{display:flex;flex-direction:column;max-width:66%}
.bubble-row.mine{align-self:flex-end;align-items:flex-end}
.bubble-row.theirs{align-self:flex-start;align-items:flex-start}
.bubble{padding:10px 14px;border-radius:16px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.bubble-row.mine .bubble{background:var(--ink);color:#fff;border-bottom-right-radius:4px}
.bubble-row.theirs .bubble{background:var(--surface);border:1px solid var(--line);border-bottom-left-radius:4px}
.bubble-meta{margin-top:4px;color:var(--ink-faint);font-size:10px;padding:0 4px}

.composer{flex:0 0 auto;padding:14px 22px 20px;background:var(--surface);border-top:1px solid var(--line)}
.composer form{display:flex;align-items:flex-end;gap:10px}
.composer textarea{flex:1;max-height:120px;min-height:44px;padding:11px 14px;border:1px solid var(--line);border-radius:14px;background:var(--paper);color:var(--ink);font-size:13.5px;font-family:inherit;resize:none;outline:0}
.composer textarea:focus{border-color:var(--amber)}
.send-btn{display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:var(--ink);color:#fff;flex:0 0 auto;transition:background .18s ease}
.send-btn:hover{background:var(--amber-deep)}
.composer-hint{margin-top:8px;color:var(--ink-faint);font-size:10.5px}
</style>
</head>
<body>

<header class="topbar">
  <a href="<?= htmlspecialchars($dashboard) ?>?tab=commandes" class="back-link"><?= icon('chevron-left', 16) ?> Retour</a>
  <div class="topbar-person">
    <div class="avatar"><?= initiales($interlocuteur['prenom_utilisateur'] ?? '?', $interlocuteur['nom_utilisateur'] ?? '?') ?></div>
    <div><strong><?= $nomInterlocuteur ?></strong><span><?= htmlspecialchars($cmd['titre_prestation']) ?></span></div>
  </div>
  <span class="cmd-chip"><?= icon('package', 13) ?> Commande #<?= $idCommande ?> · <?= htmlspecialchars($cmd['statut']) ?></span>
</header>

<?php if ($err): ?><div class="err-bar"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="thread" id="thread">
  <?php if (empty($messages)): ?>
    <p class="empty-thread">Aucun message pour l'instant. Lancez la conversation !</p>
  <?php else: foreach ($messages as $m):
    $estMoi = (int)$m['id_expediteur'] === $idUser;
  ?>
  <div class="bubble-row <?= $estMoi ? 'mine' : 'theirs' ?>">
    <div class="bubble"><?= nl2br(htmlspecialchars($m['contenu'])) ?></div>
    <div class="bubble-meta"><?= date('d/m H:i', strtotime($m['date_envoi'])) ?></div>
  </div>
  <?php endforeach; endif; ?>
</div>

<div class="composer">
  <form method="POST" action="envoyer_message.php">
    <?= champCSRF() ?>
    <input type="hidden" name="id_commande" value="<?= $idCommande ?>">
    <textarea name="contenu" placeholder="Écrivez votre message…" required maxlength="5000" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"></textarea>
    <button type="submit" class="send-btn" aria-label="Envoyer"><?= icon('send', 18) ?></button>
  </form>
  <p class="composer-hint">Les coordonnées (email, téléphone) partagées ici sont automatiquement masquées.</p>
</div>

<script>
  var thread = document.getElementById('thread');
  thread.scrollTop = thread.scrollHeight;
</script>

</body>
</html>
