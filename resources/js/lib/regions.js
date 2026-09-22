/* ==========================================================================
   Zones rafraîchies en direct
   --------------------------------------------------------------------------
   Une zone de page se déclare dans le Blade :

     <section data-region="commandes" data-region-evenements="commande">…</section>

   Quand le serveur annonce un événement de ce type (ex. « commande » : une commande vient de changer d'état), on recharge
   la page en arrière-plan et on remplace seulement le contenu de cette zone — même nom, même rendu que le serveur, donc
   aucune règle métier dupliquée en JavaScript. La zone qui change s'illumine brièvement.

   On ne touche pas à la page pendant que la personne écrit dans un champ de la zone ou qu'une boîte de dialogue est ouverte :
   on réessaie un peu plus tard.
   ========================================================================== */

import { sur } from './tempsReel';

const DELAI_GROUPE = 350; // plusieurs événements presque simultanés = un seul rechargement
const DELAI_REESSAI = 2000;

let attente = null;
let enCours = false;
let types = new Set();
let rebrancher = () => {};

function zoneOccupee(zone) {
    if (document.querySelector('dialog[open]')) return true;
    const actif = document.activeElement;
    return Boolean(actif && zone.contains(actif) && actif.matches('input, textarea, select, [contenteditable="true"]') && actif.type !== 'checkbox' && actif.type !== 'radio');
}

async function actualiser() {
    if (enCours) return;
    enCours = true;

    try {
        const reponse = await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, cache: 'no-store' });
        // Redirection (session terminée, page devenue inaccessible) ou erreur : on laisse la page telle quelle.
        if (!reponse.ok || reponse.redirected) return;

        const nouveau = new DOMParser().parseFromString(await reponse.text(), 'text/html');
        let reporte = false;

        document.querySelectorAll('[data-region]').forEach((zone) => {
            const evenements = (zone.dataset.regionEvenements || 'commande').split(/\s+/);
            if (!evenements.some((t) => types.has(t))) return;

            const remplacante = [...nouveau.querySelectorAll('[data-region]')].find((z) => z.dataset.region === zone.dataset.region);
            if (!remplacante || remplacante.innerHTML === zone.innerHTML) return;

            if (zoneOccupee(zone)) {
                reporte = true;
                return;
            }

            zone.innerHTML = remplacante.innerHTML;
            rebrancher(zone);
            zone.classList.remove('region-maj');
            void zone.offsetWidth; // relance l'animation
            zone.classList.add('region-maj');
        });

        // Ce qui n'a pas pu être remplacé (champ en cours de saisie) reste demandé.
        if (!reporte) types = new Set();
        else attente = setTimeout(programmer, DELAI_REESSAI);
    } catch {
        // Réseau coupé : le prochain événement relancera.
    } finally {
        enCours = false;
    }
}

function programmer() {
    clearTimeout(attente);
    attente = setTimeout(actualiser, DELAI_GROUPE);
}

/** Branche les zones de la page sur le flux temps réel. @param {(zone: Element) => void} pourRebrancher */
export function brancherRegions(pourRebrancher) {
    rebrancher = pourRebrancher;
    if (!document.querySelector('[data-region]')) return;

    const connus = new Set();
    document.querySelectorAll('[data-region]').forEach((zone) => (zone.dataset.regionEvenements || 'commande').split(/\s+/).forEach((t) => connus.add(t)));

    connus.forEach((type) => {
        sur(type, () => {
            types.add(type);
            programmer();
        });
    });
}
