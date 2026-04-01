<?php require_once "config.php"; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KoudMain | Accueil</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <!-- ── Navigation ── -->
  <nav>
    <a class="nav-brand" href="index.php">Koud<span>Main</span></a>
    <div class="nav-links">
      <?php if (estConnecte()): ?>
        <?php if (estAdmin()): ?>
          <a href="admin_dashboard.php">Admin</a>
        <?php elseif (estPrestataire()): ?>
          <a href="prestataire_dashboard.php">Mon espace</a>
        <?php else: ?>
          <a href="client_dashboard.php">Mon espace</a>
        <?php endif; ?>
        <a href="connexion.php?action=logout" class="btn-nav">Déconnexion</a>
      <?php else: ?>
        <a href="connexion.php">Connexion</a>
        <a href="inscription.php" class="btn-nav">S'inscrire</a>
      <?php endif; ?>
    </div>
  </nav>

  <!-- ── Hero ── -->
  <section class="hero">
    <h1>Trouvez le <em>service</em><br>qu'il vous faut</h1>
    <p>Connectez-vous avec des prestataires qualifiés dans votre quartier pour tous vos besoins du quotidien.</p>
    <div class="hero-actions">
      <?php if (!estConnecte()): ?>
        <a href="inscription.php" class="btn btn-primary">Commencer maintenant</a>
        <a href="connexion.php" class="btn btn-secondary">Se connecter</a>
      <?php else: ?>
        <a href="client_dashboard.php" class="btn btn-primary">Parcourir les services</a>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Catégories ── -->
  <div class="container">
    <?php
    $pdo = getConnexion();
    $stmt = $pdo->query("SELECT * FROM Categorie ORDER BY nom_categorie");
    $categories = $stmt->fetchAll();

    $icons = ['🧖','🔧','👕','👶','🍽️','⚡','🌿','📦','💄','🏠'];
    $i = 0;
    ?>
  </div>

  <footer style="text-align:center;padding:1rem 1rem;margin-top:4rem;border-top:1px solid var(--border);color:var(--text-muted);font-size:0.85rem;">
    © <?= date('Y') ?> KoudMain — Plateforme de mise en relation de services
  </footer>

  </body>
</html>
