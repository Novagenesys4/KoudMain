# Mettre KoudMain en production

Ce guide fait passer l'application de votre ordinateur à un site en ligne : **Render** héberge le site (image Docker Apache + PHP),
**Supabase** garde la base PostgreSQL et les photos. Comptez une heure la première fois, en suivant les étapes dans l'ordre.

```
Navigateur ──HTTPS──▶ Render (conteneur Docker)  ──▶ Supabase : base PostgreSQL (session pooler, port 5432)
                       ├─ Apache + PHP 8.4         └▶ Supabase Storage : photos (bucket « medias »)
                       ├─ planificateur (schedule:work) : paiements libérés, nettoyage, instantanés
                       └─ journal JSON ──▶ onglet « Logs » de Render      ──▶ (facultatif) Sentry pour les erreurs
```

Ce que fait le conteneur tout seul à chaque démarrage : vérifie la configuration, construit les caches, **applique les migrations**, charge
les régions/quartiers et les catégories **si la base en est dépourvue**, lance le planificateur, puis Apache. Vous n'avez rien à lancer à la main
sauf la création de l'administrateur (étape 6).

---

## 0. Avant de commencer

| Il vous faut | Pourquoi | Coût |
|--------------|----------|------|
| Un compte **GitHub** avec le dépôt du projet | Render déploie ce qui est sur GitHub | gratuit |
| Un compte **Render** | héberge le site | à partir d'environ 7 $/mois (plan `0.5c-512mb`, ex-« Starter ») : le plan gratuit met le site en veille et arrête le planificateur |
| Un compte **Supabase** | base de données + photos | gratuit pour démarrer |
| Un compte **Brevo** ou **Resend** | e-mails (inscription, commandes...) | gratuit au début |
| (facultatif) un compte **Sentry** | être prévenu d'une erreur | gratuit au début |
| (plus tard) un compte **CinetPay** | vrais paiements Mobile Money | selon leurs conditions |

Vérifiez les tarifs actuels sur les sites des fournisseurs : ils changent.

> **Important — l'ancienne base.** Le site actuel (`koudmain.onrender.com`, version PHP) utilise une base Supabase avec **l'ancien schéma**.
> Le nouveau site crée ses propres tables (`users`, `commandes`, `escrows`...). **Ne le branchez pas sur l'ancienne base** : créez un **nouveau projet
> Supabase** pour la version Laravel. Les données de test de l'ancien site ne sont pas reprises (aucun import n'est prévu) ; quand le nouveau site
> fonctionne, vous pourrez supprimer l'ancien service Render et l'ancien projet Supabase.

---

## 1. Supabase : la base et les photos

1. **Nouveau projet** : choisissez la région **la plus proche de vos utilisateurs et de Render** (Render : `frankfurt` dans `render.yaml` ; prenez une région Supabase européenne voisine).
   Chaque requête SQL fait un aller-retour entre Render et Supabase : des régions éloignées ralentissent tout le site.
2. Notez le **mot de passe de la base** choisi à la création (Settings > Database > Database password, bouton *Reset* si vous l'avez perdu).
   Il ne se saisit **que** dans les variables d'environnement de Render. Jamais dans un fichier du dépôt, un message ou une capture d'écran.
3. Bouton **Connect** en haut du projet > **Session pooler**. Relevez :
   - l'hôte (`aws-0-<région>.pooler.supabase.com`) → `DB_HOST` ;
   - le port **5432** → `DB_PORT` ;
   - l'utilisateur (`postgres.<référence-du-projet>`) → `DB_USERNAME` ;
   - la base `postgres` → `DB_DATABASE`.

   Utilisez bien le **session pooler** (port **5432**) : la connexion directe (`db.xxx.supabase.co`) est en IPv6 et Render ne l'atteint pas toujours, et le
   *transaction pooler* (port 6543) ne supporte pas les requêtes préparées de Laravel.
4. **Storage > New bucket** : nom exactement `medias`, cochez **Public bucket**. (Public : les photos des prestations doivent s'afficher sans identification.)
5. **Project Settings > API** : relevez le **Project URL** (`https://xxxx.supabase.co`) → `SUPABASE_URL`, et la clé **`service_role`** (dans le nouveau système de clés,
   la clé « secret ») → `SUPABASE_SERVICE_KEY`. Cette clé donne tous les droits : **secrète**, uniquement dans Render.

Les intitulés du tableau de bord Supabase évoluent parfois ; la documentation officielle (`supabase.com/docs`) fait foi si un libellé diffère.

---

## 2. GitHub : envoyer le projet

Le dépôt doit contenir le projet Laravel (avec `Dockerfile`, `render.yaml`, `docker/`, `.dockerignore`, `.gitattributes`). Le fichier `.env` **n'y est jamais** (il est dans `.gitignore`).

Avant le premier envoi, vérifiez qu'aucun secret n'a déjà été poussé :

```bash
git log --all --oneline -- .env      # doit n'afficher RIEN
git grep -n "SUPABASE_SERVICE_KEY=\|DB_PASSWORD=" $(git rev-list --all) -- . ':!.env.example' | head
```

Si un secret apparaît dans l'historique : considérez-le comme public. Changez-le (mot de passe de la base, clé `service_role`) **avant** tout le reste ;
supprimer le fichier plus tard ne l'efface pas de l'historique.

Recommandation : travaillez sur une **branche** (`laravel`) ou un **nouveau dépôt** tant que l'ancien site tourne, puis basculez.

---

## 3. Render : créer le service

1. Générez la clé de l'application **sur votre ordinateur** : `php artisan key:generate --show`. Copiez le résultat en entier, `base64:` compris.
2. Render > **New > Blueprint** > choisissez le dépôt (et la branche). Render lit `render.yaml` et prépare le service `koudmain`.
3. Render demande les valeurs marquées « secret » (elles ne sont écrites nulle part dans Git) :

| Variable | Valeur |
|----------|--------|
| `APP_KEY` | la clé de l'étape 1 |
| `APP_URL` | `https://koudmain.onrender.com` (l'adresse que Render vous donnera, ou votre domaine) |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | relevés à l'étape 1 de Supabase |
| `SUPABASE_URL`, `SUPABASE_SERVICE_KEY` | relevés à l'étape 1 de Supabase |
| `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP de Brevo/Resend (voir étape 8) ; l'adresse d'expéditeur doit être validée chez eux |
| `SENTRY_DSN` | laissez vide pour l'instant (étape 9) |

Les autres réglages sont déjà dans `render.yaml` avec leur explication : `APP_ENV=production`, `APP_DEBUG=false`, `TRUST_PROXY=true`, `SESSION_SECURE_COOKIE=true`,
`LOG_CHANNEL=json`, `DB_PERSISTENT=true`, `MEDIA_DRIVER=supabase`, `PAIEMENT_DRIVER=aucun`, `TEMPS_REEL_MODE=sondage`,
`SESSION_LIFETIME=120`, `SESSION_ENCRYPT=true`, `CONTROLE_PRODUCTION=strict`...

**Coffre de secrets** : ces valeurs « secret » sont chiffrées par Render et n'existent **nulle part** dans Git ni dans l'image Docker : il n'y a **aucun fichier `.env` en production**
(le conteneur refuse de démarrer s'il en trouve un). Pour partager les mêmes secrets entre production et préproduction : Render > *Environment Groups* > *New*, puis
dans le service > *Environment* > *Linked Environment Groups* (voir `SECURITE.md`).

**E-mail obligatoire pour s'inscrire** : depuis le lot 10, un compte n'est actif qu'après clic sur le lien de confirmation reçu par e-mail. Sans SMTP fonctionnel
(`MAIL_MAILER=smtp`), personne ne peut activer son compte : le contrôle de démarrage refuse donc `MAIL_MAILER=log`.
**Tout premier déploiement sans SMTP encore prêt** : ajoutez temporairement `CONTROLE_PRODUCTION=avertir` (les problèmes s'affichent sans bloquer), réglez l'étape 8, puis remettez `strict`.

4. **Apply** : Render construit l'image (5 à 10 minutes la première fois), puis démarre le conteneur. Suivez l'onglet **Logs**.

---

## 4. Le premier démarrage, ligne par ligne

Dans les logs, vous devez voir, dans cet ordre :

```
Configuration de production conforme.   (koudmain:controle-production, avant tout le reste)
[koudmain] ... Caching framework bootstrap, configuration, and metadata.
   INFO  Running migrations.   ...   DONE            (une trentaine de migrations)
Régions et quartiers : ...  Catégories et services : ...
RLS activée sur N table(s) : ...        (koudmain:securiser-base ; « RLS déjà active » aux déploiements suivants)
[koudmain] Planificateur lancé.
[koudmain] Démarrage d'Apache sur le port 10000 (version koudmain@abc1234).
```

Puis Render interroge `/up` : quand il répond, le déploiement passe à **Live**. À chaque déploiement suivant, les migrations en attente s'appliquent toutes seules.

Si le conteneur s'arrête avec un message `[koudmain] ERREUR` ou « Configuration de production refusée », il dit exactement **quelle variable** corriger (jamais sa valeur) : voir
`SECURITE.md` (« Le contrôle au démarrage ») et la section « Dépannage ». Render laisse l'ancienne version en ligne tant que la nouvelle ne démarre pas.

---

## 5. Vérifier que le site répond

- `https://VOTRE-SITE/up` → une page « OK ».
- La page d'accueil s'affiche avec ses styles (F12 > onglet Réseau : aucun fichier en rouge).
- `/inscription` : la liste des quartiers est remplie.

---

## 6. Créer l'administrateur

Aucun compte administrateur n'existe par défaut (l'ancien `admin@service.ci` / `password` a disparu : c'était une faille).
Render > votre service > onglet **Shell** (disponible sur les instances payantes) :

```bash
cd /var/www/html
runuser -u www-data -- php artisan koudmain:creer-admin votre.adresse@exemple.ci
```

Le mot de passe est demandé de façon masquée (8 caractères minimum, avec des lettres et des chiffres). Connectez-vous ensuite sur `/connexion` : vous arrivez dans l'espace administrateur.

*Sans Shell :* lancez la même commande depuis votre ordinateur en pointant temporairement vers la base de production
(PowerShell : `$env:DB_HOST="..."; $env:DB_PORT="5432"; $env:DB_DATABASE="postgres"; $env:DB_USERNAME="..."; $env:DB_PASSWORD="..."; $env:DB_SSLMODE="require"; php artisan koudmain:creer-admin vous@exemple.ci`),
puis fermez la fenêtre PowerShell (les variables disparaissent avec elle).

---

## 7. Lire la page « Métriques et santé »

Espace administrateur > **Métriques et santé** > section **Santé du système**. Sur un site bien configuré, tout est « En ordre » sauf, au début :

| Contrôle | Ce qu'il dit | Que faire |
|----------|--------------|-----------|
| Planificateur de tâches | « Actif : dernier battement il y a … » | Rien. S'il dit « Arrêté » : le conteneur a redémarré il y a moins de 3 minutes, ou `RUN_SCHEDULER=0` |
| Paiement Mobile Money | « Aucun agrégateur » (attention) | Normal tant que CinetPay n'est pas branché (étape 10) |
| Suivi des erreurs | « Non configuré » (attention) | Étape 9 |
| E-mails | « écrits dans les journaux » (attention) | Vérifiez `MAIL_MAILER=smtp` et l'étape 8 |
| Environnement | rouge si `APP_DEBUG=true` | Repassez `APP_DEBUG` à `false` |
| Stockage des photos | rouge si `SUPABASE_*` manque | Étape 1 |

Cette page ne montre **jamais** un secret (clé, mot de passe, DSN) : elle dit seulement si c'est configuré.

---

## 8. E-mails transactionnels

Brevo (ou Resend) > SMTP : créez une clé SMTP, puis dans Render : `MAIL_HOST` (ex. `smtp-relay.brevo.com`), `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS` (une adresse que vous avez validée chez le fournisseur). Pour que les e-mails n'arrivent pas en courrier indésirable, ajoutez chez
votre hébergeur de nom de domaine les enregistrements SPF et DKIM que le fournisseur indique.

Test : créez un compte client sur le site avec une adresse à vous → l'e-mail **« Confirmez votre adresse e-mail »** doit arriver ; le clic sur le lien active le compte, puis l'e-mail de bienvenue part. Les envois sont faits **après** l'action de l'utilisateur et
n'ont jamais fait échouer une inscription ou une commande, même si le fournisseur est en panne (la cloche dans le site reste la référence).

---

## 9. Sentry : être prévenu des erreurs

1. Sentry > nouveau projet (plateforme **PHP** ou **Laravel**) > **Settings > Client Keys (DSN)** : copiez le DSN.
2. Render > Environment : `SENTRY_DSN` = le DSN. Le service redémarre.
3. Test : Render > Shell : `runuser -u www-data -- php artisan tinker --execute="report(new Exception('Test Sentry KoudMain'));"` → l'erreur apparaît dans Sentry en quelques secondes.

Ce que Sentry reçoit : le type d'erreur, le message (adresses e-mail et longues suites de chiffres masquées), la pile d'appels, la méthode et le chemin de la page
(sans paramètres), l'identifiant de la personne connectée et le numéro de la requête. **Jamais** : cookies, en-têtes, contenu des formulaires.
Les 404, les validations de formulaire et les accès refusés ne sont pas envoyés (ce ne sont pas des erreurs).

Le journal (Render > **Logs**) reste la source complète : une ligne JSON par événement, avec `requete` (le même numéro que l'en-tête `X-Request-Id` de la page).
Recherches utiles : `paiement.` (argent), `commande.confirmer_reception` et `commande.liberation_auto` (paiements libérés), `retrait.` , `connexion.bloquee`, `tache.echec`.

---

## 10. Passer aux vrais paiements (CinetPay)

1. Compte CinetPay validé → **clé API** et **identifiant du site**.
2. Déclarez chez CinetPay l'adresse de notification : `https://VOTRE-SITE/paiements/notification`.
3. Render > Environment : `PAIEMENT_DRIVER=cinetpay`, `CINETPAY_API_KEY`, `CINETPAY_SITE_ID`.
4. **Essai réel avec 500 FCFA** : rechargement du wallet d'un compte client depuis « Mon wallet ». Le crédit n'est écrit que si CinetPay confirme le montant (jamais sur la foi du navigateur).
5. La page « Métriques et santé » affiche « CinetPay configuré ». Ne mettez **jamais** `PAIEMENT_DRIVER=simulation` en production (la page l'affiche en rouge).

Le pilote CinetPay n'a pas encore été essayé avec de vraies clés : ne l'ouvrez au public qu'après l'essai de l'étape 4 et un retrait complet (checklist B ci-dessous).

---

## 11. Sécurité : ce qu'il faut avoir fait

Le détail des 20 règles (état, preuve, action manuelle) est dans **`SECURITE.md`**. Avant l'ouverture au public :

- [ ] Mot de passe de la base Supabase **réinitialisé** s'il a déjà circulé ailleurs que dans Render, puis mis à jour dans Render (`DB_PASSWORD`).
- [ ] Aucun `.env` ni secret dans Git (vérifié à l'étape 2 ; la CI `Sécurité` scanne tout l'historique avec gitleaks). Sinon : tout changer.
- [ ] Un ancien document ou message contenant un `.env` réel a été **supprimé**, et les secrets qu'il contenait **changés** (le supprimer ne suffit pas).
- [ ] Le démarrage affiche « Configuration de production conforme. » (`CONTROLE_PRODUCTION=strict`).
- [ ] Un seul administrateur créé par `koudmain:creer-admin` ; l'ancien `admin@service.ci` n'existe pas dans la nouvelle base.
- [ ] Clé `service_role` uniquement dans Render (pas dans le navigateur, pas dans Git).
- [ ] Anti force brute actif : 5 échecs par e-mail et 20 par IP sur 15 minutes ; la page « Métriques » compte les blocages.
- [ ] Sessions : 120 minutes d'inactivité, cookie `Secure`, contenu chiffré.
- [ ] RLS active sur les tables (ligne « RLS activée / déjà active » au démarrage ; `php artisan koudmain:securiser-base` pour le relancer à la main).
- [ ] SMTP réel configuré : la confirmation d'e-mail fonctionne de bout en bout (étape 8).
- [ ] **Sauvegardes** : workflow `Sauvegarde` configuré (bucket Cloudflare R2, clé age, secrets GitHub : voir `SECURITE.md`), exécuté une fois à la main avec succès, et **une restauration de test faite** avec `scripts/restaurer.sh`.
- [ ] Si CinetPay est activé : `CINETPAY_SECRET_KEY` saisie (sans elle, les notifications de paiement sont refusées, par sécurité).

---

## 12. Exploitation au quotidien

**Mettre à jour le site** : `git push` sur la branche suivie → Render reconstruit et redéploie (`autoDeployTrigger: commit`) → les migrations s'appliquent au démarrage.
Le déploiement est « sans coupure » côté Render : l'ancienne version répond tant que la nouvelle n'est pas prête.
Conséquence : les **sessions étant sur le disque du conteneur**, chaque déploiement déconnecte les utilisateurs, et les compteurs anti force brute repartent de zéro.
Si cela gêne, mettez `SESSION_DRIVER=database` (les sessions passent dans la base : 2 à 3 requêtes de plus par page).

**Revenir en arrière** : Render > Events > *Rollback* redéploie l'image précédente. Une migration déjà appliquée n'est **pas** annulée : ne supprimez pas de colonne
dans une migration sans être sûr, préférez en ajouter une.

**Sauvegardes** : chaque nuit, le workflow GitHub `Sauvegarde` exporte la base, **prouve** qu'elle se restaure dans une base jetable, la **chiffre** (age) et l'envoie vers Cloudflare R2
(mise en place et restauration : `SECURITE.md`, règle 20). Le plan Supabase avec sauvegardes reste utile, surtout pour les **photos** (Storage), que `pg_dump` ne couvre pas.
Copie manuelle à tout moment :

```bash
pg_dump "postgresql://postgres.REF:MOT_DE_PASSE@aws-0-REGION.pooler.supabase.com:5432/postgres" --no-owner -Fc -f koudmain-AAAA-MM-JJ.dump
```

**Tâches automatiques** (page « Métriques et santé » > *Tâches automatiques*) :

| Tâche | Quand | Rôle |
|-------|-------|------|
| `battement` | chaque minute | prouve que le planificateur tourne |
| `liberation-escrows` | chaque heure | paie les prestataires dont la commande est terminée depuis 3 jours sans confirmation ni litige du client |
| `temps-reel-purge` | toutes les 15 min | vide les événements temps réel périmés |
| `instantane-metriques` | chaque nuit, 2 h 30 | photographie les indicateurs (comptes, commandes en cours, séquestre) |
| `nettoyage` | chaque nuit, 3 h | purge les vieilles notifications, métriques et journaux (jamais une commande, un paiement, un avis ni un message) |

**Ne lancez qu'UNE instance** du service. Le planificateur vit dans le conteneur : deux instances l'exécuteraient deux fois. Si vous devez en avoir plusieurs un jour,
mettez `RUN_SCHEDULER=0` sur toutes sauf une.

**Mémoire et connexions** : le plan `0.5c-512mb` a 512 Mo de mémoire. `APACHE_MAX_WORKERS=8` limite à 8 le nombre de requêtes traitées en même temps **et** à 8 le nombre de
connexions gardées ouvertes vers Supabase. Si la page « Métriques » montre une base lente ou si Render signale un manque de mémoire, passez à `1c-2g` avant d'augmenter ce nombre.

**Temps réel** : en production, `TEMPS_REEL_MODE=sondage` (le navigateur demande les nouveautés toutes les 3 secondes : messages et notifications arrivent en quelques secondes).
Le mode `flux` (SSE, instantané) garde **un processus Apache ouvert par onglet** : avec 8 processus, 8 onglets suffiraient à bloquer le site. Ne l'activez qu'avec un serveur à
beaucoup de processus.

---

## 13. Dépannage

| Symptôme | Cause probable | Solution |
|----------|----------------|----------|
| `[koudmain] ERREUR : APP_KEY est vide` | variable non saisie | Render > Environment > `APP_KEY` (étape 3.1) |
| `Aucune base de données : renseignez DB_HOST...` | variables `DB_*` manquantes | Étape 3 |
| `La migration a échoué 5 fois de suite` | mauvais hôte, port, utilisateur ou mot de passe ; ou port 6543 | Reprenez l'étape 1.3 : session pooler, port **5432**, utilisateur `postgres.<réf>` |
| `prepared statement "pdo_stmt_..." does not exist` | transaction pooler (6543) | Passez au port 5432 |
| `SQLSTATE[08006] ... SSL` | `DB_SSLMODE` | Laissez `require` |
| Render : « Deploy failed — health check » | le conteneur n'a pas répondu sur `/up` | Lisez les logs juste avant ; le port est fourni par Render (`PORT`), ne le fixez pas |
| Page blanche / erreur 500 | voir le journal : la ligne contient `requete` | Render > Logs, cherchez le numéro affiché en bas de la page d'erreur, ou Sentry |
| Styles absents | `APP_URL` incorrect (http au lieu de https) | Corrigez `APP_URL` |
| Les photos ne s'affichent pas | bucket non public, ou `SUPABASE_URL` faux | Étape 1.4 ; la page de santé indique « Stockage des photos » |
| Aucun e-mail reçu | `MAIL_MAILER` resté `log`, ou expéditeur non validé | Page de santé, étape 8 |
| « Planificateur : Arrêté » | conteneur redémarré depuis peu, ou `RUN_SCHEDULER=0` | Attendez 3 minutes ; sinon logs : `[koudmain] Le planificateur s'est arrêté` |
| Toutes les adresses IP se ressemblent dans les journaux | `TRUST_PROXY` absent | `TRUST_PROXY=true` (déjà dans `render.yaml`) |
| Site lent | région Supabase loin de Render, ou `DB_PERSISTENT` absent | Même région ; `DB_PERSISTENT=true` |

---

## 14. Essayer l'image chez vous avant Render (facultatif)

```bash
docker compose up -d db
APP_KEY=$(php artisan key:generate --show) docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build
# puis http://localhost:8080  (PowerShell : $env:APP_KEY = php artisan key:generate --show)
```

C'est exactement l'image de production (Apache, planificateur, migrations au démarrage), contre la base Docker locale. `APP_ENV=staging` y remplace `production`, car en
production l'application force les liens en `https`, ce que `localhost` n'a pas.

---

# Checklist de mise en production

Cochez chaque ligne **sur le site en ligne**, avec de vrais comptes de test (adresses à vous). Un seul non-coché = on ne l'ouvre pas au public.

## A. Configuration

- [ ] `/up` répond. La page d'accueil, `/inscription` et `/connexion` s'affichent avec leurs styles, en clair et en sombre.
- [ ] Admin > Métriques et santé : aucune ligne rouge (carte « Sécurité » comprise) ; les lignes « attention » restantes sont comprises et acceptées.
- [ ] Le planificateur affiche « Actif » et les 4 tâches ont un dernier passage (attendre une heure pour `liberation-escrows`).
- [ ] Les e-mails partent : e-mail de confirmation à l'inscription, clic sur le lien → compte actif → bienvenue.
- [ ] Les photos s'envoient (profil, prestation) et s'affichent après actualisation, et **après un redéploiement**.
- [ ] Une erreur de test arrive dans Sentry (étape 9).

## B. Parcours complet (le scénario de la phase 3 du plan)

1. [ ] **Inscription client** : compte créé, e-mail de bienvenue reçu, puis connexion : vous arrivez dans « Mon espace ».
2. [ ] **Inscription prestataire** : compte « en attente », connexion refusée avec un message clair.
3. [ ] **Validation par l'admin** (Admin > Prestataires) : le prestataire reçoit l'e-mail et peut se connecter.
4. [ ] **Prestation** : le prestataire crée une prestation avec 2 photos et ses horaires ; elle apparaît dans le catalogue, avec recherche et filtres.
5. [ ] **Dépôt** : le client recharge son wallet (500 FCFA en réel avec CinetPay) ; le solde augmente **une seule fois**, même si on recharge la page de retour.
6. [ ] **Commande** : le client réserve un créneau ; l'argent passe en séquestre ; le prestataire est prévenu (cloche + e-mail).
7. [ ] **Messagerie** : les deux se répondent ; le message arrive chez l'autre en quelques secondes, sans recharger.
8. [ ] **Acceptation, démarrage, fin** par le prestataire ; le client est prévenu à chaque étape.
9. [ ] **Confirmation** par le client : le prestataire est payé (wallet), le séquestre revient à zéro pour cette commande.
10. [ ] **Note** : le client note la prestation (seulement possible une fois terminée) ; la moyenne s'affiche sur la page publique.
11. [ ] **Retrait** : le prestataire demande un retrait ; l'admin le voit dans Retraits, fait le virement Mobile Money, confirme ; le prestataire est prévenu.
12. [ ] **Litige** : une deuxième commande contestée par le client → l'admin tranche → l'argent va à la bonne personne.
13. [ ] **Annulation** avant acceptation : le client est remboursé intégralement.

## C. Sécurité

- [ ] 6 mauvais mots de passe de suite → blocage de 15 minutes, message identique pour un compte inexistant.
- [ ] Un client ouvrant `/admin` ou `/prestataire` obtient « Accès refusé » (403).
- [ ] Un visiteur ouvrant une page d'espace est renvoyé vers `/connexion`.
- [ ] Le journal (Render > Logs) ne contient ni mot de passe, ni jeton, ni numéro de téléphone entier, ni adresse e-mail entière.
- [ ] `https://VOTRE-SITE/.env` et `/.git/config` répondent 403 ou 404.
- [ ] En-têtes de sécurité présents (F12 > Réseau > la page > En-têtes : `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`...).
- [ ] `http://VOTRE-SITE` redirige vers `https://` ; un compte non confirmé ne peut pas se connecter ; le même message s'affiche pour une adresse déjà inscrite.
- [ ] Une adresse d'un autre site (Origin étranger) ne reçoit aucun en-tête `Access-Control-Allow-Origin`.
- [ ] Une notification de paiement sans signature valide est refusée (403), une valide est acceptée (une fois CinetPay activé).

## D. Performances

- [ ] Une page d'espace s'affiche en moins d'une seconde (F12 > Réseau), depuis un téléphone en 4G.
- [ ] Admin > Métriques : « Base de données » répond en moins de ~300 ms.
- [ ] La page d'accueil ne charge pas plus de ~1 Mo de fichiers au total.

## E. Après l'ouverture

- [ ] Le workflow `Sauvegarde` est vert chaque matin (e-mail de GitHub en cas d'échec) et une **restauration de test** a réussi.
- [ ] Les demandes Dependabot et le rapport hebdomadaire `Sécurité` (GitHub > Actions) sont lus chaque semaine.
- [ ] Vous savez où lire les erreurs : Render > Logs, Sentry, page « Métriques et santé ».
- [ ] Le lendemain matin : les tâches `instantane-metriques` et `nettoyage` ont tourné (page Métriques > Tâches automatiques).
- [ ] Une semaine après : les courbes « Depuis le … » de la page Métriques se remplissent.
