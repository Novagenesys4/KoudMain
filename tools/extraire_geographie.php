<?php

declare(strict_types=1);

/**
 * Extrait la géographie de la Côte d'Ivoire de l'ancien script SQL PostgreSQL
 * (régions > départements > villes > quartiers) et l'écrit dans un fichier JSON
 * que GeographieSeeder saura relire.
 *
 * Usage  : php tools/extraire_geographie.php chemin/vers/KoudMain_PostgresSQL_complet_CI.sql
 * Sortie : database/data/geographie_ci.json
 *
 * À lancer une seule fois. Le JSON est ensuite versionné avec le projet.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}

/** Un littéral SQL entre apostrophes ; une apostrophe interne est doublée ('') : 'M''Badon'. */
const LITTERAL = "'(?:[^']|'')*'";

function decoder(string $litteral): string
{
    return str_replace("''", "'", substr($litteral, 1, -1));
}

/** @return list<string> tous les littéraux d'un morceau de SQL, décodés */
function litteraux(string $texte): array
{
    preg_match_all('/' . LITTERAL . '/u', $texte, $m);

    return array_map('decoder', $m[0]);
}

function echec(string $message): never
{
    fwrite(STDERR, "ERREUR : $message\n");
    exit(1);
}

$source = $argv[1] ?? null;
if ($source === null || !is_readable($source)) {
    fwrite(STDERR, "Usage : php tools/extraire_geographie.php <fichier.sql>\n");
    exit(1);
}

$sql = (string) file_get_contents($source);
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql; // BOM éventuel
if (!mb_check_encoding($sql, 'UTF-8')) {
    $sql = mb_convert_encoding($sql, 'UTF-8', 'Windows-1252');
}

// 1. La liste des régions (INSERT INTO Region ...) donne l'ordre et les noms officiels.
if (!preg_match('/INSERT INTO Region \(nom_region\) VALUES(.*?)ON CONFLICT/is', $sql, $m)) {
    echec("l'INSERT INTO Region est introuvable dans le fichier.");
}
$arbre = [];
foreach (litteraux($m[1]) as $nomRegion) {
    $arbre[$nomRegion] = [];
}

// 2. Le bloc DO $$ ... $$ répète toujours le même schéma d'instructions, dans l'ordre :
//    région -> département -> ville -> quartiers. On les relit dans l'ordre d'apparition.
$motif = '/'
    . 'SELECT id_region INTO r_id FROM Region WHERE nom_region = (?<reg>' . LITTERAL . ')'
    . '|SELECT id_departement INTO d_id FROM Departement WHERE nom_departement = (?<dep>' . LITTERAL . ')'
    . '|SELECT id_ville INTO v_id FROM Ville WHERE nom_ville = (?<vil>' . LITTERAL . ')'
    . '|INSERT INTO Quartier \(nom_quartier, id_ville\) VALUES(?<qua>.*?)ON CONFLICT'
    . '/isu';

preg_match_all($motif, $sql, $jetons, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
if ($jetons === []) {
    echec('aucun bloc géographique reconnu (le format du fichier a-t-il changé ?).');
}

$region = $departement = $ville = null;

foreach ($jetons as $jeton) {
    if ($jeton['reg'] !== null) {
        $region = decoder($jeton['reg']);
        $departement = $ville = null;
        if (!array_key_exists($region, $arbre)) {
            echec("région inconnue dans le bloc DO : « $region » (absente de la liste des régions).");
        }
    } elseif ($jeton['dep'] !== null) {
        if ($region === null) {
            echec('département rencontré avant toute région.');
        }
        $departement = decoder($jeton['dep']);
        $ville = null;
        $arbre[$region][$departement] ??= [];
    } elseif ($jeton['vil'] !== null) {
        if ($region === null || $departement === null) {
            echec('ville rencontrée avant son département.');
        }
        $ville = decoder($jeton['vil']);
        $arbre[$region][$departement][$ville] ??= [];
    } elseif ($jeton['qua'] !== null) {
        if ($region === null || $departement === null || $ville === null) {
            echec('quartiers rencontrés avant leur ville.');
        }
        foreach (litteraux($jeton['qua']) as $quartier) {
            if (!in_array($quartier, $arbre[$region][$departement][$ville], true)) {
                $arbre[$region][$departement][$ville][] = $quartier;
            }
        }
    }
}

// 3. Mise en forme du JSON.
$sortie = [];
$nbDepartements = $nbVilles = $nbQuartiers = 0;

foreach ($arbre as $nomRegion => $departements) {
    $r = ['nom' => (string) $nomRegion, 'departements' => []];
    foreach ($departements as $nomDepartement => $villes) {
        $d = ['nom' => (string) $nomDepartement, 'villes' => []];
        foreach ($villes as $nomVille => $quartiers) {
            $d['villes'][] = ['nom' => (string) $nomVille, 'quartiers' => $quartiers];
            $nbVilles++;
            $nbQuartiers += count($quartiers);
        }
        $r['departements'][] = $d;
        $nbDepartements++;
    }
    $sortie[] = $r;
}

$dossier = dirname(__DIR__) . '/database/data';
if (!is_dir($dossier) && !mkdir($dossier, 0755, true) && !is_dir($dossier)) {
    echec("impossible de créer $dossier");
}

$chemin = $dossier . '/geographie_ci.json';
file_put_contents(
    $chemin,
    json_encode($sortie, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
);

printf(
    "OK : %d régions, %d départements, %d villes, %d quartiers -> %s\n",
    count($sortie),
    $nbDepartements,
    $nbVilles,
    $nbQuartiers,
    $chemin
);

$vides = array_keys(array_filter($arbre, fn (array $deps) => $deps === []));
if ($vides !== []) {
    fwrite(STDERR, 'ATTENTION, régions sans aucun département : ' . implode(', ', $vides) . "\n");
}
