<?php
require_once "config.php";
requireConnexion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('messages.php');
}

verifierTokenCSRF();

$pdo        = getConnexion();
$idUser     = $_SESSION['id_utilisateur'];
$idCommande = (int)($_POST['id_commande'] ?? 0);
$contenu    = (string)($_POST['contenu'] ?? '');

if ($idCommande <= 0) {
    rediriger('messages.php');
}

$res = envoyerMessage($pdo, $idCommande, $idUser, $contenu);

$suffixe = $res['ok'] ? '' : ('&err=' . urlencode($res['message']));
rediriger('conversation.php?id_commande=' . $idCommande . $suffixe);
