import { AnimatePresence, motion, useAnimationControls } from 'motion/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EASE } from '../components/motion/Reveal';
import { cn } from '../lib/cn';
import { iconeNotification, ilYA } from '../lib/iconesNotif';
import { envoyerJson, lireJson, sur } from '../lib/tempsReel';

/**
 * La cloche de l'en-tête : le nombre de notifications non lues (à jour en direct) et la liste des dernières.
 * Les notifications sont créées par le serveur ; ici on ne fait que les afficher, les marquer comme lues et suivre celles qui arrivent.
 */
export default function Cloche({ nonLues = 0, base = '/notifications' }) {
    const [nombre, setNombre] = useState(nonLues);
    const [ouvert, setOuvert] = useState(false);
    const [liste, setListe] = useState(null); // null = pas encore chargée
    const secousse = useAnimationControls();
    const conteneur = useRef(null);
    const bouton = useRef(null);

    const charger = useCallback(async () => {
        const { ok, donnees } = await lireJson(`${base}/recentes`);
        if (ok) {
            setListe(donnees.notifications);
            setNombre(donnees.non_lues);
        }
    }, [base]);

    // Suivi en direct : une notification arrive, ou une autre fenêtre en a marqué comme lues.
    useEffect(() => {
        const arret1 = sur('notification', (d) => {
            setNombre(d.non_lues);
            secousse.start({ rotate: [0, -14, 12, -8, 5, 0], transition: { duration: 0.6, ease: EASE } });
            setListe((precedente) => (precedente ? [d, ...precedente.filter((n) => n.id !== d.id)].slice(0, 8) : precedente));
        });
        const arret2 = sur('notifications_lues', (d) => {
            setNombre(d.non_lues);
            setListe((precedente) => precedente?.map((n) => (d.id === null || d.id === n.id ? { ...n, lue: true } : n)) ?? precedente);
        });
        return () => {
            arret1();
            arret2();
        };
    }, [secousse]);

    // Fermeture : clic à l'extérieur, Échap.
    useEffect(() => {
        if (!ouvert) return undefined;
        const dehors = (e) => {
            if (conteneur.current && !conteneur.current.contains(e.target)) setOuvert(false);
        };
        const touche = (e) => {
            if (e.key === 'Escape') {
                setOuvert(false);
                bouton.current?.focus();
            }
        };
        document.addEventListener('pointerdown', dehors);
        document.addEventListener('keydown', touche);
        return () => {
            document.removeEventListener('pointerdown', dehors);
            document.removeEventListener('keydown', touche);
        };
    }, [ouvert]);

    const basculer = () => {
        setOuvert((o) => !o);
        if (!ouvert) charger();
    };

    const ouvrir = async (notification) => {
        await envoyerJson(`${base}/${notification.id}`);
        window.location.assign(notification.url);
    };

    const toutLire = async () => {
        const { ok } = await envoyerJson(`${base}/tout-lire`);
        if (ok) {
            setNombre(0);
            setListe((l) => l?.map((n) => ({ ...n, lue: true })) ?? l);
        }
    };

    return (
        <div ref={conteneur} className="cloche">
            <motion.button
                ref={bouton}
                type="button"
                className="espace-icone"
                aria-label={nombre > 0 ? `Notifications, ${nombre} non lue${nombre > 1 ? 's' : ''}` : 'Notifications'}
                aria-expanded={ouvert}
                aria-haspopup="true"
                title="Notifications"
                onClick={basculer}
                animate={secousse}
            >
                <Bell className="size-5" aria-hidden="true" />
                <AnimatePresence>
                    {nombre > 0 && (
                        <motion.span
                            key="point"
                            className="espace-point"
                            initial={{ scale: 0 }}
                            animate={{ scale: 1 }}
                            exit={{ scale: 0 }}
                            transition={{ type: 'spring', stiffness: 500, damping: 26 }}
                        >
                            {nombre > 99 ? '99+' : nombre}
                        </motion.span>
                    )}
                </AnimatePresence>
            </motion.button>

            <AnimatePresence>
                {ouvert && (
                    <motion.div
                        className="cloche-panneau"
                        role="region"
                        aria-label="Notifications récentes"
                        initial={{ opacity: 0, y: -6, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -4, scale: 0.98 }}
                        transition={{ duration: 0.18, ease: EASE }}
                    >
                        <div className="cloche-entete">
                            <strong>Notifications</strong>
                            {nombre > 0 && (
                                <button type="button" className="cloche-lien" onClick={toutLire}>
                                    <CheckCheck className="size-3.5" aria-hidden="true" /> Tout marquer comme lu
                                </button>
                            )}
                        </div>

                        <div className="cloche-liste" aria-live="polite">
                            {liste === null && <p className="cloche-vide">Chargement…</p>}
                            {liste?.length === 0 && <p className="cloche-vide">Rien pour le moment. Vous serez prévenu ici, en direct.</p>}
                            {liste?.map((n) => {
                                const Icone = iconeNotification(n.icone);
                                return (
                                    <button key={n.id} type="button" className={cn('cloche-item', !n.lue && 'cloche-item-neuve')} onClick={() => ouvrir(n)}>
                                        <span className="cloche-icone"><Icone className="size-4" aria-hidden="true" /></span>
                                        <span className="min-w-0 flex-1">
                                            <span className="cloche-titre">{n.titre}</span>
                                            <span className="cloche-texte">{n.texte}</span>
                                            <span className="cloche-date">{ilYA(n.date)}</span>
                                        </span>
                                        {!n.lue && <span className="cloche-non-lue" aria-label="Non lue" />}
                                    </button>
                                );
                            })}
                        </div>

                        <a href={base} className="cloche-pied">Voir toutes les notifications</a>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
