import { AnimatePresence, motion, useDragControls } from 'motion/react';
import { CalendarDays, Clock, Info, Lock, MapPin, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { EASE } from '../motion/Reveal';
import { Avatar } from '../ui/Avatar';
import { Coche } from '../ui/Coche';
import { CRENEAUX, creneauLibre, prochainsJours } from '../../data/demo';
import { ecouter, evenements } from '../../lib/bus';
import { cn } from '../../lib/cn';
import { DEVISE, fcfa, montant } from '../../lib/format';
import { useDialogue, useMediaQuery } from '../../lib/hooks';

const PARTICULES = Array.from({ length: 16 }, (_, i) => {
    const angle = (i / 16) * Math.PI * 2;
    const distance = 70 + (i % 3) * 22;
    return { x: Math.cos(angle) * distance, y: Math.sin(angle) * distance, couleur: ['var(--amber)', 'var(--teal)', 'var(--rose)'][i % 3], taille: 6 + (i % 3) * 2 };
});

/** Cercle qui se trace, coche qui se dessine, gerbe de particules : la confirmation. */
function Succes({ prestation, jour, heure, surTerminer }) {
    return (
        <motion.div key="succes" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: 0.3 }} className="flex flex-1 flex-col items-center justify-center px-8 py-10 text-center">
            <div className="relative grid size-24 place-items-center">
                {PARTICULES.map((p, i) => (
                    <motion.span
                        key={i}
                        aria-hidden="true"
                        className="absolute rounded-full"
                        style={{ width: p.taille, height: p.taille, background: p.couleur }}
                        initial={{ x: 0, y: 0, opacity: 1, scale: 0.4 }}
                        animate={{ x: p.x, y: p.y, opacity: 0, scale: 1 }}
                        transition={{ duration: 0.95, ease: EASE, delay: 0.25 }}
                    />
                ))}
                <svg viewBox="0 0 100 100" className="absolute inset-0 size-full -rotate-90" fill="none" aria-hidden="true">
                    <motion.circle cx="50" cy="50" r="44" stroke="var(--teal)" strokeWidth="4" strokeLinecap="round" initial={{ pathLength: 0 }} animate={{ pathLength: 1 }} transition={{ duration: 0.7, ease: EASE }} />
                </svg>
                <motion.span className="grid size-16 place-items-center rounded-full bg-teal text-paper" initial={{ scale: 0 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 260, damping: 16, delay: 0.3 }}>
                    <Coche taille={30} epaisseur={2.75} delai={0.55} />
                </motion.span>
            </div>

            <motion.h2 initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.5, duration: 0.7, ease: EASE }} className="mt-7 text-2xl font-semibold">
                Demande envoyée
            </motion.h2>
            <motion.p initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.62, duration: 0.7, ease: EASE }} className="mt-3 max-w-sm text-soft">
                {prestation.prestataire} a reçu votre demande pour le <strong className="font-semibold text-ink">{jour.long}</strong> à <strong className="font-semibold text-ink">{heure}</strong>. Vous serez prévenu dès qu'elle est acceptée.
            </motion.p>
            <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.8 }} className="mt-6 inline-flex items-center gap-2 rounded-full bg-amber-tint px-4 py-2 text-[0.8125rem] font-medium text-ink">
                <Lock className="size-3.5" aria-hidden="true" />
                {fcfa(prestation.prix)} resteront en séquestre
            </motion.p>
            <motion.button initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.9 }} type="button" onClick={surTerminer} data-autofocus className="btn btn-plein mt-8">
                <span>Terminer</span>
            </motion.button>
            <p className="mt-6 flex items-start gap-2 text-left text-[0.8125rem] text-faint">
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Démonstration : aucune commande n'a été enregistrée. Le paiement réel arrive avec le lot 3.
            </p>
        </motion.div>
    );
}

/**
 * Tiroir « Commande rapide » : panneau latéral (feuille du bas sur mobile) avec choix du jour
 * et du créneau, récapitulatif animé et confirmation fluide.
 * Accessible : boîte de dialogue modale, Échap, focus piégé, focus rendu à la fermeture.
 */
export function OrderDrawer({ prestation, surFermer }) {
    const bureau = useMediaQuery('(min-width: 640px)');
    const refPanneau = useRef(null);
    const glisser = useDragControls();
    const jours = useMemo(() => prochainsJours(8), []);
    const [cleJour, setCleJour] = useState(null);
    const [heure, setHeure] = useState(null);
    const [precisions, setPrecisions] = useState('');
    const [etat, setEtat] = useState('saisie'); // saisie | envoi | ok
    const jour = jours.find((j) => j.cle === cleJour);
    const pret = Boolean(jour && heure);
    const progression = ((jour ? 1 : 0) + (heure ? 1 : 0)) / 2;
    const titreId = `commande-${prestation.id}`;

    useDialogue(refPanneau, surFermer);

    const choisirJour = (cle) => {
        setCleJour(cle);
        // Si le créneau déjà choisi est complet ce jour-là, on l'oublie.
        if (heure && !creneauLibre(prestation.id, cle, heure)) setHeure(null);
    };

    const confirmer = () => {
        if (!pret || etat !== 'saisie') return;
        setEtat('envoi');
        setTimeout(() => setEtat('ok'), 1300);
    };

    const entree = bureau ? { x: '100%' } : { y: '100%' };
    const repos = bureau ? { x: 0 } : { y: 0 };

    return createPortal(
        <motion.div className="fixed inset-0 z-[90]" initial={{ opacity: 1 }} animate={{ opacity: 1 }} exit={{ opacity: 1 }}>
            <motion.div aria-hidden="true" className="absolute inset-0 bg-black/45" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.35 }} onClick={surFermer} />

            <motion.aside
                ref={refPanneau}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titreId}
                tabIndex={-1}
                initial={entree}
                animate={{ ...repos, transition: { type: 'spring', stiffness: 260, damping: 32 } }}
                exit={{ ...entree, transition: { duration: 0.35, ease: [0.32, 0.72, 0, 1] } }}
                drag={bureau ? false : 'y'}
                dragControls={glisser}
                dragListener={false}
                dragConstraints={{ top: 0, bottom: 0 }}
                dragElastic={{ top: 0, bottom: 0.6 }}
                onDragEnd={(_, info) => {
                    if (info.offset.y > 120 || info.velocity.y > 600) surFermer();
                }}
                className="absolute bottom-0 right-0 flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-3xl border border-line bg-surface shadow-lift outline-none sm:top-0 sm:h-full sm:max-h-none sm:max-w-[28rem] sm:rounded-l-3xl sm:rounded-tr-none"
            >
                {/* Poignée (mobile) : on tire vers le bas pour fermer */}
                <div className="flex justify-center pt-3 sm:hidden" onPointerDown={(e) => glisser.start(e)} style={{ touchAction: 'none' }} aria-hidden="true">
                    <span className="h-1.5 w-12 rounded-full bg-line" />
                </div>

                <div className="flex items-start justify-between gap-4 px-6 pb-4 pt-5 sm:px-7 sm:pt-7">
                    <div>
                        <p className="etiquette">Commande rapide</p>
                        <h2 id={titreId} className="mt-1.5 text-2xl">
                            {etat === 'ok' ? 'Merci !' : 'Choisissez votre créneau'}
                        </h2>
                    </div>
                    <button type="button" onClick={surFermer} aria-label="Fermer" className="grid size-9 shrink-0 cursor-pointer place-items-center rounded-lg border border-line text-soft transition-colors hover:border-accent hover:text-ink active:scale-95">
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </div>

                <div className="mx-6 sm:mx-7" role="progressbar" aria-label="Progression de la commande" aria-valuemin={0} aria-valuemax={100} aria-valuenow={etat === 'ok' ? 100 : Math.round(progression * 100)}>
                    <div className="progres">
                        <motion.i initial={false} animate={{ scaleX: etat === 'ok' ? 1 : progression }} transition={{ duration: 0.6, ease: EASE }} style={{ transition: 'none' }} />
                    </div>
                </div>

                <AnimatePresence mode="wait" initial={false}>
                    {etat === 'ok' ? (
                        <Succes key="ok" prestation={prestation} jour={jour} heure={heure} surTerminer={surFermer} />
                    ) : (
                        <motion.div key="saisie" className="flex min-h-0 flex-1 flex-col" exit={{ opacity: 0, y: -10 }} transition={{ duration: 0.2 }}>
                            <div className="flex-1 overflow-y-auto overscroll-contain px-6 py-5 sm:px-7">
                                {/* Résumé de la prestation */}
                                <div className="flex items-center gap-3 rounded-2xl border border-line bg-deep p-3.5">
                                    <Avatar nom={prestation.prestataire} teinte={prestation.teinte} className="size-11 text-sm" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[0.9375rem] font-semibold leading-tight">{prestation.titre}</p>
                                        <p className="mt-1 flex flex-wrap items-center gap-x-3 text-[0.8125rem] text-soft">
                                            <span>{prestation.prestataire}</span>
                                            <span className="inline-flex items-center gap-1">
                                                <MapPin className="size-3.5" aria-hidden="true" />
                                                {prestation.quartier}
                                            </span>
                                        </p>
                                    </div>
                                    <p className="text-right leading-none">
                                        <span className="text-lg font-semibold">{montant(prestation.prix)}</span>
                                        <span className="mt-1 block text-[0.6875rem] text-soft">{DEVISE}</span>
                                    </p>
                                </div>

                                {/* Jour */}
                                <p className="mt-7 flex items-center gap-2 text-sm font-medium">
                                    <CalendarDays className="size-4" aria-hidden="true" />
                                    Quel jour ?
                                </p>
                                <div role="radiogroup" aria-label="Jour" className="-mx-6 mt-3 flex snap-x scroll-px-6 gap-2 overflow-x-auto px-6 pb-2 [scrollbar-width:none] sm:-mx-7 sm:scroll-px-7 sm:px-7">
                                    {jours.map((j) => {
                                        const actif = j.cle === cleJour;
                                        return (
                                            <button
                                                key={j.cle}
                                                type="button"
                                                role="radio"
                                                aria-checked={actif}
                                                aria-label={j.long}
                                                onClick={() => choisirJour(j.cle)}
                                                className={cn('relative grid w-16 shrink-0 snap-start cursor-pointer justify-items-center gap-0.5 rounded-xl border px-2 py-2.5 transition-colors duration-300 active:scale-[0.98]', actif ? 'border-transparent text-paper' : 'border-line bg-surface text-ink hover:border-accent')}
                                            >
                                                {actif && <motion.span layoutId="jour-actif" className="absolute inset-0 rounded-xl bg-ink" transition={{ type: 'spring', bounce: 0.2, duration: 0.5 }} />}
                                                <span className="relative text-xs opacity-80">{j.court}</span>
                                                <span className="relative text-xl font-semibold leading-none">{j.numero}</span>
                                                <span className="relative text-[0.6875rem] opacity-80">{j.mois}</span>
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* Heure */}
                                <p className="mt-6 flex items-center gap-2 text-sm font-medium">
                                    <Clock className="size-4" aria-hidden="true" />
                                    Quelle heure ?
                                </p>
                                {!jour ? (
                                    <p className="mt-3 rounded-xl border border-dashed border-line px-4 py-4 text-center text-sm text-soft">Choisissez d'abord un jour pour voir les créneaux libres.</p>
                                ) : (
                                    <motion.div key={jour.cle} initial="cache" animate="visible" variants={{ cache: {}, visible: { transition: { staggerChildren: 0.035 } } }} className="mt-3 grid gap-4">
                                        {Object.entries(CRENEAUX).map(([groupe, heures]) => (
                                            <div key={groupe} role="radiogroup" aria-label={groupe}>
                                                <p className="mb-2 text-[0.8125rem] text-faint">{groupe}</p>
                                                <div className="grid grid-cols-4 gap-2">
                                                    {heures.map((h) => {
                                                        const libre = creneauLibre(prestation.id, jour.cle, h);
                                                        const actif = heure === h;
                                                        return (
                                                            <motion.button
                                                                key={h}
                                                                type="button"
                                                                role="radio"
                                                                aria-checked={actif}
                                                                aria-disabled={!libre}
                                                                disabled={!libre}
                                                                onClick={() => setHeure(h)}
                                                                variants={{ cache: { opacity: 0, y: 8 }, visible: { opacity: 1, y: 0 } }}
                                                                whileTap={libre ? { scale: 0.96 } : undefined}
                                                                className={cn('relative rounded-lg border py-2 text-sm font-medium tabular-nums transition-colors duration-300', libre ? 'cursor-pointer border-line bg-surface text-ink hover:border-accent' : 'cursor-not-allowed border-transparent bg-deep text-faint line-through', actif && 'border-transparent text-on-amber')}
                                                            >
                                                                {actif && <motion.span layoutId="creneau-actif" className="absolute inset-0 rounded-lg bg-amber" transition={{ type: 'spring', bounce: 0.2, duration: 0.5 }} />}
                                                                <span className="relative">{h}</span>
                                                                {!libre && <span className="sr-only"> (complet)</span>}
                                                            </motion.button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </motion.div>
                                )}

                                {/* Précisions */}
                                <div className="champ mt-7">
                                    <label htmlFor="precisions-commande">Précisions pour {prestation.prestataire.split(' ')[0]} (facultatif)</label>
                                    <div className="champ-saisie">
                                        <input id="precisions-commande" type="text" value={precisions} onChange={(e) => setPrecisions(e.target.value)} placeholder="Repère, étage, consignes…" maxLength={140} autoComplete="off" />
                                    </div>
                                </div>

                                {/* Récapitulatif */}
                                <AnimatePresence initial={false}>
                                    {pret && (
                                        <motion.div initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.45, ease: EASE }} className="overflow-hidden">
                                            <div className="mt-6 rounded-2xl bg-amber-tint p-4">
                                                <p className="text-sm font-medium text-soft">Récapitulatif</p>
                                                <p className="mt-1.5 text-base font-semibold leading-snug">
                                                    {jour.long} à {heure}
                                                </p>
                                                <p className="mt-1 text-sm text-soft">
                                                    {prestation.quartier}, {prestation.ville} · {prestation.duree}
                                                </p>
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>

                            {/* Pied : bouton de confirmation, la barre se remplit pendant l'envoi */}
                            <div className="border-t border-line bg-surface px-6 pb-6 pt-4 sm:px-7 sm:pb-7">
                                <button
                                    type="button"
                                    onClick={confirmer}
                                    disabled={!pret || etat !== 'saisie'}
                                    className="relative isolate flex h-12 w-full cursor-pointer items-center justify-center overflow-hidden rounded-xl bg-ink px-6 text-[0.9375rem] font-medium text-paper transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <motion.span aria-hidden="true" className="absolute inset-0 -z-10 origin-left bg-teal" initial={false} animate={{ scaleX: etat === 'envoi' ? 1 : 0 }} transition={{ duration: etat === 'envoi' ? 1.2 : 0, ease: 'easeInOut' }} />
                                    <AnimatePresence mode="wait" initial={false}>
                                        <motion.span key={etat} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }} transition={{ duration: 0.16 }}>
                                            {etat === 'envoi' ? 'Envoi de la demande…' : pret ? `Confirmer · ${fcfa(prestation.prix)}` : 'Choisissez un jour et une heure'}
                                        </motion.span>
                                    </AnimatePresence>
                                </button>
                                <p className="mt-3 flex items-center justify-center gap-1.5 text-center text-[0.8125rem] text-faint">
                                    <Lock className="size-3.5" aria-hidden="true" />
                                    Votre argent reste en séquestre jusqu'à la fin de la prestation.
                                </p>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>
            </motion.aside>
        </motion.div>,
        document.body,
    );
}

/** À monter une seule fois par page : ouvre le tiroir quand une carte émet l'événement « commande ». */
export function OrderDrawerHote() {
    const [prestation, setPrestation] = useState(null);

    useEffect(() => ecouter(evenements.commande, (d) => d.prestation && setPrestation(d.prestation)), []);

    return <AnimatePresence>{prestation && <OrderDrawer key={prestation.id} prestation={prestation} surFermer={() => setPrestation(null)} />}</AnimatePresence>;
}
