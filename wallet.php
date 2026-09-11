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

    // --- AJOUTER CARTE VIRTUELLE ---
    elseif ($_POST['action'] === 'ajouter_carte') {
        $libelle    = trim($_POST['libelle'] ?? 'Carte KoudMain');
        $type_carte = in_array($_POST['type_carte'] ?? '', ['visa', 'mastercard']) ? $_POST['type_carte'] : 'visa';
        $couleur    = in_array($_POST['couleur'] ?? '', ['emerald', 'silver', 'platinum', 'amber', 'midnight']) ? $_POST['couleur'] : 'emerald';
        $nom        = trim($_POST['nom_titulaire'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? 'Utilisateur')));
        $exp        = preg_match('/^\d{2}\/\d{2}$/', $_POST['date_expiration'] ?? '') ? $_POST['date_expiration'] : '12/28';

        $last4 = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $numero_masque = '**** **** **** ' . $last4;

        try {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM Carte_Virtuelle WHERE id_wallet = ?");
            $cnt->execute([$id_wallet]);
            if ((int)$cnt->fetchColumn() >= 5) {
                $err = "Vous ne pouvez pas avoir plus de 5 cartes virtuelles.";
            } else {
                $pdo->prepare("
                    INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale)
                    VALUES (?, ?, ?, ?, ?, ?, ?, FALSE)
                ")->execute([$id_wallet, mb_substr($libelle, 0, 80), $type_carte, $couleur, $numero_masque, mb_substr($nom, 0, 120), $exp]);
                $msg = "Carte virtuelle « " . htmlspecialchars($libelle) . " » ajoutée.";
            }
        } catch (Exception $e) {
            $err = "Impossible d'ajouter la carte. Exécutez le script SQL mis à jour sur Supabase. (" . $e->getMessage() . ")";
        }
    }
}

// --- Cartes virtuelles ---
$cartes = [];
try {
    $stmtC = $pdo->prepare("SELECT * FROM Carte_Virtuelle WHERE id_wallet = ? ORDER BY est_principale DESC, date_creation ASC");
    $stmtC->execute([$id_wallet]);
    $cartes = $stmtC->fetchAll();
} catch (Exception $e) {
    $cartes = [];
}
if (empty($cartes)) {
    $cartes = [[
        'id_carte' => 0,
        'libelle' => 'Emerald',
        'type_carte' => 'visa',
        'couleur' => 'emerald',
        'numero_masque' => '**** **** **** 0212',
        'nom_titulaire' => ($_SESSION['prenom'] ?? 'Utilisateur') . ' ' . ($_SESSION['nom'] ?? ''),
        'date_expiration' => '12/28',
        'est_principale' => true
    ]];
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

// ── Répartition mensuelle (6 derniers mois) ──
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

// Redirection selon rôle
$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Utilisateur');
$nom    = htmlspecialchars($_SESSION['nom'] ?? '');
$role   = estAdmin() ? 'Admin' : (estPrestataire() ? 'Prestataire' : 'Client');
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
button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
input,select{font-family:inherit}
h1,h2,h3,.km-serif{font-family:'Fraunces',serif}
@media(prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}

/* ── SIDEBAR (identique client_dashboard) ── */
.km-app{display:flex;min-height:100vh}
.km-sidebar{
  width:var(--sidebar-w);background:var(--ink);color:#E8E4D8;
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease;
}
.km-sb-brand{padding:1.5rem 1.4rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.1)}
.km-sb-brand a{font-family:'Fraunces',serif;font-weight:600;font-size:1.3rem;color:#fff}
.km-sb-brand em{font-style:normal;color:var(--amber)}
.km-sb-nav{flex:1;padding:1.1rem .8rem;display:flex;flex-direction:column;gap:.15rem;overflow-y:auto}
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

/* Overlay mobile */
.km-sidebar-overlay{
  display:none;position:fixed;inset:0;background:rgba(28,27,23,.45);z-index:90;
  opacity:0;transition:opacity .25s ease;pointer-events:none;
}
.km-sidebar-overlay.visible{display:block;opacity:1;pointer-events:auto}

/* ── MAIN ── */
.km-main{flex:1;margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.km-topbar{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 1.8rem;background:var(--paper);border-bottom:1px solid var(--line);
  position:sticky;top:0;z-index:50;
}
.km-menu-toggle{display:none;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--ink);padding:.2rem;line-height:1}
.km-topbar-title{font-size:1.05rem;font-weight:600;white-space:nowrap}
.km-topbar-search{flex:1;max-width:280px;position:relative}
.km-topbar-search input{
  width:100%;padding:.55rem 1rem .55rem 2.3rem;background:var(--surface);
  border:1px solid var(--line);border-radius:20px;font-size:.86rem;color:var(--ink);outline:none;
  transition:border-color .18s ease;
}
.km-topbar-search input:focus{border-color:var(--ink)}
.km-topbar-search .s-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.82rem;opacity:.5;pointer-events:none}
.km-avatar{
  width:36px;height:36px;border-radius:50%;background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.92rem;flex-shrink:0;
}
.km-topbar-user{display:flex;align-items:center;gap:.6rem;flex-shrink:0}
.km-topbar-user-name{font-size:.86rem;font-weight:600}
.km-topbar-user-role{font-size:.72rem;color:var(--ink-soft)}

.km-content{padding:1.8rem 1.8rem 3rem;flex:1;max-width:1120px;width:100%}

/* ── Alerts ── */
.km-alert{padding:.75rem 1rem;border-left:2px solid;font-size:.86rem;margin-bottom:1.2rem}
.km-alert-ok{border-color:var(--teal);background:var(--teal-tint);color:#1E4B41}
.km-alert-err{border-color:var(--danger);background:var(--danger-tint);color:#7A2E1D}

/* ── Section label ── */
.km-section-label{
  font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--ink-faint);margin-bottom:.85rem;
}

/* ── Cartes virtuelles (carrousel) ── */
.km-cards-wrap{margin-bottom:1.7rem}
.km-cards-scroll{
  display:flex;gap:1rem;overflow-x:auto;padding-bottom:.6rem;
  scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;
}
.km-cards-scroll::-webkit-scrollbar{height:5px}
.km-cards-scroll::-webkit-scrollbar-thumb{background:var(--line);border-radius:4px}

.km-vcard{
  flex:0 0 260px;height:162px;border-radius:var(--radius);padding:1.2rem 1.3rem;
  position:relative;overflow:hidden;scroll-snap-align:start;
  display:flex;flex-direction:column;justify-content:space-between;
  box-shadow:0 6px 20px rgba(28,27,23,.12);
  transition:transform .2s ease,box-shadow .2s ease;
  color:#fff;
}
.km-vcard:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(28,27,23,.18)}
.km-vcard::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse 80% 55% at 92% 8%,rgba(255,255,255,.18),transparent 55%);
  pointer-events:none;
}
.km-vcard.emerald{background:linear-gradient(145deg,#2E6B5E 0%,#1e4a42 55%,#163832 100%)}
.km-vcard.silver{background:linear-gradient(145deg,#7a7568 0%,#5c574c 50%,#3f3c35 100%)}
.km-vcard.platinum{background:linear-gradient(145deg,#3d3a34 0%,#2a2824 60%,#1C1B17 100%);border:1px solid rgba(255,255,255,.12)}
.km-vcard.amber{background:linear-gradient(145deg,#B96B2A 0%,#8A4E1B 55%,#6b3c14 100%)}
.km-vcard.midnight{background:linear-gradient(145deg,#3a3650 0%,#2a2740 55%,#1a1828 100%)}

.km-vcard-top{display:flex;justify-content:space-between;align-items:flex-start;z-index:1}
.km-vcard-brand{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.92}
.km-vcard-type{font-size:.68rem;font-weight:600;opacity:.7;letter-spacing:.04em;margin-top:.1rem}
.km-vcard-chip{
  width:32px;height:24px;border-radius:4px;
  background:linear-gradient(135deg,#f0d78c,#d4a84b 45%,#b8860b);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.35);
  margin:.5rem 0 .15rem;z-index:1;
}
.km-vcard-number{font-size:1rem;font-weight:600;letter-spacing:.14em;z-index:1}
.km-vcard-bottom{display:flex;justify-content:space-between;align-items:flex-end;z-index:1}
.km-vcard-holder{font-size:.78rem;font-weight:500;opacity:.9;max-width:58%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.km-vcard-exp{font-size:.7rem;opacity:.7}
.km-vcard-logo{font-family:'Fraunces',serif;font-size:1.1rem;font-weight:600;letter-spacing:-.02em;opacity:.95}

.km-vcard-add{
  flex:0 0 140px;height:162px;border-radius:var(--radius);
  border:1.5px dashed var(--line);background:var(--surface);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:.4rem;color:var(--ink-soft);scroll-snap-align:start;
  transition:border-color .18s ease,background .18s ease,color .18s ease;
}
.km-vcard-add:hover{border-color:var(--amber-deep);background:var(--amber-tint);color:var(--amber-deep)}
.km-vcard-add span{font-size:1.5rem;line-height:1}
.km-vcard-add small{font-size:.78rem;font-weight:600}

/* ── Métriques solde ── */
.km-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.7rem}
.km-metric{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.15rem 1.25rem;transition:border-color .18s ease,transform .18s ease;
}
.km-metric:hover{border-color:var(--amber-deep);transform:translateY(-2px)}
.km-metric-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-faint);margin-bottom:.35rem}
.km-metric-val{font-family:'Fraunces',serif;font-size:1.55rem;font-weight:600;line-height:1.2}
.km-metric-val.credit{color:var(--teal)}
.km-metric-val.debit{color:var(--amber-deep)}
.km-metric-sub{font-size:.75rem;color:var(--ink-soft);margin-top:.25rem}

/* ── Grid ── */
.km-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:1.2rem;margin-bottom:1.7rem}
.km-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.km-panel-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:1rem 1.25rem;border-bottom:1px solid var(--line);
}
.km-panel-head h3{font-size:.92rem;font-weight:600;font-family:'Inter',sans-serif}
.km-panel-head a{font-size:.8rem;color:var(--amber-deep);font-weight:600}
.km-panel-body{padding:1.1rem 1.25rem}

/* Chart */
.km-chart{display:flex;align-items:flex-end;gap:10px;height:120px;padding-top:.4rem}
.km-chart-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.4rem}
.km-chart-bar{
  width:100%;background:linear-gradient(180deg,var(--amber),var(--amber-tint));
  border-radius:5px 5px 2px 2px;min-height:4px;transition:height .5s ease;
}
.km-chart-label{font-size:.68rem;color:var(--ink-faint)}

/* Recent tx */
.km-tx-row{
  display:flex;align-items:center;gap:.8rem;padding:.7rem 0;
  border-bottom:1px solid var(--line);
}
.km-tx-row:last-child{border-bottom:none}
.km-tx-dot{
  width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:.9rem;flex-shrink:0;
}
.km-tx-dot.credit{background:var(--teal-tint);color:var(--teal)}
.km-tx-dot.debit{background:var(--amber-tint);color:var(--amber-deep)}
.km-tx-label{flex:1;min-width:0}
.km-tx-label strong{display:block;font-size:.86rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.km-tx-time{font-size:.72rem;color:var(--ink-faint);margin-top:.1rem}
.km-tx-amount{font-family:'Fraunces',serif;font-weight:600;font-size:.9rem;white-space:nowrap}
.km-tx-amount.credit{color:var(--teal)}

/* Quick actions */
.km-quick{display:flex;flex-direction:column;gap:.55rem}
.km-quick-btn{
  display:flex;align-items:center;gap:.7rem;padding:.75rem .9rem;
  border:1px solid var(--line);border-radius:10px;font-size:.86rem;font-weight:600;
  transition:border-color .18s ease,background .18s ease;text-align:left;width:100%;
}
.km-quick-btn:hover{border-color:var(--amber-deep);background:var(--amber-tint)}
.km-quick-btn .q-icon{
  width:30px;height:30px;border-radius:8px;background:var(--amber-tint);color:var(--amber-deep);
  display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;
}

/* Historique */
.km-hist-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.4rem}
.km-hist-head h3{font-size:1.15rem;font-weight:600}
.km-hist-head span{font-size:.8rem;color:var(--ink-soft)}

.km-table-wrap{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.km-thead{
  display:grid;grid-template-columns:70px 1fr 140px 110px 120px;
  background:var(--paper-deep);border-bottom:1px solid var(--line);
}
.km-thead div{
  padding:.7rem 1rem;font-size:.65rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.05em;color:var(--ink-soft);
}
.km-trow{
  display:grid;grid-template-columns:70px 1fr 140px 110px 120px;
  border-bottom:1px solid var(--line);transition:background .15s ease;
}
.km-trow:last-child{border-bottom:none}
.km-trow:hover{background:var(--paper)}
.km-trow > div{padding:.75rem 1rem;display:flex;align-items:center;font-size:.85rem}
.km-trow .lib{font-weight:600}
.km-trow .sub{font-size:.7rem;color:var(--ink-faint);font-weight:400;margin-top:.1rem}
.km-cell-right{justify-content:flex-end;text-align:right}

.km-badge{display:inline-flex;padding:.2rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700}
.km-badge-teal{background:var(--teal-tint);color:var(--teal)}
.km-badge-amber{background:var(--amber-tint);color:var(--amber-deep)}
.km-badge-neutral{background:var(--paper-deep);color:var(--ink-soft)}

.km-empty{text-align:center;padding:2.8rem 1rem;color:var(--ink-soft);font-size:.9rem}

/* Buttons */
.km-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.65rem 1.1rem;border-radius:8px;border:1px solid transparent;font-weight:600;
  font-size:.86rem;cursor:pointer;transition:transform .16s ease,background .16s ease;
}
.km-btn:active{transform:scale(.97)}
.km-btn-primary{background:var(--ink);color:var(--paper)}
.km-btn-primary:hover{background:var(--amber-deep)}
.km-btn-outline{background:transparent;border-color:var(--line);color:var(--ink)}
.km-btn-outline:hover{border-color:var(--ink)}
.km-btn-block{width:100%}

/* Modal */
.km-modal-overlay{
  display:none;position:fixed;inset:0;background:rgba(28,27,23,.55);z-index:200;
  align-items:center;justify-content:center;padding:1rem;
}
.km-modal-overlay.open{display:flex}
.km-modal{
  background:var(--surface);border-radius:18px;padding:1.8rem 1.9rem;
  width:100%;max-width:440px;max-height:90vh;overflow-y:auto;border:1px solid var(--line);
}
.km-modal-head{
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:1.2rem;padding-bottom:.9rem;border-bottom:1px solid var(--line);
}
.km-modal-head h3{font-size:1.05rem;font-weight:600}
.km-modal-close{
  background:var(--paper);border:1px solid var(--line);width:28px;height:28px;
  border-radius:6px;cursor:pointer;font-size:.9rem;color:var(--ink-soft);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.km-mfield{margin-bottom:1rem}
.km-mfield label{
  display:block;font-size:.75rem;font-weight:600;color:var(--ink-soft);
  text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;
}
.km-mfield input,.km-mfield select{
  width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:8px;
  background:var(--paper);font-family:'Inter',sans-serif;font-size:.9rem;color:var(--ink);outline:none;
}
.km-mfield input:focus,.km-mfield select:focus{border-color:var(--amber-deep)}
.km-mfield small{display:block;color:var(--ink-faint);font-size:.74rem;margin-top:.35rem}
.km-presets{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.7rem}
.km-preset{
  padding:.35rem .8rem;background:var(--paper);border:1px solid var(--line);
  border-radius:20px;font-size:.78rem;font-weight:600;color:var(--ink-soft);cursor:pointer;
}
.km-preset:hover{border-color:var(--amber-deep);color:var(--amber-deep)}
.km-methode-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.9rem}
.km-methode-opt input{display:none}
.km-methode-lbl{
  display:flex;align-items:center;gap:.4rem;padding:.6rem .8rem;
  background:var(--paper);border:1.5px solid var(--line);border-radius:8px;
  cursor:pointer;font-size:.82rem;font-weight:600;color:var(--ink-soft);
}
.km-methode-opt input:checked + .km-methode-lbl{
  border-color:var(--amber-deep);color:var(--amber-deep);background:var(--amber-tint);
}
.km-sim-note{
  padding:.6rem .8rem;background:var(--teal-tint);color:#1E4B41;
  border-radius:8px;font-size:.78rem;
}
.km-couleur-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:.45rem}
.km-couleur-opt input{display:none}
.km-couleur-swatch{
  height:36px;border-radius:8px;cursor:pointer;border:2px solid transparent;
  transition:border-color .18s ease,transform .15s ease;
}
.km-couleur-opt input:checked + .km-couleur-swatch{border-color:var(--ink);transform:scale(1.06)}
.km-couleur-swatch.emerald{background:linear-gradient(145deg,#2E6B5E,#1e4a42)}
.km-couleur-swatch.silver{background:linear-gradient(145deg,#7a7568,#5c574c)}
.km-couleur-swatch.platinum{background:linear-gradient(145deg,#3d3a34,#1C1B17)}
.km-couleur-swatch.amber{background:linear-gradient(145deg,#B96B2A,#8A4E1B)}
.km-couleur-swatch.midnight{background:linear-gradient(145deg,#3a3650,#1a1828)}

a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{
  outline:2px solid var(--amber-deep);outline-offset:2px;
}

/* ══════════════ RESPONSIVE ══════════════ */
@media(max-width:1080px){
  .km-grid{grid-template-columns:1fr}
  .km-metrics{grid-template-columns:1fr 1fr}
  .km-metrics .km-metric:last-child{grid-column:1 / -1}
}
@media(max-width:860px){
  .km-sidebar{transform:translateX(-100%)}
  .km-sidebar.open{transform:translateX(0);box-shadow:8px 0 32px rgba(28,27,23,.25)}
  .km-main{margin-left:0}
  .km-menu-toggle{display:block}
  .km-topbar-search{display:none}
}
@media(max-width:640px){
  /* Tableau → liste de cartes */
  .km-thead{display:none}
  .km-trow{
    display:flex;flex-wrap:wrap;align-items:flex-start;gap:.35rem .8rem;
    padding:.9rem 1rem;border-bottom:1px solid var(--line);
  }
  .km-trow > div{padding:0;width:auto}
  .km-trow > div:nth-child(1){order:1}
  .km-trow > div:nth-child(2){order:3;flex:1 1 100%;margin-top:.15rem}
  .km-trow > div:nth-child(3){order:2;margin-left:auto;font-size:.78rem;color:var(--ink-soft)}
  .km-trow > div:nth-child(4){order:4;font-size:1rem}
  .km-trow > div:nth-child(5){order:5;font-size:.78rem;color:var(--ink-soft);margin-left:auto}
  .km-cell-right{justify-content:flex-start;text-align:left}
}
@media(max-width:560px){
  .km-content{padding:1.2rem 1rem 2.5rem}
  .km-topbar{padding:.8rem 1rem;gap:.6rem}
  .km-topbar-title{font-size:.95rem}
  .km-topbar-user-name,.km-topbar-user-role{display:none}
  .km-metrics{grid-template-columns:1fr}
  .km-metrics .km-metric:last-child{grid-column:auto}
  .km-vcard{flex:0 0 240px;height:152px}
  .km-vcard-add{flex:0 0 120px;height:152px}
  .km-modal{padding:1.4rem 1.3rem;max-width:100%;width:92%;border-radius:14px}
  .km-methode-grid{grid-template-columns:1fr 1fr}
  .km-couleur-grid{grid-template-columns:repeat(auto-fit,minmax(40px,1fr))}
  .km-metric-val{font-size:1.35rem}
}
</style>
</head>
<body>

<div class="km-app">

  <!-- Overlay mobile -->
  <div class="km-sidebar-overlay" id="km-overlay" onclick="closeSidebar()"></div>

  <aside class="km-sidebar" id="km-sidebar">
    <div class="km-sb-brand"><a href="index.php">Koud<em>Main</em></a></div>

    <nav class="km-sb-nav">
      <a href="index.php" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Accueil
      </a>
      <a href="<?= $dashboard ?>?tab=catalogue" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M17 17l-4-4"/></svg></span>
        Catalogue
      </a>
      <a href="<?= $dashboard ?>?tab=overview" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><path d="M3 10l7-6 7 6M5 9v7h10V9"/></svg></span>
        Vue d'ensemble
      </a>
      <a href="<?= $dashboard ?>?tab=commandes" class="km-sb-link">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="7" width="14" height="10" rx="1"/><path d="M7 7V4h6v3"/></svg></span>
        Mes commandes
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

      <div class="km-sb-section">Finance</div>
      <a href="wallet.php" class="km-sb-link active">
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="2" y="5" width="16" height="11" rx="2"/><path d="M2 9h16"/></svg></span>
        Mon Wallet
      </a>

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

    <div class="km-sb-foot">
      <a href="wallet.php" class="km-wallet-mini">
        <div class="w-label">Solde disponible</div>
        <div class="w-solde"><?= number_format($solde, 0, ',', ' ') ?> FCFA</div>
      </a>
    </div>
  </aside>

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle" type="button" id="km-burger" aria-label="Menu">☰</button>
      <div class="km-topbar-title">Mon Wallet</div>
      <div class="km-topbar-search">
        <span class="s-icon">⌕</span>
        <input type="text" placeholder="Rechercher une transaction…" id="searchInput" autocomplete="off">
      </div>
      <div class="km-topbar-user">
        <div class="km-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? 'K', 0, 1)) ?></div>
        <div>
          <div class="km-topbar-user-name"><?= $prenom ?></div>
          <div class="km-topbar-user-role"><?= $role ?></div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- Cartes virtuelles -->
      <div class="km-cards-wrap">
        <div class="km-section-label">Mes cartes</div>
        <div class="km-cards-scroll">
          <?php foreach ($cartes as $c):
            $coul = htmlspecialchars($c['couleur'] ?? 'emerald');
            $type = strtoupper($c['type_carte'] ?? 'visa');
          ?>
          <article class="km-vcard <?= $coul ?>">
            <div class="km-vcard-top">
              <div>
                <div class="km-vcard-brand"><?= htmlspecialchars($c['libelle'] ?? 'Carte') ?></div>
                <div class="km-vcard-type"><?= $type ?></div>
              </div>
              <div class="km-vcard-logo"><?= $type === 'MASTERCARD' ? 'MC' : 'VISA' ?></div>
            </div>
            <div class="km-vcard-chip" aria-hidden="true"></div>
            <div class="km-vcard-number"><?= htmlspecialchars($c['numero_masque'] ?? '**** **** **** 0000') ?></div>
            <div class="km-vcard-bottom">
              <div class="km-vcard-holder"><?= htmlspecialchars($c['nom_titulaire'] ?? $prenom) ?></div>
              <div class="km-vcard-exp"><?= htmlspecialchars($c['date_expiration'] ?? '12/28') ?></div>
            </div>
          </article>
          <?php endforeach; ?>

          <button type="button" class="km-vcard-add" onclick="openModal('modal-carte')" aria-label="Ajouter une carte">
            <span>+</span>
            <small>Ajouter</small>
          </button>
        </div>
      </div>

      <!-- Bank Balance -->
      <div class="km-section-label">Bank Balance</div>
      <div class="km-metrics">
        <div class="km-metric">
          <div class="km-metric-label">Total Cash Balance</div>
          <div class="km-metric-val"><?= number_format($solde, 0, ',', ' ') ?> <span style="font-size:.8rem;opacity:.55">FCFA</span></div>
          <div class="km-metric-sub">Solde disponible</div>
        </div>
        <div class="km-metric">
          <div class="km-metric-label">Credits</div>
          <div class="km-metric-val credit">+ <?= number_format($total_credit, 0, ',', ' ') ?></div>
          <div class="km-metric-sub">Entrées cumulées</div>
        </div>
        <div class="km-metric">
          <div class="km-metric-label">Debits</div>
          <div class="km-metric-val debit">− <?= number_format($total_debit, 0, ',', ' ') ?></div>
          <div class="km-metric-sub">Sorties cumulées</div>
        </div>
      </div>

      <!-- Grid chart + actions -->
      <div class="km-grid">
        <div class="km-panel">
          <div class="km-panel-head"><h3>Crédits — 6 derniers mois</h3></div>
          <div class="km-panel-body">
            <div class="km-chart">
              <?php foreach ($par_mois as $m):
                $h = max(6, round($m['credits'] / $max_mois * 100));
              ?>
                <div class="km-chart-col">
                  <div class="km-chart-bar" style="height:<?= $h ?>%" title="<?= number_format($m['credits'], 0, ',', ' ') ?> FCFA"></div>
                  <div class="km-chart-label"><?= $m['label'] ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="km-panel-head" style="border-top:1px solid var(--line)">
            <h3>Transactions récentes</h3>
            <a href="#historique">Voir tout →</a>
          </div>
          <div class="km-panel-body" style="padding-top:.3rem;padding-bottom:.4rem">
            <?php if (empty($transactions)): ?>
              <div class="km-empty">Aucune transaction pour l'instant.</div>
            <?php else: foreach (array_slice($transactions, 0, 5) as $tx):
              $isCredit = $tx['type_transaction'] === 'credit';
              $icon = $isCredit ? '↓' : ($tx['type_transaction'] === 'retrait' ? '↑' : '·');
            ?>
              <div class="km-tx-row">
                <div class="km-tx-dot <?= $isCredit ? 'credit' : 'debit' ?>"><?= $icon ?></div>
                <div class="km-tx-label">
                  <strong><?= htmlspecialchars(mb_substr($tx['libelle'], 0, 36)) ?></strong>
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
            <div class="km-quick">
              <button type="button" class="km-quick-btn" onclick="openModal('modal-recharge')">
                <span class="q-icon">＋</span>
                Recharger le wallet
              </button>
              <?php if (estPrestataire()): ?>
              <button type="button" class="km-quick-btn" onclick="openModal('modal-retrait')">
                <span class="q-icon">↓</span>
                Retirer des fonds
              </button>
              <?php endif; ?>
              <button type="button" class="km-quick-btn" onclick="openModal('modal-carte')">
                <span class="q-icon">◇</span>
                Nouvelle carte virtuelle
              </button>
              <a href="<?= $dashboard ?>?tab=commandes" class="km-quick-btn">
                <span class="q-icon">☰</span>
                Mes commandes
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Historique -->
      <div id="historique">
        <div class="km-hist-head">
          <h3>Historique</h3>
          <span><?= $nb_tx ?> opération<?= $nb_tx > 1 ? 's' : '' ?></span>
        </div>

        <div class="km-table-wrap">
          <?php if (empty($transactions)): ?>
            <div class="km-empty">Aucune transaction. Rechargez votre wallet pour commencer.</div>
          <?php else: ?>
            <div class="km-thead">
              <div>Type</div>
              <div>Libellé</div>
              <div>Date</div>
              <div class="km-cell-right">Montant</div>
              <div class="km-cell-right">Solde après</div>
            </div>
            <?php foreach ($transactions as $tx):
              $isCredit = $tx['type_transaction'] === 'credit';
              $badgeClass = $isCredit ? 'km-badge-teal' : ($tx['type_transaction'] === 'retrait' ? 'km-badge-amber' : 'km-badge-neutral');
            ?>
            <div class="km-trow" data-search="<?= htmlspecialchars(mb_strtolower($tx['libelle'])) ?>">
              <div><span class="km-badge <?= $badgeClass ?>"><?= strtoupper($tx['type_transaction']) ?></span></div>
              <div>
                <div class="lib"><?= htmlspecialchars($tx['libelle']) ?></div>
                <div class="sub">#<?= $tx['id_transaction'] ?? '' ?></div>
              </div>
              <div><?= date('d/m/Y H:i', strtotime($tx['date_transaction'])) ?></div>
              <div class="km-cell-right" style="font-family:'Fraunces',serif;font-weight:600;color:<?= $isCredit ? 'var(--teal)' : 'var(--ink)' ?>">
                <?= $isCredit ? '+' : '−' ?> <?= number_format($tx['montant'], 0, ',', ' ') ?>
              </div>
              <div class="km-cell-right" style="color:var(--ink-soft)"><?= number_format($tx['solde_apres'], 0, ',', ' ') ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- MODAL RECHARGE -->
<div class="km-modal-overlay" id="modal-recharge" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Recharger mon wallet</h3>
      <button type="button" class="km-modal-close" onclick="closeModal('modal-recharge')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="wallet.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="recharger">
      <div class="km-mfield">
        <label>Montant (FCFA)</label>
        <div class="km-presets">
          <?php foreach ([2000, 5000, 10000, 25000, 50000] as $p): ?>
            <span class="km-preset" role="button" tabindex="0" onclick="document.getElementById('montant-recharge').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></span>
          <?php endforeach; ?>
        </div>
        <input type="number" name="montant" id="montant-recharge" min="500" max="1000000" step="100" placeholder="Ex : 10000" required>
        <small>Min 500 — Max 1 000 000 FCFA</small>
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
      <button type="submit" class="km-btn km-btn-primary km-btn-block" style="margin-top:.3rem">Confirmer la recharge</button>
    </form>
  </div>
</div>

<?php if (estPrestataire()): ?>
<!-- MODAL RETRAIT -->
<div class="km-modal-overlay" id="modal-retrait" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Retirer des fonds</h3>
      <button type="button" class="km-modal-close" onclick="closeModal('modal-retrait')" aria-label="Fermer">✕</button>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem;padding:.8rem 1rem;background:var(--paper);border-radius:10px;margin-bottom:1.15rem;border:1px solid var(--line)">
      <div>
        <div style="font-size:.7rem;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em">Solde disponible</div>
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
            <span class="km-preset" role="button" tabindex="0" onclick="document.getElementById('montant-retrait').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></span>
          <?php endforeach; ?>
          <span class="km-preset" role="button" tabindex="0" onclick="document.getElementById('montant-retrait').value=<?= floor($solde) ?>">Tout</span>
        </div>
        <input type="number" name="montant" id="montant-retrait" min="1000" max="<?= max(1000, floor($solde)) ?>" step="100" placeholder="Ex : 10000" required>
        <small>Minimum 1 000 FCFA</small>
      </div>
      <div class="km-mfield">
        <label>Numéro Mobile Money / RIB</label>
        <input type="text" name="rib" placeholder="Ex : 07 00 00 00 00" required>
        <small>Traitement sous 24–48 h ouvrées.</small>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block" onclick="return confirm('Confirmer le retrait ?')">Demander le retrait</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- MODAL CARTE -->
<div class="km-modal-overlay" id="modal-carte" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Nouvelle carte virtuelle</h3>
      <button type="button" class="km-modal-close" onclick="closeModal('modal-carte')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="wallet.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_carte">
      <div class="km-mfield">
        <label>Nom de la carte</label>
        <input type="text" name="libelle" maxlength="80" placeholder="Ex : Emerald, Silver…" value="Emerald" required>
      </div>
      <div class="km-mfield">
        <label>Type</label>
        <select name="type_carte">
          <option value="visa">VISA</option>
          <option value="mastercard">Mastercard</option>
        </select>
      </div>
      <div class="km-mfield">
        <label>Couleur</label>
        <div class="km-couleur-grid">
          <?php foreach (['emerald','silver','platinum','amber','midnight'] as $i => $col): ?>
          <div class="km-couleur-opt">
            <input type="radio" name="couleur" id="col-<?= $col ?>" value="<?= $col ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <label class="km-couleur-swatch <?= $col ?>" for="col-<?= $col ?>" title="<?= ucfirst($col) ?>"></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="km-mfield">
        <label>Nom du titulaire</label>
        <input type="text" name="nom_titulaire" maxlength="120" value="<?= htmlspecialchars(trim($prenom . ' ' . $nom)) ?>" required>
      </div>
      <div class="km-mfield">
        <label>Expiration (MM/AA)</label>
        <input type="text" name="date_expiration" pattern="\d{2}/\d{2}" placeholder="12/28" value="12/28" required>
      </div>
      <button type="submit" class="km-btn km-btn-primary km-btn-block">Créer la carte</button>
    </form>
  </div>
</div>

<script>
function openSidebar() {
  document.getElementById('km-sidebar').classList.add('open');
  document.getElementById('km-overlay').classList.add('visible');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('km-sidebar').classList.remove('open');
  document.getElementById('km-overlay').classList.remove('visible');
  document.body.style.overflow = '';
}
document.getElementById('km-burger').addEventListener('click', function () {
  const sb = document.getElementById('km-sidebar');
  if (sb.classList.contains('open')) closeSidebar();
  else openSidebar();
});

function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}

document.querySelectorAll('.km-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.km-modal-overlay.open').forEach(m => closeModal(m.id));
    closeSidebar();
  }
});

const searchInput = document.getElementById('searchInput');
if (searchInput) {
  searchInput.addEventListener('input', function () {
    const val = this.value.toLowerCase();
    document.querySelectorAll('.km-trow').forEach(row => {
      row.style.display = (row.dataset.search || '').includes(val) ? '' : 'none';
    });
  });
}
</script>

</body>
</html>
