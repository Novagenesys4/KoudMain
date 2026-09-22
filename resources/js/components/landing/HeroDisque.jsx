import { AnimatePresence, motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react';
import { BadgeCheck, Lock, MapPin } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EASE } from '../motion/Reveal';

/** Couche qui suit très légèrement le curseur : plus la profondeur est grande, plus elle bouge. */
function Couche({ mx, my, profondeur, className, children }) {
    const x = useTransform(mx, [-0.5, 0.5], [-profondeur, profondeur]);
    const y = useTransform(my, [-0.5, 0.5], [-profondeur, profondeur]);
    return (
        <motion.div style={{ x, y }} className={className}>
            {children}
        </motion.div>
    );
}

/**
 * Composition du hero : un grand disque sombre cerclé de fins anneaux, des cartes qui flottent autour.
 * Les chiffres et les quartiers viennent de la base de données ; rien n'est inventé.
 * Décoratif : masqué aux lecteurs d'écran (le titre et le texte de la page disent la même chose).
 */
export function HeroDisque({ quartiers = [], nbPrestataires = 0, photo = null }) {
    const reduit = useReducedMotion();
    const [i, setI] = useState(0);
    const px = useMotionValue(0);
    const py = useMotionValue(0);
    const mx = useSpring(px, { stiffness: 80, damping: 20 });
    const my = useSpring(py, { stiffness: 80, damping: 20 });

    useEffect(() => {
        if (reduit || quartiers.length < 2) return undefined;
        const id = setInterval(() => setI((n) => (n + 1) % quartiers.length), 2400);
        return () => clearInterval(id);
    }, [reduit, quartiers.length]);

    const bouger = (e) => {
        if (reduit || e.pointerType === 'touch') return;
        const b = e.currentTarget.getBoundingClientRect();
        px.set((e.clientX - b.left) / b.width - 0.5);
        py.set((e.clientY - b.top) / b.height - 0.5);
    };

    const entree = (delai) => ({ initial: { opacity: 0, y: 20, scale: 0.96 }, animate: { opacity: 1, y: 0, scale: 1 }, transition: { duration: 0.8, ease: EASE, delay: delai } });

    return (
        <div className="relative mx-auto aspect-square w-full max-w-[31rem] select-none" onPointerMove={bouger} onPointerLeave={() => { px.set(0); py.set(0); }} aria-hidden="true">
            {/* Anneaux : le premier tourne lentement, avec un petit repère terracotta */}
            <motion.div className="absolute inset-0 rounded-full border border-line" initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 1.1, ease: EASE }}>
                <div className="anim-tourne absolute inset-0 rounded-full" style={{ animationDuration: '48s' }}>
                    <span className="absolute left-1/2 top-0 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-amber" />
                </div>
            </motion.div>
            <motion.div className="absolute inset-[5%] rounded-full border border-line/70" initial={{ opacity: 0, scale: 0.92 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 1.1, ease: EASE, delay: 0.1 }} />

            {/* Le disque */}
            <Couche mx={mx} my={my} profondeur={5} className="absolute inset-[9%]">
                <motion.div {...entree(0.15)} className="disque relative flex size-full flex-col justify-center overflow-hidden rounded-full p-[14%] text-on-panel">
                    <p className="font-mono text-[0.6875rem] font-medium uppercase tracking-[0.14em] text-on-panel-accent">À proximité</p>
                    <p className="mt-3 font-serif text-[clamp(2rem,4.6vw,3.1rem)] font-semibold leading-[1.02] tracking-[-0.055em]">
                        Des gens
                        <span className="block font-medium italic text-on-panel-accent">de confiance.</span>
                    </p>
                    <p className="mt-7 flex items-center gap-2 font-mono text-[0.6875rem] uppercase tracking-[0.12em] text-on-panel-soft">
                        <MapPin className="size-3.5 text-on-panel-accent" /> Côte d'Ivoire
                    </p>
                </motion.div>
            </Couche>

            {/* Photo réelle d'une prestation, en médaillon */}
            {photo && (
                <Couche mx={mx} my={my} profondeur={14} className="absolute bottom-[8%] right-[4%] w-[30%]">
                    <motion.div {...entree(0.5)} className="aspect-square overflow-hidden rounded-full border-[6px] border-paper bg-deep shadow-lift">
                        <img src={photo} alt="" className="size-full object-cover" />
                    </motion.div>
                </Couche>
            )}

            {/* Prestataires vérifiés */}
            <Couche mx={mx} my={my} profondeur={16} className="absolute right-0 top-[14%] z-10 hidden sm:block">
                <motion.div {...entree(0.4)} className="flex items-center gap-3 rounded-2xl border border-line bg-surface/95 p-3 pr-4 shadow-lift backdrop-blur">
                    <span className="grid size-9 place-items-center rounded-xl bg-teal-tint text-teal">
                        <BadgeCheck className="size-[1.125rem]" />
                    </span>
                    <span className="block leading-tight">
                        <span className="block text-sm font-semibold text-ink">{nbPrestataires > 0 ? `${nbPrestataires} prestataire${nbPrestataires > 1 ? 's' : ''}` : 'Prestataires vérifiés'}</span>
                        <span className="block text-xs text-soft">{nbPrestataires > 0 ? 'vérifié' + (nbPrestataires > 1 ? 's' : '') + ' avant leur mise en ligne' : 'validés avant leur mise en ligne'}</span>
                    </span>
                </motion.div>
            </Couche>

            {/* Séquestre */}
            <Couche mx={mx} my={my} profondeur={10} className="absolute bottom-[3%] left-[2%] z-10">
                <motion.div {...entree(0.6)} className="flex items-center gap-3 rounded-2xl border border-line bg-surface/95 p-3 pr-4 shadow-lift backdrop-blur">
                    <span className="grid size-9 place-items-center rounded-xl bg-amber-tint text-accent">
                        <Lock className="size-4" />
                    </span>
                    <span className="block leading-tight">
                        <span className="block text-sm font-semibold text-ink">Paiement en séquestre</span>
                        <span className="block text-xs text-soft">réglé à la fin de la prestation</span>
                    </span>
                </motion.div>
            </Couche>

            {/* Quartiers desservis, en boucle */}
            {quartiers.length > 0 && (
                <Couche mx={mx} my={my} profondeur={20} className="absolute left-[8%] top-[6%] z-10 hidden sm:block">
                    <motion.div {...entree(0.75)} className="flex items-center gap-2 rounded-full border border-line bg-surface/95 py-2 pl-3 pr-4 shadow-lift backdrop-blur">
                        <span className="anim-onde relative size-1.5 rounded-full bg-teal text-teal" />
                        <span className="relative block h-4 w-32 overflow-hidden text-xs text-soft">
                            <AnimatePresence mode="wait" initial={false}>
                                <motion.span key={i} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -10 }} transition={{ duration: 0.25, ease: EASE }} className="absolute inset-0 truncate leading-4">
                                    Disponible à <b className="font-semibold text-ink">{quartiers[i % quartiers.length]}</b>
                                </motion.span>
                            </AnimatePresence>
                        </span>
                    </motion.div>
                </Couche>
            )}
        </div>
    );
}
