# KoudMain × CinetPay — mettre en place les vrais paiements Mobile Money

Ce guide est écrit pour une première fois : aucun compte CinetPay n'est nécessaire pour lire ce qui suit, et KoudMain reste en « simulation » (aucun argent réel) tant que tu n'as pas ajouté tes clés.

## 1. Ce que CinetPay fait dans KoudMain

| Sens de l'argent | Aujourd'hui | Avec CinetPay |
|---|---|---|
| **Recharge du wallet** (le client alimente son solde) | Simulation | **Automatique** : le client paie en Orange Money, MTN MoMo, Wave ou carte ; le solde est crédité dès que CinetPay confirme. |
| **Commande** (payée depuis le wallet, séquestre) | Interne à KoudMain | Inchangé : aucun appel à CinetPay, c'est de la comptabilité interne. |
| **Retrait du prestataire** | L'administrateur voit la demande et envoie l'argent lui-même, puis clique « Confirmer » | **Inchangé pour l'instant** (voir §7). |

Comment KoudMain se protège : il ne croit jamais le navigateur ni la notification. Après chaque paiement, il redemande à CinetPay l'état réel de la transaction, vérifie que le montant payé est bien celui demandé, et ne crédite le solde qu'une seule fois, même si CinetPay envoie la notification plusieurs fois. Si la notification se perd, une tâche planifiée revérifie toutes les 5 minutes les recharges restées « en attente ».

## 2. Avant d'ouvrir le compte

Ce que je n'ai **pas** pu vérifier dans la documentation officielle (à confirmer auprès de CinetPay avant de perdre du temps) :

- **Quel type de compte marchand est ouvert à une personne sans entreprise enregistrée ?** Des articles tiers (2026) citent, pour une entreprise : registre de commerce (RCCM), pièce d'identité du gérant, RIB, justificatif d'adresse, et 3 à 7 jours ouvrés d'activation. Écris à **support.technique@cinetpay.com** (adresse indiquée dans leur documentation) et pose la question telle quelle : « J'ai une plateforme de mise en relation client / prestataire en Côte d'Ivoire ; quel compte marchand dois-je ouvrir en tant que personne physique, et quels documents fournir ? ».
- **Existe-t-il un environnement de test (sandbox) ?** Des articles le disent, la documentation technique ne le mentionne pas. Demande-le aussi. À défaut, le premier essai se fait en réel avec 500 FCFA (montant minimum de recharge de KoudMain).
- **Frais** : annoncés autour de 1,5 à 2 % de commission CinetPay, plus les frais de l'opérateur (article de juin 2026, non officiel). Décide qui les supporte (toi, ou le client) avant d'ouvrir au public.

### Point juridique important (à ne pas sauter)

KoudMain garde l'argent des clients dans un wallet interne et le libère au prestataire à la fin de la prestation (séquestre). Détenir et redistribuer de l'argent pour le compte d'autres personnes peut relever d'une **activité réglementée** dans l'UEMOA (monnaie électronique / services de paiement). Je ne suis pas juriste : avant d'ouvrir au grand public, demande à CinetPay comment ils traitent les places de marché (« marketplace ») et fais valider le modèle par un juriste ou un conseiller. Pour une phase pilote avec quelques amis, ce n'est pas un blocage technique, mais c'est une question à régler avant de grandir.

## 3. Ouvrir le compte (à faire par toi : je ne peux pas créer de compte ni saisir tes pièces)

1. Va sur le site officiel de CinetPay (cinetpay.com) et crée un compte marchand avec ton adresse e-mail.
2. Renseigne ton activité (plateforme de services en Côte d'Ivoire), l'adresse de ton site (https://koudmain.onrender.com quand il sera prêt) et fournis les pièces demandées.
3. Attends la validation. Tant que le compte n'est pas activé, tu ne peux pas encaisser de vrais paiements.
4. Ne partage jamais tes clés (voir §4) : ni dans un message, ni dans GitHub, ni à moi. Tu les colles seulement dans ton fichier `.env` local (non versionné) et dans les variables d'environnement de Render.

## 4. Récupérer les trois valeurs dans ton espace marchand

Dans le tableau de bord CinetPay (section API / développeurs ; les libellés exacts peuvent varier) :

| Variable KoudMain | Ce que c'est |
|---|---|
| `CINETPAY_API_KEY` | ta clé API |
| `CINETPAY_SITE_ID` | l'identifiant de ton site |
| `CINETPAY_SECRET_KEY` | la clé secrète, qui sert à vérifier la signature des notifications |

## 5. Brancher KoudMain

Dans `.env` (PC) **ou** dans Render → Environment :

```
PAIEMENT_DRIVER=cinetpay
CINETPAY_API_KEY=...
CINETPAY_SITE_ID=...
CINETPAY_SECRET_KEY=...
APP_URL=https://ton-adresse-publique
```

Puis `php artisan config:clear`. La page admin **Métriques et santé** doit afficher « Paiement Mobile Money : CinetPay configuré ». Elle te prévient aussi si `APP_URL` est une adresse locale ou si la clé secrète manque.

## 6. Premier essai réel

CinetPay doit pouvoir appeler ton serveur (URL de notification). `http://localhost` n'est pas joignable : soit tu testes sur Render, soit tu ouvres un tunnel HTTPS vers ton PC (par exemple `cloudflared tunnel --url http://localhost:8000` ou ngrok), et tu mets l'adresse obtenue dans `APP_URL`. Sans notification, le retour du client sur le site et le rattrapage toutes les 5 minutes créditent quand même.

1. Connecte-toi en client, ouvre « Recharger », choisis Orange Money / MTN MoMo / Wave, saisis **500 FCFA** (montant multiple de 5 obligatoire).
2. Tu es envoyé sur la page de paiement CinetPay ; confirme sur ton téléphone.
3. Au retour, le solde monte de 500 FCFA. Vérifie dans le tableau de bord CinetPay que la transaction (référence `KM…`) apparaît bien.
4. Si le solde ne monte pas : regarde `storage/logs/laravel.log` (événements `paiement.*`). Si tu vois `paiement.notification_signature_invalide`, mets temporairement `CINETPAY_VERIFIER_SIGNATURE=false` pour isoler le problème et envoie-moi la ligne du journal (jamais tes clés).

## 7. Les retraits des prestataires

CinetPay propose une **API de transfert** (envoyer de l'argent vers un numéro Mobile Money), mais elle demande des conditions à régler d'abord : activation du service de transfert sur ton compte, un solde de transfert alimenté à l'avance, et une **liste d'adresses IP autorisées** (l'API refuse toute autre adresse, code 709). Or l'offre gratuite de Render change d'adresse sortante ; il faudrait une adresse fixe. C'est pourquoi les retraits restent **manuels** pour le lancement :

1. Le prestataire demande un retrait : son solde baisse tout de suite, la demande est « en attente ».
2. L'administrateur (page « Retraits ») voit le montant et le numéro ; il envoie l'argent (depuis son compte Orange Money / Wave / MTN, ou depuis la section transferts du tableau de bord CinetPay), puis clique « Confirmer ».
3. Si l'envoi est impossible, « Refuser » avec un motif rend l'argent au prestataire.

Quand ton compte de transfert sera actif, on branchera l'automatisation : je préfère la coder avec tes vraies réponses de test sous les yeux plutôt qu'à l'aveugle, puisqu'elle déplace de l'argent.

## 8. Avant d'ouvrir au public

- [ ] Compte CinetPay activé, 500 FCFA testés de bout en bout.
- [ ] `PAIEMENT_DRIVER=cinetpay` et jamais `simulation` en production (la page de santé le signale en rouge).
- [ ] `APP_URL` en https, adresse publique. Planificateur actif sur Render (le rattrapage en dépend).
- [ ] Mot de passe Supabase changé, clés uniquement dans les variables d'environnement de Render.
- [ ] Modèle « wallet + séquestre » validé juridiquement (§2).
- [ ] Conditions d'utilisation et politique de remboursement publiées.

## Références (documentation officielle CinetPay)

- Initialisation d'un paiement : https://docs.cinetpay.com/api/1.0-fr/checkout/initialisation
- Notification : https://docs.cinetpay.com/api/1.0-fr/checkout/notification
- Vérification de la signature (x-token) : https://docs.cinetpay.com/api/1.0-en/checkout/hmac
- Vérifier une transaction : https://docs.cinetpay.com/api/1.0-en/checkout/verification
- API de transfert : https://docs.cinetpay.com/api/1.0-fr/transfert/utilisation
