import { AnimatePresence, motion } from 'motion/react';
import { ArrowRight, BadgeCheck, Clock, Heart, MapPin, Star } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SpotlightCard } from '../motion/SpotlightCard';
import { EASE } from '../motion/Reveal';
import { Avatar, TEINTES_FOND } from '../ui/Avatar';
import { Chargement, Coche } from '../ui/Coche';
import { Skeleton } from '../ui/Skeleton';
import { ouvrirCommande } from '../../lib/bus';
import { montant, note as formaterNote, DEVISE } from '../../lib/format';
import { iconePour } from '../../lib/icones';

/**
 * Carte de prestation (compacte).
 *  - survol : la lueur suit le curseur, la bordure s'éclaire ;
 *  - MODE CATALOGUE (`prestation.url` renseignée) : toute la carte mène à la page de la prestation ; la photo
 *    éventuelle illustre le haut de la carte ; le cœur n'apparaît que si `surFavori` est fourni (il renvoie false si le serveur a refusé) ;
 *  - MODE DÉMONSTRATION (sans url, données fictives) : cœur qui rebondit, bouton « Réserver » (chargement → coche
 *    → tiroir de commande simulé).
 * `prestation` : { id, titre, prestataire, metier, categorie?, note (null = nouveau), avis, quartier, ville, prix,
 *                  duree?, verifie, dispo?, teinte, url?, photo?, avatar?, prestataire_url? }
 */
export function ServiceCard({ prestation, surReserver = ouvrirCommande, surFavori, className }) {
    const { titre, prestataire, metier, categorie, note, avis, quartier, prix, duree, verifie, dispo, teinte = 'amber', url, photo, avatar, prestataire_url: urlPrestataire } = prestation;
    const catalogue = Boolean(url);
    const avecCoeur = !catalogue || Boolean(surFavori);
    const Icone = iconePour(categorie ?? metier);
    const [favori, setFavori] = useState(Boolean(prestation.favori));
    const [etat, setEtat] = useState('repos'); // repos -> charge -> ok -> repos
    const minuteurs = useRef([]);

    useEffect(() => () => minuteurs.current.forEach(clearTimeout), []);

    const reserver = () => {
        if (etat !== 'repos') return;
        setEtat('charge');
        const apres = (ms, fn) => minuteurs.current.push(setTimeout(fn, ms));
        apres(650, () => setEtat('ok'));
        apres(1150, () => surReserver(prestation));
        apres(2600, () => setEtat('repos'));
    };

    return (
        <SpotlightCard as="article" className={`group flex h-full flex-col rounded-[var(--radius)] border border-line bg-surface p-4 shadow-soft transition-[transform,box-shadow] duration-500 ease-[var(--ease)] hover:-translate-y-0.5 hover:shadow-lift ${className ?? ''}`}>
            {photo && (
                <div className="relative z-[1] -mx-4 -mt-4 mb-4 aspect-[2/1] overflow-hidden rounded-t-2xl bg-deep">
                    <img src={photo} alt="" loading="lazy" decoding="async" className="size-full object-cover transition-transform duration-700 group-hover:scale-[1.03]" />
                </div>
            )}
            <div className="flex items-start gap-3">
                <span className="relative shrink-0">
                    <Avatar nom={prestataire} teinte={teinte} src={avatar} className="size-11 text-sm" />
                    <span className="absolute -bottom-1 -right-1 grid size-5 place-items-center rounded-full border-2 border-surface" style={{ background: TEINTES_FOND[teinte] }}>
                        <Icone className="size-2.5 text-ink" strokeWidth={2.2} aria-hidden="true" />
                    </span>
                </span>

                <div className="min-w-0 flex-1">
                    <p className="font-mono text-[0.625rem] font-medium uppercase tracking-[0.12em] text-accent">{metier}</p>
                    <h3 className="mt-0.5 line-clamp-2 min-h-[2.6rem] text-[1.0625rem] font-semibold leading-tight">
                        {catalogue ? (
                            // Lien « étiré » : sa zone cliquable couvre toute la carte (le prestataire, au-dessus, reste cliquable).
                            <a href={url} className="after:absolute after:inset-0 after:z-[1] after:rounded-2xl focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-accent">
                                {titre}
                            </a>
                        ) : (
                            titre
                        )}
                    </h3>
                </div>

                {avecCoeur && (
                    <motion.button
                        type="button"
                        aria-pressed={favori}
                        aria-label={favori ? `Retirer ${titre} des favoris` : `Ajouter ${titre} aux favoris`}
                        onClick={async () => {
                            const suivant = !favori;
                            setFavori(suivant); // tout de suite : le cœur ne fait pas attendre
                            // Le serveur a le dernier mot : s'il refuse (réseau, session), le cœur revient à son état.
                            if ((await surFavori?.(prestation, suivant)) === false) setFavori(!suivant);
                        }}
                        whileTap={{ scale: 0.88 }}
                        className="relative z-[2] -mr-1 -mt-1 grid size-8 shrink-0 cursor-pointer place-items-center rounded-lg text-soft transition-colors hover:bg-deep hover:text-ink"
                    >
                        <AnimatePresence>
                            {favori && (
                                <motion.span
                                    aria-hidden="true"
                                    className="absolute inset-0 rounded-lg border-2 border-rose"
                                    initial={{ scale: 0.7, opacity: 0.9 }}
                                    animate={{ scale: 1.5, opacity: 0 }}
                                    exit={{ opacity: 0 }}
                                    transition={{ duration: 0.5, ease: EASE }}
                                />
                            )}
                        </AnimatePresence>
                        <motion.span animate={favori ? { scale: [1, 1.35, 1] } : { scale: 1 }} transition={{ duration: 0.4, ease: EASE }} className="grid place-items-center">
                            <Heart className={favori ? 'size-4 fill-rose text-rose' : 'size-4'} aria-hidden="true" />
                        </motion.span>
                    </motion.button>
                )}
            </div>

            <p className="mt-3 flex items-center gap-1.5 text-sm">
                {catalogue && urlPrestataire ? (
                    <a href={urlPrestataire} className="relative z-[2] rounded-sm hover:text-accent hover:underline">
                        {prestataire}
                    </a>
                ) : (
                    prestataire
                )}
                {verifie && (
                    <>
                        <BadgeCheck className="size-4 text-teal" aria-hidden="true" />
                        <span className="sr-only">prestataire vérifié</span>
                    </>
                )}
            </p>

            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.8125rem] text-soft">
                {note == null ? (
                    <span className="inline-flex items-center gap-1 font-medium text-ink">
                        <Star className="size-3.5 text-soft" aria-hidden="true" />
                        Nouveau
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 font-medium text-ink">
                        <Star className="size-3.5 fill-amber text-amber" aria-hidden="true" />
                        {formaterNote(note)}
                        <span className="font-normal text-soft">({avis})</span>
                        <span className="sr-only">, note sur 5 selon {avis} avis</span>
                    </span>
                )}
                <span className="inline-flex items-center gap-1">
                    <MapPin className="size-3.5" aria-hidden="true" />
                    {quartier}
                </span>
                {duree && (
                    <span className="inline-flex items-center gap-1">
                        <Clock className="size-3.5" aria-hidden="true" />
                        {duree}
                    </span>
                )}
            </p>

            {dispo ? (
                <p className="mb-3 mt-2 inline-flex items-center gap-2 text-xs text-soft">
                    <span className="anim-onde relative size-1.5 rounded-full bg-teal text-teal" aria-hidden="true" />
                    {dispo}
                </p>
            ) : (
                <div className="mb-3" />
            )}

            <div className="mt-auto flex items-end justify-between gap-3 border-t border-line pt-3.5">
                <p className="min-w-0 leading-none">
                    <span className="block text-xs text-faint">à partir de</span>
                    <span className="mt-1 flex items-baseline gap-1">
                        <span className="font-serif text-2xl font-semibold tracking-[-0.05em]">{montant(prix)}</span>
                        <span className="text-xs font-medium text-soft">{DEVISE}</span>
                    </span>
                </p>

                {catalogue ? (
                    <span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-xl bg-ink text-paper transition-transform duration-300 group-hover:translate-x-0.5">
                        <ArrowRight className="size-4" />
                    </span>
                ) : (
                    <motion.button
                        type="button"
                        onClick={reserver}
                        aria-disabled={etat !== 'repos'}
                        whileTap={etat === 'repos' ? { scale: 0.98 } : undefined}
                        aria-label={`Réserver : ${titre}`}
                        className="group/btn relative isolate flex h-10 min-w-[6.75rem] cursor-pointer items-center justify-center overflow-hidden rounded-xl bg-ink px-4 text-sm font-medium text-paper aria-disabled:cursor-default"
                    >
                        <motion.span aria-hidden="true" className="absolute inset-0 -z-10 origin-left bg-teal" initial={false} animate={{ scaleX: etat === 'ok' ? 1 : 0 }} transition={{ duration: 0.4, ease: EASE }} />
                        <AnimatePresence mode="wait" initial={false}>
                            {etat === 'repos' && (
                                <motion.span key="repos" className="flex items-center gap-2" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }} transition={{ duration: 0.16 }}>
                                    Réserver
                                    <ArrowRight className="size-4 transition-transform duration-300 group-hover/btn:translate-x-1" aria-hidden="true" />
                                </motion.span>
                            )}
                            {etat === 'charge' && (
                                <motion.span key="charge" initial={{ opacity: 0, scale: 0.6 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.6 }} transition={{ duration: 0.16 }}>
                                    <Chargement />
                                </motion.span>
                            )}
                            {etat === 'ok' && (
                                <motion.span key="ok" className="flex items-center gap-2" initial={{ opacity: 0, scale: 0.7 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0 }} transition={{ type: 'spring', stiffness: 400, damping: 22 }}>
                                    <Coche />
                                    Prêt
                                </motion.span>
                            )}
                        </AnimatePresence>
                    </motion.button>
                )}
            </div>
            <span className="sr-only" role="status">
                {etat === 'ok' ? 'Ouverture du formulaire de commande' : ''}
            </span>
        </SpotlightCard>
    );
}

/** Squelette : même gabarit que la carte, pour que rien ne bouge quand le contenu arrive. */
export function ServiceCardSkeleton() {
    return (
        <div className="flex h-full flex-col rounded-2xl border border-line bg-surface p-4 shadow-soft" aria-hidden="true">
            <div className="flex items-start gap-3">
                <Skeleton className="size-11 rounded-full" />
                <div className="flex-1">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="mt-2 h-4 w-11/12" />
                    <Skeleton className="mt-1.5 h-4 w-2/3" />
                </div>
            </div>
            <Skeleton className="mt-4 h-4 w-32" />
            <Skeleton className="mt-2 h-3.5 w-44" />
            <Skeleton className="mt-3 h-3 w-24" />
            <div className="mt-auto flex items-end justify-between border-t border-line pt-3.5">
                <Skeleton className="h-8 w-24" />
                <Skeleton className="h-10 w-28 rounded-xl" />
            </div>
        </div>
    );
}
