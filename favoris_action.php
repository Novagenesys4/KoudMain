<?php
require_once "config.php";
requireConnexion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('client_dashboard.php?tab=catalogue');
}

verifierTokenCSRF();

$pdo         = getConnexion();
$idUser      = $_SESSION['id_utilisateur'];
$idPrestation = (int)($_POST['id_prestation'] ?? 0);
$action      = ($_POST['action'] ?? '') === 'retirer' ? 'retirer' : 'ajouter';

$res = basculerFavori($pdo, $idUser, $idPrestation, $action);

$estAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($estAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$retour = $_POST['retour'] ?? 'client_dashboard.php?tab=catalogue';
rediriger($retour);
