/* ==========================================================================
   Temps réel côté navigateur
   --------------------------------------------------------------------------
   Une seule connexion par onglet vers le serveur (Server-Sent Events : /temps-reel), partagée par toute la page :
   la cloche, les messages, les pastilles, les zones de commandes... s'y abonnent avec `sur('type', rappel)`.

   Le serveur ferme volontairement le flux toutes les ~25 s : ici, on le rouvre aussitôt en redonnant le numéro du dernier
   événement reçu (`?depuis=`), donc rien ne se perd. Si le réseau met les flux en tampon (certains proxys d'entreprise), on
   s'en aperçoit — le message d'accueil n'arrive pas tout de suite — et on passe à une simple interrogation toutes les 3 s
   (/temps-reel/sonder) : c'est moins instantané, mais ça marche partout.
   ========================================================================== */

const abonnes = new Map(); // type -> Set<rappel> ; « * » reçoit tout
let configuration = null;
let source = null;
let dernier = null; // numéro du dernier événement reçu
let minuteurAccueil = null;
let minuteurSondage = null;
let minuteurReprise = null;
let mode = 'arret'; // 'arret' | 'flux' | 'sondage'
let echecs = 0;
let sondageImpose = false; // le serveur ne sait pas tenir un flux : on n'essaie jamais d'en rouvrir un
let connecte = false;

const DELAI_ACCUEIL = 6000; // sans message d'accueil au bout de 6 s : flux en tampon -> sondage
const PAUSE_SONDAGE = 3000;
const REPRISE_FLUX = 120000; // en sondage, on retente le flux toutes les 2 minutes

/** S'abonne à un type d'événement (ou « * ») ; renvoie la fonction de désabonnement. */
export function sur(type, rappel) {
    if (!abonnes.has(type)) abonnes.set(type, new Set());
    abonnes.get(type).add(rappel);
    return () => abonnes.get(type)?.delete(rappel);
}

/** Vrai tant que le serveur répond (flux ouvert ou sondage réussi). */
export function estConnecte() {
    return connecte;
}

export function modeActuel() {
    return mode;
}

function distribuer(evenement) {
    const donnees = evenement.donnees ?? {};
    for (const type of [evenement.type, '*']) {
        abonnes.get(type)?.forEach((rappel) => {
            try {
                rappel(donnees, evenement);
            } catch (erreur) {
                console.error(`Rappel temps réel en échec (${evenement.type})`, erreur);
            }
        });
    }
}

function changerEtat(valeur) {
    if (connecte === valeur) return;
    connecte = valeur;
    distribuer({ type: valeur ? 'connecte' : 'deconnecte', donnees: { mode } });
}

function adresse(base) {
    const url = new URL(base, window.location.origin);
    if (dernier !== null) url.searchParams.set('depuis', String(dernier));
    return url.toString();
}

function recevoir(evenement) {
    if (typeof evenement.id === 'number') {
        // Un numéro déjà vu (rattrapage qui recoupe le flux) n'est pas rejoué.
        if (dernier !== null && evenement.id <= dernier) return;
        dernier = evenement.id;
    }
    if (evenement.type === 'bonjour') return;
    distribuer(evenement);
}

// ------------------------------------------------------------------- Flux

function ouvrirFlux() {
    if (mode === 'arret' || typeof EventSource === 'undefined') {
        if (typeof EventSource === 'undefined') demarrerSondage();
        return;
    }

    fermerFlux();
    mode = 'flux';
    let accueilRecu = false;

    source = new EventSource(adresse(configuration.flux), { withCredentials: true });

    minuteurAccueil = setTimeout(() => {
        if (!accueilRecu) demarrerSondage(); // flux mis en tampon quelque part entre le serveur et le navigateur
    }, DELAI_ACCUEIL);

    source.onmessage = (message) => {
        accueilRecu = true;
        echecs = 0;
        clearTimeout(minuteurAccueil);
        changerEtat(true);

        let evenement;
        try {
            evenement = JSON.parse(message.data);
        } catch {
            return;
        }
        if (evenement.type === 'arret') {
            // Le serveur a désactivé le temps réel : on interroge doucement au lieu d'insister.
            demarrerSondage();
            return;
        }
        recevoir(evenement);
    };

    source.onerror = () => {
        // Fin normale d'un flux (le serveur le ferme après ~25 s) ou coupure : on rouvre tout de suite, sans attendre
        // le minuteur du navigateur (qui est ralenti dans un onglet caché).
        fermerFlux();
        if (mode !== 'flux') return;

        if (!accueilRecu) {
            echecs += 1;
            changerEtat(false);
        }
        if (echecs >= 3) {
            demarrerSondage();
            return;
        }
        minuteurReprise = setTimeout(ouvrirFlux, accueilRecu ? 0 : 1500 * echecs);
    };
}

function fermerFlux() {
    clearTimeout(minuteurAccueil);
    clearTimeout(minuteurReprise);
    if (source) {
        source.onmessage = null;
        source.onerror = null;
        source.close();
        source = null;
    }
}

// ---------------------------------------------------------------- Sondage

function demarrerSondage() {
    fermerFlux();
    clearTimeout(minuteurSondage);
    mode = 'sondage';

    const tour = async () => {
        if (mode !== 'sondage') return;
        // Onglet caché : inutile d'interroger le serveur (on reprend tout de suite au retour, voir visibilitychange).
        if (document.visibilityState === 'hidden') {
            minuteurSondage = setTimeout(tour, PAUSE_SONDAGE * 2);
            return;
        }
        try {
            const reponse = await fetch(adresse(configuration.sonder), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });
            if (reponse.status === 401 || reponse.status === 419) {
                // Session terminée : inutile d'insister (la prochaine page demandera de se reconnecter).
                mode = 'arret';
                changerEtat(false);
                return;
            }
            if (!reponse.ok) throw new Error(String(reponse.status));
            const donnees = await reponse.json();
            changerEtat(true);
            if (dernier === null && typeof donnees.dernier === 'number') dernier = donnees.dernier;
            (donnees.evenements ?? []).forEach(recevoir);
        } catch {
            changerEtat(false);
        }
        if (mode === 'sondage') minuteurSondage = setTimeout(tour, PAUSE_SONDAGE);
    };

    minuteurSondage = setTimeout(tour, 0);
    // De temps en temps, on retente le vrai flux : le réseau a peut-être changé (sauf si le serveur n'en supporte pas).
    if (sondageImpose) return;
    minuteurReprise = setTimeout(() => {
        if (mode !== 'sondage') return;
        clearTimeout(minuteurSondage);
        echecs = 0;
        ouvrirFlux();
    }, REPRISE_FLUX);
}

// ------------------------------------------------------------- Démarrage

/**
 * Lance la connexion (une seule fois par page).
 * @param {{flux: string, sonder: string, mode?: 'flux'|'sondage'}} config adresses du flux et du rattrapage, et mode voulu par le serveur
 */
export function demarrer(config) {
    if (configuration || !config?.flux) return;
    configuration = config;
    mode = 'flux';

    // Onglet caché puis réaffiché : on se rebranche tout de suite et on rattrape ce qui a été manqué.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || mode === 'arret') return;
        if (mode === 'flux' && !source) ouvrirFlux();
        if (mode === 'sondage') {
            clearTimeout(minuteurSondage);
            minuteurSondage = setTimeout(() => demarrerSondage(), 0);
        }
    });
    window.addEventListener('online', () => {
        if (mode === 'flux') ouvrirFlux();
    });
    // On ferme proprement en quittant la page : le serveur libère son processus sans attendre.
    window.addEventListener('pagehide', () => {
        fermerFlux();
        clearTimeout(minuteurSondage);
    });
    window.addEventListener('pageshow', (e) => {
        if (e.persisted && mode !== 'arret') mode === 'sondage' ? demarrerSondage() : ouvrirFlux();
    });

    // Serveur à un seul processus (serveur de développement) : pas de flux, une interrogation légère — un flux gèlerait les pages.
    if (config.mode === 'sondage') {
        sondageImpose = true;
        demarrerSondage();
        return;
    }

    ouvrirFlux();
}

// -------------------------------------------------- Aide aux requêtes JS

/** Le jeton CSRF de la page (pour les requêtes POST faites en JavaScript). */
export function jeton() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** POST JSON vers le serveur ; renvoie { ok, statut, donnees }. Ne lève jamais d'erreur réseau : `ok` vaut alors false. */
export async function envoyerJson(url, corps = {}) {
    try {
        const reponse = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(corps),
        });
        let donnees = null;
        try {
            donnees = await reponse.json();
        } catch {
            donnees = null;
        }
        return { ok: reponse.ok, statut: reponse.status, donnees };
    } catch {
        return { ok: false, statut: 0, donnees: null };
    }
}

export async function lireJson(url) {
    try {
        const reponse = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
        if (!reponse.ok) return { ok: false, statut: reponse.status, donnees: null };
        return { ok: true, statut: reponse.status, donnees: await reponse.json() };
    } catch {
        return { ok: false, statut: 0, donnees: null };
    }
}
