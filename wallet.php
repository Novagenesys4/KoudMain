<?php
require_once "config.php";
requireConnexion();

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

// --- Récupérer ou créer le wallet ---
$wallet    = getOuCreerWallet($pdo, $idUser);
$id_wallet = (int)$wallet['id_wallet'];
$solde     = (float)$wallet['solde'];

// =============================================================
//  ACTIONS POST
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();

    // --- RECHARGER ---
    if ($_POST['action'] === 'recharger') {
        $montant = (float)str_replace(',', '.', $_POST['montant'] ?? 0);
        $methode = htmlspecialchars($_POST['methode'] ?? 'Simulation');

        if ($montant < 500) {
            $err = "Le montant minimum de recharge est de 500 FCFA.";
        } elseif ($montant > 1000000) {
            $err = "Le montant maximum par recharge est de 1 000 000 FCFA.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtLock = $pdo->prepare("SELECT id_wallet, solde FROM Wallet WHERE id_utilisateur = ? FOR UPDATE");
                $stmtLock->execute([$idUser]);
                $wData = $stmtLock->fetch();

                $soldeActuel   = (float)$wData['solde'];
                $nouveau_solde = $soldeActuel + $montant;

                $pdo->prepare("UPDATE Wallet SET solde = ? WHERE id_wallet = ?")
                    ->execute([$nouveau_solde, $wData['id_wallet']]);

                $pdo->prepare("
                    INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
                    VALUES (?, 'credit', ?, ?, ?)
                ")->execute([$wData['id_wallet'], $montant, "Recharge via $methode", $nouveau_solde]);

                $pdo->commit();
                $solde = $nouveau_solde;
                $wallet['solde'] = $nouveau_solde;
                $msg   = "Recharge de " . number_format($montant, 0, ',', ' ') . " FCFA effectuée avec succès !";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = "Erreur lors de la recharge : " . $e->getMessage();
            }
        }
    }

    // --- RETRAIT (prestataires uniquement) ---
    elseif ($_POST['action'] === 'retrait' && estPrestataire()) {
        $montant = (float)str_replace(',', '.', $_POST['montant'] ?? 0);
        $rib     = trim($_POST['rib'] ?? '');

        if ($montant < 1000) {
            $err = "Le montant minimum de retrait est de 1 000 FCFA.";
        } elseif (empty($rib)) {
            $err = "Veuillez indiquer un numéro de compte / mobile money.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtLock = $pdo->prepare("SELECT id_wallet, solde FROM Wallet WHERE id_utilisateur = ? FOR UPDATE");
                $stmtLock->execute([$idUser]);
                $wData = $stmtLock->fetch();

                $soldeActuel = (float)$wData['solde'];

                if ($montant > $soldeActuel) {
                    $pdo->rollBack();
                    $err = "Solde insuffisant. Votre solde actuel est de " . number_format($soldeActuel, 0, ',', ' ') . " FCFA.";
                } else {
                    $nouveau_solde = $soldeActuel - $montant;

                    $pdo->prepare("UPDATE Wallet SET solde = ? WHERE id_wallet = ?")
                        ->execute([$nouveau_solde, $wData['id_wallet']]);

                    $pdo->prepare("
                        INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
                        VALUES (?, 'retrait', ?, ?, ?)
                    ")->execute([$wData['id_wallet'], $montant, "Retrait vers $rib", $nouveau_solde]);

                    $pdo->commit();
                    $solde = $nouveau_solde;
                    $wallet['solde'] = $nouveau_solde;
                    $msg   = "Demande de retrait de " . number_format($montant, 0, ',', ' ') . " FCFA enregistrée.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = "Erreur lors du retrait : " . $e->getMessage();
            }
        }
    }
}

// --- Historique des transactions ---
$transactions = $pdo->prepare("
    SELECT * FROM Transaction_Wallet
    WHERE id_wallet = ?
    ORDER BY date_transaction DESC
    LIMIT 50
");
$transactions->execute([$id_wallet]);
$transactions = $transactions->fetchAll();

// --- Stats rapides ---
$total_credit = $pdo->prepare("
    SELECT COALESCE(SUM(montant), 0) FROM Transaction_Wallet
    WHERE id_wallet = ? AND type_transaction = 'credit'
");
$total_credit->execute([$id_wallet]);
$total_credit = (float)$total_credit->fetchColumn();

$total_debit = $pdo->prepare("
    SELECT COALESCE(SUM(montant), 0) FROM Transaction_Wallet
    WHERE id_wallet = ? AND type_transaction IN ('debit', 'retrait')
");
$total_debit->execute([$id_wallet]);
$total_debit = (float)$total_debit->fetchColumn();

$nb_tx = count($transactions);

// ── Répartition mensuelle réelle (6 derniers mois, à partir de l'historique déjà chargé) ──
$mois_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin','07'=>'Juil','08'=>'Août','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
$par_mois = [];
for ($i = 5; $i >= 0; $i--) {
    $cle = date('Y-m', strtotime("-$i months"));
    $par_mois[$cle] = ['label' => $mois_fr[date('m', strtotime("-$i months"))], 'credits' => 0.0];
}
foreach ($transactions as $tx) {
    $cle = date('Y-m', strtotime($tx['date_transaction']));
    if (isset($par_mois[$cle]) && $tx['type_transaction'] === 'credit') {
        $par_mois[$cle]['credits'] += (float)$tx['montant'];
    }
}
$max_mois = max(1, ...array_values(array_map(fn($m) => $m['credits'], $par_mois)));

// Redirection selon rôle pour les liens
$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon Wallet — KoudMain</title>
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
.km-sb-section{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#8B8778;padding:1rem .85rem .3rem}
.km-sb-foot{padding:1rem .9rem 1.3rem;border-top:1px solid rgba(255,255,255,.1);font-size:.78rem;color:#8B8778}
.km-sb-foot a{color:var(--amber);font-weight:600}

/* ── MAIN ── */
.km-main{flex:1;margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.km-topbar{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 1.8rem;background:var(--paper);border-bottom:1px solid var(--line);
  position:sticky;top:0;z-index:50;
}
.km-menu-toggle{display:none;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--ink)}
.km-topbar-title{font-size:1.05rem;font-weight:600}
.km-topbar-search{position:relative;max-width:260px}
.km-topbar-search input{
  padding:.5rem .9rem .5rem 2.2rem;background:var(--surface);border:1px solid var(--line);
  border-radius:20px;font-size:.84rem;color:var(--ink);outline:none;width:100%;
}
.km-topbar-search .s-icon{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);opacity:.5;font-size:.8rem}
.km-avatar{width:34px;height:34px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.88rem}
.km-topbar-user{display:flex;align-items:center;gap:.55rem}
.km-topbar-user-name{font-size:.85rem;font-weight:600}
.km-topbar-user-role{font-size:.7rem;color:var(--ink-soft)}

.km-content{padding:1.8rem 1.8rem 3rem;flex:1;max-width:1180px}

/* ── Alerts ── */
.km-alert{padding:.75rem 1rem;border-left:2px solid;font-size:.86rem;margin-bottom:1.2rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

/* ── Balance hero ── */
.km-balance-hero{
  background:var(--ink);color:#fff;border-radius:20px;padding:2.2rem 2.4rem;
  display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;margin-bottom:1.6rem;
}
.km-balance-label{font-size:.78rem;color:#B9B4A5;text-transform:uppercase;letter-spacing:.06em}
.km-balance-amount{font-family:'Fraunces',serif;font-size:2.5rem;font-weight:600;margin-top:.4rem}
.km-balance-amount small{font-size:1rem;font-weight:400;color:#B9B4A5}
.km-balance-actions{display:flex;gap:.7rem;flex-wrap:wrap}

/* ── Buttons ── */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.65rem 1.15rem;border-radius:8px;border:1px solid transparent;font-weight:600;
  font-size:.86rem;cursor:pointer;transition:transform .16s ease,background .16s ease;
}
.km-btn:active{transform:scale(.97)}
.km-btn-amber{background:var(--amber);color:var(--ink)}
.km-btn-amber:hover{background:#CE8442}
.km-btn-ghost{background:rgba(255,255,255,.08);color:#fff;border-color:rgba(255,255,255,.2)}
.km-btn-ghost:hover{background:rgba(255,255,255,.15)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep)}
.km-btn-outline{background:transparent;border-color:var(--line);color:var(--ink)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-block{width:100%}

/* ── Stat tiles ── */
.km-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.6rem}
.km-stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1.1rem 1.25rem}
.km-stat-val{font-family:'Fraunces',serif;font-size:1.5rem;font-weight:600}
.km-stat-val.teal{color:var(--teal)}
.km-stat-val.danger{color:var(--danger)}
.km-stat-label{font-size:.78rem;color:var(--ink-soft);margin-top:.2rem}

/* ── Flux bar ── */
.km-flux{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1.2rem 1.3rem;margin-bottom:1.6rem}
.km-flux-labels{display:flex;justify-content:space-between;font-size:.78rem;color:var(--ink-soft);margin-bottom:.5rem}
.km-flux-track{height:6px;background:var(--paper-deep);border-radius:6px;overflow:hidden}
.km-flux-fill{height:100%;background:var(--teal);border-radius:6px;transition:width 1s ease}

/* ── Two col ── */
.km-two-col{display:grid;grid-template-columns:1.3fr 1fr;gap:1.3rem;margin-bottom:1.6rem}
.km-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.km-panel-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--line)}
.km-panel-head h3{font-size:.92rem;font-weight:600;font-family:'Inter',sans-serif}
.km-panel-head a{font-size:.8rem;color:var(--amber-deep);font-weight:600}
.km-panel-body{padding:1.1rem 1.25rem}

/* Mini chart */
.km-chart{display:flex;align-items:flex-end;gap:10px;height:120px}
.km-chart-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.4rem}
.km-chart-bar{width:100%;background:linear-gradient(180deg,var(--amber),var(--amber-tint));border-radius:5px 5px 2px 2px;min-height:4px;transition:height .5s ease}
.km-chart-label{font-size:.68rem;color:var(--ink-soft)}

/* Recent tx list */
.km-tx-row{display:flex;align-items:center;gap:.8rem;padding:.7rem 0;border-bottom:1px solid var(--line)}
.km-tx-row:last-child{border-bottom:none}
.km-tx-dot{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.km-tx-dot.credit{background:var(--teal-tint);color:var(--teal)}
.km-tx-dot.debit{background:var(--amber-tint);color:var(--amber-deep)}
.km-tx-label{flex:1;font-size:.86rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.km-tx-time{font-size:.72rem;color:var(--ink-faint)}
.km-tx-amount{font-family:'Fraunces',serif;font-weight:600;font-size:.9rem;white-space:nowrap}
.km-tx-amount.credit{color:var(--teal)}

/* Quick actions */
.km-quick-list{display:flex;flex-direction:column;gap:.6rem}
.km-quick-btn{display:flex;align-items:center;gap:.7rem;padding:.75rem .9rem;border:1px solid var(--line);border-radius:10px;font-size:.86rem;font-weight:600;transition:border-color .18s ease,background .18s ease}
.km-quick-btn:hover{border-color:var(--amber-deep);background:var(--amber-tint)}

/* Table historique */
.km-tx-table-wrap{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.km-tx-thead{display:grid;grid-template-columns:52px 1fr 150px 120px 120px;background:var(--paper-deep);border-bottom:1px solid var(--line)}
.km-tx-thead div{padding:.7rem .9rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft)}
.km-tx-trow{display:grid;grid-template-columns:52px 1fr 150px 120px 120px;border-bottom:1px solid var(--line);transition:background .15s ease}
.km-tx-trow:hover{background:var(--paper)}
.km-tx-trow > div{padding:.75rem .9rem;display:flex;align-items:center;font-size:.85rem}
.km-tx-trow .lib{font-weight:600}
.km-tx-trow .sub{font-size:.7rem;color:var(--ink-faint);font-weight:400}
.km-tx-cell-right{justify-content:flex-end;text-align:right}

.km-badge{display:inline-flex;padding:.2rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700}
.km-badge-teal{background:var(--teal-tint);color:var(--teal)}
.km-badge-amber{background:var(--amber-tint);color:var(--amber-deep)}
.km-badge-neutral{background:var(--paper-deep);color:var(--ink-soft)}

.km-empty{text-align:center;padding:3rem 1rem;color:var(--ink-soft)}

/* ── Modal ── */
.km-modal-overlay{display:none;position:fixed;inset:0;background:rgba(28,27,23,.55);z-index:200;align-items:center;justify-content:center;padding:1rem}
.km-modal-overlay.open{display:flex}
.km-modal{background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;width:100%;max-width:440px;max-height:90vh;overflow-y:auto;border:1px solid var(--line)}
.km-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.km-modal-head h3{font-size:1.05rem;font-weight:600}
.km-modal-close{background:var(--paper);border:1px solid var(--line);width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:.9rem;color:var(--ink-soft)}
.km-mfield{margin-bottom:1rem}
.km-mfield label{display:block;font-size:.75rem;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem}
.km-mfield input{width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:8px;background:var(--paper);font-family:'Inter',sans-serif;font-size:.9rem;color:var(--ink);outline:none}
.km-mfield small{display:block;color:var(--ink-faint);font-size:.74rem;margin-top:.35rem}
.km-presets{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.7rem}
.km-preset{padding:.35rem .8rem;background:var(--paper);border:1px solid var(--line);border-radius:20px;font-size:.78rem;font-weight:600;color:var(--ink-soft);cursor:pointer}
.km-preset:hover{border-color:var(--amber-deep);color:var(--amber-deep)}
.km-methode-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.9rem}
.km-methode-opt input{display:none}
.km-methode-lbl{display:flex;align-items:center;gap:.4rem;padding:.6rem .8rem;background:var(--paper);border:1.5px solid var(--line);border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600;color:var(--ink-soft)}
.km-methode-opt input:checked + .km-methode-lbl{border-color:var(--amber-deep);color:var(--amber-deep);background:var(--amber-tint)}
.km-sim-note{padding:.6rem .8rem;background:var(--teal-tint);color:#1E4B41;border-radius:8px;font-size:.78rem}

a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--amber-deep);outline-offset:2px}

@media(max-width:1080px){.km-two-col{grid-template-columns:1fr}.km-stats{grid-template-columns:1fr 1fr}}
@media(max-width:860px){
  .km-sidebar{transform:translateX(-100%)}
  .km-sidebar.open{transform:translateX(0)}
  .km-main{margin-left:0}
  .km-menu-toggle{display:block}
  .km-topbar-search{display:none}
}
@media(max-width:560px){
  .km-content{padding:1.2rem 1rem 2.5rem}
  .km-topbar{padding:.8rem 1rem}
  .km-balance-hero{padding:1.6rem 1.4rem}
  .km-balance-amount{font-size:2rem}
  .km-tx-thead,.km-tx-trow{grid-template-columns:44px 1fr 90px}
  .km-tx-thead div:nth-child(4),.km-tx-thead div:nth-child(5),
  .km-tx-trow > div:nth-child(4),.km-tx-trow > div:nth-child(5){display:none}
}
</style>
</head>
<body>

<div class="km-app">

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand"><a href="index.php">Koud<em>Main</em></a></div>

    <nav class="km-sb-nav">
      <a href="<?= $dashboard ?>" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Vue d'ensemble
      </a>
      <a href="wallet.php" class="km-sb-link active">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="2" y="5" width="16" height="11" rx="2"/><path d="M2 9h16"/></svg></span>
        Mon Wallet
      </a>
      <a href="<?= $dashboard ?>?tab=commandes" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></span>
        Commandes
      </a>
      <?php if (estPrestataire()): ?>
      <a href="<?= $dashboard ?>?tab=prestations" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M13.5 6.5a3.5 3.5 0 0 1-4.6 3.3L4 14.7 5.3 16l4.9-4.9a3.5 3.5 0 0 0 4.5-4.6l-2 2-1.6-1.6 2-2Z"/></svg></span>
        Mes prestations
      </a>
      <?php endif; ?>
      <?php if (estAdmin()): ?>
      <a href="admin_dashboard.php" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 15V9m5 6V5m5 10v-4"/></svg></span>
        Statistiques
      </a>
      <?php endif; ?>

      <div class="km-sb-section">Compte</div>
      <a href="<?= $dashboard ?>" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><circle cx="10" cy="7" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg></span>
        Profil
      </a>
      <a href="connexion.php?action=logout" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M8 4H4v12h4M13 14l4-4-4-4M17 10H8"/></svg></span>
        Déconnexion
      </a>
    </nav>

    <div class="km-sb-foot">Besoin d'aide ? <a href="<?= $dashboard ?>">Contacter le support</a></div>
  </aside>

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle" onclick="document.getElementById('km-sidebar').classList.toggle('open')">☰</button>
      <div class="km-topbar-title">Mon Wallet</div>
      <div style="display:flex;align-items:center;gap:1rem">
        <div class="km-topbar-search">
          <span class="s-icon">⌕</span>
          <input type="text" placeholder="Rechercher une transaction…" id="searchInput">
        </div>
        <div class="km-topbar-user">
          <div class="km-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? 'K', 0, 1)) ?></div>
          <div>
            <div class="km-topbar-user-name"><?= htmlspecialchars($_SESSION['prenom'] ?? 'Utilisateur') ?></div>
            <div class="km-topbar-user-role"><?= estAdmin() ? 'Admin' : (estPrestataire() ? 'Prestataire' : 'Client') ?></div>
          </div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- ── Balance hero ── -->
      <div class="km-balance-hero">
        <div>
          <div class="km-balance-label">Solde disponible</div>
          <div class="km-balance-amount"><?= number_format($solde, 0, ',', ' ') ?> <small>FCFA</small></div>
        </div>
        <div class="km-balance-actions">
          <button class="km-btn km-btn-amber" onclick="document.getElementById('modal-recharge').classList.add('open')">＋ Recharger</button>
          <?php if (estPrestataire()): ?>
            <button class="km-btn km-btn-ghost" onclick="document.getElementById('modal-retrait').classList.add('open')">↓ Retirer</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Stats ── -->
      <div class="km-stats">
        <div class="km-stat"><div class="km-stat-val"><?= $nb_tx ?></div><div class="km-stat-label">Transactions</div></div>
        <div class="km-stat"><div class="km-stat-val teal"><?= number_format($total_credit, 0, ',', ' ') ?></div><div class="km-stat-label">Total crédits (FCFA)</div></div>
        <div class="km-stat"><div class="km-stat-val danger"><?= number_format($total_debit, 0, ',', ' ') ?></div><div class="km-stat-label">Total débits (FCFA)</div></div>
      </div>

      <!-- ── Flux ── -->
      <?php $totalFlow = $total_credit + $total_debit; $pctCredit = $totalFlow > 0 ? round($total_credit / $totalFlow * 100) : 0; ?>
      <div class="km-flux">
        <div class="km-flux-labels"><span>Flux entrant <?= $pctCredit ?>%</span><span>Flux sortant <?= 100 - $pctCredit ?>%</span></div>
        <div class="km-flux-track"><div class="km-flux-fill" style="width:<?= $pctCredit ?>%"></div></div>
      </div>

      <!-- ── Deux colonnes : graphique + transactions récentes / actions ── -->
      <div class="km-two-col">
        <div class="km-panel">
          <div class="km-panel-head"><h3>Crédits des 6 derniers mois</h3></div>
          <div class="km-panel-body">
            <div class="km-chart">
              <?php foreach ($par_mois as $m): $h = max(6, round($m['credits'] / $max_mois * 100)); ?>
                <div class="km-chart-col">
                  <div class="km-chart-bar" style="height:<?= $h ?>%"></div>
                  <div class="km-chart-label"><?= $m['label'] ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="km-panel-head" style="border-top:1px solid var(--line)">
            <h3>Transactions récentes</h3>
            <a href="#historique">Voir tout →</a>
          </div>
          <div class="km-panel-body">
            <?php if (empty($transactions)): ?>
              <div class="km-empty">Aucune transaction pour l'instant.</div>
            <?php else: foreach (array_slice($transactions, 0, 6) as $tx):
              $isCredit = $tx['type_transaction'] === 'credit';
              $icon = $isCredit ? '↓' : ($tx['type_transaction'] === 'retrait' ? '↑' : '·');
            ?>
              <div class="km-tx-row">
                <div class="km-tx-dot <?= $isCredit ? 'credit' : 'debit' ?>"><?= $icon ?></div>
                <div class="km-tx-label">
                  <?= htmlspecialchars(mb_substr($tx['libelle'], 0, 34)) ?>
                  <div class="km-tx-time"><?= date('d/m à H:i', strtotime($tx['date_transaction'])) ?></div>
                </div>
                <div class="km-tx-amount <?= $isCredit ? 'credit' : '' ?>"><?= $isCredit ? '+' : '−' ?> <?= number_format($tx['montant'], 0, ',', ' ') ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="km-panel">
          <div class="km-panel-head"><h3>Actions rapides</h3></div>
          <div class="km-panel-body">
            <div class="km-quick-list">
              <button class="km-quick-btn" style="text-align:left;width:100%;background:none" onclick="document.getElementById('modal-recharge').classList.add('open')">＋ Recharger le wallet</button>
              <?php if (estPrestataire()): ?>
                <button class="km-quick-btn" style="text-align:left;width:100%;background:none" onclick="document.getElementById('modal-retrait').classList.add('open')">↓ Retirer des fonds</button>
              <?php endif; ?>
              <a href="<?= $dashboard ?>" class="km-quick-btn">📋 Mes commandes</a>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Historique complet ── -->
      <div id="historique">
        <div class="km-panel-head" style="padding:0 0 1rem;border:none">
          <h3 style="font-size:1rem">Historique complet</h3>
          <span style="font-size:.8rem;color:var(--ink-soft)"><?= $nb_tx ?> opération<?= $nb_tx > 1 ? 's' : '' ?></span>
        </div>

        <div class="km-tx-table-wrap">
          <?php if (empty($transactions)): ?>
            <div class="km-empty">Aucune transaction pour l'instant. Rechargez votre wallet pour commencer.</div>
          <?php else: ?>
            <div class="km-tx-thead">
              <div>Type</div><div>Libellé</div><div>Date</div><div class="km-tx-cell-right">Montant</div><div class="km-tx-cell-right">Solde après</div>
            </div>
            <?php foreach ($transactions as $tx):
              $isCredit = $tx['type_transaction'] === 'credit';
              $badgeClass = $isCredit ? 'km-badge-teal' : ($tx['type_transaction'] === 'retrait' ? 'km-badge-amber' : 'km-badge-neutral');
            ?>
            <div class="km-tx-trow" data-search="<?= htmlspecialchars(mb_strtolower($tx['libelle'])) ?>">
              <div><span class="km-badge <?= $badgeClass ?>"><?= strtoupper($tx['type_transaction']) ?></span></div>
              <div>
                <div>
                  <div class="lib"><?= htmlspecialchars($tx['libelle']) ?></div>
                  <div class="sub">#<?= $tx['id_transaction_wallet'] ?? $tx['id_transaction'] ?? '' ?></div>
                </div>
              </div>
              <div><?= date('d/m/Y à H:i', strtotime($tx['date_transaction'])) ?></div>
              <div class="km-tx-cell-right" style="font-family:'Fraunces',serif;font-weight:600;color:<?= $isCredit ? 'var(--teal)' : 'var(--ink)' ?>"><?= $isCredit ? '+' : '−' ?> <?= number_format($tx['montant'], 0, ',', ' ') ?></div>
              <div class="km-tx-cell-right" style="color:var(--ink-soft)"><?= number_format($tx['solde_apres'], 0, ',', ' ') ?> FCFA</div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ── MODAL RECHARGE ── -->
<div class="km-modal-overlay" id="modal-recharge">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Recharger mon wallet</h3>
      <button class="km-modal-close" onclick="document.getElementById('modal-recharge').classList.remove('open')">✕</button>
    </div>
    <form method="POST" action="wallet.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="recharger">
      <div class="km-mfield">
        <label>Montant à recharger (FCFA)</label>
        <div class="km-presets">
          <?php foreach ([2000, 5000, 10000, 25000, 50000] as $p): ?>
            <span class="km-preset" onclick="document.getElementById('montant-recharge').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></span>
          <?php endforeach; ?>
        </div>
        <input type="number" name="montant" id="montant-recharge" min="500" max="1000000" step="100" placeholder="Ex : 10000" required>
        <small>Min : 500 FCFA — Max : 1 000 000 FCFA</small>
      </div>
      <div class="km-mfield">
        <label>Mode de paiement</label>
        <div class="km-methode-grid">
          <?php $methodes = ['Orange Money','MTN MoMo','Wave','Carte Bancaire']; $first = true; foreach ($methodes as $nom): ?>
          <div class="km-methode-opt">
            <input type="radio" name="methode" id="m-<?= md5($nom) ?>" value="<?= $nom ?>" <?= $first ? 'checked' : '' ?>>
            <label class="km-methode-lbl" for="m-<?= md5($nom) ?>"><?= $nom ?></label>
          </div>
          <?php $first = false; endforeach; ?>
        </div>
        <div class="km-sim-note">Mode simulation — aucun paiement réel n'est effectué.</div>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block" style="margin-top:.4rem">Confirmer la recharge</button>
    </form>
  </div>
</div>

<?php if (estPrestataire()): ?>
<!-- ── MODAL RETRAIT ── -->
<div class="km-modal-overlay" id="modal-retrait">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Retirer des fonds</h3>
      <button class="km-modal-close" onclick="document.getElementById('modal-retrait').classList.remove('open')">✕</button>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem;padding:.8rem 1rem;background:var(--paper);border-radius:10px;margin-bottom:1.2rem">
      <div>
        <div style="font-size:.72rem;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em">Solde disponible</div>
        <div style="font-family:'Fraunces',serif;font-size:1.2rem;font-weight:600;color:var(--amber-deep)"><?= number_format($solde, 0, ',', ' ') ?> FCFA</div>
      </div>
    </div>
    <form method="POST" action="wallet.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="retrait">
      <div class="km-mfield">
        <label>Montant à retirer (FCFA)</label>
        <div class="km-presets">
          <?php foreach ([5000, 10000, 25000, 50000] as $p): ?>
            <span class="km-preset" onclick="document.getElementById('montant-retrait').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></span>
          <?php endforeach; ?>
          <span class="km-preset" onclick="document.getElementById('montant-retrait').value=<?= floor($solde) ?>">Tout retirer</span>
        </div>
        <input type="number" name="montant" id="montant-retrait" min="1000" max="<?= floor($solde) ?>" step="100" placeholder="Ex : 10000" required>
        <small>Min : 1 000 FCFA</small>
      </div>
      <div class="km-mfield">
        <label>Numéro Mobile Money / RIB</label>
        <input type="text" name="rib" placeholder="Ex : 07 00 00 00 00 (Orange Money)" required>
        <small>Traitement sous 24-48h ouvrées.</small>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block" onclick="return confirm('Confirmer le retrait ?')">Demander le retrait</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.km-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
document.addEventListener('click', function(e){
  const sb = document.getElementById('km-sidebar');
  if (window.innerWidth <= 860 && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.classList.contains('km-menu-toggle')) {
    sb.classList.remove('open');
  }
});
const searchInput = document.getElementById('searchInput');
if (searchInput) {
  searchInput.addEventListener('input', function () {
    const val = this.value.toLowerCase();
    document.querySelectorAll('.km-tx-trow').forEach(row => {
      row.style.display = row.dataset.search.includes(val) ? '' : 'none';
    });
  });
}
</script>

</body>
</html>
