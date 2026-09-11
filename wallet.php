<?php
require_once "config.php";
requireConnexion();

$pdo    = getConnexion();
$idUser = $_SESSION['id_utilisateur'];
$msg    = "";
$err    = "";

function kmEnsureWalletSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $alters = [
        "ALTER TABLE Carte_Virtuelle ADD COLUMN IF NOT EXISTS est_gelee BOOLEAN NOT NULL DEFAULT FALSE",
        "ALTER TABLE Transaction_Wallet ADD COLUMN IF NOT EXISTS id_carte INTEGER",
    ];
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            try {
                $pdo->exec(str_ireplace(' IF NOT EXISTS', '', $sql));
            } catch (Exception $e2) {
                /* colonne déjà présente ou moteur trop ancien */
            }
        }
    }
}

function kmIsAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function kmJsonExit(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

kmEnsureWalletSchema($pdo);

$wallet    = getOuCreerWallet($pdo, $idUser);
$id_wallet = (int)$wallet['id_wallet'];
$solde     = (float)$wallet['solde'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierTokenCSRF();
    $idCartePost = (int)($_POST['id_carte'] ?? 0);

    if ($_POST['action'] === 'geler_carte') {
        $id_carte = (int)($_POST['id_carte'] ?? 0);
        $geler    = ($_POST['geler'] ?? '1') === '1';
        try {
            if ($id_carte > 0) {
                $sql = "UPDATE Carte_Virtuelle SET est_gelee = " . ($geler ? "TRUE" : "FALSE")
                     . " WHERE id_carte = ? AND id_wallet = ?";
                $pdo->prepare($sql)->execute([$id_carte, $id_wallet]);
            }
            if (kmIsAjax()) {
                kmJsonExit(['ok' => true, 'gelee' => $geler, 'id_carte' => $id_carte]);
            }
            $msg = $geler ? "Carte gelée. Les paiements sont bloqués." : "Carte dégelée.";
        } catch (Exception $e) {
            if (kmIsAjax()) kmJsonExit(['ok' => false, 'error' => $e->getMessage()], 400);
            $err = "Impossible de geler/dégeler la carte. Exécutez le script SQL mis à jour sur Supabase.";
        }
    }

    elseif ($_POST['action'] === 'recharger') {
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

                try {
                    $pdo->prepare("
                        INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres, id_carte)
                        VALUES (?, 'credit', ?, ?, ?, ?)
                    ")->execute([
                        $wData['id_wallet'],
                        $montant,
                        "Recharge via $methode",
                        $nouveau_solde,
                        $idCartePost > 0 ? $idCartePost : null,
                    ]);
                } catch (Exception $eIns) {
                    $pdo->prepare("
                        INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
                        VALUES (?, 'credit', ?, ?, ?)
                    ")->execute([$wData['id_wallet'], $montant, "Recharge via $methode", $nouveau_solde]);
                }

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

                    try {
                        $pdo->prepare("
                            INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres, id_carte)
                            VALUES (?, 'retrait', ?, ?, ?, ?)
                        ")->execute([
                            $wData['id_wallet'],
                            $montant,
                            "Retrait vers $rib",
                            $nouveau_solde,
                            $idCartePost > 0 ? $idCartePost : null,
                        ]);
                    } catch (Exception $eIns) {
                        $pdo->prepare("
                            INSERT INTO Transaction_Wallet (id_wallet, type_transaction, montant, libelle, solde_apres)
                            VALUES (?, 'retrait', ?, ?, ?)
                        ")->execute([$wData['id_wallet'], $montant, "Retrait vers $rib", $nouveau_solde]);
                    }

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
                try {
                    $pdo->prepare("
                        INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale, est_gelee)
                        VALUES (?, ?, ?, ?, ?, ?, ?, FALSE, FALSE)
                    ")->execute([$id_wallet, mb_substr($libelle, 0, 80), $type_carte, $couleur, $numero_masque, mb_substr($nom, 0, 120), $exp]);
                } catch (Exception $eIns) {
                    $pdo->prepare("
                        INSERT INTO Carte_Virtuelle (id_wallet, libelle, type_carte, couleur, numero_masque, nom_titulaire, date_expiration, est_principale)
                        VALUES (?, ?, ?, ?, ?, ?, ?, FALSE)
                    ")->execute([$id_wallet, mb_substr($libelle, 0, 80), $type_carte, $couleur, $numero_masque, mb_substr($nom, 0, 120), $exp]);
                }
                $msg = "Carte virtuelle « " . htmlspecialchars($libelle) . " » ajoutée.";
            }
        } catch (Exception $e) {
            $err = "Impossible d'ajouter la carte. Exécutez le script SQL mis à jour sur Supabase. (" . $e->getMessage() . ")";
        }
    }
}

$cartes = [];
try {
    $stmtC = $pdo->prepare("SELECT * FROM Carte_Virtuelle WHERE id_wallet = ? ORDER BY est_principale DESC, date_creation ASC");
    $stmtC->execute([$id_wallet]);
    $cartes = $stmtC->fetchAll();
} catch (Exception $e) {
    $cartes = [];
}

$titulaireDefaut = trim(($_SESSION['prenom'] ?? 'Utilisateur') . ' ' . ($_SESSION['nom'] ?? ''));
if (empty($cartes)) {
    $cartes = [
        [
            'id_carte' => 0, 'libelle' => 'Emerald', 'type_carte' => 'visa', 'couleur' => 'emerald',
            'numero_masque' => '**** **** **** 0212', 'nom_titulaire' => $titulaireDefaut,
            'date_expiration' => '12/28', 'est_principale' => true, 'est_gelee' => false,
        ],
        [
            'id_carte' => -1, 'libelle' => 'Amber Prestige', 'type_carte' => 'mastercard', 'couleur' => 'amber',
            'numero_masque' => '**** **** **** 7741', 'nom_titulaire' => $titulaireDefaut,
            'date_expiration' => '09/29', 'est_principale' => false, 'est_gelee' => false,
        ],
        [
            'id_carte' => -2, 'libelle' => 'Midnight', 'type_carte' => 'visa', 'couleur' => 'midnight',
            'numero_masque' => '**** **** **** 3908', 'nom_titulaire' => $titulaireDefaut,
            'date_expiration' => '04/30', 'est_principale' => false, 'est_gelee' => false,
        ],
    ];
}

$transactions = [];
try {
    $stmtT = $pdo->prepare("
        SELECT * FROM Transaction_Wallet
        WHERE id_wallet = ?
        ORDER BY date_transaction DESC
        LIMIT 50
    ");
    $stmtT->execute([$id_wallet]);
    $transactions = $stmtT->fetchAll();
} catch (Exception $e) {
    $transactions = [];
}

$total_credit = 0.0;
$total_debit  = 0.0;
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM Transaction_Wallet WHERE id_wallet = ? AND type_transaction = 'credit'");
    $st->execute([$id_wallet]);
    $total_credit = (float)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM Transaction_Wallet WHERE id_wallet = ? AND type_transaction IN ('debit', 'retrait')");
    $st->execute([$id_wallet]);
    $total_debit = (float)$st->fetchColumn();
} catch (Exception $e) { /* ignore */ }

$nb_tx = count($transactions);

$mois_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin','07'=>'Juil','08'=>'Août','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
$emptyMois = [];
for ($i = 5; $i >= 0; $i--) {
    $cle = date('Y-m', strtotime("-$i months"));
    $emptyMois[$cle] = ['label' => $mois_fr[date('m', strtotime("-$i months"))], 'credits' => 0.0];
}

$nCartes = max(1, count($cartes));
$cartesPayload = [];

foreach ($cartes as $idx => $c) {
    $cid = (int)($c['id_carte'] ?? 0);
    $isPrin = !empty($c['est_principale']);
    $cardTxs = [];

    foreach ($transactions as $tx) {
        $txCid = isset($tx['id_carte']) && $tx['id_carte'] !== null && $tx['id_carte'] !== ''
            ? (int)$tx['id_carte'] : 0;
        if ($txCid > 0) {
            if ($cid > 0 && $txCid === $cid) $cardTxs[] = $tx;
        } else {
            $bucket = abs(crc32((string)($tx['id_transaction'] ?? $tx['libelle']))) % $nCartes;
            if ($bucket === $idx) $cardTxs[] = $tx;
        }
    }

    $credits = 0.0;
    $debits  = 0.0;
    $par_mois_card = $emptyMois;
    foreach ($cardTxs as $tx) {
        $m = (float)$tx['montant'];
        if ($tx['type_transaction'] === 'credit') {
            $credits += $m;
            $cle = date('Y-m', strtotime($tx['date_transaction']));
            if (isset($par_mois_card[$cle])) $par_mois_card[$cle]['credits'] += $m;
        } else {
            $debits += $m;
        }
    }

    $soldeCarte = $isPrin ? $solde : max(0, $credits - $debits);
    $last4 = preg_replace('/\D/', '', $c['numero_masque'] ?? '000');
    $cvv = str_pad((string)((int)substr($last4, -3) ?: random_int(100, 999)), 3, '0', STR_PAD_LEFT);

    $mapTx = static function (array $tx): array {
        $isCredit = $tx['type_transaction'] === 'credit';
        return [
            'id'          => (int)($tx['id_transaction'] ?? 0),
            'type'        => $tx['type_transaction'],
            'montant'     => (float)$tx['montant'],
            'libelle'     => $tx['libelle'],
            'date'        => date('d/m à H:i', strtotime($tx['date_transaction'])),
            'date_full'   => date('d/m/Y H:i', strtotime($tx['date_transaction'])),
            'solde_apres' => (float)$tx['solde_apres'],
            'credit'      => $isCredit,
        ];
    };

    $cartesPayload[] = [
        'id'         => $cid,
        'libelle'    => $c['libelle'] ?? 'Carte',
        'type'       => $c['type_carte'] ?? 'visa',
        'couleur'    => $c['couleur'] ?? 'emerald',
        'numero'     => $c['numero_masque'] ?? '**** **** **** 0000',
        'titulaire'  => $c['nom_titulaire'] ?? $titulaireDefaut,
        'exp'        => $c['date_expiration'] ?? '12/28',
        'cvv'        => $cvv,
        'principale' => (bool)$isPrin,
        'gelee'      => !empty($c['est_gelee']),
        'solde'      => $soldeCarte,
        'credits'    => $isPrin ? $total_credit : $credits,
        'debits'     => $isPrin ? $total_debit : $debits,
        'mois'       => array_values($par_mois_card),
        'txs'        => array_map($mapTx, $cardTxs),
    ];
}

if (!empty($cartesPayload[0]) && $cartesPayload[0]['principale']) {
    $cartesPayload[0]['solde']   = $solde;
    $cartesPayload[0]['credits'] = $total_credit;
    $cartesPayload[0]['debits']  = $total_debit;
}

$dashboard = estAdmin() ? 'admin_dashboard.php' : (estPrestataire() ? 'prestataire_dashboard.php' : 'client_dashboard.php');
$prenom = htmlspecialchars($_SESSION['prenom'] ?? 'Utilisateur');
$nom    = htmlspecialchars($_SESSION['nom'] ?? '');
$role   = estAdmin() ? 'Admin' : (estPrestataire() ? 'Prestataire' : 'Client');
$csrf   = genererTokenCSRF();
$initial = strtoupper(substr($_SESSION['prenom'] ?? 'K', 0, 1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Mon Wallet — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --paper: #f5f4f0;
  --paper-deep: #eae7df;
  --surface: #ffffff;
  --ink: #1c1b17;
  --ink-soft: #6c675c;
  --ink-faint: #9b9788;
  --line: #dad6cb;
  --amber: #b96b2a;
  --amber-deep: #8a4e1b;
  --amber-tint: #f1e3d2;
  --teal: #2e6b5e;
  --teal-tint: #e4ede9;
  --danger: #a6412b;
  --danger-tint: #f3e2dc;
  --radius: 14px;
  --radius-sm: 8px;
  --sidebar-w: 250px;
  --card-h: 172px;
  --peek: 26px;
  --ease-stack: cubic-bezier(0.2, 0.8, 0.2, 1);
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

html {
  overflow-x: hidden;
  max-width: 100%;
  -webkit-text-size-adjust: 100%;
  touch-action: manipulation;
}

body {
  margin: 0;
  background: var(--paper);
  color: var(--ink);
  font-family: "Inter", ui-sans-serif, system-ui, sans-serif;
  font-size: 15.5px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
  max-width: 100vw;
  width: 100%;
  position: relative;
}

body::before {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  opacity: 0.04;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.55'/></svg>");
}

a {
  color: inherit;
  text-decoration: none;
}

button {
  font-family: inherit;
  cursor: pointer;
  border: none;
  background: none;
  color: inherit;
}

input,
select {
  font-family: inherit;
  max-width: 100%;
}

h1,
h2,
h3,
.km-serif {
  font-family: "Fraunces", ui-serif, Georgia, serif;
}

img,
svg,
video {
  max-width: 100%;
  height: auto;
}

@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.001ms !important;
    transition-duration: 0.001ms !important;
  }
}

/* ── SHELL ── */
.km-app {
  display: flex;
  min-height: 100vh;
  overflow-x: hidden;
  max-width: 100vw;
  width: 100%;
  position: relative;
  z-index: 1;
}
.km-sidebar {
  width: var(--sidebar-w);
  background: var(--ink);
  color: #e8e4d8;
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  z-index: 100;
  transition: transform 0.3s var(--ease-stack);
}
.km-sb-brand {
  padding: 1.5rem 1.4rem 1.2rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.km-sb-brand a {
  font-family: "Fraunces", serif;
  font-weight: 600;
  font-size: 1.3rem;
  color: #fff;
}
.km-sb-brand em {
  font-style: normal;
  color: var(--amber);
}
.km-sb-nav {
  flex: 1;
  padding: 1.1rem 0.8rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  overflow-y: auto;
}
.km-sb-link {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.68rem 0.85rem;
  border-radius: 8px;
  font-size: 0.9rem;
  color: #b9b4a5;
  position: relative;
  transition:
    background 0.18s ease,
    color 0.18s ease;
}
.km-sb-link:hover {
  background: rgba(255, 255, 255, 0.06);
  color: #fff;
}
.km-sb-link.active {
  background: rgba(185, 107, 42, 0.18);
  color: #f0dfc7;
  font-weight: 600;
}
.km-sb-link.active::before {
  content: "";
  position: absolute;
  left: 0;
  top: 22%;
  bottom: 22%;
  width: 2px;
  background: var(--amber);
}
.km-sb-icon {
  width: 17px;
  height: 17px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
}
.km-sb-icon svg {
  width: 100%;
  height: 100%;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.km-sb-section {
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #8b8778;
  padding: 1rem 0.85rem 0.3rem;
}
.km-sb-foot {
  padding: 1rem 0.9rem 1.3rem;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}
.km-wallet-mini {
  display: block;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 12px;
  padding: 0.9rem 1rem;
  transition: border-color 0.18s ease;
}
.km-wallet-mini:hover {
  border-color: var(--amber);
}
.km-wallet-mini .w-label {
  font-size: 0.7rem;
  color: #a8a398;
}
.km-wallet-mini .w-solde {
  font-family: "Fraunces", serif;
  font-weight: 600;
  color: #fff;
  font-size: 1.15rem;
  margin-top: 0.2rem;
  font-variant-numeric: tabular-nums;
}
.km-sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(28, 27, 23, 0.45);
  z-index: 90;
  opacity: 0;
  transition: opacity 0.25s ease;
  pointer-events: none;
}
.km-sidebar-overlay.visible {
  display: block;
  opacity: 1;
  pointer-events: auto;
}

.km-main {
  flex: 1;
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow-x: hidden;
  max-width: 100%;
  width: 100%;
  min-width: 0;
}
.km-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.9rem 1.8rem;
  background: color-mix(in srgb, var(--paper) 86%, transparent);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--line);
  position: sticky;
  top: 0;
  z-index: 50;
  width: 100%;
  max-width: 100%;
}
.km-menu-toggle {
  display: none;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--ink);
  padding: 0.35rem;
  line-height: 1;
  flex-shrink: 0;
}
.km-topbar-search {
  flex: 1;
  max-width: 280px;
  position: relative;
  min-width: 0;
}
.km-topbar-search input {
  width: 100%;
  padding: 0.55rem 1rem 0.55rem 2.3rem;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 20px;
  font-size: 0.86rem;
  color: var(--ink);
  outline: none;
}
.km-topbar-search input:focus {
  border-color: var(--ink);
}
.km-topbar-search .s-icon {
  position: absolute;
  left: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0.5;
  pointer-events: none;
  display: grid;
  place-items: center;
}
.km-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--amber-tint);
  color: var(--amber-deep);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.92rem;
  flex-shrink: 0;
}
.km-topbar-user {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-shrink: 0;
}
.km-topbar-user-name {
  font-size: 0.86rem;
  font-weight: 600;
}
.km-topbar-user-role {
  font-size: 0.72rem;
  color: var(--ink-soft);
}

.km-content {
  padding: 1.8rem 1.8rem 3rem;
  flex: 1;
  width: 100%;
  max-width: 1120px;
  overflow-x: hidden;
  min-width: 0;
}

.km-alert {
  padding: 0.75rem 1rem;
  border-left: 2px solid;
  font-size: 0.86rem;
  margin-bottom: 1.2rem;
  word-break: break-word;
}
.km-alert-ok {
  border-color: var(--teal);
  background: var(--teal-tint);
  color: #1e4b41;
}
.km-alert-err {
  border-color: var(--danger);
  background: var(--danger-tint);
  color: #7a2e1d;
}

.km-section-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-faint);
  margin-bottom: 0.85rem;
}

/* ── METRICS ── */
.km-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.9rem;
  margin-bottom: 1.7rem;
  width: 100%;
}
.km-metric {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 1.15rem 1.25rem;
  transition:
    border-color 0.18s ease,
    transform 0.18s ease;
  min-width: 0;
  box-shadow: 3px 3px 0 color-mix(in srgb, var(--ink) 6%, transparent);
}
.km-metric:hover {
  border-color: var(--amber-deep);
  transform: translateY(-2px);
}
.km-metric-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ink-faint);
  margin-bottom: 0.35rem;
}
.km-metric-val {
  font-family: "Fraunces", serif;
  font-size: 1.55rem;
  font-weight: 600;
  line-height: 1.2;
  word-break: break-word;
  font-variant-numeric: tabular-nums;
}
.km-metric-val.credit {
  color: var(--teal);
}
.km-metric-val.debit {
  color: var(--amber-deep);
}
.km-metric-sub {
  font-size: 0.75rem;
  color: var(--ink-soft);
  margin-top: 0.25rem;
}
.km-metric-val.km-num-out {
  animation: numOut 0.16s var(--ease-stack) both;
}
.km-metric-val.km-num-in {
  animation: numIn 0.32s var(--ease-stack) both;
}
@keyframes numOut {
  to {
    opacity: 0;
    transform: translateY(8px);
    filter: blur(2px);
  }
}
@keyframes numIn {
  from {
    opacity: 0;
    transform: translateY(-8px);
    filter: blur(3px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
    filter: blur(0);
  }
}

/* ═══════════════════════════════════════
   CARD STACK — 3D accordion
   ═══════════════════════════════════════ */
.km-cards-wrap {
  margin-bottom: 1.4rem;
  width: 100%;
  max-width: 100%;
}
.km-stack-stage {
  position: relative;
  width: min(100%, 348px);
  margin: 0 auto;
  height: calc(var(--card-h) + var(--peek) * (var(--stack-n, 1) - 1) + 8px);
  touch-action: pan-x; /* le geste vertical pilote le changement de carte, pas le scroll de page */
  outline: none;
}
.km-vcard {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(var(--peek) * (var(--stack-n, 1) - 1));
  height: var(--card-h);
  transform: translate3d(0, var(--ty, 0px), 0) scale(var(--sc, 1));
  transition:
    transform 0.4s var(--ease-stack),
    box-shadow 0.4s var(--ease-stack);
  z-index: var(--z, 1);
  cursor: default;
  will-change: transform;
  border-radius: var(--radius);
  background: var(--ink); /* fond de secours 100% opaque, ceinture + bretelles */
  overflow: hidden;
  -webkit-tap-highlight-color: transparent;
}
.km-vcard:focus-visible {
  outline: 2px solid var(--amber-deep);
  outline-offset: 3px;
}
.vcard-front {
  position: absolute;
  inset: 0;
  border-radius: var(--radius);
  padding: 1.15rem 1.25rem;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: #fff;
  overflow: hidden;
  box-shadow:
    0 10px 28px rgba(28, 27, 23, 0.18),
    0 1px 0 rgba(255, 255, 255, 0.12) inset;
}
.vcard-front::after {
  content: "";
  position: absolute;
  top: 0;
  left: -80%;
  width: 55%;
  height: 100%;
  background: linear-gradient(
    105deg,
    transparent 20%,
    rgba(255, 255, 255, 0.22) 45%,
    rgba(255, 255, 255, 0.08) 55%,
    transparent 80%
  );
  transform: skewX(-18deg);
  pointer-events: none;
  animation: shine 5.5s ease-in-out infinite;
}
@keyframes shine {
  0%,
  70% {
    left: -80%;
    opacity: 0;
  }
  75% {
    opacity: 1;
  }
  100% {
    left: 130%;
    opacity: 0;
  }
}
.vcard-front::before {
  content: "";
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse 80% 55% at 92% 8%, rgba(255, 255, 255, 0.16), transparent 55%);
  pointer-events: none;
  border-radius: inherit;
}
.vcard-front.emerald {
  background: linear-gradient(145deg, #2e6b5e 0%, #1e4a42 55%, #163832 100%);
}
.vcard-front.silver {
  background: linear-gradient(145deg, #7a7568 0%, #5c574c 50%, #3f3c35 100%);
}
.vcard-front.platinum {
  background: linear-gradient(145deg, #3d3a34 0%, #2a2824 60%, #1c1b17 100%);
  border: 1px solid rgba(255, 255, 255, 0.12);
}
.vcard-front.amber {
  background: linear-gradient(145deg, #b96b2a 0%, #8a4e1b 55%, #6b3c14 100%);
}
.vcard-front.midnight {
  background: linear-gradient(145deg, #3a3650 0%, #2a2740 55%, #1a1828 100%);
}

.km-vcard.active .vcard-front {
  box-shadow:
    0 0 0 1.5px rgba(255, 255, 255, 0.28),
    0 16px 36px rgba(28, 27, 23, 0.3);
}
.km-vcard.active.emerald-halo .vcard-front {
  box-shadow:
    0 0 0 1.5px rgba(46, 107, 94, 0.55),
    0 16px 36px rgba(46, 107, 94, 0.32);
}
.km-vcard.active.amber-halo .vcard-front {
  box-shadow:
    0 0 0 1.5px rgba(185, 107, 42, 0.5),
    0 16px 36px rgba(185, 107, 42, 0.32);
}
.km-vcard.active.midnight-halo .vcard-front {
  box-shadow:
    0 0 0 1.5px rgba(90, 80, 140, 0.45),
    0 16px 36px rgba(40, 35, 80, 0.4);
}

.km-vcard-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  z-index: 1;
  position: relative;
}
.km-vcard-brand {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  opacity: 0.92;
}
.km-vcard-type {
  font-size: 0.68rem;
  font-weight: 600;
  opacity: 0.7;
  letter-spacing: 0.04em;
  margin-top: 0.1rem;
}
.km-vcard-chip {
  width: 34px;
  height: 24px;
  border-radius: 4px;
  background:
    linear-gradient(90deg, transparent 45%, rgba(0, 0, 0, 0.12) 45%, rgba(0, 0, 0, 0.12) 55%, transparent 55%),
    linear-gradient(0deg, transparent 45%, rgba(0, 0, 0, 0.12) 45%, rgba(0, 0, 0, 0.12) 55%, transparent 55%),
    linear-gradient(135deg, #efe0a8, #c9a24a 45%, #8a6a22);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
  margin: 0.2rem 0 0.05rem;
  z-index: 1;
  position: relative;
}
.km-vcard-number {
  font-size: 0.98rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  z-index: 1;
  position: relative;
  font-variant-numeric: tabular-nums;
}
.km-vcard-bottom {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  z-index: 1;
  position: relative;
}
.km-vcard-holder {
  font-size: 0.76rem;
  font-weight: 500;
  opacity: 0.9;
  max-width: 58%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.km-vcard-exp {
  font-size: 0.7rem;
  opacity: 0.7;
}
.km-vcard-logo {
  font-family: "Fraunces", serif;
  font-size: 1.05rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  opacity: 0.95;
}

/* Frost overlay */
.vcard-frost {
  position: absolute;
  inset: 0;
  border-radius: inherit;
  overflow: hidden;
  pointer-events: none;
  z-index: 8;
  opacity: 0;
  clip-path: inset(0 0 100% 0);
  transition:
    opacity 0.45s var(--ease-stack),
    clip-path 0.7s var(--ease-stack);
  background-image:
    repeating-linear-gradient(
      23deg,
      transparent,
      transparent 11px,
      rgba(255, 255, 255, 0.09) 11px,
      rgba(255, 255, 255, 0.09) 12px
    ),
    repeating-linear-gradient(
      -19deg,
      transparent,
      transparent 17px,
      rgba(255, 255, 255, 0.07) 17px,
      rgba(255, 255, 255, 0.07) 18px
    ),
    repeating-linear-gradient(
      68deg,
      transparent,
      transparent 8px,
      rgba(46, 107, 94, 0.08) 8px,
      rgba(46, 107, 94, 0.08) 9px
    ),
    linear-gradient(165deg, rgba(245, 244, 240, 0.55), rgba(196, 216, 210, 0.28) 42%, rgba(46, 107, 94, 0.28));
  backdrop-filter: blur(4px) saturate(0.72);
  -webkit-backdrop-filter: blur(4px) saturate(0.72);
}
.km-vcard.is-frozen .vcard-frost {
  opacity: 1;
  clip-path: inset(0 0 0 0);
}
.vcard-frost svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0.7;
}
.vcard-frost-label {
  position: absolute;
  left: 1.1rem;
  bottom: 1.05rem;
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #1c1b17;
  background: rgba(245, 244, 240, 0.78);
  padding: 0.28rem 0.55rem;
  border-radius: 4px;
}

.km-stack-actions {
  width: min(100%, 348px);
  margin: 0.95rem auto 0;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.km-freeze-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  width: 100%;
  min-height: 46px;
  padding: 0.7rem 1rem;
  border: 1px solid var(--ink);
  border-radius: 10px;
  background: var(--ink);
  color: var(--paper);
  font-size: 0.86rem;
  font-weight: 600;
  transition:
    transform 0.18s var(--ease-stack),
    background 0.18s ease,
    color 0.18s ease,
    border-color 0.18s ease;
  will-change: transform;
}
.km-freeze-btn:hover {
  background: #2a2924;
}
.km-freeze-btn:active {
  transform: scale(0.96);
}
.km-freeze-btn.is-frozen {
  background: var(--teal-tint);
  color: #1e4b41;
  border-color: var(--teal);
}
.km-freeze-btn svg {
  width: 16px;
  height: 16px;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.km-vcard-add {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  width: 100%;
  min-height: 46px;
  border-radius: 10px;
  border: 1.5px dashed var(--line);
  background: var(--surface);
  color: var(--ink-soft);
  font-size: 0.86rem;
  font-weight: 600;
  transition:
    border-color 0.18s ease,
    background 0.18s ease,
    color 0.18s ease;
}
.km-vcard-add:hover {
  border-color: var(--amber-deep);
  background: var(--amber-tint);
  color: var(--amber-deep);
}
.km-stack-hint {
  text-align: center;
  font-size: 0.72rem;
  color: var(--ink-faint);
  margin-top: 0.15rem;
}

/* ── PANELS / TX / CHART ── */
.km-grid {
  display: grid;
  grid-template-columns: 1.35fr 1fr;
  gap: 1.2rem;
  margin-bottom: 1.7rem;
  width: 100%;
}
.km-panel {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  overflow: hidden;
  width: 100%;
  min-width: 0;
  box-shadow: 3px 3px 0 color-mix(in srgb, var(--ink) 6%, transparent);
}
.km-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--line);
}
.km-panel-head h3 {
  font-size: 0.92rem;
  font-weight: 600;
  font-family: "Inter", sans-serif;
  margin: 0;
}
.km-panel-head a {
  font-size: 0.8rem;
  color: var(--amber-deep);
  font-weight: 600;
  white-space: nowrap;
}
.km-panel-body {
  padding: 1.1rem 1.25rem;
  overflow: hidden;
}

.km-live.is-exiting .km-tx-row,
.km-live.is-exiting .km-chart-col {
  animation: slideOutDown 0.18s ease-in both;
}
.km-live.is-entering .km-tx-row,
.km-live.is-entering .km-chart-col {
  animation: slideIn 0.45s var(--ease-stack) both;
}
.km-live.is-entering .km-tx-row:nth-child(1),
.km-live.is-entering .km-chart-col:nth-child(1) {
  animation-delay: 0.02s;
}
.km-live.is-entering .km-tx-row:nth-child(2),
.km-live.is-entering .km-chart-col:nth-child(2) {
  animation-delay: 0.06s;
}
.km-live.is-entering .km-tx-row:nth-child(3),
.km-live.is-entering .km-chart-col:nth-child(3) {
  animation-delay: 0.1s;
}
.km-live.is-entering .km-tx-row:nth-child(4),
.km-live.is-entering .km-chart-col:nth-child(4) {
  animation-delay: 0.14s;
}
.km-live.is-entering .km-tx-row:nth-child(5),
.km-live.is-entering .km-chart-col:nth-child(5) {
  animation-delay: 0.18s;
}
.km-live.is-entering .km-tx-row:nth-child(6),
.km-live.is-entering .km-chart-col:nth-child(6) {
  animation-delay: 0.22s;
}

@keyframes slideOutDown {
  to {
    opacity: 0;
    transform: translateY(14px);
    filter: blur(2px);
  }
}
@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
    filter: blur(3px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
    filter: blur(0);
  }
}

.km-chart {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  height: 120px;
  padding-top: 0.4rem;
  width: 100%;
}
.km-chart-col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  height: 100%;
  justify-content: flex-end;
}
.km-chart-bar {
  width: 100%;
  background: linear-gradient(180deg, var(--amber), var(--amber-tint));
  border-radius: 5px 5px 2px 2px;
  min-height: 4px;
  transition: height 0.5s var(--ease-stack);
}
.km-chart-label {
  font-size: 0.65rem;
  color: var(--ink-faint);
}

.km-tx-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.7rem 0;
  border-bottom: 1px solid var(--line);
  min-width: 0;
}
.km-tx-row:last-child {
  border-bottom: none;
}
.km-tx-dot {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.km-tx-dot.credit {
  background: var(--teal-tint);
  color: var(--teal);
}
.km-tx-dot.debit {
  background: var(--amber-tint);
  color: var(--amber-deep);
}
.km-tx-dot svg {
  width: 15px;
  height: 15px;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.8;
}
.km-tx-label {
  flex: 1;
  min-width: 0;
}
.km-tx-label strong {
  display: block;
  font-size: 0.86rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.km-tx-time {
  font-size: 0.72rem;
  color: var(--ink-faint);
  margin-top: 0.1rem;
}
.km-tx-amount {
  font-family: "Fraunces", serif;
  font-weight: 600;
  font-size: 0.9rem;
  white-space: nowrap;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.km-tx-amount.credit {
  color: var(--teal);
}

.km-quick {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.km-quick-btn {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--line);
  border-radius: 10px;
  font-size: 0.86rem;
  font-weight: 600;
  transition:
    border-color 0.18s ease,
    background 0.18s ease;
  text-align: left;
  width: 100%;
}
.km-quick-btn:hover {
  border-color: var(--amber-deep);
  background: var(--amber-tint);
}
.km-quick-btn .q-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: var(--amber-tint);
  color: var(--amber-deep);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.km-quick-btn .q-icon svg {
  width: 15px;
  height: 15px;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.8;
}

.km-hist-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 1rem;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.km-hist-head h3 {
  font-size: 1.15rem;
  font-weight: 600;
  margin: 0;
}
.km-hist-head span {
  font-size: 0.8rem;
  color: var(--ink-soft);
}
.km-table-wrap {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  overflow: hidden;
  width: 100%;
}
.km-thead {
  display: grid;
  grid-template-columns: 70px 1fr 140px 110px 120px;
  background: var(--paper-deep);
  border-bottom: 1px solid var(--line);
}
.km-thead div {
  padding: 0.7rem 1rem;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ink-soft);
}
.km-trow {
  display: grid;
  grid-template-columns: 70px 1fr 140px 110px 120px;
  border-bottom: 1px solid var(--line);
  transition: background 0.15s ease;
}
.km-trow:last-child {
  border-bottom: none;
}
.km-trow:hover {
  background: var(--paper);
}
.km-trow > div {
  padding: 0.75rem 1rem;
  display: flex;
  align-items: center;
  font-size: 0.85rem;
  min-width: 0;
}
.km-trow .lib {
  font-weight: 600;
  word-break: break-word;
}
.km-trow .sub {
  font-size: 0.7rem;
  color: var(--ink-faint);
  font-weight: 400;
  margin-top: 0.1rem;
}
.km-cell-right {
  justify-content: flex-end;
  text-align: right;
}
.km-badge {
  display: inline-flex;
  padding: 0.2rem 0.55rem;
  border-radius: 20px;
  font-size: 0.68rem;
  font-weight: 700;
}
.km-badge-teal {
  background: var(--teal-tint);
  color: var(--teal);
}
.km-badge-amber {
  background: var(--amber-tint);
  color: var(--amber-deep);
}
.km-badge-neutral {
  background: var(--paper-deep);
  color: var(--ink-soft);
}
.km-empty {
  text-align: center;
  padding: 2.8rem 1rem;
  color: var(--ink-soft);
  font-size: 0.9rem;
}

.km-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.65rem 1.1rem;
  border-radius: 8px;
  border: 1px solid transparent;
  font-weight: 600;
  font-size: 0.86rem;
  cursor: pointer;
  transition:
    transform 0.16s ease,
    background 0.16s ease;
}
.km-btn:active {
  transform: scale(0.96);
}
.km-btn-primary {
  background: var(--ink);
  color: var(--paper);
}
.km-btn-primary:hover {
  background: var(--amber-deep);
}
.km-btn-block {
  width: 100%;
}

.km-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(28, 27, 23, 0.55);
  z-index: 200;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
}
.km-modal-overlay.open {
  display: flex;
}
.km-modal {
  background: var(--surface);
  border-radius: 18px;
  padding: 1.8rem 1.9rem;
  width: 100%;
  max-width: 440px;
  max-height: 90vh;
  overflow-y: auto;
  border: 1px solid var(--line);
  margin: auto;
}
.km-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1.2rem;
  padding-bottom: 0.9rem;
  border-bottom: 1px solid var(--line);
}
.km-modal-head h3 {
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0;
}
.km-modal-close {
  background: var(--paper);
  border: 1px solid var(--line);
  width: 28px;
  height: 28px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  color: var(--ink-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.km-mfield {
  margin-bottom: 1rem;
}
.km-mfield label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--ink-soft);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.4rem;
}
.km-mfield input,
.km-mfield select {
  width: 100%;
  padding: 0.65rem 0.85rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--paper);
  font-family: "Inter", sans-serif;
  font-size: 0.9rem;
  color: var(--ink);
  outline: none;
}
.km-mfield input:focus,
.km-mfield select:focus {
  border-color: var(--amber-deep);
}
.km-mfield small {
  display: block;
  color: var(--ink-faint);
  font-size: 0.74rem;
  margin-top: 0.35rem;
}
.km-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 0.7rem;
}
.km-preset {
  padding: 0.35rem 0.8rem;
  background: var(--paper);
  border: 1px solid var(--line);
  border-radius: 20px;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--ink-soft);
  cursor: pointer;
}
.km-preset:hover {
  border-color: var(--amber-deep);
  color: var(--amber-deep);
}
.km-methode-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-bottom: 0.9rem;
}
.km-methode-opt input {
  display: none;
}
.km-methode-lbl {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.6rem 0.8rem;
  background: var(--paper);
  border: 1.5px solid var(--line);
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--ink-soft);
}
.km-methode-opt input:checked + .km-methode-lbl {
  border-color: var(--amber-deep);
  color: var(--amber-deep);
  background: var(--amber-tint);
}
.km-sim-note {
  padding: 0.6rem 0.8rem;
  background: var(--teal-tint);
  color: #1e4b41;
  border-radius: 8px;
  font-size: 0.78rem;
}
.km-couleur-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 0.45rem;
}
.km-couleur-opt input {
  display: none;
}
.km-couleur-swatch {
  height: 36px;
  border-radius: 8px;
  cursor: pointer;
  border: 2px solid transparent;
  display: block;
  transition:
    border-color 0.18s ease,
    transform 0.15s ease;
}
.km-couleur-opt input:checked + .km-couleur-swatch {
  border-color: var(--ink);
  transform: scale(1.06);
}
.km-couleur-swatch.emerald {
  background: linear-gradient(145deg, #2e6b5e, #1e4a42);
}
.km-couleur-swatch.silver {
  background: linear-gradient(145deg, #7a7568, #5c574c);
}
.km-couleur-swatch.platinum {
  background: linear-gradient(145deg, #3d3a34, #1c1b17);
}
.km-couleur-swatch.amber {
  background: linear-gradient(145deg, #b96b2a, #8a4e1b);
}
.km-couleur-swatch.midnight {
  background: linear-gradient(145deg, #3a3650, #1a1828);
}

a:focus-visible,
button:focus-visible,
input:focus-visible,
select:focus-visible {
  outline: 2px solid var(--amber-deep);
  outline-offset: 2px;
}

@media (max-width: 1080px) {
  .km-grid {
    grid-template-columns: 1fr;
  }
  .km-metrics {
    grid-template-columns: 1fr 1fr;
  }
  .km-metrics .km-metric:last-child {
    grid-column: 1 / -1;
  }
}
@media (max-width: 860px) {
  .km-sidebar {
    transform: translateX(-100%);
  }
  .km-sidebar.open {
    transform: translateX(0);
    box-shadow: 8px 0 32px rgba(28, 27, 23, 0.25);
  }
  .km-main {
    margin-left: 0;
    width: 100%;
    max-width: 100vw;
  }
  .km-menu-toggle {
    display: grid;
    place-items: center;
  }
  .km-topbar-search {
    display: none;
  }
}
@media (max-width: 768px) {
  .km-content {
    padding: 0.85rem 0.85rem 2.2rem;
    max-width: 100%;
    width: 100%;
  }
  .km-topbar {
    padding: 0.75rem 0.85rem;
    gap: 0.5rem;
  }
  .km-metrics {
    grid-template-columns: 1fr;
    gap: 0.7rem;
  }
  .km-metrics .km-metric:last-child {
    grid-column: auto;
  }
  .km-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  .km-panel-body {
    padding: 0.95rem 1rem;
  }
  .km-panel-head {
    padding: 0.9rem 1rem;
  }
  .km-metric-val {
    font-size: 1.4rem;
  }
}
@media (max-width: 640px) {
  .km-thead {
    display: none;
  }
  .km-trow {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 0.35rem 0.8rem;
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--line);
  }
  .km-trow > div {
    padding: 0;
    width: auto;
  }
  .km-trow > div:nth-child(1) {
    order: 1;
  }
  .km-trow > div:nth-child(2) {
    order: 3;
    flex: 1 1 100%;
    margin-top: 0.15rem;
  }
  .km-trow > div:nth-child(3) {
    order: 2;
    margin-left: auto;
    font-size: 0.78rem;
    color: var(--ink-soft);
  }
  .km-trow > div:nth-child(4) {
    order: 4;
    font-size: 1rem;
  }
  .km-trow > div:nth-child(5) {
    order: 5;
    font-size: 0.78rem;
    color: var(--ink-soft);
    margin-left: auto;
  }
  .km-cell-right {
    justify-content: flex-start;
    text-align: left;
  }
}
@media (max-width: 560px) {
  .km-topbar-user-name,
  .km-topbar-user-role {
    display: none;
  }
  .km-modal {
    padding: 1.35rem 1.2rem;
    width: min(92vw, 440px);
    max-width: 100%;
    border-radius: 14px;
  }
  .km-couleur-grid {
    grid-template-columns: repeat(auto-fit, minmax(40px, 1fr));
  }
  .km-metric-val {
    font-size: 1.3rem;
  }
  .km-vcard-number {
    font-size: 0.9rem;
    letter-spacing: 0.1em;
  }
}

</style>
</head>
<body>
<div class="km-app">
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
        <span class="km-sb-icon"><svg viewBox="0 0 20 20"><rect x="3" y="4" width="14" height="12" rx="1"/><path d="M3 9h14"/></svg></span>
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
        <div class="w-label">Solde de la carte</div>
        <div class="w-solde" id="sb-solde"><?= number_format($solde, 0, ',', ' ') ?> FCFA</div>
      </a>
    </div>
  </aside>

  <div class="km-main">
    <header class="km-topbar">
      <button class="km-menu-toggle" type="button" id="km-burger" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <div class="km-topbar-search">
        <span class="s-icon"><svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M17 17l-4-4"/></svg></span>
        <input type="text" placeholder="Rechercher…" id="searchInput" autocomplete="off">
      </div>
      <div class="km-topbar-user">
        <div class="km-avatar"><?= $initial ?></div>
        <div>
          <div class="km-topbar-user-name"><?= $prenom ?></div>
          <div class="km-topbar-user-role"><?= $role ?></div>
        </div>
      </div>
    </header>

    <div class="km-content">
      <?php if ($msg): ?><div class="km-alert km-alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="km-alert km-alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <div class="km-section-label" id="card-section-label">Cette carte</div>
      <div class="km-metrics km-live" id="km-metrics">
        <div class="km-metric">
          <div class="km-metric-label">Solde de la carte</div>
          <div class="km-metric-val" id="m-solde"><?= number_format($solde, 0, ',', ' ') ?> <span style="font-size:.8rem;opacity:.55">FCFA</span></div>
          <div class="km-metric-sub" id="m-solde-sub">Solde disponible</div>
        </div>
        <div class="km-metric">
          <div class="km-metric-label">Credits</div>
          <div class="km-metric-val credit" id="m-credits">+ <?= number_format($total_credit, 0, ',', ' ') ?></div>
          <div class="km-metric-sub">Entrées cumulées</div>
        </div>
        <div class="km-metric">
          <div class="km-metric-label">Debits</div>
          <div class="km-metric-val debit" id="m-debits">− <?= number_format($total_debit, 0, ',', ' ') ?></div>
          <div class="km-metric-sub">Sorties cumulées</div>
        </div>
      </div>

      <div class="km-cards-wrap">
        <div class="km-section-label">Mes cartes · scrollez ou swipez verticalement pour changer</div>
        <div class="km-stack-stage" id="cards-stack" style="--stack-n: <?= count($cartes) ?>">
          <?php foreach ($cartes as $idx => $c):
            $coul = htmlspecialchars($c['couleur'] ?? 'emerald');
            $type = strtoupper($c['type_carte'] ?? 'visa');
            $cid  = (int)($c['id_carte'] ?? $idx);
            $last4 = preg_replace('/\D/', '', $c['numero_masque'] ?? '0212');
            $cvv  = str_pad((string)((int)substr($last4, -3) ?: random_int(100, 999)), 3, '0', STR_PAD_LEFT);
            $gelee = !empty($c['est_gelee']);
          ?>
          <article class="km-vcard <?= $coul ?>-halo<?= $gelee ? ' is-frozen' : '' ?>"
               data-card-id="<?= $cid ?>"
               data-index="<?= $idx ?>"
               data-cvv="<?= $cvv ?>"
               aria-hidden="true">
              <div class="vcard-front <?= $coul ?>">
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
              </div>
            <div class="vcard-frost" aria-hidden="true">
              <svg viewBox="0 0 348 172" preserveAspectRatio="none">
                <path d="M28 0 L72 58 L44 172 M72 58 L140 40 L188 172 M140 40 L210 0 M188 90 L260 20 L330 110 M260 20 L300 172" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="0.7"/>
                <path d="M0 80 L60 96 L90 40 L130 120 L200 70 L250 150 L348 88" fill="none" stroke="rgba(28,27,23,.18)" stroke-width="0.6"/>
                <circle cx="72" cy="58" r="2.2" fill="rgba(255,255,255,.5)"/>
                <circle cx="140" cy="40" r="1.6" fill="rgba(255,255,255,.45)"/>
                <circle cx="260" cy="20" r="2" fill="rgba(255,255,255,.4)"/>
              </svg>
              <div class="vcard-frost-label">Carte gelée</div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <div class="km-stack-actions">
          <button type="button" class="km-freeze-btn" id="btn-freeze" aria-pressed="false">
            <svg viewBox="0 0 24 24" id="ico-freeze"><path d="M12 2v20M12 12L4 6m8 6l8-6M12 12L4 18m8-6l8 6M7 4.5L12 12 17 4.5"/></svg>
            <span id="btn-freeze-label">Geler la carte</span>
          </button>
          <button type="button" class="km-freeze-btn" id="btn-cvv" style="background:var(--surface);color:var(--ink);border-color:var(--line)">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
            <span>Voir le CVV</span>
          </button>
          <div id="cvv-popover" style="display:none;text-align:center;font-family:'Fraunces',serif;font-weight:600;font-size:1.1rem;letter-spacing:.15em;background:var(--paper-deep);border-radius:8px;padding:.5rem;margin-top:-.2rem"></div>
          <button type="button" class="km-vcard-add" onclick="openModal('modal-carte')" aria-label="Ajouter une carte">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>
            Ajouter une carte
          </button>
          <p class="km-stack-hint">↕ Scrollez ou swipez verticalement sur la pile pour changer de carte</p>
        </div>
      </div>

      <div class="km-grid">
        <div class="km-panel km-live" id="panel-live" style="order:2">
          <div class="km-panel-head"><h3>Crédits — 6 derniers mois</h3></div>
          <div class="km-panel-body">
            <div class="km-chart" id="km-chart"></div>
          </div>
          <div class="km-panel-head" style="border-top:1px solid var(--line)">
            <h3>Transactions récentes</h3>
            <a href="#historique">Voir tout →</a>
          </div>
          <div class="km-panel-body" id="km-tx-list" style="padding-top:.3rem;padding-bottom:.4rem"></div>
        </div>

        <div class="km-panel" style="order:1">
          <div class="km-panel-head"><h3>Actions rapides</h3></div>
          <div class="km-panel-body">
            <div class="km-quick">
              <button type="button" class="km-quick-btn" onclick="openModal('modal-recharge')">
                <span class="q-icon"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
                Recharger le wallet
              </button>
              <?php if (estPrestataire()): ?>
              <button type="button" class="km-quick-btn" onclick="openModal('modal-retrait')">
                <span class="q-icon"><svg viewBox="0 0 24 24"><path d="M12 5v14M7 14l5 5 5-5"/></svg></span>
                Retirer des fonds
              </button>
              <?php endif; ?>
              <button type="button" class="km-quick-btn" onclick="openModal('modal-carte')">
                <span class="q-icon"><svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
                Nouvelle carte virtuelle
              </button>
              <button type="button" class="km-quick-btn" id="quick-freeze">
                <span class="q-icon"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
                <span id="quick-freeze-label">Geler cette carte</span>
              </button>
              <a href="<?= $dashboard ?>?tab=commandes" class="km-quick-btn">
                <span class="q-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span>
                Mes commandes
              </a>
            </div>
          </div>
        </div>
      </div>

      <div id="historique">
        <div class="km-hist-head">
          <h3>Historique</h3>
          <span id="hist-count"><?= $nb_tx ?> opération<?= $nb_tx > 1 ? 's' : '' ?></span>
        </div>
        <div class="km-table-wrap km-live" id="km-hist"></div>
      </div>
    </div>
  </div>
</div>

<div class="km-modal-overlay" id="modal-recharge" role="dialog" aria-modal="true">
  <div class="km-modal">
    <div class="km-modal-head">
      <h3>Recharger mon wallet</h3>
      <button type="button" class="km-modal-close" onclick="closeModal('modal-recharge')" aria-label="Fermer">✕</button>
    </div>
    <form method="POST" action="wallet.php">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="recharger">
      <input type="hidden" name="id_carte" class="js-id-carte" value="">
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
      <input type="hidden" name="id_carte" class="js-id-carte" value="">
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

<script type="application/json" id="km-cards-data"><?= json_encode($cartesPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
<script>
const KM_CSRF = <?= json_encode($csrf) ?>;
const KM_CARDS = JSON.parse(document.getElementById('km-cards-data').textContent);
const PEEK = 26;
const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(n || 0));

const stage = document.getElementById('cards-stack');
const vcards = [...stage.querySelectorAll('.km-vcard')];
let active = 0;
let liveTimer = null;

function layoutStack() {
  const n = vcards.length;
  stage.style.setProperty('--stack-n', n);
  vcards.forEach((card, i) => {
    const behind = (active - i + n) % n;
    card.classList.toggle('active', behind === 0);
    card.style.setProperty('--z', String(n - behind));
    if (behind === 0) {
      card.style.setProperty('--ty', '0px');
      card.style.setProperty('--sc', '1');
      card.style.setProperty('--rx', '0deg');
    } else {
      card.style.setProperty('--ty', (-PEEK * behind) + 'px');
      card.style.setProperty('--sc', String(Math.max(0.82, 1 - 0.05 * behind)));
    }
  });
}

function currentCard() {
  return KM_CARDS[active] || KM_CARDS[0];
}

function playLive(fn) {
  const lives = document.querySelectorAll('.km-live');
  lives.forEach(el => { el.classList.remove('is-entering'); el.classList.add('is-exiting'); });
  if (liveTimer) clearTimeout(liveTimer);
  liveTimer = setTimeout(() => {
    fn();
    lives.forEach(el => { el.classList.remove('is-exiting'); el.classList.add('is-entering'); });
    setTimeout(() => lives.forEach(el => el.classList.remove('is-entering')), 520);
  }, 170);
}

function iconTx(credit) {
  return credit
    ? '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M6 18V6h12"/></svg>'
    : '<svg viewBox="0 0 24 24"><path d="M18 18L6 6M18 6v12H6"/></svg>';
}

function renderDashboard() {
  const c = currentCard();
  document.getElementById('card-section-label').textContent = 'Cette carte · ' + c.libelle;
  const soldeEl = document.getElementById('m-solde');
  soldeEl.classList.remove('km-num-in','km-num-out');
  void soldeEl.offsetWidth;
  soldeEl.classList.add('km-num-out');
  setTimeout(() => {
    soldeEl.innerHTML = fmt(c.solde) + ' <span style="font-size:.8rem;opacity:.55">FCFA</span>';
    soldeEl.classList.remove('km-num-out');
    soldeEl.classList.add('km-num-in');
  }, 150);
  document.getElementById('m-credits').textContent = '+ ' + fmt(c.credits);
  document.getElementById('m-debits').textContent  = '− ' + fmt(c.debits);
  document.getElementById('m-solde-sub').textContent = c.gelee ? 'Carte gelée — paiements bloqués' : 'Solde disponible';
  document.getElementById('sb-solde').textContent = fmt(c.solde) + ' FCFA';

  const maxM = Math.max(1, ...c.mois.map(m => m.credits));
  document.getElementById('km-chart').innerHTML = c.mois.map(m => {
    const h = Math.max(6, Math.round((m.credits / maxM) * 100));
    return `<div class="km-chart-col"><div class="km-chart-bar" style="height:${h}%" title="${fmt(m.credits)} FCFA"></div><div class="km-chart-label">${m.label}</div></div>`;
  }).join('');

  const txs = c.txs || [];
  const list = document.getElementById('km-tx-list');
  if (!txs.length) {
    list.innerHTML = '<div class="km-empty">Aucune transaction sur cette carte.</div>';
  } else {
    list.innerHTML = txs.slice(0, 5).map(tx => `
      <div class="km-tx-row">
        <div class="km-tx-dot ${tx.credit ? 'credit' : 'debit'}">${iconTx(tx.credit)}</div>
        <div class="km-tx-label">
          <strong>${escapeHtml(tx.libelle)}</strong>
          <div class="km-tx-time">${tx.date}</div>
        </div>
        <div class="km-tx-amount${tx.credit ? ' credit' : ''}">${tx.credit ? '+' : '−'} ${fmt(tx.montant)}</div>
      </div>`).join('');
  }

  const hist = document.getElementById('km-hist');
  document.getElementById('hist-count').textContent = txs.length + ' opération' + (txs.length > 1 ? 's' : '') + ' · ' + c.libelle;
  if (!txs.length) {
    hist.innerHTML = '<div class="km-empty">Aucune transaction sur cette carte.</div>';
  } else {
    hist.innerHTML = `<div class="km-thead"><div>Type</div><div>Libellé</div><div>Date</div><div class="km-cell-right">Montant</div><div class="km-cell-right">Solde après</div></div>`
      + txs.map(tx => {
        const badge = tx.credit ? 'km-badge-teal' : (tx.type === 'retrait' ? 'km-badge-amber' : 'km-badge-neutral');
        return `<div class="km-trow" data-search="${escapeHtml((tx.libelle || '').toLowerCase())}">
          <div><span class="km-badge ${badge}">${escapeHtml((tx.type || '').toUpperCase())}</span></div>
          <div><div class="lib">${escapeHtml(tx.libelle)}</div><div class="sub">#${tx.id}</div></div>
          <div>${tx.date_full}</div>
          <div class="km-cell-right" style="font-family:'Fraunces',serif;font-weight:600;color:${tx.credit ? 'var(--teal)' : 'var(--ink)'}">${tx.credit ? '+' : '−'} ${fmt(tx.montant)}</div>
          <div class="km-cell-right" style="color:var(--ink-soft)">${fmt(tx.solde_apres)}</div>
        </div>`;
      }).join('');
  }

  document.querySelectorAll('.js-id-carte').forEach(el => { el.value = c.id > 0 ? c.id : ''; });
  syncFreezeUi();
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, ch => ({'&':'&','<':'<','>':'>','"':'"',"'":'&#39;'}[ch]));
}

function syncFreezeUi() {
  const c = currentCard();
  const btn = document.getElementById('btn-freeze');
  const lab = document.getElementById('btn-freeze-label');
  const qlab = document.getElementById('quick-freeze-label');
  btn.classList.toggle('is-frozen', !!c.gelee);
  btn.setAttribute('aria-pressed', c.gelee ? 'true' : 'false');
  lab.textContent = c.gelee ? 'Dégeler' : 'Geler la carte';
  if (qlab) qlab.textContent = c.gelee ? 'Dégeler cette carte' : 'Geler cette carte';
  vcards.forEach((el, i) => {
    el.classList.toggle('is-frozen', !!(KM_CARDS[i] && KM_CARDS[i].gelee));
  });
}

function selectCard(index) {
  if (index === active) return;
  active = index;
  layoutStack();
  playLive(renderDashboard);
  hideCvv();
}

// ── Navigation exclusivement au scroll / swipe / clavier ──
stage.setAttribute('tabindex', '0');
stage.setAttribute('role', 'listbox');
stage.setAttribute('aria-label', 'Cartes virtuelles — scrollez ou swipez pour changer');

let navLocked = false;
function lockNav(ms = 420) {
  navLocked = true;
  setTimeout(() => { navLocked = false; }, ms);
}
function step(dir) {
  if (navLocked || vcards.length < 2) return;
  const n = vcards.length;
  selectCard((active + dir + n) % n);
  lockNav();
}

stage.addEventListener('wheel', e => {
  e.preventDefault();
  if (Math.abs(e.deltaY) < 4) return;
  step(e.deltaY > 0 ? 1 : -1);
}, { passive: false });

let touchStartY = null;
stage.addEventListener('touchstart', e => {
  touchStartY = e.touches[0].clientY;
}, { passive: true });
stage.addEventListener('touchmove', e => {
  if (touchStartY === null || navLocked) return;
  const dy = e.touches[0].clientY - touchStartY;
  if (Math.abs(dy) > 32) {
    step(dy < 0 ? 1 : -1);
    touchStartY = null;
  }
}, { passive: true });
stage.addEventListener('touchend', () => { touchStartY = null; });

stage.addEventListener('keydown', e => {
  if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); step(1); }
  if (e.key === 'ArrowUp'   || e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
});

// ── CVV à la demande (bouton dédié, hors carte) ──
const cvvBtn = document.getElementById('btn-cvv');
const cvvPop = document.getElementById('cvv-popover');
let cvvTimer = null;
function hideCvv() { cvvPop.style.display = 'none'; }
cvvBtn.addEventListener('click', () => {
  const card = vcards[active];
  cvvPop.textContent = 'CVV : ' + (card.dataset.cvv || '---');
  cvvPop.style.display = 'block';
  clearTimeout(cvvTimer);
  cvvTimer = setTimeout(hideCvv, 4000);
});

async function toggleFreeze() {
  const c = currentCard();
  const next = !c.gelee;
  c.gelee = next;
  syncFreezeUi();
  if (!(c.id > 0)) return;
  const fd = new FormData();
  fd.append('action', 'geler_carte');
  fd.append('id_carte', String(c.id));
  fd.append('geler', next ? '1' : '0');
  fd.append('csrf_token', KM_CSRF);
  try {
    await fetch('wallet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  } catch (e) { /* état local conservé */ }
}

document.getElementById('btn-freeze').addEventListener('click', toggleFreeze);
const qf = document.getElementById('quick-freeze');
if (qf) qf.addEventListener('click', toggleFreeze);

const freezeBtn = document.getElementById('btn-freeze');
freezeBtn.addEventListener('mousemove', e => {
  if (window.matchMedia('(pointer: coarse)').matches) return;
  const r = freezeBtn.getBoundingClientRect();
  freezeBtn.style.transform = `translate(${(e.clientX - r.left - r.width/2) * 0.12}px, ${(e.clientY - r.top - r.height/2) * 0.18}px)`;
});
freezeBtn.addEventListener('mouseleave', () => { freezeBtn.style.transform = ''; });

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
document.getElementById('km-burger').addEventListener('click', () => {
  const sb = document.getElementById('km-sidebar');
  if (sb.classList.contains('open')) closeSidebar(); else openSidebar();
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

layoutStack();
renderDashboard();
</script>
</body>
</html>