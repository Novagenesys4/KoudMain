# Sécurité de KoudMain : les 20 règles

Ce document dit, règle par règle, **ce qui est fait dans le code**, **où le vérifier** (fichier et test automatique) et **ce qui reste à faire à la main**
(ce que le code ne peut pas faire à votre place : saisir un secret, créer un compte chez un fournisseur...).

Tous les tests cités se lancent avec `php artisan test` (dossier `tests/Feature/Securite/`).

## Vue d'ensemble

| # | Règle | État | Où / preuve | À faire à la main |
|---|-------|------|-------------|-------------------|
| 1 | Secrets dans un coffre, pas de `.env` en production | Fait | `render.yaml` (`sync: false`), `ControleProduction` au démarrage, `DepotEtDeploiementTest` | Saisir les secrets dans Render ; créer l'Environment Group (voir plus bas) |
| 2 | Aucun secret dans le code ni dans Git | Fait | `.gitignore`, `.dockerignore`, `.gitleaks.toml`, `.github/workflows/securite.yml` | Changer tout secret déjà publié (mot de passe Supabase...) |
| 3 | Limitation de débit (connexion, endpoints sensibles) | Fait | `AppServiceProvider` (limiteurs), `routes/web.php`, `LimitesDeRequetesTest` | Rien |
| 4 | RLS activé sur la base | Fait | `SecuriteBase`, commande `koudmain:securiser-base`, migration `..._000002_lot10_row_level_security`, `RowLevelSecurityTest` | Rien (automatique à chaque déploiement) |
| 5 | Hachage moderne des mots de passe | Fait | bcrypt coût 12 + re-hachage automatique à la connexion (`ConnexionRequest`), `SessionsEtMotsDePasseTest` | Rien |
| 6 | Droits vérifiés côté serveur | Fait | middlewares de rôle, policies, `AccesTest` (10 scénarios) | Rien |
| 7 | Seules des clés publiques côté navigateur | Fait | `ControleProduction` (refuse `VITE_*` secret), `ConsoleEtSecretsNavigateurTest` | Ne jamais préfixer un secret par `VITE_` |
| 8 | HTTPS partout (redirection + HSTS) | Fait | `ForceHttps`, `SecurityHeaders`, `TransportEtErreursTest` | `TRUST_PROXY=true` sur Render (déjà dans `render.yaml`) |
| 9 | Sessions qui expirent | Fait | `SESSION_LIFETIME=120`, `AuthenticateSession`, déconnexion des autres appareils au changement de mot de passe | Rien |
| 10 | Validation stricte côté serveur | Fait | FormRequests + `Saisie` (rejette les tableaux là où on attend du texte), `EntreesHostilesTest` | Rien |
| 11 | Taille maximale des envois | Fait | `docker/php/koudmain.ini`, `docker/apache/koudmain.conf`, `image_service`, `UploadsTest` | Rien |
| 12 | Type de fichier vérifié (MIME + octets magiques) | Fait | service d'images, `UploadsTest` (9 scénarios : faux `.jpg`, SVG, PHP déguisé...) | Rien |
| 13 | CORS en liste blanche, jamais `*` | Fait | `config/cors.php`, `TransportEtErreursTest` | Ajouter un site partenaire : `CORS_ALLOWED_ORIGINS` |
| 14 | Erreurs détaillées coupées en production | Fait | `APP_DEBUG=false` imposé au démarrage, pages d'erreur neutres, `TransportEtErreursTest` | Rien |
| 15 | Pas de `console.log` ni de journal sensible | Fait | `vite.config.js` (`esbuild.drop`), CI, `Journal` (masque e-mails, téléphones, jetons), `ConsoleEtSecretsNavigateurTest` | Rien |
| 16 | Un seul message d'erreur (pas d'énumération d'e-mails) | Fait | connexion, inscription et renvoi de confirmation répondent identiquement, `InscriptionTest` | Rien |
| 17 | Webhooks signés | Fait | `PaiementCinetPay::jetonValide` (HMAC, **refuse si la clé manque**), `CinetPayTest` | Saisir `CINETPAY_SECRET_KEY` le jour où CinetPay est activé |
| 18 | Dépendances à jour et auditées | Fait | `composer audit` + `npm audit` en CI, `.github/dependabot.yml` | Fusionner les demandes Dependabot chaque semaine |
| 19 | E-mail confirmé avant activation du compte | Fait | `ConfirmationEmailService`, middleware `email.confirme`, `ConfirmationEmailTest` | Configurer un vrai SMTP (Brevo, Resend...) |
| 20 | Sauvegarde automatique de la base (et des secrets) | Fait (à configurer) | `.github/workflows/sauvegarde.yml`, `scripts/restaurer.sh`, `DepotEtDeploiementTest` | Créer le bucket R2, la clé age et les secrets GitHub (voir plus bas) |

## Le contrôle au démarrage

Avant de lancer Apache, `docker/entrypoint.sh` exécute `php artisan koudmain:controle-production`. Il **refuse de démarrer** le site si :

- un fichier `.env` se trouve dans le conteneur, `APP_KEY` est vide, ou `DB_PASSWORD` est vide ou est un mot de passe de développement connu ;
- `APP_DEBUG=true` ;
- `APP_URL` n'est pas une adresse publique en `https://` ;
- `TRUST_PROXY` n'est pas à `true` (derrière Render, le site ne saurait pas qu'il est en HTTPS) ;
- `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` explicitement à `false`, ou `BCRYPT_ROUNDS` inférieur à 12 ;
- `PAIEMENT_DRIVER=simulation`, ou `cinetpay` sans ses clés (dont `CINETPAY_SECRET_KEY`, règle 17) ;
- `MAIL_MAILER=log` ou `array` (les e-mails de confirmation ne partiraient pas : personne ne pourrait activer son compte) ;
- une variable `VITE_*` qui ressemble à un secret (elle serait publiée dans le JavaScript du navigateur), ou un `*` dans `CORS_ALLOWED_ORIGINS`.

Sont seulement signalés (« attention », sans bloquer) : `DB_SSLMODE` qui n'impose pas TLS, `LOG_LEVEL=debug`, `FORCE_HTTPS=false`, `SESSION_LIFETIME` au-delà de 120 minutes.

Le message indique le **nom** de la variable en cause, jamais sa valeur. Render garde alors l'ancienne version en ligne : rien n'est coupé.

**Premier déploiement** : si vous n'avez pas encore de SMTP, mettez temporairement `CONTROLE_PRODUCTION=avertir` (les problèmes s'affichent sans bloquer), puis repassez à `strict` dès que tout est réglé.

## Règle 1 : le coffre de secrets (Render)

- Dans `render.yaml`, **aucun secret n'a de valeur** : ils sont marqués `sync: false` et Render vous les demande à la création du service. Ils sont stockés chiffrés chez Render et n'entrent jamais dans Git ni dans l'image Docker.
- Pour **partager** des secrets entre production et préproduction : tableau de bord Render > *Environment Groups* > *New*, puis dans chaque service > *Environment* > *Linked Environment Groups*. (Un Blueprint ne sait pas créer un groupe dont les valeurs sont secrètes : cette étape est manuelle.)
- `php artisan koudmain:inventaire-secrets` liste les **noms** des secrets attendus et si chacun est renseigné (jamais leur valeur) : c'est votre liste de contrôle pour la rotation.

### Rotation (à faire au moins une fois par an, et tout de suite si un secret a fuité)

1. Mot de passe de la base : Supabase > Settings > Database > *Reset database password*, puis mettez-le à jour dans Render (`DB_PASSWORD`) et dans GitHub (`BACKUP_DATABASE_URL`).
2. `SUPABASE_SERVICE_KEY`, `MAIL_PASSWORD`, `CINETPAY_*`, `SENTRY_DSN` : régénérez chez le fournisseur, mettez à jour dans Render, redéployez.
3. `APP_KEY` : **ne changez pas** sans raison. Le changer déconnecte tout le monde et rend illisibles les sessions chiffrées et les cartes enregistrées. En cas de fuite avérée : générez-en une nouvelle (`php artisan key:generate --show`) et acceptez la déconnexion générale.
4. Vérifiez : `koudmain:inventaire-secrets` puis la page admin « Métriques et santé » (carte Sécurité).

## Règle 4 : RLS (Row Level Security)

Supabase expose par défaut une API REST devant la base : sans RLS, quiconque possède la clé publique `anon` pourrait lire les tables. KoudMain n'utilise **pas** cette API pour ses données (tout passe par Laravel, avec le compte propriétaire de la base). La commande `koudmain:securiser-base` :

- active la RLS sur **toutes** les tables appartenant à l'application (y compris celles ajoutées plus tard : elle est relancée à chaque déploiement) ;
- retire les droits des rôles `anon` et `authenticated` ;
- ne fait **jamais** `FORCE ROW LEVEL SECURITY` (le propriétaire, donc Laravel, continue de fonctionner) ;
- se rejoue sans danger, et n'empêche jamais le site de démarrer si elle échoue (l'erreur est affichée dans les journaux).

Aucune politique n'est créée : « RLS activée sans politique » = **aucun accès** pour l'API publique, ce qui est voulu.

## Règles 9 et 19 : comptes

- Inactivité maximale de 2 heures (`SESSION_LIFETIME=120`), identifiant de session régénéré à la connexion, cookie `Secure`, `HttpOnly`, `SameSite=Lax`, contenu chiffré.
- Changer son mot de passe déconnecte **tous les autres appareils**.
- À l'inscription, un lien de confirmation valable 48 h est envoyé. **Sans confirmation, pas de connexion, pas de bienvenue, pas d'alerte aux administrateurs** (une adresse fictive ne remplit pas la file de validation).
- La réponse à l'inscription est identique que l'adresse existe déjà ou non ; le propriétaire de l'adresse reçoit alors un e-mail « Vous avez déjà un compte ».
- **Les comptes existants** sont considérés comme confirmés par la migration (sinon plus personne ne pourrait se connecter).
- L'administrateur créé par `koudmain:creer-admin` est confirmé d'office.
- **« Mot de passe oublié »** (`MotDePasseOublieService`, `MotDePasseOublieController`) réutilise le même mécanisme de lien signé que la confirmation
  d'adresse, plutôt que la table `password_reset_tokens` de Laravel : le lien est valable 60 minutes (`koudmain.securite.reinitialisation_minutes`),
  et porte l'empreinte du mot de passe haché ACTUEL, donc devient inutilisable dès qu'il a servi une fois (pas de table de jetons à gérer). La demande
  répond toujours pareil, que l'adresse soit inscrite ou non (règle 16), et est bornée par adresse et par IP (`mot-de-passe-oublie`,
  `lien-mot-de-passe-oublie`). Le jeton « rester connecté » est renouvelé à la réinitialisation ; les sessions déjà ouvertes ailleurs ne sont, elles,
  coupées que par un changement de mot de passe depuis un compte connecté (`Auth::logoutOtherDevices`, hors de portée d'un visiteur non connecté).

## Règle 20 : sauvegardes automatiques (Cloudflare R2, chiffrées)

Le workflow `.github/workflows/sauvegarde.yml` tourne **chaque nuit à 02 h 30 (UTC)** et à la demande (GitHub > Actions > *Sauvegarde* > *Run workflow*) :

1. `pg_dump` de la base (format personnalisé, sans propriétaire ni droits) ;
2. **preuve** : restauration dans une base PostgreSQL jetable, puis comptage des tables ; une sauvegarde qui ne se restaure pas fait échouer le workflow (vous recevez l'e-mail d'échec de GitHub) ;
3. chiffrement avec **age** (clé publique) : seul le détenteur de la clé privée peut relire le fichier ;
4. envoi vers Cloudflare R2, puis vérification que l'objet existe ;
5. (facultatif) sauvegarde chiffrée des variables d'environnement de Render.

### Mise en place (une seule fois, ~15 minutes)

1. **Cloudflare** > R2 > *Create bucket* (par exemple `koudmain-sauvegardes`), **privé**. Notez l'*Account ID*.
2. R2 > *Manage R2 API Tokens* > *Create API token* > droits **Object Read & Write** limités à ce bucket. Notez `Access Key ID` et `Secret Access Key`.
3. Bucket > *Settings* > *Object lifecycle rules* : supprimer les objets après **30 jours** (ajustez selon votre besoin).
4. Sur votre ordinateur : `age-keygen -o cle-sauvegarde-age.txt`. Le fichier contient la clé **privée** ; la ligne `# public key: age1...` est la clé **publique**.
   - Rangez `cle-sauvegarde-age.txt` **hors ligne** (gestionnaire de mots de passe + clé USB). **Sans elle, les sauvegardes sont illisibles. Ne la mettez jamais dans GitHub, Render ni Git.**
5. GitHub > dépôt > Settings > *Secrets and variables* > *Actions* :

   | Type | Nom | Valeur |
   |------|-----|--------|
   | Secret | `BACKUP_DATABASE_URL` | URL PostgreSQL de Supabase (session pooler, port 5432) : `postgresql://utilisateur:motdepasse@hôte:5432/postgres` |
   | Secret | `R2_ACCESS_KEY_ID` | clé d'accès R2 |
   | Secret | `R2_SECRET_ACCESS_KEY` | clé secrète R2 |
   | Variable | `BACKUP_AGE_RECIPIENT` | la clé **publique** `age1...` |
   | Variable | `R2_ACCOUNT_ID` | identifiant du compte Cloudflare |
   | Variable | `R2_BUCKET` | nom du bucket |
   | Variable (facultatif) | `BACKUP_PG_VERSION` | version majeure de PostgreSQL de Supabase (17 par défaut) |
   | Secret (facultatif) | `RENDER_API_KEY` | clé API Render, pour sauvegarder aussi les variables d'environnement |
   | Variable (facultatif) | `RENDER_SERVICE_ID` | identifiant `srv-...` du service |

6. Lancez le workflow une première fois à la main et vérifiez qu'il est vert et qu'un fichier `koudmain-....dump.age` apparaît dans R2.

### Restaurer

Sur **votre ordinateur** (là où se trouve la clé privée) :

```bash
export R2_ACCOUNT_ID=... R2_BUCKET=... AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
scripts/restaurer.sh --cle ~/cle-sauvegarde-age.txt \
  --vers "postgresql://postgres.xxx:MOTDEPASSE@aws-0-eu.pooler.supabase.com:5432/postgres" --dernier
```

Sans `--ecraser`, la base cible doit être **vide** (un projet Supabase neuf) : rien n'est supprimé. **Essayez une restauration de test dès la première semaine** : une sauvegarde jamais restaurée n'est qu'une hypothèse.

### Ce que la sauvegarde ne couvre pas

- **Les photos** (Supabase Storage) ne sont pas dans `pg_dump`. Activez les sauvegardes du plan Supabase, ou copiez le bucket `medias` périodiquement.
- **Les secrets** : les sauvegarder dans un fichier en clair serait pire que de les perdre. Deux voies : (1) le gestionnaire de mots de passe, avec `koudmain:inventaire-secrets` comme liste de contrôle ; (2) l'étape facultative du workflow, qui exporte les variables de Render **chiffrées avec la même clé age**.

## Dépendances (règle 18)

- La CI (`securite.yml`) lance `composer audit` (PHP, y compris les outils de test) et `npm audit` (JavaScript livré aux visiteurs : bloquant ; outils de construction : information) à chaque envoi de code **et chaque semaine**.
- `.github/dependabot.yml` propose chaque semaine les mises à jour (Composer, npm, GitHub Actions, Docker), regroupées pour ne pas noyer la boîte de réception.
- Vérifié à la livraison : `npm audit` = 0 faille ; `composer.lock` comparé à la base FriendsOfPHP/security-advisories = 0 faille.

## Limites connues (à connaître, pas à cacher)

- Le pilote CinetPay n'a jamais été essayé avec de vraies clés : faites l'essai décrit dans `DEPLOIEMENT.md` (étape 10) avant d'ouvrir les paiements au public.
- Le fichier de configuration Apache (`docker/apache/koudmain.conf`) est vérifié par des tests de contenu, pas exécuté : le premier déploiement est son vrai test (regardez les journaux de démarrage).
- Un compte non confirmé ne révèle son existence qu'**après** saisie du bon mot de passe (message « confirmez votre e-mail ») : c'est le compromis usuel pour ne pas bloquer un vrai utilisateur.
- `post_max_size` (12 Mo) est inférieur à 6 photos × 5 Mo : au-delà de 12 Mo en un seul envoi, la requête est refusée (erreur 413). C'est une limite antérieure à ce lot, volontairement prudente ; augmentez-la dans `docker/php/koudmain.ini` si vos prestataires envoient souvent de grosses photos.
