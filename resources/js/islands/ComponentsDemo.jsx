import { AnimatePresence, motion } from 'motion/react';
import { CreditCard, LayoutGrid, Palette, PanelRightOpen } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ServiceCard, ServiceCardSkeleton } from '../components/features/ServiceCard';
import { WalletApercu } from '../components/features/WalletApercu';
import { EASE, Reveal } from '../components/motion/Reveal';
import { TextEffect } from '../components/motion/TextEffect';
import { Skeleton } from '../components/ui/Skeleton';
import { Tabs } from '../components/ui/Tabs';
import { PRESTATIONS_DEMO } from '../data/demo';
import { ouvrirCommande } from '../lib/bus';
import { useTheme } from '../lib/hooks';

const ONGLETS = [
    { cle: 'cartes', libelle: 'Carte de prestation', icone: <LayoutGrid className="size-4" aria-hidden="true" /> },
    { cle: 'wallet', libelle: 'Porte-monnaie', icone: <CreditCard className="size-4" aria-hidden="true" /> },
    { cle: 'commande', libelle: 'Commande rapide', icone: <PanelRightOpen className="size-4" aria-hidden="true" /> },
    { cle: 'design', libelle: 'Design', icone: <Palette className="size-4" aria-hidden="true" /> },
];

const NUANCES = [
    ['Papier', '--paper', '#F5F2EC'],
    ['Papier profond', '--deep', '#EBE6DC'],
    ['Surface', '--surface', '#FFFDFA'],
    ['Encre', '--ink', '#26241F'],
    ['Terracotta', '--amber', '#B25F38'],
    ['Terracotta foncée', '--accent', '#93482C'],
    ['Terracotta pâle', '--amber-tint', '#F2DED3'],
    ['Sauge', '--teal', '#3D6B5B'],
    ['Sauge pâle', '--teal-tint', '#DEEBE3'],
    ['Prune', '--rose', '#7A5F65'],
    ['Prune pâle', '--rose-tint', '#EEE2E5'],
    ['Filet', '--line', '#DED8CC'],
];

function Cartes() {
    const [chargement, setChargement] = useState(false);

    useEffect(() => {
        if (!chargement) return undefined;
        const id = setTimeout(() => setChargement(false), 2200);
        return () => clearTimeout(id);
    }, [chargement]);

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-4">
                <p className="max-w-xl text-soft">Note, lieu, prix en FCFA et bouton de réservation. Survolez : la lueur suit le curseur. Le bouton passe par un chargement puis une coche avant d'ouvrir le tiroir de commande.</p>
                <button type="button" onClick={() => setChargement(true)} disabled={chargement} className="btn min-h-11 disabled:opacity-60">
                    <span>{chargement ? 'Chargement…' : 'Simuler le chargement'}</span>
                </button>
            </div>
            <AnimatePresence mode="wait" initial={false}>
                <motion.div key={chargement ? 'squelettes' : 'cartes'} className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-busy={chargement} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.35, ease: EASE }}>
                    {chargement ? [0, 1, 2].map((i) => <ServiceCardSkeleton key={i} />) : PRESTATIONS_DEMO.slice(0, 3).map((p) => <ServiceCard key={p.id} prestation={p} />)}
                </motion.div>
            </AnimatePresence>
        </div>
    );
}

function Commande() {
    return (
        <div className="grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <div>
                <p className="max-w-md text-soft">Un panneau qui glisse depuis la droite (feuille du bas sur mobile, que l'on ferme en la tirant). Jour, créneau, précisions, récapitulatif animé, puis confirmation avec coche et particules.</p>
                <ul className="mt-6 grid gap-2 text-sm text-soft">
                    {['Échap ferme, Tab reste dans le panneau', 'Les créneaux complets sont barrés et désactivés', "La pastille de sélection glisse d'un jour à l'autre"].map((t) => (
                        <li key={t} className="flex gap-2">
                            <span className="mt-2 size-1.5 shrink-0 rounded-full bg-amber" aria-hidden="true" />
                            {t}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="grid gap-3">
                {PRESTATIONS_DEMO.slice(0, 4).map((p) => (
                    <button key={p.id} type="button" onClick={() => ouvrirCommande(p)} className="group flex cursor-pointer items-center justify-between gap-4 rounded-2xl border border-line bg-surface p-4 text-left shadow-soft transition-all duration-300 hover:-translate-y-0.5 hover:border-accent hover:shadow-lift active:scale-[0.99]">
                        <span>
                            <span className="block text-[0.9375rem] font-semibold leading-tight">{p.titre}</span>
                            <span className="mt-1 block text-sm text-soft">
                                {p.prestataire} · {p.quartier}
                            </span>
                        </span>
                        <span className="shrink-0 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-paper">Ouvrir</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Lit les valeurs réelles des variables : le code affiché correspond toujours à la pastille, en clair comme en sombre. */
function useValeurs() {
    const { theme } = useTheme();
    const [valeurs, setValeurs] = useState({});

    useEffect(() => {
        const style = getComputedStyle(document.documentElement);
        setValeurs(Object.fromEntries(NUANCES.map(([, variable, hex]) => [variable, (style.getPropertyValue(variable).trim() || hex).toUpperCase()])));
    }, [theme]);

    return valeurs;
}

function Design() {
    const valeurs = useValeurs();

    return (
        <div className="grid gap-12">
            <div>
                <h3 className="text-lg font-semibold">Palette</h3>
                <p className="mt-2 text-sm text-soft">Valeurs du thème actuel : les teintes s'adaptent quand vous basculez entre clair et sombre.</p>
                <ul className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {NUANCES.map(([nom, variable, hex]) => (
                        <li key={variable} className="overflow-hidden rounded-xl border border-line bg-surface">
                            <span className="block h-14" style={{ background: `var(${variable})` }} />
                            <span className="block px-3 py-2.5">
                                <span className="block text-sm font-medium">{nom}</span>
                                <span className="block text-xs text-soft">{valeurs[variable] ?? hex}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            <div className="grid grid-cols-[minmax(0,1fr)] gap-14 lg:grid-cols-2">
                <div>
                    <h3 className="text-lg font-semibold">Boutons</h3>
                    <div className="mt-5 flex flex-wrap gap-3">
                        <button type="button" className="btn btn-plein"><span>Plein</span></button>
                        <button type="button" className="btn"><span>Contour</span></button>
                        <button type="button" className="btn btn-ambre"><span>Terracotta</span></button>
                    </div>
                    <p className="mt-3 text-sm text-soft">Au survol, l'aplat monte depuis le bas. Au clic : léger enfoncement (0,98).</p>

                    <h3 className="mt-10 text-lg font-semibold">Puces</h3>
                    <div className="mt-5 flex flex-wrap items-center gap-3">
                        <span className="puce">Vérifié</span>
                        <span className="puce">Cocody</span>
                                            </div>

                    <h3 className="mt-10 text-lg font-semibold">Messages</h3>
                    <div className="mt-5 grid gap-3">
                        <p className="message">Votre demande a bien été envoyée.</p>
                        <p className="message message-erreur">Certains champs sont à corriger.</p>
                    </div>
                </div>

                <div>
                    <h3 className="text-lg font-semibold">Champs et choix</h3>
                    <div className="mt-5 grid gap-6">
                        <div className="champ">
                            <label htmlFor="demo-email">Adresse e-mail</label>
                            <div className="champ-saisie">
                                <input id="demo-email" type="email" placeholder="vous@exemple.ci" />
                            </div>
                        </div>
                        <fieldset className="seg">
                            <label>
                                <input type="radio" name="demo-role" value="client" defaultChecked />
                                <strong>Client</strong>
                                <span>Je cherche un service</span>
                            </label>
                            <label>
                                <input type="radio" name="demo-role" value="prestataire" />
                                <strong>Prestataire</strong>
                                <span>Je propose mes services</span>
                            </label>
                        </fieldset>
                    </div>

                    <h3 className="mt-10 text-lg font-semibold">Squelettes</h3>
                    <div className="mt-5 grid gap-3 rounded-2xl border border-line bg-surface p-5">
                        <Skeleton className="h-4 w-1/3" />
                        <Skeleton className="h-8 w-3/4" />
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-5/6" />
                    </div>
                </div>
            </div>
        </div>
    );
}

/** Vitrine des composants : montre le kit avec des données fictives. Réservée à la démonstration. */
export default function ComponentsDemo() {
    const [onglet, setOnglet] = useState('cartes');

    return (
        <section className="mx-auto w-full max-w-6xl px-5 pb-8 pt-14 sm:px-8 lg:pt-20">
            <p className="etiquette">Kit d'interface</p>
            <TextEffect as="h1" className="mt-3 max-w-3xl text-[clamp(2rem,4vw,3rem)]" parts={[{ t: 'Les composants, ' }, { t: 'en action.', em: true }]} />
            <Reveal delay={0.2}>
                <p className="mt-4 max-w-2xl text-[1.0625rem] text-soft">Les briques d'interface de KoudMain, avec des données fictives. Les tableaux de bord des prochains lots sont construits avec elles.</p>
            </Reveal>

            <Reveal delay={0.3} className="mt-8">
                <Tabs items={ONGLETS} valeur={onglet} onChange={setOnglet} etiquette="Composants" panneau="panneau-composants" />
            </Reveal>

            <div id="panneau-composants" role="tabpanel" className="mt-10 min-h-[32rem]">
                <AnimatePresence mode="wait" initial={false}>
                    <motion.div key={onglet} initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -10 }} transition={{ duration: 0.35, ease: EASE }}>
                        {onglet === 'cartes' && <Cartes />}
                        {onglet === 'wallet' && (
                            <div className="mx-auto max-w-xl">
                                <WalletApercu />
                            </div>
                        )}
                        {onglet === 'commande' && <Commande />}
                        {onglet === 'design' && <Design />}
                    </motion.div>
                </AnimatePresence>
            </div>
        </section>
    );
}
