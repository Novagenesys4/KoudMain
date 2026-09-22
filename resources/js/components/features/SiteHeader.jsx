import { AnimatePresence, motion, useMotionValueEvent, useScroll, useSpring } from 'motion/react';
import { ArrowRight, ChevronDown, KeyRound, LayoutDashboard, LogOut, Menu, Moon, Sparkles, Sun, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EASE } from '../motion/Reveal';
import { Magnetic } from '../motion/Magnetic';
import { Avatar } from '../ui/Avatar';
import { Logo } from '../ui/Logo';
import { cn } from '../../lib/cn';
import { useTheme } from '../../lib/hooks';
import { OrderDrawerHote } from './OrderDrawer';

/** Interrupteur de thème : un curseur glisse entre le soleil et la lune. */
function BasculeTheme({ className }) {
    const { theme, basculer } = useTheme();
    const sombre = theme === 'dark';
    return (
        <button
            type="button"
            role="switch"
            aria-checked={sombre}
            onClick={(e) => basculer(e.currentTarget)}
            aria-label="Thème sombre"
            className={cn('group flex h-9 cursor-pointer items-center gap-2 rounded-full px-1 text-faint', className)}
        >
            <Sun className={cn('size-3.5 transition-colors', !sombre && 'text-accent')} aria-hidden="true" />
            <span className="relative h-6 w-11 rounded-full border border-line bg-deep transition-colors group-hover:border-edge">
                <motion.span className="absolute left-0.5 top-0.5 size-[1.125rem] rounded-full bg-ink" animate={{ x: sombre ? 20 : 0 }} transition={{ type: 'spring', stiffness: 500, damping: 32 }} />
            </span>
            <Moon className={cn('size-3.5 transition-colors', sombre && 'text-accent')} aria-hidden="true" />
        </button>
    );
}

/** Menu du compte (connecté) : Mon espace, Mes prestations (prestataire), Mon profil, Mot de passe, Se déconnecter (formulaire POST protégé par jeton CSRF). */
function MenuCompte({ prenom, urls, csrf }) {
    const [ouvert, setOuvert] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!ouvert) return undefined;
        const dehors = (e) => ref.current && !ref.current.contains(e.target) && setOuvert(false);
        const echap = (e) => e.key === 'Escape' && setOuvert(false);
        document.addEventListener('pointerdown', dehors);
        document.addEventListener('keydown', echap);
        return () => {
            document.removeEventListener('pointerdown', dehors);
            document.removeEventListener('keydown', echap);
        };
    }, [ouvert]);

    const ligne = 'flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-deep';

    return (
        <div className="relative" ref={ref}>
            <button type="button" onClick={() => setOuvert((o) => !o)} aria-expanded={ouvert} aria-haspopup="menu" aria-label={`Menu du compte de ${prenom}`} className="flex h-10 cursor-pointer items-center gap-1.5 rounded-xl border border-line py-1 pl-1 pr-2.5 transition-colors hover:border-accent active:scale-[0.98]">
                <Avatar nom={prenom} className="size-8 rounded-lg text-sm" />
                <ChevronDown className={cn('size-4 text-soft transition-transform duration-300', ouvert && 'rotate-180')} aria-hidden="true" />
            </button>
            <AnimatePresence>
                {ouvert && (
                    <motion.div role="menu" initial={{ opacity: 0, y: -8, scale: 0.96 }} animate={{ opacity: 1, y: 0, scale: 1 }} exit={{ opacity: 0, y: -6, scale: 0.97 }} transition={{ duration: 0.2, ease: EASE }} style={{ transformOrigin: 'top right' }} className="absolute right-0 top-full z-10 mt-3 w-60 rounded-2xl border border-line bg-surface p-1.5 shadow-lift">
                        <p className="px-3 pb-1.5 pt-2 text-xs text-soft">Connecté : {prenom}</p>
                        <a role="menuitem" href={urls.espace} className={ligne}>
                            <LayoutDashboard className="size-4 text-soft" aria-hidden="true" /> Mon espace
                        </a>
                        {urls.prestations && (
                            <a role="menuitem" href={urls.prestations} className={ligne}>
                                <Sparkles className="size-4 text-soft" aria-hidden="true" /> Mes prestations
                            </a>
                        )}
                        <a role="menuitem" href={urls.profil} className={ligne}>
                            <UserRound className="size-4 text-soft" aria-hidden="true" /> Mon profil
                        </a>
                        <a role="menuitem" href={urls.motdepasse} className={ligne}>
                            <KeyRound className="size-4 text-soft" aria-hidden="true" /> Mot de passe
                        </a>
                        <form method="POST" action={urls.deconnexion}>
                            <input type="hidden" name="_token" value={csrf} />
                            <button role="menuitem" type="submit" className={ligne}>
                                <LogOut className="size-4 text-soft" aria-hidden="true" /> Se déconnecter
                            </button>
                        </form>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

/**
 * En-tête flottant en verre dépoli.
 *  - se resserre et gagne en relief dès qu'on défile ; une fine barre ambre montre la progression de la page ;
 *  - la pastille de survol GLISSE d'un lien à l'autre (layoutId) ;
 *  - le bouton de thème dessine un cercle qui s'étend ;
 *  - sur mobile : menu déroulant avec liens qui apparaissent en cascade.
 * Monte aussi le tiroir de commande (rendu dans <body>, hors de l'en-tête).
 */
export default function SiteHeader({ liens = [], connecte = false, prenom = '', urls, csrf = '' }) {
    const [compact, setCompact] = useState(false);
    const [survol, setSurvol] = useState(null);
    const [menu, setMenu] = useState(false);
    const { scrollY, scrollYProgress } = useScroll();
    const progression = useSpring(scrollYProgress, { stiffness: 120, damping: 30, mass: 0.4 });

    useMotionValueEvent(scrollY, 'change', (v) => setCompact(v > 24));

    useEffect(() => {
        if (!menu) return undefined;
        const echap = (e) => e.key === 'Escape' && setMenu(false);
        document.addEventListener('keydown', echap);
        return () => document.removeEventListener('keydown', echap);
    }, [menu]);

    return (
        <>
            <a className="lien-evitement" href="#contenu">
                Aller au contenu principal
            </a>
            <div className={cn('relative border-b bg-paper/85 backdrop-blur-xl transition-[border-color,box-shadow] duration-500', compact ? 'border-line shadow-soft' : 'border-transparent')}>
                <motion.div className="relative mx-auto w-full max-w-6xl" initial={{ y: -16, opacity: 0 }} animate={{ y: 0, opacity: 1 }} transition={{ duration: 0.7, ease: EASE }}>
                    <div className="flex h-16 items-center justify-between gap-4 px-5 sm:px-8">
                        <a href={urls.accueil} aria-label="KoudMain, accueil" className="shrink-0">
                            <Logo className="text-[1.65rem]" />
                        </a>

                        <nav aria-label="Navigation principale" className="hidden items-center lg:flex" onPointerLeave={() => setSurvol(null)}>
                            {liens.map((lien) => (
                                <a key={lien.href} href={lien.href} onPointerEnter={() => setSurvol(lien.href)} onFocus={() => setSurvol(lien.href)} onBlur={() => setSurvol(null)} className="relative whitespace-nowrap rounded-lg px-4 py-2 text-sm text-soft transition-colors hover:text-ink focus-visible:text-ink">
                                    {survol === lien.href && <motion.span layoutId="nav-survol" className="absolute inset-0 rounded-lg bg-deep" transition={{ type: 'spring', bounce: 0.15, duration: 0.4 }} />}
                                    <span className="relative">{lien.libelle}</span>
                                </a>
                            ))}
                        </nav>

                        <div className="flex items-center gap-1.5 sm:gap-3">
                            <BasculeTheme className="hidden sm:flex" />

                            {connecte ? (
                                <MenuCompte prenom={prenom} urls={urls} csrf={csrf} />
                            ) : (
                                <>
                                    <a href={urls.connexion} className="hidden whitespace-nowrap rounded-lg px-3 py-2 text-sm text-soft transition-colors hover:text-ink sm:block">
                                        Connexion
                                    </a>
                                    <Magnetic className="hidden sm:block">
                                        <a href={urls.inscription} className="btn btn-plein min-h-10 whitespace-nowrap px-4 text-sm">
                                            <span>S'inscrire</span>
                                            <ArrowRight className="fleche size-4" aria-hidden="true" />
                                        </a>
                                    </Magnetic>
                                </>
                            )}

                            <button type="button" onClick={() => setMenu((m) => !m)} aria-expanded={menu} aria-controls="menu-mobile" aria-label={menu ? 'Fermer le menu' : 'Ouvrir le menu'} className="grid size-10 cursor-pointer place-items-center rounded-xl border border-line text-ink transition-colors hover:border-accent active:scale-95 lg:hidden">
                                <AnimatePresence mode="wait" initial={false}>
                                    <motion.span key={menu ? 'x' : 'm'} initial={{ rotate: -80, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: 80, opacity: 0 }} transition={{ duration: 0.18 }}>
                                        {menu ? <X className="size-[1.15rem]" aria-hidden="true" /> : <Menu className="size-[1.15rem]" aria-hidden="true" />}
                                    </motion.span>
                                </AnimatePresence>
                            </button>
                        </div>
                    </div>

                    <AnimatePresence>
                        {menu && (
                            <motion.div
                                id="menu-mobile"
                                initial={{ opacity: 0, y: -10, scale: 0.98 }}
                                animate={{ opacity: 1, y: 0, scale: 1 }}
                                exit={{ opacity: 0, y: -8, scale: 0.98 }}
                                transition={{ duration: 0.3, ease: EASE }}
                                style={{ transformOrigin: 'top center' }}
                                className="absolute inset-x-3 top-full z-10 mt-2 overflow-hidden rounded-2xl border border-line bg-surface p-2 shadow-lift sm:inset-x-6 lg:hidden"
                            >
                                <motion.nav aria-label="Navigation mobile" initial="cache" animate="visible" variants={{ cache: {}, visible: { transition: { staggerChildren: 0.05, delayChildren: 0.05 } } }} className="grid">
                                    {[...liens, ...(connecte ? [{ libelle: 'Mon espace', href: urls.espace }, ...(urls.prestations ? [{ libelle: 'Mes prestations', href: urls.prestations }] : []), { libelle: 'Mon profil', href: urls.profil }] : [{ libelle: 'Connexion', href: urls.connexion }])].map((lien) => (
                                        <motion.a key={lien.href} variants={{ cache: { opacity: 0, x: -12 }, visible: { opacity: 1, x: 0 } }} href={lien.href} onClick={() => setMenu(false)} className="rounded-xl px-4 py-3 text-lg font-medium transition-colors hover:bg-deep">
                                            {lien.libelle}
                                        </motion.a>
                                    ))}
                                </motion.nav>
                                {!connecte && (
                                    <a href={urls.inscription} className="btn btn-plein mt-3 w-full">
                                        <span>S'inscrire</span>
                                    </a>
                                )}
                                <div className="mt-2 flex items-center justify-between border-t border-line px-3 pb-1 pt-3">
                                    <span className="text-sm text-soft">Thème sombre</span>
                                    <BasculeTheme />
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </motion.div>

                {/* Progression de lecture : un fil terracotta sur le bord bas de l'en-tête */}
                <span aria-hidden="true" className={cn('pointer-events-none absolute inset-x-0 -bottom-px h-[2px] overflow-hidden transition-opacity duration-300', compact ? 'opacity-100' : 'opacity-0')}>
                    <motion.span className="block h-full origin-left bg-amber" style={{ scaleX: progression }} />
                </span>
            </div>

            <OrderDrawerHote />
        </>
    );
}
