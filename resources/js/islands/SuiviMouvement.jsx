import { animate, AnimatePresence, motion, useInView, useReducedMotion } from 'motion/react';
import { ArrowDownLeft, ArrowUpRight, Check, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EASE } from '../components/motion/Reveal';
import { cn } from '../lib/cn';
import { fcfa, montant as formaterMontant } from '../lib/format';

/**
 * Suivi d'une recharge ou d'un retrait qui vient d'avoir lieu : les chiffres comptent jusqu'à leur valeur (comme l'« Animated Number »
 * de motion-primitives, déclenché par useInView) et les étapes s'allument à mesure que la transaction avance.
 * Le solde de la bannière compte, lui, de l'ancien au nouveau solde (île SoldeAnime).
 */
const ETAPES = {
    recharge: ['Demande envoyée', "Confirmation de l'opérateur", 'Wallet crédité'],
    retrait: ['Demande envoyée', 'Solde débité', 'Virement sous 24 à 48 h'],
};

export default function SuiviMouvement({ type = 'recharge', montant = 0, avant = 0, apres = 0, moyen = '', simulation = false }) {
    const racine = useRef(null);
    const visible = useInView(racine, { once: true, amount: 0.5 });
    const reduit = useReducedMotion();
    const [progression, setProgression] = useState(reduit ? 1 : 0);
    const [ferme, setFerme] = useState(false);
    const recharge = type === 'recharge';
    const etapes = ETAPES[recharge ? 'recharge' : 'retrait'];

    useEffect(() => {
        if (!visible || reduit) return undefined;
        const controle = animate(0, 1, { duration: 2.4, ease: EASE, onUpdate: setProgression });
        return () => controle.stop();
    }, [visible, reduit]);

    const valeur = montant * progression;
    const soldeCourant = avant + (apres - avant) * progression;
    const fini = progression >= 0.999;
    // Une étape s'allume quand la progression franchit son seuil : 0 %, 45 %, 100 %. La dernière d'un retrait reste « en attente » (24 à 48 h).
    const seuils = [0, 0.45, 1];

    return (
        <AnimatePresence>
            {!ferme && (
                <motion.section
                    ref={racine}
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, height: 0, marginTop: 0 }}
                    transition={{ duration: 0.45, ease: EASE }}
                    className="overflow-hidden rounded-2xl border border-line bg-surface p-5 shadow-soft sm:p-6"
                    aria-label={recharge ? 'Suivi de votre recharge' : 'Suivi de votre retrait'}
                >
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className={cn('grid size-11 shrink-0 place-items-center rounded-xl', recharge ? 'bg-teal-tint text-teal' : 'bg-amber-tint text-accent')}>
                                {recharge ? <ArrowDownLeft className="size-5" aria-hidden="true" /> : <ArrowUpRight className="size-5" aria-hidden="true" />}
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-faint">{recharge ? 'Recharge' : 'Retrait'}{moyen ? ` · ${moyen}` : ''}</p>
                                <p className="truncate text-sm text-soft">{simulation ? 'Simulation : aucun argent réel ne circule.' : fini ? (recharge ? 'Votre wallet est à jour.' : 'Demande transmise, le virement suit.') : 'En cours…'}</p>
                            </div>
                        </div>
                        <button type="button" onClick={() => setFerme(true)} className="grid size-8 shrink-0 cursor-pointer place-items-center rounded-lg text-faint transition-colors hover:bg-deep hover:text-ink" aria-label="Fermer le suivi">
                            <X className="size-4" aria-hidden="true" />
                        </button>
                    </div>

                    <div className="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                        <div>
                            <p className="font-serif text-[clamp(2.2rem,5vw,3.2rem)] font-medium leading-none tracking-[-0.04em] tabular-nums">
                                <span aria-hidden="true">
                                    {recharge ? '+ ' : '− '}
                                    {formaterMontant(valeur)}
                                </span>
                                <span className="sr-only">{fcfa(montant)}</span>
                                <span className="ml-2 font-sans text-base font-medium tracking-normal text-soft" aria-hidden="true">FCFA</span>
                            </p>
                            <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-deep" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(progression * 100)} aria-label="Avancement">
                                <div className={cn('h-full origin-left rounded-full', recharge ? 'bg-teal' : 'bg-accent')} style={{ transform: `scaleX(${progression})` }} />
                            </div>
                        </div>

                        <div className="rounded-xl bg-deep px-4 py-3">
                            <p className="text-xs text-faint">Solde du wallet</p>
                            <p className="mt-1 flex flex-wrap items-baseline gap-x-2 text-sm text-soft tabular-nums">
                                <span className="line-through decoration-faint/60">{formaterMontant(avant)}</span>
                                <span aria-hidden="true">→</span>
                                <strong className="text-lg font-semibold text-ink">{formaterMontant(soldeCourant)}</strong>
                                <span className="text-xs">FCFA</span>
                            </p>
                        </div>
                    </div>

                    <ol className="mt-5 grid gap-2.5 sm:grid-cols-3" aria-label="Étapes">
                        {etapes.map((libelle, i) => {
                            const derniereEnAttente = !recharge && i === 2;
                            const atteinte = progression >= seuils[i] - 0.001 && (i === 0 || progression > 0);
                            const faite = atteinte && !derniereEnAttente && (i < 2 || fini);
                            return (
                                <li key={libelle} className={cn('flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors duration-500', atteinte ? 'border-transparent bg-deep text-ink' : 'border-line text-faint')}>
                                    <span className={cn('grid size-5 shrink-0 place-items-center rounded-full text-[0.625rem] font-semibold transition-colors duration-500', faite ? (recharge ? 'bg-teal text-white' : 'bg-accent text-white') : 'bg-line text-soft')}>
                                        {faite ? <Check className="size-3" strokeWidth={3} aria-hidden="true" /> : i + 1}
                                    </span>
                                    {libelle}
                                </li>
                            );
                        })}
                    </ol>
                </motion.section>
            )}
        </AnimatePresence>
    );
}
