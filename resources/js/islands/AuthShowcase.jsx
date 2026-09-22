import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { BadgeCheck, Lock, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EASE } from '../components/motion/Reveal';
import { TextEffect } from '../components/motion/TextEffect';
import { Logo } from '../components/ui/Logo';

const TITRES = {
    connexion: [{ t: 'Retrouvez vos prestataires ' }, { t: 'de confiance.', em: true }],
    inscription: [{ t: 'Pour la coiffure, la plomberie, la laverie… ' }, { t: 'et bien plus.', em: true }],
};

const NOEUDS = [
    { icone: User, libelle: 'Vous', legende: 'Vous payez depuis votre porte-monnaie.' },
    { icone: Lock, libelle: 'Séquestre', legende: "L'argent est mis de côté, à l'abri." },
    { icone: BadgeCheck, libelle: 'Prestataire', legende: 'Il est réglé une fois la prestation terminée.' },
];

/** Le trajet de l'argent, en boucle : une bague ambre glisse d'un nœud à l'autre (layoutId). */
function TrajetArgent() {
    const reduit = useReducedMotion();
    const [etape, setEtape] = useState(0);

    useEffect(() => {
        if (reduit) return undefined;
        const id = setInterval(() => setEtape((e) => (e + 1) % NOEUDS.length), 2800);
        return () => clearInterval(id);
    }, [reduit]);

    return (
        <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-5" role="img" aria-label="Le trajet de l'argent : vous, le séquestre, puis le prestataire">
            <div className="relative flex items-start justify-between px-2">
                <span aria-hidden="true" className="absolute left-12 right-12 top-6 h-px bg-white/15" />
                <motion.span aria-hidden="true" className="absolute left-12 right-12 top-6 h-px origin-left" style={{ background: 'var(--on-panel-accent)' }} animate={{ scaleX: etape / 2 }} transition={{ duration: 0.9, ease: EASE }} />
                {NOEUDS.map(({ icone: Icone, libelle }, i) => (
                    <div key={libelle} className="relative z-10 flex w-24 flex-col items-center gap-2.5 text-center">
                        <span className={`relative grid size-12 place-items-center rounded-full border transition-colors duration-500 ${i <= etape ? 'border-transparent bg-on-panel-accent text-panel' : 'border-white/15 bg-white/[0.06] text-on-panel-soft'}`}>
                            {etape === i && <motion.span layoutId="piece" className="absolute -inset-1 rounded-full border-2 border-on-panel-accent" transition={{ type: 'spring', bounce: 0.25, duration: 0.7 }} />}
                            <Icone className="size-5" aria-hidden="true" />
                        </span>
                        <span className={`text-xs font-medium transition-colors duration-500 ${i === etape ? 'text-on-panel' : 'text-on-panel-soft'}`}>{libelle}</span>
                    </div>
                ))}
            </div>
            <div className="relative mt-5 h-6 overflow-hidden text-center text-sm text-on-panel-soft" aria-hidden="true">
                <AnimatePresence mode="wait" initial={false}>
                    <motion.p key={etape} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3, ease: EASE }} className="absolute inset-0">
                        {NOEUDS[etape].legende}
                    </motion.p>
                </AnimatePresence>
            </div>
        </div>
    );
}

/**
 * Panneau de marque des pages connexion / inscription. Il reste sombre dans les deux thèmes.
 * Sur mobile il se réduit au logo ; sur grand écran il montre le titre animé et le trajet de l'argent.
 */
export default function AuthShowcase({ variante = 'connexion', accueil = '/' }) {
    return (
        <div className="relative flex h-full flex-col justify-between gap-10">
            <a href={accueil} className="w-fit text-on-panel [--logo-accent:var(--on-panel-accent)]" aria-label="KoudMain, retour à l'accueil">
                <Logo className="text-[1.75rem]" />
            </a>

            <div className="hidden lg:block">
                <p className="etiquette etiquette-trait text-on-panel-accent">Paiement sécurisé par séquestre</p>
                <TextEffect as="p" parts={TITRES[variante] ?? TITRES.connexion} className="mt-6 font-serif text-[2.6rem] font-semibold leading-[1.03] tracking-[-0.055em] text-on-panel xl:text-[3.4rem] [&_em]:font-medium [&_em]:italic [&_em]:text-on-panel-accent" />
                <div className="mt-10 max-w-md">
                    <TrajetArgent />
                </div>
            </div>

            <p className="hidden max-w-sm text-sm text-on-panel-soft lg:block">Le prestataire n'est réglé qu'une fois la prestation terminée : votre argent reste protégé jusque-là.</p>
        </div>
    );
}
