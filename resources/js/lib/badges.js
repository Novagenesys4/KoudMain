/* ==========================================================================
   Pastilles de comptage en direct
   --------------------------------------------------------------------------
   Le serveur écrit les pastilles dans la page :
     <span data-badge="messages" hidden>0</span>
   (la cloche est un îlot React : elle suit ses notifications toute seule, mais le total de l'onglet passe par ici)
   Ce module met leur nombre à jour dès qu'un événement arrive, et reflète le total dans l'onglet du navigateur : « (3) Titre ».
   ========================================================================== */

import { sur } from './tempsReel';

const compteurs = { messages: 0, notifications: 0 };
let titreDeBase = null;

/** Les nombres de départ viennent de <body data-non-lus-messages data-non-lues-notifications>, posés par le serveur. */
function lireInitial() {
    compteurs.messages = Number(document.body.dataset.nonLusMessages) || 0;
    compteurs.notifications = Number(document.body.dataset.nonLuesNotifications) || 0;
}

function afficher() {
    for (const [nom, nombre] of Object.entries(compteurs)) {
        document.querySelectorAll(`[data-badge="${nom}"]`).forEach((element) => {
            const texte = nombre > 99 ? '99+' : String(nombre);
            element.dataset.nombre = String(nombre);
            element.hidden = nombre <= 0;
            const interieur = element.querySelector('[data-badge-texte]');
            (interieur ?? element).textContent = texte;
            element.setAttribute('aria-label', `${nombre} non lu${nombre > 1 ? 's' : ''}`);
        });
    }

    titreDeBase ??= document.title.replace(/^\(\d+\+?\)\s*/, '');
    const total = compteurs.messages + compteurs.notifications;
    document.title = total > 0 ? `(${total > 99 ? '99+' : total}) ${titreDeBase}` : titreDeBase;
}

function definir(nom, valeur) {
    if (typeof valeur !== 'number') return;
    compteurs[nom] = Math.max(0, valeur);
    afficher();
}

/** Nombre courant de notifications non lues (la cloche le demande). */
export function notificationsNonLues() {
    return compteurs.notifications;
}

export function brancherBadges() {
    lireInitial();
    afficher();

    sur('notification', (d) => definir('notifications', d.non_lues));
    sur('notifications_lues', (d) => definir('notifications', d.non_lues));
    sur('message', (d) => definir('messages', d.non_lus_total));
    sur('non_lus', (d) => definir('messages', d.non_lus_total));
}
