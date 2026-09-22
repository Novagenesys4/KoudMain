#!/bin/sh
# Démarrage du conteneur KoudMain : vérifie la configuration, prépare la base, lance le planificateur de tâches, puis Apache.
# Réglages (variables d'environnement) : RUN_MIGRATIONS=0 saute la migration, RUN_SCHEDULER=0 ne lance pas le planificateur.
set -eu

cd /var/www/html

info() { echo "[koudmain] $*"; }
erreur() { echo "[koudmain] ERREUR : $*" >&2; exit 1; }
# Les commandes artisan tournent sous l'identité d'Apache (www-data) : sinon les fichiers qu'elles créent (journaux, cache)
# appartiendraient à root et Apache ne pourrait plus les écrire.
en_www() { runuser -u www-data -- "$@"; }

# Toute autre commande que « apache2-foreground » (ex. : php artisan koudmain:creer-admin ...) est exécutée telle quelle.
if [ "${1:-}" != "apache2-foreground" ]; then
    exec runuser -u www-data -- "$@"
fi

# ---- 1. Configuration indispensable : on s'arrête tout de suite, avec un message clair, plutôt que de planter à la première page.
[ -n "${APP_KEY:-}" ] || erreur "APP_KEY est vide. Générez-la (php artisan key:generate --show) et saisissez-la dans les variables d'environnement."
if [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    erreur "Aucune base de données : renseignez DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME et DB_PASSWORD (voir DEPLOIEMENT.md)."
fi

# Contrôle de sécurité (règles 1, 2, 5, 7, 8, 14, 17, 19) : un secret par défaut, un .env dans l'image, APP_DEBUG=true, un paiement
# simulé ou des e-mails « log » arrêtent le démarrage avec un message clair. Render garde alors l'ancienne version en ligne.
# CONTROLE_PRODUCTION=avertir : affiche les problèmes sans bloquer (à réserver à un tout premier déploiement, le temps de tout régler).
if [ "${APP_ENV:-production}" = "production" ]; then
    if [ "${CONTROLE_PRODUCTION:-strict}" = "avertir" ]; then
        info "CONTROLE_PRODUCTION=avertir : les erreurs de configuration ne bloquent pas le démarrage. Repassez en strict dès que possible."
        en_www php artisan koudmain:controle-production --avertir --no-interaction
    else
        en_www php artisan koudmain:controle-production --no-interaction || erreur "Configuration de production refusée (voir les lignes ci-dessus et SECURITE.md)."
    fi
fi

# Version affichée dans Sentry et la page de santé : les 7 premiers caractères du commit déployé (Render les fournit).
if [ -z "${APP_VERSION:-}" ] && [ -n "${RENDER_GIT_COMMIT:-}" ]; then
    APP_VERSION="koudmain@$(printf '%s' "$RENDER_GIT_COMMIT" | cut -c1-7)"
    export APP_VERSION
fi

# ---- 2. Caches de performance, construits avec les variables d'environnement de CE démarrage (config, routes, vues, événements).
en_www php artisan optimize --no-interaction

# ---- 3. Base de données : migrations (rejouées sans risque) puis données de référence (seulement si la base en est dépourvue).
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    essai=1
    until en_www php artisan migrate --force --no-interaction; do
        [ "$essai" -lt 5 ] || erreur "La migration a échoué 5 fois de suite. Vérifiez DB_HOST, DB_PORT (Supabase : session pooler, port 5432), DB_USERNAME, DB_PASSWORD."
        info "Base injoignable ou migration en échec (essai $essai/5). Nouvelle tentative dans 6 s..."
        essai=$((essai + 1))
        sleep 6
    done
    en_www php artisan koudmain:initialiser --no-interaction
    # Règle 4 : Row Level Security sur toutes les tables (dont celles ajoutées depuis le dernier déploiement). Sans danger à rejouer,
    # jamais bloquant : le site continue de démarrer même si cette étape échoue (l'erreur est affichée).
    en_www php artisan koudmain:securiser-base --no-interaction || info "ATTENTION : la protection RLS n'a pas pu être appliquée (voir ci-dessus). Relancez : php artisan koudmain:securiser-base"
fi

# ---- 4. Planificateur : libération des paiements, nettoyage, instantanés... Relancé s'il s'arrête.
if [ "${RUN_SCHEDULER:-1}" = "1" ]; then
    (
        while true; do
            en_www php artisan schedule:work --no-interaction || true
            info "Le planificateur s'est arrêté ; redémarrage dans 5 s."
            sleep 5
        done
    ) &
    info "Planificateur lancé."
fi

info "Démarrage d'Apache sur le port ${PORT:-8080} (version ${APP_VERSION:-inconnue})."
exec "$@"
