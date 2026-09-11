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
        $libelle   = trim($_POST['libelle'] ?? 'Carte KoudMain');
        $type_carte = in_array($_POST['type_carte'] ?? '', ['visa', 'mastercard']) ? $_POST['type_carte'] : 'visa';
        $couleur   = in_array($_POST['couleur'] ?? '', ['emerald', 'silver', 'platinum', 'amber', 'midnight']) ? $_POST['couleur'] : 'emerald';
        $nom       = trim($_POST['nom_titulaire'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? 'Utilisateur')));
        $exp       = preg_match('/^\d{2}\/\d{2}$/', $_POST['date_expiration'] ?? '') ? $_POST['date_expiration'] : '12/28';

        // Générer un numéro masqué unique-ish
        $last4 = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $numero_masque = '**** **** **** ' . $last4;

        try {
            // Max 5 cartes par wallet
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
            // Table peut ne pas encore exister si migration non faite
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
    // Table absente → on crée une carte "virtuelle" côté PHP pour l'affichage
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

// Si aucune carte en base, on propose d'en créer une par défaut (optionnel)
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

// ── Répartition mensuelle réelle (6 derniers mois) ──
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
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
/* ============================================================
   KoudMain Wallet — Design system (inspiration motion-primitives
   + watermelon UI : profondeur, arrondis généreux, micro-mouvements)
   RÈGLE D'OR : zéro esthétique "vibe-coding" générique
   ============================================================ */
:root {
  --bg: #0f1115;
  --bg-elevated: #161a21;
  --surface: #1c212b;
  --surface-2: #242b38;
  --border: rgba(255,255,255,.06);
  --border-strong: rgba(255,255,255,.12);
  --ink: #f2f0ea;
  --ink-soft: #9a958a;
  --ink-faint: #6b675e;
  --accent: #2dd4bf;          /* teal-emerald */
  --accent-deep: #14b8a6;
  --accent-glow: rgba(45,212,191,.18);
  --amber: #e8a04a;
  --amber-deep: #c47d2a;
  --danger: #f07167;
  --credit: #34d399;
  --debit: #fbbf24;
  --radius: 1.25rem;          /* rounded-2xl */
  --radius-sm: .75rem;
  --radius-xs: .5rem;
  --sidebar-w: 15.5rem;
  --font: 'DM Sans', system-ui, sans-serif;
  --serif: 'Instrument Serif', Georgia, serif;
  --shadow-card: 0 8px 32px rgba(0,0,0,.35);
  --transition: 220ms cubic-bezier(.22,1,.36,1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--ink);
  font-family: var(--font);
  font-size: 15px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
}
a { color: inherit; text-decoration: none; }
button { font-family: inherit; cursor: pointer; border: none; background: none; color: inherit; }
input, select { font-family: inherit; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .001ms !important; transition-duration: .001ms !important; }
}

/* ── Layout ── */
.km-app { display: flex; min-height: 100vh; }
.km-sidebar {
  width: var(--sidebar-w);
  background: var(--bg-elevated);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; inset: 0 auto 0 0; z-index: 100;
  transition: transform var(--transition);
}
.km-sb-brand {
  padding: 1.6rem 1.35rem 1.3rem;
  border-bottom: 1px solid var(--border);
}
.km-sb-brand a {
  font-family: var(--serif);
  font-size: 1.45rem;
  letter-spacing: -.02em;
  color: var(--ink);
}
.km-sb-brand em { font-style: italic; color: var(--accent); }
.km-sb-nav { flex: 1; padding: 1.1rem .7rem; display: flex; flex-direction: column; gap: .2rem; }
.km-sb-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .7rem .9rem; border-radius: var(--radius-sm);
  font-size: .875rem; font-weight: 500; color: var(--ink-soft);
  transition: background var(--transition), color var(--transition), transform 160ms ease;
  position: relative;
}
.km-sb-link:hover { background: rgba(255,255,255,.04); color: var(--ink); }
.km-sb-link.active {
  background: var(--accent-glow);
  color: var(--accent);
  font-weight: 600;
}
.km-sb-link.active::before {
  content: '';
  position: absolute; left: 0; top: 22%; bottom: 22%;
  width: 2.5px; border-radius: 2px; background: var(--accent);
}
.km-sb-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: .9; }
.km-sb-icon svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.km-sb-section {
  font-size: .65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .1em; color: var(--ink-faint);
  padding: 1.15rem .9rem .35rem;
}
.km-sb-foot {
  padding: 1rem 1rem 1.35rem;
  border-top: 1px solid var(--border);
  font-size: .78rem; color: var(--ink-faint);
}
.km-sb-foot a { color: var(--accent); font-weight: 600; }

.km-main { flex: 1; margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; }
.km-topbar {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: .95rem 1.75rem;
  background: rgba(15,17,21,.82);
  backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border);
  position: sticky; top: 0; z-index: 50;
}
.km-menu-toggle { display: none; font-size: 1.25rem; color: var(--ink); padding: .25rem; }
.km-topbar-title {
  font-family: var(--serif);
  font-size: 1.35rem;
  letter-spacing: -.02em;
}
.km-topbar-search { position: relative; max-width: 240px; }
.km-topbar-search input {
  width: 100%;
  padding: .55rem .95rem .55rem 2.15rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: .84rem; color: var(--ink); outline: none;
  transition: border-color var(--transition);
}
.km-topbar-search input:focus { border-color: var(--accent); }
.km-topbar-search .s-icon {
  position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
  opacity: .45; font-size: .85rem; pointer-events: none;
}
.km-topbar-user { display: flex; align-items: center; gap: .6rem; }
.km-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(145deg, var(--accent-deep), #0f766e);
  color: #041016; display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: .9rem;
  box-shadow: 0 0 0 2px var(--bg), 0 0 0 3px var(--accent-glow);
}
.km-topbar-user-name { font-size: .86rem; font-weight: 600; }
.km-topbar-user-role { font-size: .7rem; color: var(--ink-soft); }

.km-content {
  padding: 1.65rem 1.75rem 3.5rem;
  flex: 1;
  max-width: 1120px;
  width: 100%;
}

/* ── Alerts ── */
.km-alert {
  padding: .8rem 1.05rem;
  border-radius: var(--radius-sm);
  font-size: .875rem;
  margin-bottom: 1.25rem;
  border: 1px solid transparent;
  animation: fadeSlide .35s ease;
}
.km-alert-ok { background: rgba(52,211,153,.12); border-color: rgba(52,211,153,.25); color: #6ee7b7; }
.km-alert-err { background: rgba(240,113,103,.12); border-color: rgba(240,113,103,.25); color: #fca5a5; }
@keyframes fadeSlide {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: none; }
}

/* ── Section headers ── */
.km-section-label {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; color: var(--ink-faint);
  margin-bottom: .85rem;
}

/* ── Cards carousel (widget dossier) ── */
.km-cards-wrap {
  margin-bottom: 1.75rem;
}
.km-cards-scroll {
  display: flex; gap: 1rem;
  overflow-x: auto;
  padding-bottom: .5rem;
  scroll-snap-type: x mandatory;
  -webkit-overflow-scrolling: touch;
}
.km-cards-scroll::-webkit-scrollbar { height: 4px; }
.km-cards-scroll::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }

.km-vcard {
  flex: 0 0 280px;
  height: 168px;
  border-radius: var(--radius);
  padding: 1.25rem 1.35rem;
  position: relative;
  overflow: hidden;
  scroll-snap-align: start;
  display: flex; flex-direction: column; justify-content: space-between;
  box-shadow: var(--shadow-card);
  transition: transform var(--transition), box-shadow var(--transition);
}
.km-vcard:hover { transform: translateY(-3px); box-shadow: 0 14px 40px rgba(0,0,0,.45); }
.km-vcard::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 90% 10%, rgba(255,255,255,.14), transparent 55%);
  pointer-events: none;
}
.km-vcard.emerald {
  background: linear-gradient(145deg, #0d9488 0%, #115e59 55%, #0f3d3a 100%);
}
.km-vcard.silver {
  background: linear-gradient(145deg, #64748b 0%, #475569 50%, #334155 100%);
}
.km-vcard.platinum {
  background: linear-gradient(145deg, #1e293b 0%, #0f172a 60%, #020617 100%);
  border: 1px solid rgba(148,163,184,.25);
}
.km-vcard.amber {
  background: linear-gradient(145deg, #d97706 0%, #b45309 55%, #78350f 100%);
}
.km-vcard.midnight {
  background: linear-gradient(145deg, #312e81 0%, #1e1b4b 55%, #0f0c29 100%);
}

.km-vcard-top { display: flex; justify-content: space-between; align-items: flex-start; z-index: 1; }
.km-vcard-brand {
  font-size: .72rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; opacity: .9;
}
.km-vcard-type {
  font-size: .7rem; font-weight: 600; opacity: .75;
  letter-spacing: .04em;
}
.km-vcard-chip {
  width: 34px; height: 26px; border-radius: 5px;
  background: linear-gradient(135deg, #fcd34d, #f59e0b 40%, #d97706);
  box-shadow: inset 0 1px 0 rgba(255,255,255,.35);
  margin: .6rem 0 .2rem;
  position: relative; z-index: 1;
}
.km-vcard-number {
  font-family: var(--font);
  font-size: 1.05rem; font-weight: 600; letter-spacing: .12em;
  z-index: 1;
}
.km-vcard-bottom {
  display: flex; justify-content: space-between; align-items: flex-end;
  z-index: 1;
}
.km-vcard-holder {
  font-size: .78rem; font-weight: 500; opacity: .9;
  max-width: 60%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.km-vcard-exp { font-size: .7rem; opacity: .7; }
.km-vcard-logo {
  font-family: var(--serif);
  font-size: 1.15rem; font-weight: 600; letter-spacing: -.02em;
  opacity: .95;
}

/* Add card tile */
.km-vcard-add {
  flex: 0 0 160px;
  height: 168px;
  border-radius: var(--radius);
  border: 1.5px dashed var(--border-strong);
  background: var(--surface);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: .5rem; color: var(--ink-soft);
  transition: border-color var(--transition), background var(--transition), color var(--transition);
  scroll-snap-align: start;
}
.km-vcard-add:hover {
  border-color: var(--accent);
  background: var(--accent-glow);
  color: var(--accent);
}
.km-vcard-add span { font-size: 1.6rem; line-height: 1; }
.km-vcard-add small { font-size: .78rem; font-weight: 600; }

/* ── Balance metrics (3 cards) ── */
.km-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 1.75rem;
}
.km-metric {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.25rem 1.35rem;
  transition: border-color var(--transition), transform var(--transition);
}
.km-metric:hover { border-color: var(--border-strong); transform: translateY(-2px); }
.km-metric-label {
  font-size: .72rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .07em; color: var(--ink-faint); margin-bottom: .45rem;
}
.km-metric-val {
  font-family: var(--serif);
  font-size: 1.65rem; letter-spacing: -.02em;
  line-height: 1.15;
}
.km-metric-val.credit { color: var(--credit); }
.km-metric-val.debit { color: var(--debit); }
.km-metric-sub {
  font-size: .75rem; color: var(--ink-soft); margin-top: .35rem;
}

/* ── Grid two-col ── */
.km-grid {
  display: grid;
  grid-template-columns: 1.35fr 1fr;
  gap: 1.15rem;
  margin-bottom: 1.75rem;
}
.km-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
.km-panel-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
}
.km-panel-head h3 {
  font-size: .92rem; font-weight: 600;
}
.km-panel-head a, .km-panel-head button {
  font-size: .78rem; font-weight: 600; color: var(--accent);
}
.km-panel-body { padding: 1.15rem 1.25rem; }

/* Mini chart */
.km-chart {
  display: flex; align-items: flex-end; gap: 10px;
  height: 130px; padding-top: .5rem;
}
.km-chart-col {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: .4rem;
}
.km-chart-bar {
  width: 100%;
  background: linear-gradient(180deg, var(--accent), rgba(45,212,191,.25));
  border-radius: 6px 6px 3px 3px;
  min-height: 4px;
  transition: height .6s cubic-bezier(.22,1,.36,1);
}
.km-chart-label { font-size: .68rem; color: var(--ink-faint); }

/* Recent tx */
.km-tx-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .72rem 0;
  border-bottom: 1px solid var(--border);
  transition: background var(--transition);
}
.km-tx-row:last-child { border-bottom: none; }
.km-tx-dot {
  width: 38px; height: 38px; border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; flex-shrink: 0;
}
.km-tx-dot.credit { background: rgba(52,211,153,.14); color: var(--credit); }
.km-tx-dot.debit { background: rgba(251,191,36,.12); color: var(--debit); }
.km-tx-label { flex: 1; min-width: 0; }
.km-tx-label strong {
  display: block; font-size: .875rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.km-tx-time { font-size: .72rem; color: var(--ink-faint); margin-top: .1rem; }
.km-tx-amount {
  font-family: var(--serif);
  font-size: 1rem; font-weight: 500; white-space: nowrap;
}
.km-tx-amount.credit { color: var(--credit); }

/* Quick actions */
.km-quick {
  display: flex; flex-direction: column; gap: .55rem;
}
.km-quick-btn {
  display: flex; align-items: center; gap: .75rem;
  padding: .85rem 1rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: .875rem; font-weight: 600;
  transition: border-color var(--transition), background var(--transition), transform 160ms ease;
  text-align: left; width: 100%;
}
.km-quick-btn:hover {
  border-color: var(--accent);
  background: var(--accent-glow);
  transform: translateX(2px);
}
.km-quick-btn .q-icon {
  width: 32px; height: 32px; border-radius: 9px;
  background: rgba(45,212,191,.12); color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}

/* Historique table */
.km-hist-head {
  display: flex; align-items: baseline; justify-content: space-between;
  margin-bottom: 1rem;
}
.km-hist-head h3 {
  font-family: var(--serif);
  font-size: 1.25rem; letter-spacing: -.02em;
}
.km-hist-head span { font-size: .8rem; color: var(--ink-soft); }

.km-table-wrap {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
.km-thead {
  display: grid;
  grid-template-columns: 70px 1fr 140px 110px 120px;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
}
.km-thead div {
  padding: .7rem 1rem;
  font-size: .65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--ink-faint);
}
.km-trow {
  display: grid;
  grid-template-columns: 70px 1fr 140px 110px 120px;
  border-bottom: 1px solid var(--border);
  transition: background var(--transition);
}
.km-trow:last-child { border-bottom: none; }
.km-trow:hover { background: rgba(255,255,255,.02); }
.km-trow > div {
  padding: .8rem 1rem;
  display: flex; align-items: center;
  font-size: .85rem;
}
.km-trow .lib { font-weight: 600; }
.km-trow .sub { font-size: .7rem; color: var(--ink-faint); font-weight: 400; margin-top: .1rem; }
.km-cell-right { justify-content: flex-end; text-align: right; }

.km-badge {
  display: inline-flex; padding: .2rem .55rem;
  border-radius: 999px; font-size: .65rem; font-weight: 700;
  letter-spacing: .03em;
}
.km-badge-credit { background: rgba(52,211,153,.15); color: var(--credit); }
.km-badge-debit { background: rgba(251,191,36,.12); color: var(--debit); }
.km-badge-retrait { background: rgba(232,160,74,.12); color: var(--amber); }

.km-empty {
  text-align: center; padding: 2.8rem 1.2rem;
  color: var(--ink-soft); font-size: .9rem;
}

/* ── Buttons ── */
.km-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
  padding: .7rem 1.2rem; border-radius: var(--radius-sm);
  font-weight: 600; font-size: .875rem;
  transition: transform 160ms ease, background var(--transition), box-shadow var(--transition);
}
.km-btn:active { transform: scale(.97); }
.km-btn-accent {
  background: var(--accent);
  color: #042f2e;
  box-shadow: 0 4px 16px var(--accent-glow);
}
.km-btn-accent:hover { background: #5eead4; }
.km-btn-ghost {
  background: rgba(255,255,255,.06);
  color: var(--ink);
  border: 1px solid var(--border-strong);
}
.km-btn-ghost:hover { background: rgba(255,255,255,.1); }
.km-btn-block { width: 100%; }

/* ── Modal ── */
.km-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(8,10,14,.72);
  backdrop-filter: blur(8px);
  z-index: 200;
  align-items: center; justify-content: center;
  padding: 1rem;
  animation: fadeIn .25s ease;
}
.km-modal-overlay.open { display: flex; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.km-modal {
  background: var(--bg-elevated);
  border: 1px solid var(--border-strong);
  border-radius: calc(var(--radius) + 4px);
  padding: 1.7rem 1.8rem;
  width: 100%; max-width: 420px;
  max-height: 90vh; overflow-y: auto;
  box-shadow: 0 24px 64px rgba(0,0,0,.5);
  animation: modalUp .3s cubic-bezier(.22,1,.36,1);
}
@keyframes modalUp {
  from { opacity: 0; transform: translateY(12px) scale(.98); }
  to { opacity: 1; transform: none; }
}
.km-modal-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 1.25rem; padding-bottom: .9rem;
  border-bottom: 1px solid var(--border);
}
.km-modal-head h3 {
  font-family: var(--serif);
  font-size: 1.25rem; letter-spacing: -.02em;
}
.km-modal-close {
  width: 30px; height: 30px; border-radius: 8px;
  background: var(--surface); border: 1px solid var(--border);
  color: var(--ink-soft); font-size: .9rem;
  display: flex; align-items: center; justify-content: center;
  transition: background var(--transition);
}
.km-modal-close:hover { background: var(--surface-2); color: var(--ink); }
.km-mfield { margin-bottom: 1.05rem; }
.km-mfield label {
  display: block; font-size: .72rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--ink-soft); margin-bottom: .4rem;
}
.km-mfield input, .km-mfield select {
  width: 100%;
  padding: .7rem .9rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-xs);
  font-size: .9rem; color: var(--ink); outline: none;
  transition: border-color var(--transition);
}
.km-mfield input:focus, .km-mfield select:focus { border-color: var(--accent); }
.km-mfield small { display: block; color: var(--ink-faint); font-size: .74rem; margin-top: .35rem; }
.km-presets { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .65rem; }
.km-preset {
  padding: .35rem .75rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: .78rem; font-weight: 600; color: var(--ink-soft);
  transition: border-color var(--transition), color var(--transition);
}
.km-preset:hover { border-color: var(--accent); color: var(--accent); }
.km-methode-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: .85rem; }
.km-methode-opt input { display: none; }
.km-methode-lbl {
  display: flex; align-items: center; gap: .4rem;
  padding: .6rem .8rem;
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-xs);
  cursor: pointer; font-size: .82rem; font-weight: 600; color: var(--ink-soft);
  transition: border-color var(--transition), background var(--transition), color var(--transition);
}
.km-methode-opt input:checked + .km-methode-lbl {
  border-color: var(--accent);
  color: var(--accent);
  background: var(--accent-glow);
}
.km-sim-note {
  padding: .65rem .85rem;
  background: rgba(45,212,191,.1);
  border: 1px solid rgba(45,212,191,.2);
  border-radius: var(--radius-xs);
  font-size: .78rem; color: #5eead4;
}
.km-couleur-grid {
  display: grid; grid-template-columns: repeat(5, 1fr); gap: .45rem;
}
.km-couleur-opt input { display: none; }
.km-couleur-swatch {
  height: 36px; border-radius: 8px; cursor: pointer;
  border: 2px solid transparent;
  transition: border-color var(--transition), transform 160ms ease;
}
.km-couleur-opt input:checked + .km-couleur-swatch {
  border-color: var(--ink);
  transform: scale(1.06);
}
.km-couleur-swatch.emerald { background: linear-gradient(145deg, #0d9488, #115e59); }
.km-couleur-swatch.silver { background: linear-gradient(145deg, #64748b, #475569); }
.km-couleur-swatch.platinum { background: linear-gradient(145deg, #1e293b, #0f172a); }
.km-couleur-swatch.amber { background: linear-gradient(145deg, #d97706, #b45309); }
.km-couleur-swatch.midnight { background: linear-gradient(145deg, #312e81, #1e1b4b); }

/* Focus */
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

/* ── Responsive ── */
@media (max-width: 1080px) {
  .km-grid { grid-template-columns: 1fr; }
  .km-metrics { grid-template-columns: 1fr 1fr; }
  .km-metrics .km-metric:last-child { grid-column: 1 / -1; }
}
@media (max-width: 860px) {
  .km-sidebar { transform: translateX(-100%); }
  .km-sidebar.open { transform: translateX(0); box-shadow: 12px 0 40px rgba(0,0,0,.5); }
  .km-main { margin-left: 0; }
  .km-menu-toggle { display: block; }
  .km-topbar-search { display: none; }
}
@media (max-width: 560px) {
  .km-content { padding: 1.2rem 1rem 2.8rem; }
  .km-topbar { padding: .8rem 1rem; }
  .km-metrics { grid-template-columns: 1fr; }
  .km-metrics .km-metric:last-child { grid-column: auto; }
  .km-vcard { flex: 0 0 260px; }
  .km-thead, .km-trow { grid-template-columns: 60px 1fr 90px; }
  .km-thead div:nth-child(3), .km-thead div:nth-child(5),
  .km-trow > div:nth-child(3), .km-trow > div:nth-child(5) { display: none; }
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
      <button class="km-menu-toggle" type="button" onclick="document.getElementById('km-sidebar').classList.toggle('open')" aria-label="Menu">☰</button>
      <div class="km-topbar-title">Overview</div>
      <div style="display:flex;align-items:center;gap:1rem">
        <div class="km-topbar-search">
          <span class="s-icon">⌕</span>
          <input type="text" placeholder="Rechercher…" id="searchInput" autocomplete="off">
        </div>
        <div class="km-topbar-user">
          <div class="km-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? 'K', 0, 1)) ?></div>
          <div>
            <div class="km-topbar-user-name"><?= $prenom ?></div>
            <div class="km-topbar-user-role"><?= $role ?></div>
          </div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- ── Cartes virtuelles (carrousel) ── -->
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

          <button type="button" class="km-vcard-add" onclick="document.getElementById('modal-carte').classList.add('open')" aria-label="Ajouter une carte">
            <span>+</span>
            <small>Ajouter</small>
          </button>
        </div>
      </div>

      <!-- ── Bank Balance metrics ── -->
      <div class="km-section-label">Bank Balance</div>
      <div class="km-metrics">
        <div class="km-metric">
          <div class="km-metric-label">Total Cash Balance</div>
          <div class="km-metric-val"><?= number_format($solde, 0, ',', ' ') ?> <span style="font-size:.85rem;opacity:.6">FCFA</span></div>
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

      <!-- ── Grid : chart + recent + actions ── -->
      <div class="km-grid">
        <div class="km-panel">
          <div class="km-panel-head">
            <h3>Crédits — 6 derniers mois</h3>
          </div>
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

          <div class="km-panel-head" style="border-top:1px solid var(--border)">
            <h3>Recent Transaction</h3>
            <a href="#historique">Voir tout →</a>
          </div>
          <div class="km-panel-body" style="padding-top:.4rem;padding-bottom:.5rem">
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
              <button type="button" class="km-quick-btn" onclick="document.getElementById('modal-recharge').classList.add('open')">
                <span class="q-icon">＋</span>
                Recharger le wallet
              </button>
              <?php if (estPrestataire()): ?>
              <button type="button" class="km-quick-btn" onclick="document.getElementById('modal-retrait').classList.add('open')">
                <span class="q-icon">↓</span>
                Retirer des fonds
              </button>
              <?php endif; ?>
              <button type="button" class="km-quick-btn" onclick="document.getElementById('modal-carte').classList.add('open')">
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

      <!-- ── Historique complet ── -->
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
              $badgeClass = $isCredit ? 'km-badge-credit' : ($tx['type_transaction'] === 'retrait' ? 'km-badge-retrait' : 'km-badge-debit');
            ?>
            <div class="km-trow" data-search="<?= htmlspecialchars(mb_strtolower($tx['libelle'])) ?>">
              <div><span class="km-badge <?= $badgeClass ?>"><?= strtoupper($tx['type_transaction']) ?></span></div>
              <div>
                <div class="lib"><?= htmlspecialchars($tx['libelle']) ?></div>
                <div class="sub">#<?= $tx['id_transaction'] ?? '' ?></div>
              </div>
              <div><?= date('d/m/Y H:i', strtotime($tx['date_transaction'])) ?></div>
              <div class="km-cell-right" style="font-family:var(--serif);font-weight:500;color:<?= $isCredit ? 'var(--credit)' : 'var(--ink)' ?>">
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

<!-- ── MODAL RECHARGE ── -->
<div class="km-modal-overlay" id="modal-recharge" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Recharger mon wallet</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-recharge').classList.remove('open')" aria-label="Fermer">✕</button>
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
      <button type="submit" class="km-btn km-btn-accent km-btn-block" style="margin-top:.35rem">Confirmer la recharge</button>
    </form>
  </div>
</div>

<?php if (estPrestataire()): ?>
<!-- ── MODAL RETRAIT ── -->
<div class="km-modal-overlay" id="modal-retrait" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Retirer des fonds</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-retrait').classList.remove('open')" aria-label="Fermer">✕</button>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem;padding:.85rem 1rem;background:var(--surface);border-radius:var(--radius-sm);margin-bottom:1.15rem;border:1px solid var(--border)">
      <div>
        <div style="font-size:.68rem;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em">Solde disponible</div>
        <div style="font-family:var(--serif);font-size:1.25rem;color:var(--accent)"><?= number_format($solde, 0, ',', ' ') ?> FCFA</div>
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
      <button type="submit" class="km-btn km-btn-accent km-btn-block" onclick="return confirm('Confirmer le retrait ?')">Demander le retrait</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── MODAL AJOUTER CARTE ── -->
<div class="km-modal-overlay" id="modal-carte" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Nouvelle carte virtuelle</h3>
      <button type="button" class="km-modal-close" onclick="document.getElementById('modal-carte').classList.remove('open')" aria-label="Fermer">✕</button>
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
      <button type="submit" class="km-btn km-btn-accent km-btn-block">Créer la carte</button>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.km-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.km-modal-overlay.open').forEach(m => m.classList.remove('open'));
  }
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
    document.querySelectorAll('.km-trow').forEach(row => {
      row.style.display = (row.dataset.search || '').includes(val) ? '' : 'none';
    });
  });
}
</script>

</body>
</html>
