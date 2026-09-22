#!/usr/bin/env bash
# Restaure une sauvegarde de KoudMain (règle 20). À lancer sur VOTRE ordinateur, jamais sur le serveur : c'est ici que se trouve
# la clé PRIVÉE age qui déchiffre les sauvegardes.
#
#   scripts/restaurer.sh --cle ~/cle-sauvegarde-age.txt --vers "postgresql://postgres.xxx:MOTDEPASSE@aws-0-eu.pooler.supabase.com:5432/postgres" --dernier
#   scripts/restaurer.sh --cle ... --vers ... --fichier koudmain-2026-09-21T0230Z.dump.age     (un fichier déjà téléchargé)
#   scripts/restaurer.sh --cle ... --vers ... --dernier --ecraser                               (remplace les tables existantes)
#
# Sans --ecraser, la base cible doit être VIDE (un projet Supabase neuf, ou une base de test) : rien n'est supprimé.
# Pour télécharger depuis Cloudflare R2, définir avant : R2_ACCOUNT_ID, R2_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY
# (les mêmes valeurs que dans les réglages GitHub ; jamais dans un fichier du dépôt).
# Outils requis : age, pg_restore/psql (PostgreSQL 15+), et aws (seulement pour --dernier).
set -euo pipefail

utilisation() { sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-1}"; }
erreur() { echo "ERREUR : $*" >&2; exit 1; }

cle=""; vers=""; fichier=""; dernier=0; ecraser=0
while [ $# -gt 0 ]; do
    case "$1" in
        --cle) cle="${2:-}"; shift 2 ;;
        --vers) vers="${2:-}"; shift 2 ;;
        --fichier) fichier="${2:-}"; shift 2 ;;
        --dernier) dernier=1; shift ;;
        --ecraser) ecraser=1; shift ;;
        -h|--help) utilisation 0 ;;
        *) erreur "Option inconnue : $1 (voir --help)" ;;
    esac
done

[ -n "$cle" ] && [ -f "$cle" ] || erreur "Indiquez --cle avec le fichier de votre clé PRIVÉE age."
[ -n "$vers" ] || erreur "Indiquez --vers avec l'adresse PostgreSQL de la base à remplir."
[ -n "$fichier" ] || [ "$dernier" = 1 ] || erreur "Indiquez --fichier <sauvegarde.dump.age> ou --dernier."
for outil in age pg_restore psql; do command -v "$outil" >/dev/null || erreur "Outil manquant : $outil"; done

umask 077
travail="$(mktemp -d)"
trap 'rm -rf "$travail"' EXIT   # la sauvegarde déchiffrée ne reste jamais sur le disque

if [ "$dernier" = 1 ]; then
    command -v aws >/dev/null || erreur "Outil manquant : aws (pour télécharger depuis R2)."
    : "${R2_ACCOUNT_ID:?R2_ACCOUNT_ID manquant}" "${R2_BUCKET:?R2_BUCKET manquant}" "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID manquant}" "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY manquant}"
    export AWS_DEFAULT_REGION=auto
    point="https://$R2_ACCOUNT_ID.r2.cloudflarestorage.com"
    nom="$(aws s3 ls "s3://$R2_BUCKET/base-de-donnees/" --endpoint-url "$point" | awk '{print $4}' | grep -E '\.dump\.age$' | sort | tail -n 1)"
    [ -n "$nom" ] || erreur "Aucune sauvegarde trouvée dans le bucket."
    echo "Dernière sauvegarde : $nom"
    aws s3 cp "s3://$R2_BUCKET/base-de-donnees/$nom" "$travail/$nom" --endpoint-url "$point" --only-show-errors
    aws s3 cp "s3://$R2_BUCKET/base-de-donnees/$nom.sha256" "$travail/$nom.sha256" --endpoint-url "$point" --only-show-errors || true
    fichier="$travail/$nom"
fi

[ -f "$fichier" ] || erreur "Fichier introuvable : $fichier"

# Intégrité : la somme de contrôle enregistrée à l'envoi doit correspondre.
if [ -f "$fichier.sha256" ]; then
    attendu="$(cut -d' ' -f1 "$fichier.sha256")"
    reel="$(sha256sum "$fichier" | cut -d' ' -f1)"
    [ "$attendu" = "$reel" ] || erreur "Le fichier est corrompu (somme de contrôle différente)."
    echo "Somme de contrôle : OK"
fi

dechiffre="$travail/sauvegarde.dump"
age -d -i "$cle" -o "$dechiffre" "$fichier" || erreur "Déchiffrement impossible : mauvaise clé privée ?"
pg_restore --list "$dechiffre" >/dev/null || erreur "Le contenu déchiffré n'est pas une sauvegarde PostgreSQL valide."
echo "Sauvegarde déchiffrée : $(pg_restore --list "$dechiffre" | grep -c 'TABLE DATA') tables avec données."

# Confirmation : on montre OÙ l'on va écrire, l'utilisateur retape le nom de la base.
hote="$(printf '%s' "$vers" | sed -E 's#^[a-z]+://[^@]*@##; s#[/?].*##')"
base="$(printf '%s' "$vers" | sed -E 's#^[^@]*@[^/]*/##; s#\?.*##')"
echo
echo "Cible : base « $base » sur $hote"
[ "$ecraser" = 1 ] && echo "MODE --ecraser : les tables existantes seront SUPPRIMÉES puis recréées."
printf 'Retapez le nom de la base pour confirmer : '
read -r confirmation
[ "$confirmation" = "$base" ] || erreur "Confirmation différente : abandon, rien n'a été modifié."

# L'extension d'accents doit exister avant les tables (recherche sans accents de l'application).
psql "$vers" -qc 'CREATE EXTENSION IF NOT EXISTS unaccent' || echo "(extension unaccent : voir DEPLOIEMENT.md si la recherche sans accents ne fonctionne pas)"

options=(--no-owner --no-privileges --dbname="$vers")
[ "$ecraser" = 1 ] && options+=(--clean --if-exists)

# Une erreur sans gravité est attendue : « schema public already exists ». Les autres sont affichées.
pg_restore "${options[@]}" "$dechiffre" 2>"$travail/erreurs.log" || true
grep -v -e 'schema "public" already exists' -e 'errors ignored on restore' "$travail/erreurs.log" | grep -E 'error|erreur' | head -20 || true

tables="$(psql "$vers" -Atc "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'")"
echo "Restauration terminée : $tables tables dans la base."
echo "Étape suivante : lancer « php artisan koudmain:securiser-base » (protection RLS) puis vérifier le site."
