# KoudMain (Laravel)

Plateforme qui met en relation des clients et des prestataires de services (coiffure, plomberie, laverie, garde d'enfants...) en Côte d'Ivoire.
Migration de l'ancienne application PHP vers Laravel 12 + PostgreSQL, réalisée par lots.

## État d'avancement

| Lot | Contenu | État |
|-----|---------|------|
| 1 | Schéma complet, authentification, rôles, anti-force-brute, sessions, en-têtes de sécurité, design | fait |
| 2 | Catalogue, prestations, photos (Supabase Storage), recherche sans accents, page publique | fait |
| 2 bis | Trois espaces distincts (client, prestataire, administrateur) avec menu latéral, comme l'ancienne application | fait |
| 3 | Commandes, séquestre (escrow), wallet, retraits, disponibilités, paiement Mobile Money (CinetPay) | fait (cartes virtuelles : à décider) |
| 4 | Temps réel (SSE), messagerie, notifications (cloche, toasts, e-mails), avis, favoris | fait |
| 5 | Métriques et santé (admin), tâches planifiées, Sentry et journaux structurés, Docker, déploiement Render | fait |
| 6 | Retouches locales : écran de chargement, accueil épuré, mode de paiement des commandes, cartes saisies (numéro, expiration, CVV, titulaire, adresse), gel animé, chiffres animés, wallet en cartes | fait |
| 10 | Sécurité : les 20 règles (coffre de secrets, RLS, limitation de débit, e-mail confirmé, webhooks signés, sauvegardes chiffrées, audit des dépendances) : voir [`SECURITE.md`](SECURITE.md) | fait |

## Démarrage en local

Prérequis : PHP 8.2+ avec l'extension **gd** (traitement des photos ; sous XAMPP : décommenter `extension=gd` dans `php.ini`, puis vérifier avec `php -m`), Composer, Node 20+, Docker (pour PostgreSQL).
Sans `gd`, les 25 tests d'images sont sautés (et non en échec) et `DemoSeeder` crée les prestations sans photos.

```bash
composer install
cp .env.example .env          # puis : php artisan key:generate
docker compose up -d db       # PostgreSQL 16 sur le port 5433 (crée aussi la base de test)

# Données géographiques : à générer UNE fois depuis l'ancien script SQL
php tools/extraire_geographie.php chemin/vers/ancien_script.sql

php artisan migrate:fresh --seed
php artisan koudmain:creer-admin votre@email.ci   # le mot de passe est demandé, jamais affiché

npm install
npm run dev                   # ou : npm run build
php artisan serve
```

Il n'y a **aucun compte administrateur par défaut** : on le crée avec la commande ci-dessus.

Pour voir le catalogue rempli en local (jamais en production : la commande refuse) :

```bash
php artisan db:seed --class=DemoSeeder   # 5 prestataires fictifs, des prestations, des photos générées, des avis
# comptes de démonstration : demo.mariam@koudmain.test, demo.client@koudmain.test (mot de passe « password »)
```

## Catalogue, recherche et photos (lot 2)

- **Recherche** : PostgreSQL, sans accents ni majuscules (`réparation` = `reparation`), début de mot accepté (`plomb` trouve « plomberie »).
  Elle repose sur l'extension `unaccent` (créée par la migration `..._lot2_recherche_sans_accents_duree_avatar`) et un index GIN.
- **Photos** : `MEDIA_DRIVER=local` en développement (dossier `public/uploads`, ignoré par Git) ; `MEDIA_DRIVER=supabase` en production.
  Chaque image est contrôlée, redessinée (les métadonnées GPS disparaissent), réduite à 1600 px et compressée en WebP.
- **Supabase Storage** (production) : Storage > New bucket > nom `medias`, cocher **Public bucket**. Puis, dans les Environment Variables
  de Render : `MEDIA_DRIVER=supabase`, `SUPABASE_URL` (Project URL), `SUPABASE_SERVICE_KEY` (clé `service_role`, **secrète**), `SUPABASE_BUCKET=medias`.
- Une prestation déjà commandée ne peut pas être supprimée (l'historique en dépend) : le prestataire la **masque**.

## Les trois espaces (client, prestataire, administrateur)

Chaque rôle arrive dans son propre espace, avec le même menu latéral que l'ancienne application. Le menu est défini à **un seul endroit** :
`app/Support/Espace/Menu.php`.

| Espace | Adresse | Onglets |
|--------|---------|---------|
| Client | `/client` | Vue d'ensemble, Catalogue, Mes commandes, Mes favoris, Mon wallet, Mon profil, Mot de passe |
| Prestataire | `/prestataire` | Vue d'ensemble, Mes prestations, Commandes, Mon wallet, Mon profil, Mot de passe |
| Administrateur | `/admin` | Tableau de bord, Prestataires (valider / suspendre), Utilisateurs, Catalogue (catégories et services), Commandes, Retraits, Métriques et santé |

Tous les onglets sont désormais réels (« Métriques et santé » est livrée au lot 5). Le mécanisme « Bientôt » (`app/Support/Espace/Bientot.php`, vide aujourd'hui) reste disponible :
un onglet ajouté avant sa fonctionnalité y déclare sa page, qui dit honnêtement ce qui est prévu ; on retire l'entrée quand la vraie route arrive.

## Commandes, séquestre, wallet (lot 3)

**Parcours d'une commande** : le client choisit un jour et une heure libres, l'argent quitte son wallet et est **mis en séquestre** ;
le prestataire accepte, démarre, termine ; le client confirme la réception et le prestataire est payé. Sans réponse du client,
le prestataire est payé automatiquement après 3 jours (`koudmain:liberer-escrows`, planifiée toutes les heures).

Les statuts ont la même couleur partout (liste, détail, tableaux de bord, chronologie), en thème clair comme sombre :
**En attente** orange · **Acceptée** bleu · **En cours** violet · **Terminée** vert · **Annulée** rouge · **Litige** gris.

| Statut | Qui agit | Effet sur l'argent |
|--------|----------|--------------------|
| En attente | le client a commandé | montant bloqué en séquestre |
| Acceptée | le prestataire (le créneau est alors réservé, sans chevauchement) | — |
| En cours / Terminée | le prestataire | — |
| Terminée + réception confirmée | le client (ou automatique à J+3) | séquestre versé au prestataire |
| Annulée | client ou prestataire, avant le début | client remboursé aussitôt |
| Litige | le client signale un problème (motif obligatoire) | séquestre gelé ; un administrateur tranche : prestataire payé ou client remboursé |

- **Disponibilités** : chaque prestataire règle sa semaine type (`/prestataire/disponibilites`, 2 plages par jour). Sans horaires, 07 h – 21 h tous les jours.
  Les créneaux se proposent toutes les 30 minutes, jamais dans les 2 prochaines heures ni au-delà de 14 jours ; une commande *en attente* ne réserve pas de créneau, une commande *acceptée* oui.
- **Argent** : tout est calculé en centimes entiers ; chaque mouvement verrouille la ligne du wallet, laisse une ligne immuable dans l'historique et ne peut jamais rendre le solde négatif (la base le refuse aussi).
- **Retraits** : le montant quitte le solde du prestataire tout de suite ; l'administrateur fait le virement puis clique « Virement effectué » (`/admin/retraits`), ou refuse avec un motif (l'argent est rendu).
- Le téléphone de l'autre partie n'apparaît qu'une fois la commande acceptée.

### Cartes bancaires (lot 6)

Un **nouvel utilisateur n'a aucune carte**. Depuis son wallet (client comme prestataire), il ajoute jusqu'à **5 cartes** en saisissant : numéro
(16 chiffres, 15 pour American Express), date d'expiration `MM/AA`, code de sécurité (3 chiffres, 4 pour Amex), prénom et nom du titulaire, adresse de
facturation (adresse, ville, pays). Réseaux acceptés : Visa, Mastercard, American Express (le réseau se lit sur les premiers chiffres, la clé de Luhn est contrôlée).
La première carte ajoutée devient la carte *par défaut* ; si on la supprime, la plus ancienne des restantes la remplace.

**Sécurité** : le numéro complet et le code de sécurité servent uniquement à être *contrôlés* ; ils ne sont **jamais enregistrés** (ni en base, ni en session, ni dans les
journaux, ni rejoués dans le formulaire après une erreur). On garde le réseau, les 4 derniers chiffres, l'expiration, le titulaire, l'adresse de facturation et une
empreinte à sens unique (HMAC-SHA256 avec `APP_KEY`) qui sert seulement à refuser deux fois la même carte.

**Ce que la carte fait, et ne fait pas** : c'est un moyen de paiement *interne* à KoudMain (« payer avec », « recharger avec »). Tant qu'un agrégateur de paiement
(CinetPay…) n'est pas relié avec sa jetonisation, **aucun prélèvement réel n'est envoyé à la banque** ; le solde reste unique, celui du wallet.
Une carte **gelée** (animation de givre) refuse de payer une commande et de recharger ; on la dégèle d'un clic. Supprimer une carte garde l'historique.

Après mise à jour : `php artisan migrate` (ajoute l'adresse de facturation, l'empreinte, American Express, le mode de paiement des commandes ; supprime les anciennes
cartes « de démonstration » jamais utilisées).

### Mode de paiement d'une commande (lot 6)

À la commande, le client choisit :

- **Paiement physique** : en main propre à la fin de la prestation. Rien n'est prélevé sur le wallet et **aucun séquestre** n'est créé (donc ni blocage, ni remboursement) ;
- **Mobile Money** : le montant est prélevé sur le wallet (alimenté par Mobile Money) et bloqué en séquestre ;
- **Carte bancaire** : idem, via une carte enregistrée et non gelée (la carte est notée sur la ligne d'historique).

Le mode est affiché sur la commande (détail, liste). L'historique du wallet s'exporte en CSV (bouton « Exporter »).

### Écran d'ouverture (lot 6)

Un écran de chargement plein écran (compteur, mots qui défilent, rideau) s'affiche **une fois par session de navigation** (cookie `km_intro`, posé par le JavaScript). Il n'apparaît
pas sans JavaScript ni si le visiteur demande moins d'animations ; le bouton « Passer » le ferme. L'accueil n'a plus de recherche ni de lien vers le catalogue
(la page `/prestations` existe toujours).

### Paiement Mobile Money (recharge du wallet)

Le fournisseur est choisi par `PAIEMENT_DRIVER` :

| Valeur | Effet |
|--------|-------|
| `simulation` (défaut hors production) | recharge fictive, bandeau « Mode simulation » visible ; **aucun argent réel** |
| `cinetpay` | vraie page de paiement CinetPay (Orange Money, MTN MoMo, Wave, carte) |
| `aucun` (défaut en production) | recharge désactivée : on n'affiche jamais un faux paiement en production |

Variables pour CinetPay (Render > Environment) : `PAIEMENT_DRIVER=cinetpay`, `CINETPAY_API_KEY`, `CINETPAY_SITE_ID` (et `CINETPAY_URL` si CinetPay change d'adresse).
Le wallet n'est **jamais** crédité sur la foi du navigateur ni du webhook : `PaiementService::confirmer()` interroge CinetPay, vérifie le montant, puis crédite une seule fois.
L'adresse de notification à déclarer chez CinetPay est `https://VOTRE-SITE/paiements/notification`.
Le pilote CinetPay est écrit d'après la documentation officielle mais **n'a pas encore été essayé avec de vraies clés** : faites une recharge de 500 FCFA pour valider avant d'ouvrir au public.

### Tâches planifiées

Les tâches périodiques sont déclarées dans `routes/console.php` (détail et horaires : voir « Lot 5 » plus bas). En local : `php artisan schedule:work`.
En production (Docker), l'entrypoint le lance à côté d'Apache : rien à configurer.

### Données de démonstration

`php artisan db:seed --class=DemoSeeder` crée aussi 6 commandes dans tous les états, un wallet rechargé, des horaires, un retrait en attente et des cartes bancaires (numéros d'essai publics, jamais stockés : 3 pour le client démo).

## Temps réel, messagerie, notifications, avis, favoris (lot 4)

**Le principe** : rien à actualiser. Un message, une notification, un changement d'état de commande, un retrait apparaissent tout seuls chez
les personnes concernées.

- **Comment ça marche** : chaque page connectée ouvre un flux **Server-Sent Events** (`GET /temps-reel`, `app/Http/Controllers/TempsReelController.php`),
  intégré à Laravel : aucun service à installer, aucun serveur WebSocket. Le métier « dépose » un événement dans la boîte de la personne
  (`App\Services\TempsReel\Diffuseur::vers`, table `evenements_temps_reel`, purgée toutes les 15 minutes) et le flux le lui remet en moins d'une seconde.
  Côté navigateur, une seule connexion par onglet (`resources/js/lib/tempsReel.js`) alimente la cloche, les pastilles, les messages et les zones
  rafraîchies. Le flux dure ~25 s puis le navigateur le rouvre avec le numéro du dernier événement reçu : **rien ne se perd**, même en cas de coupure.
- **Repli automatique** : si un réseau met les flux en tampon (certains proxys d'entreprise), le navigateur le détecte et interroge le serveur toutes les 3 s
  (`/temps-reel/sonder`). C'est moins instantané, mais ça marche partout.
- **Zones rafraîchies en direct** : une zone de page se déclare `data-region="nom" data-region-evenements="commande retrait"` ; à l'événement,
  la page est rechargée en arrière-plan et seule cette zone est remplacée (le rendu vient du serveur : aucune règle métier dupliquée en JavaScript). Une zone n'est pas
  touchée pendant que vous écrivez dedans ou qu'une boîte de dialogue est ouverte.
- **Messagerie** (`/messages`) : une discussion par commande, entre son client et son prestataire. Messages instantanés, « X est en train d'écrire… », accusés
  de lecture (✓ envoyé, ✓✓ lu), non-lus par discussion, chargement par tranches. Toutes les règles sont dans `App\Services\MessageService` (une commande qui n'est pas la vôtre
  répond « introuvable »). L'administrateur lit l'échange d'une commande en lecture seule sur la page de la commande (pour trancher un litige).
- **Notifications** (`/notifications`, cloche de l'en-tête, petites alertes en bas de l'écran) : `App\Services\Notifications\Ecouteur` est le seul endroit qui décide
  *qui est prévenu de quoi* (commande passée, acceptée, démarrée, terminée, annulée, litige, arbitrage, paiement reçu, retrait, inscription, validation du prestataire, avis).
  Elles utilisent la table native `notifications` de Laravel. Une panne d'envoi ne fait **jamais** échouer l'action de l'utilisateur.
- **E-mails** : `NotificationMail`, envoyés *après* la réponse. Un e-mail « nouveau message » ne part que pour le premier message d'une série et pas à quelqu'un qui est connecté.
  Chacun peut couper les e-mails dans **Mon profil**. En local, `MAIL_MAILER=log` écrit les e-mails dans `storage/logs`.
- **Avis** : le client note (1 à 5) une prestation de SA commande **terminée** ; l'avis reste modifiable (chaque version est archivée) et le prestataire est prévenu.
- **Favoris** : le cœur des cartes du catalogue (espace client), la page « Mes favoris » et un bouton sur la page d'une prestation (sans JavaScript aussi).

### Mise en service

```bash
php artisan migrate       # table evenements_temps_reel, préférence e-mail, index des messages non lus
npm run build
```

Variables d'environnement (toutes facultatives) :

| Variable | Rôle | Défaut |
|----------|------|--------|
| `TEMPS_REEL_ACTIF` | `false` coupe le temps réel (les pages restent utilisables, sans mise à jour automatique) | `true` |
| `TEMPS_REEL_DUREE` | durée d'un flux, en secondes | `25` |
| `TEMPS_REEL_PAUSE_MS` | pause entre deux lectures de la boîte d'événements | `800` |
| `NOTIFICATIONS_EMAIL` | `false` coupe tous les e-mails de notification | `true` |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | en production : le SMTP de Brevo ou de Resend | `log` |

**Attention à l'hébergement** : un flux occupe un processus PHP pendant sa durée. Il faut donc un serveur avec **plusieurs processus** (Apache ou PHP-FPM ; c'est le cas du
Dockerfile du lot 5, où l'on préfère toutefois `TEMPS_REEL_MODE=sondage` : voir DEPLOIEMENT.md), jamais `php artisan serve` sans `--no-reload` ni un serveur à un seul processus (les pages se mettraient en file d'attente derrière le flux).
Comptez un processus par personne connectée en même temps ; au-delà de quelques centaines de personnes, on passera à Reverb/Pusher (le reste du code ne change pas : seul `Diffuseur` est à brancher).
Avec Supabase, utilisez l'adresse du **session pooler** (port 5432 du pooler), pas celle du transaction pooler (port 6543) : Laravel prépare ses requêtes, ce que le mode « transaction » ne tolère pas toujours.

Le mode du temps réel se règle avec `TEMPS_REEL_MODE` : `auto` (défaut) choisit tout seul, `flux` impose le flux SSE, `sondage` impose l'interrogation régulière (JSON, toutes les quelques secondes).
En `auto`, un `php artisan serve` sans `PHP_CLI_SERVER_WORKERS` (c'est toujours le cas sous Windows) passe en **sondage** : un serveur à un seul processus se figerait sinon derrière le premier flux ouvert.
Pour tester le vrai flux en local : Apache (XAMPP) ou, sous Linux/macOS, `PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload`.

## Métriques, santé, journaux, Docker (lot 5)

**Page admin « Métriques et santé »** (`/admin/metriques`) : commandes, argent versé, inscriptions et taux de commandes menées à terme sur 7, 30 ou 90 jours (avec la comparaison à la période
précédente), graphiques jour par jour (dessinés côté serveur, avec leur tableau de données), répartition par statut, catégories et prestations les plus commandées, connexions réussies/refusées/bloquées,
puis la **santé du système** vérifiée en direct (base, cache, planificateur, e-mails, paiement, stockage des photos, Sentry, débogage, disque) et le carnet des tâches automatiques.
Les chiffres viennent des vraies tables (`App\Services\Metriques\TableauMetriques`, six requêtes, gardées `METRIQUES_CACHE` secondes) ; seuls les événements de connexion et les instantanés
nocturnes (`etat.*`) sont écrits dans la table `metriques`. La page ne montre jamais un secret.

| Tâche (`routes/console.php`) | Quand | Rôle |
|------------------------------|-------|------|
| `battement` | chaque minute | preuve que le planificateur tourne (lue par la page de santé) |
| `koudmain:liberer-escrows` | chaque heure | libère les paiements des commandes terminées et jamais confirmées |
| `temps-reel-purge` | toutes les 15 min | vide les événements temps réel périmés |
| `koudmain:instantane-metriques` | 2 h 30 | photo quotidienne des indicateurs (courbes) |
| `koudmain:nettoyer` | 3 h | purge notifications lues (30 j), anciennes notifications (180 j), métriques, journaux (30 j) ; durées dans `config/koudmain.php` (`taches`) |

Chaque tâche note son passage dans `taches_planifiees` (`App\Services\Taches\Suivi`) ; une tâche qui plante est journalisée, envoyée à Sentry, et n'arrête ni le planificateur ni les autres.

**Journaux structurés** : `App\Support\Journal::info('paiement.libere', [...])`. `LOG_CHANNEL=json` (production) écrit une ligne JSON par événement sur la sortie d'erreur ; chaque ligne porte `requete`
(le même numéro que l'en-tête `X-Request-Id`) et `utilisateur`. Mots de passe, jetons, clés et cartes sont masqués, les e-mails réduits à `m***@domaine`, les numéros de téléphone/compte à leurs 4 derniers chiffres.
Les commandes, litiges, paiements libérés et retraits sont journalisés par `App\Services\Surveillance\Audit` (à l'écoute des événements).

**Sentry** : `SENTRY_DSN` ; sans lui, rien n'est envoyé. Petit client sans SDK (`App\Services\Surveillance\Sentry`, API « envelope ») : jamais bloquant (2 s max), 20 envois maximum par requête,
messages assainis (e-mails, chiffres), aucun cookie ni contenu de formulaire.

**Docker** : `Dockerfile` (Node → Composer → Apache + PHP 8.4, GD JPEG/WebP, PostgreSQL, opcache), `docker/entrypoint.sh` (vérifie la configuration, `optimize`, `migrate --force`, `koudmain:initialiser`,
planificateur, Apache), `render.yaml` (Blueprint Render). `docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build` essaie l'image chez vous.
`php artisan koudmain:initialiser` charge régions/quartiers et catégories **seulement si leurs tables sont vides**.
Tout le déroulé, les variables et la checklist de mise en production : **[DEPLOIEMENT.md](DEPLOIEMENT.md)**.

## Rapidité

Quand la base est distante (Supabase), **chaque requête SQL coûte un aller-retour réseau** (30 à 80 ms) : c'est le nombre de requêtes par page qui fait la lenteur, pas le PHP. Leviers en place :

| Levier | Effet | Réglage |
|--------|-------|---------|
| Sessions et cache sur **fichiers** | 3 à 8 requêtes SQL de moins par page | `SESSION_DRIVER=file`, `CACHE_STORE=file` |
| Compteurs mémorisés par requête (`App\Support\Espace\Compteurs`) | le solde, les messages non lus... ne sont demandés qu'une fois par page | automatique |
| Listes de référence en cache (`App\Support\Referentiel`) : catégories, services, villes, quartiers | 4 à 6 requêtes de moins ; vidées dès que l'administrateur modifie une de ces tables | automatique |
| Page d'accueil : dernières prestations, quartiers, prestataires gardés 60 s | l'accueil ne pose plus aucune requête à chaud | automatique |
| Connexion à la base **persistante** | plus de nouvelle poignée de main TLS à chaque page | `DB_PERSISTENT=true` (en production, avec le session pooler) |
| Compression et cache navigateur (`public/.htaccess`) | fichiers `build/` et `uploads/` gardés un an, texte compressé | Apache |
| Caches de Laravel | routes, configuration et vues précompilées | `php artisan optimize` à chaque déploiement |

Avec `DB_PERSISTENT=true` sur Apache, chaque processus garde sa connexion : limitez `MaxRequestWorkers` (ou `pm.max_children` sous PHP-FPM) pour rester sous le nombre de connexions du pooler.

Mesurer : `php tools/profil.php 3` affiche, pour chaque page et chaque rôle, le temps, le nombre de requêtes SQL et le temps passé en SQL.
`SQL=/client php tools/profil.php 1` liste les requêtes d'une page avec le fichier qui les a posées.

## Charte visuelle

Papier chaud, encre, une terracotta pour l'accent, de la sauge, de l'or et de la prune en touches douces. Trois familles, servies par le site (aucun service externe) :
**Fraunces** (titres ; le second temps d'un titre est en italique terracotta), **DM Sans** (texte), **DM Mono** (petites capitales espacées pour les intitulés).
Tout est défini par des jetons dans `resources/css/app.css` : jamais de couleur en dur, sinon le thème sombre casse. Le logo est le composant `<x-logo />` (Blade) ou `Logo.jsx` (React).

## Tests

Les tests tournent sur PostgreSQL (les migrations utilisent des fonctions propres à PostgreSQL), dans la base `koudmain_test`,
que `phpunit.xml` impose et qu'un garde-fou vérifie avant toute exécution.

```bash
# Si votre volume Docker existait déjà avant ce lot, créez la base de test à la main :
docker compose exec db psql -U koudmain -d koudmain_laravel -c "CREATE DATABASE koudmain_test"

php artisan test
```

## Sécurité (plan, phase 0 et lot 10)

Les 20 règles de sécurité sont détaillées, avec leur preuve et les actions manuelles, dans **[`SECURITE.md`](SECURITE.md)**. En bref :

- Mots de passe hachés (bcrypt, coût 12, re-haché automatiquement), identifiant de session régénéré à la connexion, inactivité limitée à 120 minutes, autres appareils déconnectés au changement de mot de passe.
- Connexion : 5 échecs par e-mail et 20 par adresse IP sur 15 minutes, puis blocage ; message identique que le compte existe ou non. Inscription, renvoi de confirmation, paiement, administration : chacun a sa limite.
- Compte actif seulement après confirmation de l'e-mail ; l'inscription ne révèle jamais si une adresse existe déjà.
- Les rôles ne sont jamais modifiables depuis un formulaire ; droits vérifiés côté serveur sur chaque route.
- HTTPS imposé (redirection + HSTS), CORS fermé par défaut, en-têtes de sécurité, aucune erreur détaillée ni `console.*` en production.
- Envois d'images : taille limitée, type vérifié (MIME **et** octets magiques), image ré-encodée (métadonnées supprimées).
- Notifications de paiement signées (refusées si la clé secrète manque).
- Row Level Security activée sur toutes les tables, à chaque déploiement.
- Aucun secret dans Git : en production, tout passe par le coffre de Render ; le conteneur **refuse de démarrer** avec une configuration dangereuse (`koudmain:controle-production`). gitleaks, `composer audit`, `npm audit` et Dependabot tournent en CI.
- Sauvegarde chiffrée de la base chaque nuit vers Cloudflare R2, avec preuve de restauration (`scripts/restaurer.sh`).
