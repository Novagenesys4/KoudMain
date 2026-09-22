import { useCallback, useEffect, useState } from 'react';
import { basculerTheme, themeActuel } from './theme';

/** Thème courant + fonction de bascule ; reste synchronisé si un autre script change data-theme. */
export function useTheme() {
    const [theme, setTheme] = useState('light');

    useEffect(() => {
        setTheme(themeActuel());
        const observateur = new MutationObserver(() => setTheme(themeActuel()));
        observateur.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        return () => observateur.disconnect();
    }, []);

    const basculer = useCallback((origine) => setTheme(basculerTheme(origine)), []);
    return { theme, basculer };
}

/** Vrai si l'écran est plus étroit que la largeur donnée (px). */
export function useMediaQuery(requete) {
    // Valeur initiale lue tout de suite (les îles ne sont rendues que dans le navigateur) : pas de « faux départ ».
    const [ok, setOk] = useState(() => (typeof window !== 'undefined' ? window.matchMedia(requete).matches : false));
    useEffect(() => {
        const media = window.matchMedia(requete);
        const maj = () => setOk(media.matches);
        maj();
        media.addEventListener('change', maj);
        return () => media.removeEventListener('change', maj);
    }, [requete]);
    return ok;
}

const FOCALISABLES = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Comportement d'une boîte de dialogue accessible : Échap ferme, Tab reste à l'intérieur,
 * le défilement de la page est bloqué, le focus revient là où il était à la fermeture.
 * À appeler dans un composant qui n'existe que tant que la boîte est ouverte.
 */
export function useDialogue(refBoite, surFermer) {
    useEffect(() => {
        const precedent = document.activeElement;
        const corps = document.body;
        const ancienOverflow = corps.style.overflow;
        const ancienPadding = corps.style.paddingRight;
        const largeurBarre = window.innerWidth - document.documentElement.clientWidth;

        corps.style.overflow = 'hidden';
        if (largeurBarre > 0) corps.style.paddingRight = `${largeurBarre}px`; // évite le saut de mise en page

        const surTouche = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                surFermer();
                return;
            }
            if (e.key !== 'Tab' || !refBoite.current) return;

            const elements = [...refBoite.current.querySelectorAll(FOCALISABLES)].filter((el) => el.offsetParent !== null);
            if (elements.length === 0) {
                e.preventDefault();
                return;
            }
            const premier = elements[0];
            const dernier = elements[elements.length - 1];
            if (e.shiftKey && document.activeElement === premier) {
                e.preventDefault();
                dernier.focus();
            } else if (!e.shiftKey && document.activeElement === dernier) {
                e.preventDefault();
                premier.focus();
            }
        };
        document.addEventListener('keydown', surTouche, true);

        const minuteur = setTimeout(() => {
            const cible = refBoite.current?.querySelector('[data-autofocus]') ?? refBoite.current;
            cible?.focus({ preventScroll: true });
        }, 60);

        return () => {
            clearTimeout(minuteur);
            document.removeEventListener('keydown', surTouche, true);
            corps.style.overflow = ancienOverflow;
            corps.style.paddingRight = ancienPadding;
            if (precedent instanceof HTMLElement) precedent.focus({ preventScroll: true });
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}

/** Navigation au clavier dans une liste (flèches, Début, Fin, Entrée). */
export function useNavigationListe(nombre, surValider) {
    const [actif, setActif] = useState(0);

    useEffect(() => {
        setActif((i) => Math.min(i, Math.max(nombre - 1, 0)));
    }, [nombre]);

    const surTouche = (e) => {
        if (nombre === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActif((i) => (i + 1) % nombre);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActif((i) => (i - 1 + nombre) % nombre);
        } else if (e.key === 'Home') {
            e.preventDefault();
            setActif(0);
        } else if (e.key === 'End') {
            e.preventDefault();
            setActif(nombre - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            surValider(actif);
        }
    };

    return { actif, setActif, surTouche };
}
