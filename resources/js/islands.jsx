import { MotionConfig } from 'motion/react';
import { useLayoutEffect } from 'react';
import { createRoot } from 'react-dom/client';

/* ==========================================================================
   Îlots React
   --------------------------------------------------------------------------
   Laravel (Blade) reste maître des pages, de l'authentification et des formulaires.
   Certains blocs — l'en-tête, la page d'accueil, le panneau de connexion — sont pris
   en charge par React : le Blade les pose sous forme de <div data-island="Nom" data-props='{…}'>
   avec, à l'intérieur, une version simple (« repli ») qui sert au référencement et aux
   visiteurs sans JavaScript. Ici, on remplace ce repli par le composant animé.

   Chaque île est un chargement séparé (import dynamique) : une page n'embarque que le code
   des îles qu'elle utilise.
   ========================================================================== */

const REGISTRE = {
    SiteHeader: () => import('./islands/SiteHeader'),
    Landing: () => import('./islands/Landing'),
    Catalogue: () => import('./islands/Catalogue'),
    PrestationGalerie: () => import('./islands/PrestationGalerie'),
    AuthShowcase: () => import('./islands/AuthShowcase'),
    ComponentsDemo: () => import('./islands/ComponentsDemo'),
    CommandeFormulaire: () => import('./islands/CommandeFormulaire'),
    CartesWallet: () => import('./islands/CartesWallet'),
    SoldeAnime: () => import('./islands/SoldeAnime'),
    SuiviMouvement: () => import('./islands/SuiviMouvement'),
    Cloche: () => import('./islands/Cloche'),
    Toasts: () => import('./islands/Toasts'),
    Messagerie: () => import('./islands/Messagerie'),
};

/** Signale que l'île est affichée : la CSS cesse alors de masquer le repli qu'elle vient de remplacer. */
function Pret({ element, children }) {
    useLayoutEffect(() => {
        element.dataset.pret = '';
    }, [element]);
    return children;
}

function lireProps(element) {
    try {
        return JSON.parse(element.dataset.props || '{}');
    } catch (erreur) {
        console.error(`Propriétés illisibles pour l'île « ${element.dataset.island} »`, erreur);
        return {};
    }
}

export function monter(racine = document) {
    racine.querySelectorAll('[data-island]:not([data-monte])').forEach(async (element) => {
        element.dataset.monte = '';
        const nom = element.dataset.island;
        const charger = REGISTRE[nom];

        if (!charger) {
            console.error(`Île inconnue : « ${nom} »`);
            element.dataset.pret = 'erreur';
            return;
        }

        try {
            const { default: Composant } = await charger();
            createRoot(element).render(
                <MotionConfig reducedMotion="user">
                    <Pret element={element}>
                        <Composant {...lireProps(element)} />
                    </Pret>
                </MotionConfig>,
            );
        } catch (erreur) {
            // Le repli (contenu du serveur) reste affiché : la page reste utilisable.
            console.error(`Échec du chargement de l'île « ${nom} »`, erreur);
            element.dataset.pret = 'erreur';
        }
    });
}
