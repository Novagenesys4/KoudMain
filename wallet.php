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

// ---------------------------------------------------------------------------
// Données complémentaires pour la nouvelle interface
// ---------------------------------------------------------------------------
$initiales2 = strtoupper(mb_substr($_SESSION['prenom'] ?? 'K', 0, 1) . mb_substr($_SESSION['nom'] ?? 'M', 0, 1));
$soldeTotalAffiche = array_sum(array_column($cartesPayload, 'solde'));

$jours_fr = [0=>'Dimanche',1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi'];
$mois_fr_long = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
$now = new DateTime();
$eyebrowDate = $jours_fr[(int)$now->format('w')] . ' ' . (int)$now->format('j') . ' ' . $mois_fr_long[(int)$now->format('n')] . ' ' . $now->format('Y');

// Liens de navigation contextuels selon le rôle connecté
if (estAdmin()) {
    $lien_catalogue  = 'admin_dashboard.php?tab=categories';
    $lien_commandes  = 'admin_dashboard.php?tab=commandes';
    $lien_stats      = 'admin_dashboard.php';
} elseif (estPrestataire()) {
    $lien_catalogue  = $dashboard . '?tab=prestations';
    $lien_commandes  = $dashboard . '?tab=commandes';
    $lien_stats      = null;
} else {
    $lien_catalogue  = $dashboard . '?tab=catalogue';
    $lien_commandes  = $dashboard . '?tab=commandes';
    $lien_stats      = null;
}

function icon(string $name, int $size = 17): string {
    $paths = [
        'arrow-down'         => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
        'arrow-down-left'    => '<path d="M17 7 7 17"/><path d="M17 17H7V7"/>',
        'arrow-down-to-line' => '<path d="M12 17V3"/><path d="m6 11 6 6 6-6"/><path d="M19 21H5"/>',
        'arrow-left'         => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-right'        => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'arrow-up'           => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
        'arrow-up-right'     => '<path d="M7 17 17 7"/><path d="M7 7h10v10"/>',
        'bar-chart'          => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'bell'               => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'chevron-down'       => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left'       => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right'      => '<path d="m9 18 6-6-6-6"/>',
        'circle-help'        => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'credit-card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'eye'                => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'            => '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a17.5 17.5 0 0 1-2.16 3.19"/><path d="M6.61 6.61A17.34 17.34 0 0 0 2 12s3.5 8 10 8a9.5 9.5 0 0 0 5-1.5"/><path d="M2 2l20 20"/><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>',
        'file-text'          => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'home'               => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'lock'               => '<circle cx="12" cy="16" r="1"/><rect x="3" y="10" width="18" height="12" rx="2"/><path d="M7 10V7a5 5 0 0 1 10 0v3"/>',
        'menu'               => '<line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'more-horizontal'    => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'plus'               => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search'             => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'settings'           => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'sparkles'           => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'user-round'         => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'wallet-cards'       => '<path d="M17 14h.01"/><path d="M7 7h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2h9a2 2 0 0 1 2 2v2"/>',
        'x'                  => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'zap'                => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'check'              => '<polyline points="20 6 9 17 4 12"/>',
    ];
    $body = $paths[$name] ?? $paths['sparkles'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Mon Wallet — KoudMain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#F5F4F0;--paper-deep:#EBE8DF;--surface:#FFFEFA;
  --ink:#1D211C;--ink-soft:#6B6D64;--ink-faint:#9A9B91;--line:#DDDCD3;
  --amber:#BB6C2D;--amber-deep:#8D4E1F;--amber-tint:#F4E4D4;
  --teal:#2E6B5E;--teal-deep:#1d5046;--teal-tint:#E3EFEA;
  --danger:#A85245;
  --radius:15px;--sidebar:256px;
  --ease:cubic-bezier(.22,1,.36,1);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;min-width:320px;background:var(--paper);color:var(--ink);font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.035;z-index:1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.5'/%3E%3C/svg%3E")}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
button{cursor:pointer;color:inherit;border:0;background:transparent}
button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--amber-deep);outline-offset:3px}
svg{display:block}
h1,h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:600;letter-spacing:-.04em}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important;scroll-behavior:auto!important}}

.wallet-app{display:flex;min-height:100vh;position:relative;z-index:2}
.sidebar{width:var(--sidebar);flex:0 0 var(--sidebar);position:fixed;inset:0 auto 0 0;z-index:50;display:flex;flex-direction:column;color:#E8E4D8;background:#1C1B17;box-shadow:12px 0 34px rgba(20,20,17,.09);transition:transform .28s var(--ease)}
.brand{padding:26px 24px 22px;border-bottom:1px solid rgba(255,255,255,.08)}
.brand-logo{display:inline-block;font-family:Fraunces,serif;font-weight:700;font-size:23px;letter-spacing:-.03em;color:#fffdf7}
.brand-logo span{color:var(--amber)}
.brand-tag{margin-top:6px;color:#7d7a6d;font-size:9.5px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}
.sidebar-scroll{flex:1;padding:22px 14px;overflow-y:auto}
.nav-group{margin-bottom:24px}
.nav-label{display:block;padding:0 12px 9px;color:#7d7a6d;font-size:10px;text-transform:uppercase;letter-spacing:.13em;font-weight:700}
.nav-item{position:relative;width:100%;display:flex;align-items:center;gap:12px;min-height:44px;padding:0 13px;color:#a8a49a;background:transparent;border:0;border-radius:10px;font-size:13.5px;font-weight:500;text-align:left;transition:background .18s ease,color .18s ease,transform .18s ease}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.06);transform:translateX(2px)}
.nav-item.active{color:#f6e2cb;background:linear-gradient(90deg,rgba(187,108,45,.24),rgba(187,108,45,.07));font-weight:650}
.nav-item.active::before{content:"";position:absolute;left:0;top:22%;bottom:22%;width:2px;border-radius:3px;background:var(--amber)}
.sidebar-bottom{padding:14px 18px 20px;border-top:1px solid rgba(255,255,255,.08)}
.wallet-card-mini{padding:15px 16px;margin-bottom:12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:13px;transition:border-color .18s ease,background .18s ease}
.wallet-card-mini:hover{border-color:var(--amber);background:rgba(187,108,45,.08)}
.wallet-card-mini-label{color:#98988c;font-size:10px;font-weight:600;letter-spacing:.02em}
.wallet-card-mini-amount{margin-top:6px;font-family:Fraunces,serif;font-weight:600;font-size:19px;color:#fff}
.wallet-card-mini-amount small{font-family:"DM Sans",sans-serif;color:#999b91;font-size:10px;font-weight:500}
.sidebar-note{display:flex;align-items:flex-start;gap:7px;margin-bottom:13px;color:#79776b;font-size:10.5px;line-height:1.5}
.sidebar-note svg{flex:0 0 auto;margin-top:2px;color:var(--amber)}
.profile-row{width:100%;display:flex;align-items:center;gap:9px;padding:0;border:0;background:transparent;color:#e8e8df;text-align:left}
.profile-row>div:nth-child(2){flex:1;min-width:0}
.profile-row strong,.profile-row span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.profile-row strong{font-size:12px;font-weight:600}
.profile-row span{color:#85887d;font-size:10px;margin-top:2px}
.avatar{width:32px;height:32px;display:grid;place-items:center;flex:0 0 auto;color:#6d3a18;background:#f0d4b7;border-radius:50%;font-size:11px;font-weight:700}
.avatar-sm{width:30px;height:30px}
.sidebar-backdrop{display:none}

.main-area{flex:1;min-width:0;margin-left:var(--sidebar)}
.topbar{height:76px;display:flex;align-items:center;justify-content:space-between;gap:22px;padding:0 42px;position:sticky;top:0;z-index:20;background:rgba(244,242,236,.83);border-bottom:1px solid rgba(222,219,209,.8);backdrop-filter:blur(14px)}
.topbar-left,.topbar-actions,.breadcrumb,.top-profile{display:flex;align-items:center}
.topbar-left{gap:20px}
.topbar-actions{gap:16px}
.breadcrumb{gap:9px;color:var(--ink-faint);font-size:12px}
.breadcrumb strong{color:var(--ink);font-weight:600}
.icon-button{width:34px;height:34px;display:grid;place-items:center;position:relative;color:var(--ink-soft);background:transparent;border:1px solid transparent;border-radius:9px;transition:color .16s ease,background .16s ease,transform .16s ease}
.icon-button:hover{color:var(--ink);background:var(--surface);border-color:var(--line)}
.icon-button:active,.button:active{transform:scale(.97)}
.topbar-left>.icon-button{display:none}
.top-search{width:250px;height:35px;display:flex;align-items:center;gap:8px;padding:0 12px;color:var(--ink-faint);background:var(--surface);border:1px solid var(--line);border-radius:9px}
.top-search input{width:100%;padding:0;color:var(--ink);background:transparent;border:0;outline:0;font-size:11px}
.top-search input::placeholder{color:var(--ink-faint)}
.topbar-actions .icon-button i{position:absolute;top:7px;right:7px;width:5px;height:5px;background:var(--amber);border:1px solid var(--paper);border-radius:50%;font-style:normal}
.top-profile{gap:7px;padding-left:5px}

.page-content{width:min(1180px,100%);margin:0 auto;padding:50px 42px 35px}
.eyebrow{color:var(--ink-faint);font-size:10px;text-transform:uppercase;letter-spacing:.15em;font-weight:700}
h1{font-size:clamp(40px,5vw,67px);line-height:.98;margin:14px 0 17px}
h1 em{color:var(--amber);font-style:normal}
h2{font-size:22px;line-height:1.1}
.hero-row{display:flex;align-items:end;justify-content:space-between;gap:30px;margin-bottom:40px;flex-wrap:wrap}
.hero-copy{max-width:420px;margin:0;color:var(--ink-soft);font-size:14px;line-height:1.65}
.hero-actions{display:flex;align-items:center;gap:9px;padding-bottom:5px}
.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:37px;padding:0 14px;color:var(--ink);border:1px solid transparent;border-radius:8px;font-size:11px;font-weight:700;transition:transform .16s var(--ease),background .16s ease,border-color .16s ease,color .16s ease}
.button-dark{color:#fff;background:var(--ink)}
.button-dark:hover{background:var(--amber-deep)}
.button-light{background:var(--surface);border-color:var(--line)}
.button-light:hover,.button-outline:hover{background:var(--amber-tint);border-color:#d2ac89;color:var(--amber-deep)}
.button-outline{color:var(--ink-soft);background:transparent;border-color:var(--line)}
.button.full{width:100%;min-height:44px;margin-top:6px}

.metrics-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:12px;margin-bottom:20px}
.metric-card{min-height:137px;padding:19px 21px;background:rgba(255,254,250,.7);border:1px solid var(--line);border-radius:var(--radius);box-shadow:3px 4px 0 rgba(31,32,28,.035);transition:transform .2s var(--ease),border-color .2s ease}
.metric-card:hover{transform:translateY(-2px);border-color:#c8bda9}
.metric-main{background:var(--ink);color:#f5f2ea;border-color:var(--ink)}
.metric-head{display:flex;align-items:center;justify-content:space-between;color:var(--ink-faint);font-size:10px;text-transform:uppercase;letter-spacing:.1em;font-weight:700}
.metric-main .metric-head{color:#a9aba1}
.metric-icon{width:27px;height:27px;display:grid;place-items:center;color:var(--amber);background:var(--amber-tint);border-radius:8px}
.metric-icon.green{color:var(--teal);background:var(--teal-tint)}
.metric-icon.amber{color:var(--amber-deep);background:var(--amber-tint)}
.metric-card>strong{display:block;margin-top:14px;font-family:Fraunces,serif;font-size:28px;line-height:1;letter-spacing:-.03em;font-weight:600}
.metric-card>strong small{font-family:"DM Sans",sans-serif;color:var(--ink-faint);font-size:10px;letter-spacing:0}
.metric-main>strong small{color:#93968d}
.green-text{color:var(--teal)}
.amber-text{color:var(--amber-deep)}
.metric-foot{display:flex;align-items:center;gap:9px;margin-top:15px;color:var(--ink-faint);font-size:10px}
.metric-main .metric-foot{color:#8d9087}

.dashboard-grid,.lower-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:20px;margin-bottom:20px}
.panel{min-width:0;overflow:hidden;background:rgba(255,254,250,.72);border:1px solid var(--line);border-radius:var(--radius);box-shadow:3px 4px 0 rgba(31,32,28,.035)}
.panel-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:19px 21px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.panel-header .eyebrow{margin-bottom:7px}
.panel-header h2{font-size:20px}
.card-nav{display:flex;align-items:center;gap:8px;color:var(--ink-faint);font-family:"DM Mono",monospace;font-size:10px}
.card-nav button{width:27px;height:27px;display:grid;place-items:center;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:7px;transition:background .16s ease,transform .16s ease}
.card-nav button:hover{background:var(--amber-tint)}
.card-nav button:active{transform:scale(.93)}
.cards-panel{background:var(--surface)}
.cards-stage{height:243px;position:relative;width:min(100%,410px);margin:25px auto 3px;cursor:grab;outline:none;touch-action:pan-x}
.cards-stage:active{cursor:grabbing}
.card-stack-item{position:absolute;inset:0;transform-origin:center top;transition:transform .42s var(--ease),opacity .3s ease}
.wallet-card{position:relative;width:100%;height:203px;display:flex;flex-direction:column;justify-content:space-between;padding:20px 22px 17px;overflow:hidden;color:#fff;border:0;border-radius:16px;box-shadow:0 17px 30px rgba(26,29,25,.2),inset 0 1px rgba(255,255,255,.22);text-align:left;transition:box-shadow .3s ease}
.card-active{cursor:default;box-shadow:0 17px 38px rgba(26,29,25,.3),0 0 0 1px rgba(255,255,255,.23) inset}
.card-sheen{position:absolute;inset:0;background:radial-gradient(ellipse 80% 55% at 94% 6%,rgba(255,255,255,.22),transparent 56%);pointer-events:none}
.card-sheen::after{content:"";position:absolute;top:-80%;left:-100%;width:42%;height:270%;transform:rotate(21deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.19),transparent);animation:sheen 5.8s ease-in-out infinite}
@keyframes sheen{0%,68%{left:-100%;opacity:0}74%{opacity:1}100%{left:170%;opacity:0}}
.wallet-card.emerald{background:linear-gradient(145deg,#347c6c,#205449 60%,#173a34)}
.wallet-card.amber{background:linear-gradient(145deg,#cf7d35,#9b561f 60%,#6d3d17)}
.wallet-card.midnight{background:linear-gradient(145deg,#514c72,#332f50 58%,#1e1c32)}
.wallet-card.silver{background:linear-gradient(145deg,#928d7e,#605c51 60%,#403e37)}
.wallet-card.platinum{background:linear-gradient(145deg,#4a4842,#25241f 65%,#171713)}
.card-topline,.card-bottom{position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between}
.card-name{display:block;font-size:12px;font-weight:700;letter-spacing:.03em}
.card-type{display:block;margin-top:3px;opacity:.65;font-size:9px;letter-spacing:.14em}
.card-network{font-family:Fraunces,serif;font-size:21px;letter-spacing:-.08em;font-weight:600;opacity:.95}
.chip{position:relative;z-index:1;width:34px;height:24px;display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(2,1fr);margin-top:-7px;overflow:hidden;background:linear-gradient(135deg,#f2db9c,#c1943c);border-radius:5px;box-shadow:inset 0 1px rgba(255,255,255,.45)}
.chip span{border:.5px solid rgba(70,52,17,.32)}
.card-number{position:relative;z-index:1;font-family:"DM Mono",monospace;font-size:14px;letter-spacing:.14em}
.card-bottom{align-items:end}
.card-bottom>div{display:flex;flex-direction:column;gap:3px}
.card-caption{opacity:.56;font-size:7px;letter-spacing:.14em}
.card-bottom strong{max-width:170px;overflow:hidden;text-overflow:ellipsis;font-size:9px;letter-spacing:.12em;white-space:nowrap}
.card-koud{font-family:Fraunces,serif;font-size:13px;font-weight:600;letter-spacing:-.06em;opacity:.9}
.card-koud span{color:#e0a36e}
.frozen-overlay{position:absolute;inset:0;z-index:4;display:flex;align-items:end;gap:7px;padding:17px 22px;color:var(--ink);background:repeating-linear-gradient(22deg,transparent,transparent 10px,rgba(255,255,255,.15) 11px,transparent 12px),rgba(226,237,232,.68);backdrop-filter:blur(4px) saturate(.7);font-size:10px;font-weight:800;letter-spacing:.16em;text-transform:uppercase}
.card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:0 21px}
.freeze-button{color:#fff;background:var(--ink)}
.freeze-button:hover{background:var(--amber-deep)}
.frozen-button{color:var(--teal-deep);background:var(--teal-tint);border-color:#8abcae}
.cvv-reveal{display:flex;align-items:center;justify-content:center;gap:7px;width:calc(100% - 42px);margin:10px auto 0;padding:8px;color:var(--amber-deep);background:var(--amber-tint);border-radius:8px;font-size:11px}
.cvv-reveal strong{font-family:"DM Mono",monospace;letter-spacing:.12em}
.cvv-reveal span{color:var(--ink-soft);font-size:9px}
.stack-hint{display:flex;align-items:center;justify-content:center;gap:5px;padding:18px 15px 15px;margin:0;color:var(--ink-faint);font-size:10px}

.quick-panel{background:#f1eee6}
.quick-list{padding:5px 21px 8px}
.quick-list button{width:100%;display:flex;align-items:center;gap:11px;padding:13px 0;border:0;border-bottom:1px solid var(--line);background:transparent;text-align:left;transition:transform .16s var(--ease)}
.quick-list button:hover{transform:translateX(4px)}
.quick-list button:last-child{border-bottom:0}
.quick-list button>span:nth-child(2){flex:1;min-width:0}
.quick-list strong,.quick-list small{display:block}
.quick-list strong{font-size:12px;font-weight:700}
.quick-list small{margin-top:3px;color:var(--ink-soft);font-size:10px}
.quick-list button>svg{color:var(--ink-faint)}
.quick-icon{width:33px;height:33px;display:grid;place-items:center;flex:0 0 auto;border-radius:10px}
.green-bg{color:var(--teal);background:var(--teal-tint)}
.amber-bg{color:var(--amber-deep);background:var(--amber-tint)}
.dark-bg{color:#fff;background:var(--ink)}
.sand-bg{color:#916442;background:#eadbc8}
.amber-icon{color:var(--amber)}
.quick-note{display:flex;gap:9px;margin:12px 21px 20px;padding:12px 13px;color:#6e6d64;background:rgba(255,255,255,.47);border:1px solid #ded6c8;border-radius:9px;font-size:10px;line-height:1.45}

.chart-panel,.recent-panel{min-height:300px}
.period-select{display:flex;align-items:center;gap:5px;padding:7px 9px;color:var(--ink-soft);background:var(--surface);border:1px solid var(--line);border-radius:7px;font-size:10px}
.chart-wrap{display:flex;height:179px;gap:12px;padding:22px 24px 4px 20px}
.chart-y{display:flex;flex-direction:column;justify-content:space-between;padding-bottom:20px;color:var(--ink-faint);font-family:"DM Mono",monospace;font-size:8px}
.chart-bars{flex:1;display:flex;align-items:end;justify-content:space-around;gap:12px;padding:0 10px;border-bottom:1px solid var(--line);background:repeating-linear-gradient(to bottom,transparent 0,transparent 38px,rgba(222,219,209,.45) 39px)}
.chart-col{position:relative;width:28px;height:100%;display:flex;flex-direction:column;justify-content:end;align-items:center;gap:9px}
.chart-bar{width:100%;min-height:8px;background:linear-gradient(to top,#c9dcd4,#4c907e);border-radius:5px 5px 1px 1px;transition:height .45s var(--ease)}
.chart-col:hover .chart-bar{background:linear-gradient(to top,#dfc29e,var(--amber))}
.chart-col>span{color:var(--ink-faint);font-size:9px}
.bar-tooltip{position:absolute;bottom:calc(var(--bar-height,50%) + 25px);display:none;padding:3px 5px;color:#fff;background:var(--ink);border-radius:4px;font-size:8px;white-space:nowrap}
.chart-col:hover .bar-tooltip{display:block}
.chart-legend{display:flex;justify-content:space-between;gap:10px;padding:12px 21px 16px;color:var(--ink-soft);font-size:10px;flex-wrap:wrap}
.chart-legend span:first-child{display:inline-flex;align-items:center;gap:6px}
.legend-dot{width:7px;height:7px;display:inline-block;border-radius:50%}
.green-dot{background:var(--teal)}
.recent-list{padding:6px 21px 15px}
.transaction-row{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--line)}
.transaction-row:last-child{border-bottom:0}
.transaction-icon{width:31px;height:31px;display:grid;place-items:center;flex:0 0 auto;border-radius:9px}
.transaction-icon.credit{color:var(--teal);background:var(--teal-tint)}
.transaction-icon.debit,.transaction-icon.retrait{color:var(--amber-deep);background:var(--amber-tint)}
.transaction-copy{flex:1;min-width:0}
.transaction-copy strong,.transaction-copy small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.transaction-copy strong{font-size:11px}
.transaction-copy small{margin-top:3px;color:var(--ink-faint);font-size:9px}
.transaction-amount{font-family:Fraunces,serif;font-size:12px;white-space:nowrap}

.history-section{margin-top:14px}
.history-title{display:flex;align-items:end;justify-content:space-between;gap:15px;margin-bottom:14px;flex-wrap:wrap}
.history-title .eyebrow{margin-bottom:8px}
.history-title>span{color:var(--ink-soft);font-size:10px}
.history-table{overflow:hidden}
.table-head,.table-row{display:grid;grid-template-columns:100px minmax(180px,1.6fr) 1.2fr 1fr 1fr;align-items:center;gap:15px}
.table-head{padding:11px 20px;color:var(--ink-soft);background:var(--paper-deep);border-bottom:1px solid var(--line);font-size:9px;text-transform:uppercase;letter-spacing:.1em;font-weight:700}
.table-row{min-height:62px;padding:0 20px;border-bottom:1px solid var(--line);font-size:11px;transition:background .16s ease}
.table-row:last-child{border-bottom:0}
.table-row:hover{background:rgba(244,242,236,.7)}
.status-badge{display:inline-flex;padding:5px 7px;border-radius:5px;font-size:8px;letter-spacing:.07em}
.status-badge.credit{color:var(--teal);background:var(--teal-tint)}
.status-badge.debit{color:var(--amber-deep);background:var(--amber-tint)}
.status-badge.retrait{color:#76604a;background:#eee5d9}
.table-label strong,.table-label small{display:block}
.table-label strong{font-size:11px}
.table-label small,.table-date,.table-balance{color:var(--ink-soft);font-size:10px}
.table-label small{margin-top:3px;color:var(--ink-faint);font-family:"DM Mono",monospace;font-size:8px}
.table-row>strong{font-family:Fraunces,serif;font-size:12px}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:160px;gap:8px;color:var(--ink-faint)}
.empty-state p{margin:0;font-size:11px}
.text-button{display:inline-flex;align-items:center;gap:5px;padding:0;color:var(--amber-deep);background:transparent;border:0;font-size:10px;font-weight:700}
.page-footer{display:flex;justify-content:space-between;padding:25px 3px 0;color:var(--ink-faint);font-size:9px;flex-wrap:wrap;gap:6px}

.modal-overlay{position:fixed;inset:0;z-index:100;display:none;place-items:center;padding:20px;background:rgba(26,28,24,.55);backdrop-filter:blur(5px);animation:fade-in .18s ease both}
.modal-overlay.open{display:grid}
.modal-card{width:min(100%,430px);max-height:calc(100vh - 40px);overflow-y:auto;padding:24px;background:var(--surface);border:1px solid rgba(255,255,255,.38);border-radius:17px;box-shadow:0 22px 70px rgba(24,25,22,.25);animation:modal-in .25s var(--ease) both}
@keyframes fade-in{from{opacity:0}to{opacity:1}}
@keyframes modal-in{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-head{display:flex;align-items:start;justify-content:space-between;gap:14px;padding-bottom:18px;margin-bottom:21px;border-bottom:1px solid var(--line)}
.modal-head .icon-button{background:var(--paper);border-color:var(--line)}
.modal-head h2{margin-top:8px;font-size:25px}
.modal-form{display:flex;flex-direction:column;gap:17px}
.modal-form label{color:var(--ink-soft);font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700}
.modal-form input,.modal-form select{display:block;width:100%;height:42px;margin-top:7px;padding:0 12px;color:var(--ink);background:var(--paper);border:1px solid var(--line);border-radius:8px;outline:0;font-size:12px;text-transform:none;letter-spacing:0}
.modal-form input:focus,.modal-form select:focus{border-color:var(--amber-deep)}
.preset-row{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 1px}
.preset-row button{padding:6px 9px;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:20px;font-size:10px;font-weight:600}
.preset-row button:hover{color:var(--amber-deep);border-color:#c39c76;background:var(--amber-tint)}
.method-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px}
.method-grid input{position:absolute;opacity:0;width:0;height:0}
.method-opt{display:block;padding:10px 8px;text-align:center;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:8px;font-size:10px;font-weight:600;cursor:pointer}
.method-grid input:checked+.method-opt{color:var(--amber-deep);background:var(--amber-tint);border-color:#bd8c60}
.sim-note{display:flex;align-items:center;gap:8px;padding:10px 11px;color:var(--teal-deep);background:var(--teal-tint);border-radius:8px;font-size:10px;text-transform:none;letter-spacing:0}
.available-balance{display:flex;align-items:center;justify-content:space-between;padding:13px 14px;color:var(--ink-soft);background:var(--paper);border:1px solid var(--line);border-radius:9px;font-size:10px}
.available-balance strong{color:var(--amber-deep);font-family:Fraunces,serif;font-size:17px}
.helper-text{margin:-7px 0 0;color:var(--ink-faint);font-size:10px}
.color-picker{display:flex;gap:8px;margin-top:9px}
.color-picker input{position:absolute;opacity:0;width:0;height:0}
.color-swatch{display:block;width:38px;height:28px;border:2px solid transparent;border-radius:7px;transition:transform .16s ease,border-color .16s ease;cursor:pointer}
.color-picker input:checked+.color-swatch{border-color:var(--ink);transform:scale(1.08)}
.color-swatch.emerald{background:linear-gradient(145deg,#347c6c,#173a34)}
.color-swatch.silver{background:linear-gradient(145deg,#928d7e,#403e37)}
.color-swatch.platinum{background:linear-gradient(145deg,#4a4842,#171713)}
.color-swatch.amber{background:linear-gradient(145deg,#cf7d35,#6d3d17)}
.color-swatch.midnight{background:linear-gradient(145deg,#514c72,#1e1c32)}

.km-toast{position:fixed;z-index:200;top:92px;right:37px;display:flex;align-items:center;gap:10px;max-width:360px;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:var(--surface);box-shadow:0 22px 60px rgba(24,25,22,.2);color:var(--ink);font-size:11px;animation:fade-in .2s ease}
.km-toast-ok{border-color:rgba(43,109,96,.25)}
.km-toast-err{border-color:rgba(167,73,53,.25)}

@media(max-width:1100px){.page-content{padding-left:28px;padding-right:28px}.topbar{padding-left:28px;padding-right:28px}.dashboard-grid,.lower-grid{grid-template-columns:1fr}.quick-list{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}.quick-list button:nth-child(3),.quick-list button:nth-child(4){border-bottom:0}}
@media(max-width:760px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0);box-shadow:10px 0 40px rgba(26,28,24,.3)}
  .sidebar-backdrop.visible{display:block;position:fixed;inset:0;z-index:40;background:rgba(26,28,24,.42)}
  .main-area{margin-left:0}
  .topbar-left>.icon-button{display:grid}
  .topbar{height:64px;padding:0 17px}
  .topbar-actions{gap:5px}
  .top-search{display:none}
  .page-content{padding:33px 17px 25px}
  .hero-row{display:block;margin-bottom:29px}
  h1{font-size:46px}
  .hero-actions{margin-top:21px}
  .metrics-grid{grid-template-columns:1fr;gap:9px}
  .metric-card{min-height:118px}
  .dashboard-grid,.lower-grid{gap:14px;margin-bottom:14px}
  .quick-list{grid-template-columns:1fr}
  .quick-list button:nth-child(3){border-bottom:1px solid var(--line)}
  .quick-list button:nth-child(4){border-bottom:0}
  .history-title{align-items:start;flex-direction:column}
  .table-head{display:none}
  .table-row{display:flex;flex-wrap:wrap;gap:6px 12px;padding:15px 16px}
  .table-row>span:first-child{order:1}
  .table-row>span:nth-child(3){order:2;margin-left:auto}
  .table-row>span:nth-child(2){order:3;width:100%}
  .table-row>strong{order:4}
  .table-row>.table-balance{order:5;margin-left:auto}
  .page-footer{gap:10px;flex-direction:column}
}
@media(max-width:480px){
  .breadcrumb span,.breadcrumb svg{display:none}
  .topbar{gap:6px}
  .hero-actions{width:100%}
  .hero-actions .button{flex:1}
  .panel-header{padding:17px 16px}
  .card-actions{padding:0 16px}
  .cards-stage{height:225px;margin-top:19px}
  .wallet-card{height:190px;padding:17px 18px 15px}
  .card-number{font-size:12px}
  .card-bottom strong{font-size:8px}
  .stack-hint{padding-left:7px;padding-right:7px;font-size:9px}
  .chart-bars{gap:5px;padding:0 3px}
  .chart-col{width:25px}
  .chart-wrap{padding-left:15px;padding-right:16px}
  .chart-legend{padding-left:16px;padding-right:16px}
  .modal-card{padding:20px}
}
</style>
</head>
<body>

<div class="wallet-app">
  <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="document.getElementById('sidebar').classList.remove('open'); this.classList.remove('visible');"></div>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <a href="index.php" class="brand-logo">Koud<span>Main</span></a>
      <div class="brand-tag">Votre argent, simplifié</div>
    </div>
    <div class="sidebar-scroll">
      <div class="nav-group">
        <span class="nav-label">Workspace</span>
        <a href="<?= htmlspecialchars($dashboard) ?>" class="nav-item"><?= icon('home') ?><span>Vue d'ensemble</span></a>
        <a href="<?= htmlspecialchars($lien_catalogue) ?>" class="nav-item"><?= icon('search') ?><span>Catalogue</span></a>
        <a href="<?= htmlspecialchars($lien_commandes) ?>" class="nav-item"><?= icon('file-text') ?><span>Mes commandes</span></a>
      </div>
      <div class="nav-group">
        <span class="nav-label">Finance</span>
        <a href="wallet.php" class="nav-item active"><?= icon('wallet-cards') ?><span>Mon Wallet</span></a>
        <?php if ($lien_stats): ?>
        <a href="<?= htmlspecialchars($lien_stats) ?>" class="nav-item"><?= icon('bar-chart') ?><span>Statistiques</span></a>
        <?php else: ?>
        <button type="button" class="nav-item" onclick="showToast('info','Statistiques bientôt disponibles.')"><?= icon('bar-chart') ?><span>Statistiques</span></button>
        <?php endif; ?>
      </div>
      <div class="nav-group">
        <span class="nav-label">Compte</span>
        <a href="<?= htmlspecialchars($dashboard) ?>" class="nav-item"><?= icon('user-round') ?><span>Profil</span></a>
        <button type="button" class="nav-item" onclick="showToast('info','Préférences bientôt disponibles.')"><?= icon('settings') ?><span>Préférences</span></button>
      </div>
    </div>
    <div class="sidebar-bottom">
      <div class="wallet-card-mini">
        <div class="wallet-card-mini-label">Solde total</div>
        <div class="wallet-card-mini-amount"><?= number_format($soldeTotalAffiche, 0, ',', ' ') ?> <small>FCFA</small></div>
      </div>
      <p class="sidebar-note"><?= icon('sparkles', 13) ?> Vos données sont protégées par un chiffrement de bout en bout.</p>
      <a href="<?= htmlspecialchars($dashboard) ?>" class="profile-row">
        <div class="avatar"><?= htmlspecialchars($initiales2) ?></div>
        <div><strong><?= $prenom ?> <?= $nom ?></strong><span><?= htmlspecialchars($role) ?></span></div>
        <?= icon('more-horizontal', 16) ?>
      </a>
    </div>
  </aside>

  <main class="main-area">
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-button" aria-label="Ouvrir le menu" onclick="document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebar-backdrop').classList.add('visible');"><?= icon('menu', 21) ?></button>
        <div class="breadcrumb"><span>Workspace</span><?= icon('chevron-right', 14) ?><strong>Mon Wallet</strong></div>
      </div>
      <div class="topbar-actions">
        <div class="top-search"><?= icon('search', 16) ?><input type="text" id="topSearch" placeholder="Rechercher une transaction"></div>
        <button class="icon-button" aria-label="Notifications" onclick="showToast('info','Vous êtes à jour — aucune nouvelle notification.')"><?= icon('bell', 18) ?><i></i></button>
        <a href="<?= htmlspecialchars($dashboard) ?>" class="top-profile"><div class="avatar avatar-sm"><?= htmlspecialchars($initiales2) ?></div><?= icon('chevron-down', 14) ?></a>
      </div>
    </header>

    <div class="page-content">
      <?php if ($msg): ?><div class="km-toast km-toast-ok" id="km-toast-msg"><?= icon('check', 15) ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
      <?php if ($err): ?><div class="km-toast km-toast-err" id="km-toast-err"><?= icon('x', 15) ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

      <section class="hero-row">
        <div>
          <div class="eyebrow"><?= htmlspecialchars($eyebrowDate) ?></div>
          <h1>Votre argent,<br><em>en mouvement.</em></h1>
          <p class="hero-copy">Une vue calme et claire sur vos dépenses, vos cartes et vos projets.</p>
        </div>
        <div class="hero-actions">
          <button type="button" class="button button-light" onclick="openModal('modal-carte')"><?= icon('plus', 16) ?> Nouvelle carte</button>
          <button type="button" class="button button-dark" onclick="openModal('modal-recharge')"><?= icon('arrow-down-to-line', 16) ?> Recharger</button>
        </div>
      </section>

      <section class="metrics-grid">
        <div class="metric-card metric-main">
          <div class="metric-head"><span>Solde disponible</span><span class="metric-icon"><?= icon('wallet-cards', 15) ?></span></div>
          <strong id="m-solde">0 <small>FCFA</small></strong>
          <div class="metric-foot"><span id="m-solde-sub">Sur cette carte</span></div>
        </div>
        <div class="metric-card">
          <div class="metric-head"><span>Entrées cumulées</span><span class="metric-icon green"><?= icon('arrow-down-left', 15) ?></span></div>
          <strong class="green-text" id="m-credits">+ 0</strong>
          <div class="metric-foot"><span>Sur cette carte</span></div>
        </div>
        <div class="metric-card">
          <div class="metric-head"><span>Sorties cumulées</span><span class="metric-icon amber"><?= icon('arrow-up-right', 15) ?></span></div>
          <strong class="amber-text" id="m-debits">− 0</strong>
          <div class="metric-foot"><span>Sur cette carte</span></div>
        </div>
      </section>

      <div class="dashboard-grid">
        <section class="cards-panel panel">
          <div class="panel-header">
            <div><div class="eyebrow">Portefeuille digital</div><h2>Mes cartes</h2></div>
            <div class="card-nav">
              <button type="button" id="card-prev" aria-label="Carte précédente"><?= icon('chevron-left', 16) ?></button>
              <span id="card-counter">01 / 0<?= count($cartesPayload) ?></span>
              <button type="button" id="card-next" aria-label="Carte suivante"><?= icon('chevron-right', 16) ?></button>
            </div>
          </div>
          <div class="cards-stage" id="cards-stage" tabindex="0">
            <?php foreach ($cartesPayload as $idx => $c): ?>
            <div class="card-stack-item" data-index="<?= $idx ?>">
              <button type="button" class="wallet-card <?= htmlspecialchars($c['couleur']) ?>" data-index="<?= $idx ?>" data-cvv="<?= htmlspecialchars($c['cvv']) ?>" aria-label="Sélectionner <?= htmlspecialchars($c['libelle']) ?>">
                <div class="card-sheen"></div>
                <div class="card-topline">
                  <div><span class="card-name"><?= htmlspecialchars($c['libelle']) ?></span><span class="card-type"><?= htmlspecialchars(strtoupper($c['type'])) ?></span></div>
                  <span class="card-network"><?= strtoupper($c['type']) === 'VISA' ? 'VISA' : 'MC' ?></span>
                </div>
                <div class="chip"><span></span><span></span><span></span><span></span></div>
                <div class="card-number"><?= htmlspecialchars($c['numero']) ?></div>
                <div class="card-bottom">
                  <div><span class="card-caption">TITULAIRE</span><strong><?= htmlspecialchars(strtoupper($c['titulaire'])) ?></strong></div>
                  <div><span class="card-caption">EXPIRE</span><strong><?= htmlspecialchars($c['exp']) ?></strong></div>
                  <span class="card-koud">koud<span>main</span></span>
                </div>
              </button>
              <?php if ($c['gelee']): ?>
              <div class="frozen-overlay" data-frozen-overlay><?= icon('lock', 15) ?> Carte gelée</div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="card-actions">
            <button type="button" class="button freeze-button" id="btn-freeze"><?= icon('lock', 15) ?> <span id="btn-freeze-label">Geler la carte</span></button>
            <button type="button" class="button button-outline" id="btn-cvv"><?= icon('eye', 15) ?> <span id="btn-cvv-label">Voir le CVV</span></button>
          </div>
          <div class="cvv-reveal" id="cvv-reveal" style="display:none">CVV · <strong id="cvv-value"></strong><span>Visible pendant votre session</span></div>
          <p class="stack-hint"><?= icon('arrow-up', 13) ?> Glissez ou utilisez les flèches pour changer de carte <?= icon('arrow-down', 13) ?></p>
        </section>

        <section class="quick-panel panel">
          <div class="panel-header"><div><div class="eyebrow">À portée de main</div><h2>Actions rapides</h2></div><span class="amber-icon"><?= icon('zap', 19) ?></span></div>
          <div class="quick-list">
            <button type="button" onclick="openModal('modal-recharge')"><span class="quick-icon green-bg"><?= icon('arrow-down-to-line', 17) ?></span><span><strong>Recharger le wallet</strong><small>Ajouter des fonds</small></span><?= icon('chevron-right', 16) ?></button>
            <?php if (estPrestataire()): ?>
            <button type="button" onclick="openModal('modal-retrait')"><span class="quick-icon amber-bg"><?= icon('arrow-up-right', 17) ?></span><span><strong>Retirer des fonds</strong><small>Vers Mobile Money ou RIB</small></span><?= icon('chevron-right', 16) ?></button>
            <?php endif; ?>
            <button type="button" onclick="openModal('modal-carte')"><span class="quick-icon dark-bg"><?= icon('credit-card', 17) ?></span><span><strong>Nouvelle carte virtuelle</strong><small>Créer en quelques secondes</small></span><?= icon('chevron-right', 16) ?></button>
            <button type="button" id="quick-freeze"><span class="quick-icon sand-bg"><?= icon('lock', 17) ?></span><span><strong id="quick-freeze-label">Geler cette carte</strong><small id="quick-freeze-sub">Bloquer les paiements</small></span><?= icon('chevron-right', 16) ?></button>
          </div>
          <div class="quick-note"><?= icon('circle-help', 16) ?><span>Vos données sont protégées par un chiffrement de bout en bout.</span></div>
        </section>
      </div>

      <div class="lower-grid">
        <section class="chart-panel panel">
          <div class="panel-header"><div><div class="eyebrow">Tendance</div><h2>Entrées sur 6 mois</h2></div><span class="period-select">6 mois <?= icon('chevron-down', 14) ?></span></div>
          <div class="chart-wrap">
            <div class="chart-y"><span>100k</span><span>75k</span><span>50k</span><span>25k</span><span>0</span></div>
            <div class="chart-bars" id="chart-bars"></div>
          </div>
          <div class="chart-legend"><span><i class="legend-dot green-dot"></i> Crédits entrants</span><span id="chart-legend-total">+ 0 FCFA au total</span></div>
        </section>
        <section class="recent-panel panel">
          <div class="panel-header"><div><div class="eyebrow">Activité</div><h2>Récentes opérations</h2></div><button type="button" class="text-button" onclick="document.getElementById('history').scrollIntoView({behavior:'smooth'})">Voir tout <?= icon('arrow-right', 14) ?></button></div>
          <div class="recent-list" id="recent-list"></div>
        </section>
      </div>

      <section class="history-section" id="history">
        <div class="history-title">
          <div><div class="eyebrow">Journal financier</div><h2>Historique</h2></div>
          <span id="history-count">0 opération</span>
        </div>
        <div class="history-table panel">
          <div class="table-head"><span>Type</span><span>Libellé</span><span>Date</span><span>Montant</span><span>Solde après</span></div>
          <div id="history-rows"></div>
        </div>
      </section>

      <footer class="page-footer"><span>© <?= date('Y') ?> KoudMain Finance</span><span>Wallet connecté à votre compte <?= htmlspecialchars($role) ?></span></footer>
    </div>
  </main>
</div>

<!-- Modal Recharger -->
<div class="modal-overlay" id="modal-recharge">
  <div class="modal-card">
    <div class="modal-head">
      <div><div class="eyebrow">Ajouter des fonds</div><h2>Recharger mon wallet</h2></div>
      <button type="button" class="icon-button" onclick="closeModal('modal-recharge')" aria-label="Fermer"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" action="wallet.php" class="modal-form">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="recharger">
      <input type="hidden" name="id_carte" class="js-id-carte" value="">
      <label>Montant (FCFA)
        <div class="preset-row">
          <?php foreach ([2000, 5000, 10000, 25000, 50000] as $p): ?>
            <button type="button" onclick="document.getElementById('montant-recharge').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></button>
          <?php endforeach; ?>
        </div>
        <input type="number" name="montant" id="montant-recharge" min="500" max="1000000" step="100" placeholder="Ex : 10 000" required>
      </label>
      <label>Mode de paiement
        <div class="method-grid">
          <?php $methodes = ['Orange Money', 'MTN MoMo', 'Wave', 'Carte bancaire']; foreach ($methodes as $i => $meth): ?>
          <span><input type="radio" name="methode" id="meth-<?= $i ?>" value="<?= htmlspecialchars($meth) ?>" <?= $i === 0 ? 'checked' : '' ?>><label class="method-opt" for="meth-<?= $i ?>"><?= htmlspecialchars($meth) ?></label></span>
          <?php endforeach; ?>
        </div>
      </label>
      <div class="sim-note"><?= icon('sparkles', 15) ?> Mode simulation — aucun paiement réel n'est effectué.</div>
      <button class="button button-dark full" type="submit">Confirmer la recharge <?= icon('arrow-right', 15) ?></button>
    </form>
  </div>
</div>

<!-- Modal Retirer -->
<?php if (estPrestataire()): ?>
<div class="modal-overlay" id="modal-retrait">
  <div class="modal-card">
    <div class="modal-head">
      <div><div class="eyebrow">Vers votre compte</div><h2>Retirer des fonds</h2></div>
      <button type="button" class="icon-button" onclick="closeModal('modal-retrait')" aria-label="Fermer"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" action="wallet.php" class="modal-form">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="retrait">
      <input type="hidden" name="id_carte" class="js-id-carte" value="">
      <div class="available-balance"><span>Solde disponible</span><strong id="retrait-solde-dispo"><?= number_format($solde, 0, ',', ' ') ?> FCFA</strong></div>
      <label>Montant à retirer (FCFA)
        <div class="preset-row">
          <?php foreach ([5000, 10000, 25000, 50000] as $p): ?>
            <button type="button" onclick="document.getElementById('montant-retrait').value=<?= $p ?>"><?= number_format($p, 0, ',', ' ') ?></button>
          <?php endforeach; ?>
          <button type="button" id="btn-retrait-tout">Tout</button>
        </div>
        <input type="number" name="montant" id="montant-retrait" min="1000" step="100" placeholder="Ex : 10 000" required>
      </label>
      <label>Mobile Money / RIB<input type="text" name="rib" placeholder="Ex : 07 00 00 00 00" required></label>
      <p class="helper-text">Traitement sous 24–48 h ouvrées.</p>
      <button class="button button-dark full" type="submit">Demander le retrait <?= icon('arrow-right', 15) ?></button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal Nouvelle carte -->
<div class="modal-overlay" id="modal-carte">
  <div class="modal-card">
    <div class="modal-head">
      <div><div class="eyebrow">Étendre votre portefeuille</div><h2>Nouvelle carte virtuelle</h2></div>
      <button type="button" class="icon-button" onclick="closeModal('modal-carte')" aria-label="Fermer"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" action="wallet.php" class="modal-form">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="ajouter_carte">
      <label>Nom de la carte<input type="text" name="libelle" maxlength="32" placeholder="Ex : Saphir" value="Saphir" required></label>
      <label>Couleur
        <div class="color-picker">
          <?php foreach (['emerald','silver','platinum','amber','midnight'] as $i => $col): ?>
          <span><input type="radio" name="couleur" id="col-<?= $col ?>" value="<?= $col ?>" <?= $i === 1 ? 'checked' : '' ?>><label class="color-swatch <?= $col ?>" for="col-<?= $col ?>" aria-label="<?= $col ?>"></label></span>
          <?php endforeach; ?>
        </div>
      </label>
      <label>Type
        <select name="type_carte">
          <option value="visa">VISA</option>
          <option value="mastercard">MASTERCARD</option>
        </select>
      </label>
      <label>Nom du titulaire<input type="text" name="nom_titulaire" maxlength="120" value="<?= htmlspecialchars(trim($prenom . ' ' . $nom)) ?>" required></label>
      <button class="button button-dark full" type="submit">Créer la carte <?= icon('plus', 16) ?></button>
    </form>
  </div>
</div>

<script type="application/json" id="km-cards-data"><?= json_encode($cartesPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
<script>
const KM_CSRF = <?= json_encode($csrf) ?>;
const KM_CARDS = JSON.parse(document.getElementById('km-cards-data').textContent);
const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(n || 0));

let active = 0;
let showCvv = false;
let liveLock = false;

const stage = document.getElementById('cards-stage');
const stackItems = [...stage.querySelectorAll('.card-stack-item')];

function currentCard() { return KM_CARDS[active] || KM_CARDS[0]; }

function layoutStack() {
  const n = stackItems.length;
  stackItems.forEach((item, i) => {
    const behind = (active - i + n) % n;
    const btn = item.querySelector('.wallet-card');
    item.style.zIndex = String(n - behind);
    item.style.transform = `translateY(${-behind * 24}px) scale(${Math.max(0.82, 1 - behind * 0.045)})`;
    item.style.opacity = behind > 3 ? '0' : '1';
    item.style.pointerEvents = behind > 3 ? 'none' : 'auto';
    btn.classList.toggle('card-active', behind === 0);
  });
}

function renderChart(card) {
  const max = Math.max(1, ...card.mois.map(m => m.credits));
  document.getElementById('chart-bars').innerHTML = card.mois.map(m => {
    const h = Math.max(6, Math.round((m.credits / max) * 100));
    return `<div class="chart-col"><div class="bar-tooltip">${fmt(m.credits)} FCFA</div><div class="chart-bar" style="height:${h}%"></div><span>${m.label}</span></div>`;
  }).join('');
}

function txIcon(type) {
  if (type === 'credit') return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7 7 17"/><path d="M17 17H7V7"/></svg>';
  return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>';
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function renderRecent(card) {
  const list = document.getElementById('recent-list');
  const txs = card.txs || [];
  if (!txs.length) {
    list.innerHTML = '<div class="empty-state" style="min-height:100px"><p>Aucune transaction sur cette carte.</p></div>';
    return;
  }
  list.innerHTML = txs.slice(0, 4).map(tx => `
    <div class="transaction-row">
      <span class="transaction-icon ${tx.type}">${txIcon(tx.type)}</span>
      <span class="transaction-copy"><strong>${escapeHtml(tx.libelle)}</strong><small>${tx.date}</small></span>
      <strong class="transaction-amount ${tx.credit ? 'green-text' : ''}">${tx.credit ? '+' : '\u2212'} ${fmt(tx.montant)}</strong>
    </div>`).join('');
}

function renderHistory(card) {
  const search = (document.getElementById('topSearch').value || '').toLowerCase();
  const txs = (card.txs || []).filter(tx => tx.libelle.toLowerCase().includes(search));
  document.getElementById('history-count').textContent = txs.length + ' opération' + (txs.length > 1 ? 's' : '') + ' · ' + card.libelle;
  const rows = document.getElementById('history-rows');
  if (!txs.length) {
    rows.innerHTML = '<div class="empty-state"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><p>Aucune transaction ne correspond à votre recherche.</p></div>';
    return;
  }
  rows.innerHTML = txs.map(tx => `
    <div class="table-row">
      <span><b class="status-badge ${tx.type}">${tx.type === 'credit' ? 'CRÉDIT' : tx.type === 'retrait' ? 'RETRAIT' : 'DÉBIT'}</b></span>
      <span class="table-label"><strong>${escapeHtml(tx.libelle)}</strong><small>Opération #${String(tx.id).padStart(4, '0')}</small></span>
      <span class="table-date">${tx.date_full}</span>
      <strong class="${tx.credit ? 'green-text' : ''}">${tx.credit ? '+' : '\u2212'} ${fmt(tx.montant)}</strong>
      <span class="table-balance">${fmt(tx.solde_apres)}</span>
    </div>`).join('');
}

function syncFreezeUi() {
  const c = currentCard();
  document.getElementById('btn-freeze').classList.toggle('frozen-button', !!c.gelee);
  document.getElementById('btn-freeze-label').textContent = c.gelee ? 'Dégeler la carte' : 'Geler la carte';
  document.getElementById('quick-freeze-label').textContent = c.gelee ? 'Dégeler cette carte' : 'Geler cette carte';
  document.getElementById('quick-freeze-sub').textContent = c.gelee ? 'Réactiver les paiements' : 'Bloquer les paiements';

  stackItems.forEach((item, i) => {
    const existing = item.querySelector('[data-frozen-overlay]');
    if (KM_CARDS[i] && KM_CARDS[i].gelee) {
      if (!existing) {
        item.insertAdjacentHTML('beforeend', '<div class="frozen-overlay" data-frozen-overlay><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="16" r="1"/><rect x="3" y="10" width="18" height="12" rx="2"/><path d="M7 10V7a5 5 0 0 1 10 0v3"/></svg> Carte gelée</div>');
      }
    } else if (existing) {
      existing.remove();
    }
  });
}

function renderDashboard() {
  const c = currentCard();
  document.getElementById('m-solde').innerHTML = fmt(c.solde) + ' <small>FCFA</small>';
  document.getElementById('m-solde-sub').textContent = c.gelee ? 'Carte gelée — paiements bloqués' : 'Sur cette carte';
  document.getElementById('m-credits').textContent = '+ ' + fmt(c.credits);
  document.getElementById('m-debits').textContent = '\u2212 ' + fmt(c.debits);
  document.getElementById('card-counter').textContent = String(active + 1).padStart(2, '0') + ' / ' + String(KM_CARDS.length).padStart(2, '0');
  renderChart(c);
  document.getElementById('chart-legend-total').textContent = '+ ' + fmt(c.credits) + ' FCFA au total';
  renderRecent(c);
  renderHistory(c);
  syncFreezeUi();
  const el = document.getElementById('cvv-reveal');
  if (showCvv) {
    document.getElementById('cvv-value').textContent = c.cvv;
    el.style.display = 'flex';
    document.getElementById('btn-cvv-label').textContent = 'CVV · ' + c.cvv;
  } else {
    el.style.display = 'none';
    document.getElementById('btn-cvv-label').textContent = 'Voir le CVV';
  }
  document.querySelectorAll('.js-id-carte').forEach(el2 => { el2.value = c.id > 0 ? c.id : ''; });
  const retraitSolde = document.getElementById('retrait-solde-dispo');
  if (retraitSolde) retraitSolde.textContent = fmt(c.solde) + ' FCFA';
  const montantRetrait = document.getElementById('montant-retrait');
  if (montantRetrait) montantRetrait.max = String(Math.max(1000, Math.round(c.solde)));
}

function selectCard(index) {
  const n = KM_CARDS.length;
  active = ((index % n) + n) % n;
  showCvv = false;
  layoutStack();
  renderDashboard();
}

document.getElementById('card-prev').addEventListener('click', () => selectCard(active - 1));
document.getElementById('card-next').addEventListener('click', () => selectCard(active + 1));
stackItems.forEach((item, i) => {
  item.querySelector('.wallet-card').addEventListener('click', () => selectCard(i));
});

stage.addEventListener('wheel', e => {
  e.preventDefault();
  if (Math.abs(e.deltaY) < 4) return;
  selectCard(active + (e.deltaY > 0 ? 1 : -1));
}, { passive: false });

let touchStartY = null;
stage.addEventListener('touchstart', e => { touchStartY = e.touches[0].clientY; }, { passive: true });
stage.addEventListener('touchend', e => {
  if (touchStartY === null) return;
  const delta = e.changedTouches[0].clientY - touchStartY;
  if (Math.abs(delta) > 30) selectCard(active + (delta < 0 ? 1 : -1));
  touchStartY = null;
});
stage.addEventListener('keydown', e => {
  if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); selectCard(active + 1); }
  if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); selectCard(active - 1); }
});

document.getElementById('btn-cvv').addEventListener('click', () => { showCvv = !showCvv; renderDashboard(); });

async function toggleFreeze() {
  const c = currentCard();
  const next = !c.gelee;
  c.gelee = next;
  syncFreezeUi();
  document.getElementById('m-solde-sub').textContent = next ? 'Carte gelée — paiements bloqués' : 'Sur cette carte';
  if (!(c.id > 0)) { showToast('ok', next ? 'Carte gelée.' : 'Carte dégelée.'); return; }
  const fd = new FormData();
  fd.append('action', 'geler_carte');
  fd.append('id_carte', String(c.id));
  fd.append('geler', next ? '1' : '0');
  fd.append('csrf_token', KM_CSRF);
  try {
    await fetch('wallet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    showToast('ok', next ? 'Carte gelée. Les paiements sont bloqués.' : 'Carte dégelée.');
  } catch (e) { /* état local conservé */ }
}
document.getElementById('btn-freeze').addEventListener('click', toggleFreeze);
document.getElementById('quick-freeze').addEventListener('click', toggleFreeze);

document.getElementById('topSearch').addEventListener('input', () => renderHistory(currentCard()));

const btnRetraitTout = document.getElementById('btn-retrait-tout');
if (btnRetraitTout) {
  btnRetraitTout.addEventListener('click', () => {
    document.getElementById('montant-retrait').value = String(Math.round(currentCard().solde));
  });
}

function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('mousedown', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-backdrop').classList.remove('visible');
  }
});

function showToast(kind, message) {
  const el = document.createElement('div');
  el.className = 'km-toast ' + (kind === 'err' ? 'km-toast-err' : 'km-toast-ok');
  el.innerHTML = '<span>' + escapeHtml(message) + '</span>';
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3600);
}

['km-toast-msg', 'km-toast-err'].forEach(id => {
  const el = document.getElementById(id);
  if (el) setTimeout(() => el.remove(), 4200);
});

layoutStack();
renderDashboard();
</script>

</body>
</html>
