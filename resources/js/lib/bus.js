/* ==========================================================================
   Communication entre îlots
   --------------------------------------------------------------------------
   Chaque île React est une application indépendante (le Blade reste maître de la page).
   Pour qu'une carte de la page d'accueil ouvre le tiroir de commande situé dans l'en-tête,
   on passe par un événement du navigateur.
   ========================================================================== */

const EVT_COMMANDE = 'km:order';

/** Ouvre le tiroir « Commande rapide » pour une prestation. */
export function ouvrirCommande(prestation) {
    window.dispatchEvent(new CustomEvent(EVT_COMMANDE, { detail: { prestation } }));
}

/** S'abonne à un événement ; renvoie la fonction de désabonnement (à utiliser dans useEffect). */
export function ecouter(nom, rappel) {
    const gestionnaire = (e) => rappel(e.detail ?? {});
    window.addEventListener(nom, gestionnaire);
    return () => window.removeEventListener(nom, gestionnaire);
}

export const evenements = { commande: EVT_COMMANDE };
