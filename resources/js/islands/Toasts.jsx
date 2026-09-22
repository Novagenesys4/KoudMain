import { AnimatePresence, motion } from 'motion/react';
import { X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EASE } from '../components/motion/Reveal';
import { iconeNotification } from '../lib/iconesNotif';
import { sur } from '../lib/tempsReel';

const DUREE = 7000;
const MAX = 3;

/** Le pavé d'un toast : icône, titre, texte ; un clic ouvre la page concernée. */
function Toast({ toast, surFermer }) {
    const Icone = iconeNotification(toast.icone);
    const minuteur = useRef(null);

    const armer = useCallback(() => {
        clearTimeout(minuteur.current);
        minuteur.current = setTimeout(() => surFermer(toast.cle), DUREE);
    }, [surFermer, toast.cle]);

    useEffect(() => {
        armer();
        return () => clearTimeout(minuteur.current);
    }, [armer]);

    return (
        <motion.li
            layout
            className="toast"
            initial={{ opacity: 0, y: 16, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, x: 40, transition: { duration: 0.2 } }}
            transition={{ duration: 0.3, ease: EASE }}
            onMouseEnter={() => clearTimeout(minuteur.current)}
            onMouseLeave={armer}
        >
            <a href={toast.url} className="toast-corps">
                <span className="toast-icone"><Icone className="size-4" aria-hidden="true" /></span>
                <span className="min-w-0 flex-1">
                    <span className="toast-titre">{toast.titre}</span>
                    <span className="toast-texte">{toast.texte}</span>
                </span>
            </a>
            <button type="button" className="toast-fermer" aria-label="Fermer" onClick={() => surFermer(toast.cle)}>
                <X className="size-3.5" aria-hidden="true" />
            </button>
        </motion.li>
    );
}

/**
 * Les petites alertes qui apparaissent en bas de l'écran quand quelque chose arrive : une notification, un nouveau message.
 * Un message de la discussion que vous avez sous les yeux ne fait pas de toast (il s'affiche déjà dans le fil).
 */
export default function Toasts() {
    const [toasts, setToasts] = useState([]);
    const compteur = useRef(0);
    const moi = Number(document.body.dataset.utilisateur) || 0;

    const fermer = useCallback((cle) => setToasts((liste) => liste.filter((t) => t.cle !== cle)), []);
    const ajouter = useCallback((toast) => {
        compteur.current += 1;
        setToasts((liste) => [...liste, { ...toast, cle: compteur.current }].slice(-MAX));
    }, []);

    useEffect(() => {
        const arret1 = sur('notification', (n) => ajouter({ titre: n.titre, texte: n.texte, url: n.url, icone: n.icone }));

        const arret2 = sur('message', (d) => {
            if (d.message.expediteur_id === moi) return; // mon propre message, affiché dans un autre onglet
            const surLaDiscussion = window.location.pathname === `/messages/${d.commande_id}` && document.visibilityState === 'visible';
            if (surLaDiscussion) return;
            ajouter({ titre: `Message de ${d.de}`, texte: d.message.contenu, url: `/messages/${d.commande_id}`, icone: 'message' });
        });

        return () => {
            arret1();
            arret2();
        };
    }, [ajouter, moi]);

    return (
        <ul className="toasts" role="status" aria-live="polite" aria-relevant="additions">
            <AnimatePresence initial={false}>
                {toasts.map((t) => (
                    <Toast key={t.cle} toast={t} surFermer={fermer} />
                ))}
            </AnimatePresence>
        </ul>
    );
}
