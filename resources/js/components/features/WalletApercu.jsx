import { AnimatePresence, motion, useInView, useReducedMotion } from 'motion/react';
import { ArrowDownLeft, ArrowUpRight, Info, Lock, RotateCcw, Wallet } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatedNumber } from '../motion/AnimatedNumber';
import { EASE } from '../motion/Reveal';
import { Tilt } from '../motion/Tilt';
import { Tabs } from '../ui/Tabs';
import { SEQUESTRE_DEMO, SOLDE_DEMO, TRANSACTIONS_DEMO } from '../../data/demo';
import { cn } from '../../lib/cn';
import { DEVISE, fcfa, montant } from '../../lib/format';

/** Durée (en secondes) pendant laquelle chaque onglet reste affiché avant de passer au suivant. */
const DUREE_ONGLET = 3.6;

const FILTRES = [
    { cle: 'tout', libelle: 'Tout' },
    { cle: 'entrees', libelle: 'Entrées' },
    { cle: 'sorties', libelle: 'Sorties' },
];

/** Petite courbe d'évolution du solde : le trait se dessine, puis suit chaque changement. */
function Courbe({ solde }) {
    const chemin = useMemo(() => {
        const points = [18, 22, 20, 31, 27, 35, Math.max(solde / 1000, 1)];
        const min = Math.min(...points);
        const max = Math.max(...points);
        const largeur = 120;
        const hauteur = 34;
        return points
            .map((v, i) => {
                const x = (i / (points.length - 1)) * largeur;
                const y = hauteur - 3 - ((v - min) / (max - min || 1)) * (hauteur - 6);
                return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
            })
            .join(' ');
    }, [solde]);

    return (
        <svg viewBox="0 0 120 34" className="h-8 w-28 overflow-visible" fill="none" aria-hidden="true">
            <motion.path d={chemin} stroke="var(--on-panel-accent)" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" initial={{ pathLength: 0 }} animate={{ pathLength: 1, d: chemin }} transition={{ duration: 1.4, ease: EASE }} />
        </svg>
    );
}

function Ligne({ transaction, indice }) {
    const { type, libelle, detail, montant: valeur, statut } = transaction;
    const entree = valeur > 0;
    const Icone = statut === 'sequestre' ? Lock : type === 'remboursement' ? RotateCcw : entree ? ArrowDownLeft : ArrowUpRight;
    const teinte = statut === 'sequestre' ? 'var(--amber-tint)' : entree ? 'var(--teal-tint)' : 'var(--deep)';
    const couleur = statut === 'sequestre' ? 'text-accent' : entree ? 'text-teal' : 'text-ink';

    return (
        <motion.li
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0, transition: { duration: 0.45, ease: EASE, delay: Math.min(indice, 6) * 0.06 } }}
            className="flex items-center gap-3 py-3"
        >
            <span className={cn('grid size-10 shrink-0 place-items-center rounded-xl', couleur)} style={{ background: teinte }}>
                <Icone className="size-4" aria-hidden="true" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium leading-tight text-ink">{libelle}</span>
                <span className="mt-0.5 block truncate text-xs text-soft">{detail}</span>
            </span>
            <span className="text-right">
                <span className={cn('block text-sm font-semibold tabular-nums', entree ? 'text-teal' : 'text-ink')}>
                    {entree ? '+' : '−'}
                    {montant(Math.abs(valeur))}
                    <span className="ml-1 text-xs font-normal text-soft">{DEVISE}</span>
                </span>
                {statut === 'sequestre' && <span className="mt-0.5 block text-xs font-medium text-accent">Bloqué</span>}
            </span>
        </motion.li>
    );
}

/**
 * Aperçu du porte-monnaie pour l'accueil : une VIDÉO, pas un outil.
 *  - aucune action possible (ni dépôt, ni retrait, ni clic sur l'historique) ;
 *  - les onglets « Tout / Entrées / Sorties » défilent seuls, une barre de lecture montre le temps qui reste ;
 *  - le défilement s'arrête hors de l'écran et n'existe pas si l'utilisateur préfère moins d'animations.
 * DÉMONSTRATION : les montants sont fictifs.
 */
export function WalletApercu({ solde = SOLDE_DEMO, sequestre = SEQUESTRE_DEMO, transactions = TRANSACTIONS_DEMO, className }) {
    const racine = useRef(null);
    const visible = useInView(racine, { amount: 0.35 });
    const reduit = useReducedMotion();
    const [indice, setIndice] = useState(0);
    const [cycle, setCycle] = useState(0);
    const filtre = FILTRES[indice].cle;

    useEffect(() => {
        if (!visible || reduit) return undefined;
        const minuteur = setTimeout(() => {
            setIndice((i) => (i + 1) % FILTRES.length);
            setCycle((c) => c + 1);
        }, DUREE_ONGLET * 1000);
        return () => clearTimeout(minuteur);
    }, [visible, reduit, indice]);

    const visibles = transactions.filter((t) => (filtre === 'tout' ? true : filtre === 'entrees' ? t.montant > 0 : t.montant < 0));

    return (
        <div ref={racine} className={className}>
            <Tilt max={6} className="rounded-2xl bg-panel p-5 text-on-panel shadow-lift sm:p-6">
                {/* Filet intérieur : donne du relief à l'aplat sans dégradé ni lueur. */}
                <span aria-hidden="true" className="pointer-events-none absolute inset-0 rounded-[inherit] ring-1 ring-inset ring-white/10" />

                <div className="relative flex min-h-[13rem] flex-col justify-between gap-7" style={{ transform: 'translateZ(20px)' }}>
                    <div className="flex items-start justify-between">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <Wallet className="size-4 text-on-panel-accent" aria-hidden="true" />
                            Porte-monnaie
                        </p>
                        <span className="rounded-full border border-white/15 px-2.5 py-1 text-xs text-on-panel-soft">FCFA</span>
                    </div>

                    <div>
                        <span className="text-sm text-on-panel-soft">Solde disponible</span>
                        <p className="mt-1 flex items-baseline gap-2 text-[2.5rem] font-semibold leading-none tracking-tight sm:text-[2.9rem]">
                            <AnimatedNumber value={solde} />
                            <span className="text-base font-medium text-on-panel-soft">{DEVISE}</span>
                        </p>
                    </div>

                    <div className="flex items-end justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-1.5 text-xs text-on-panel-soft">
                                <Lock className="size-3.5" aria-hidden="true" />
                                En séquestre
                            </p>
                            <p className="mt-0.5 text-sm font-medium tabular-nums">{fcfa(sequestre)}</p>
                        </div>
                        <Courbe solde={solde} />
                    </div>
                </div>
            </Tilt>

            {/* Historique : lecture seule, les onglets tournent tout seuls. */}
            <div className="mt-6 overflow-hidden rounded-2xl border border-line bg-surface shadow-soft" role="group" aria-label="Aperçu de l'historique des mouvements">
                <div className="p-4 sm:p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold">Mouvements</h3>
                        <Tabs items={FILTRES} valeur={filtre} onChange={() => {}} etiquette="Filtre de l'aperçu" compact passif />
                    </div>
                    <div className="min-h-[16.5rem]">
                        <AnimatePresence mode="wait" initial={false}>
                            <motion.ul key={filtre} exit={{ opacity: 0, transition: { duration: 0.18 } }} className="mt-2 divide-y divide-line">
                                {visibles.map((t, i) => (
                                    <Ligne key={t.id} transaction={t} indice={i} />
                                ))}
                            </motion.ul>
                        </AnimatePresence>
                    </div>
                </div>

                {/* Barre de lecture, comme sur une vidéo : elle se remplit puis l'onglet suivant s'affiche. */}
                {!reduit && (
                    <div className="h-0.5 bg-line" aria-hidden="true">
                        <motion.div key={`${cycle}-${visible}`} className="h-full origin-left bg-accent" initial={{ scaleX: 0 }} animate={{ scaleX: visible ? 1 : 0 }} transition={{ duration: visible ? DUREE_ONGLET : 0, ease: 'linear' }} />
                    </div>
                )}
            </div>

            <p className="mt-4 flex items-start gap-2 text-[0.8125rem] text-faint">
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>Aperçu illustratif : les montants sont fictifs. Votre vrai porte-monnaie s'ouvre dans votre espace.</span>
            </p>
        </div>
    );
}
