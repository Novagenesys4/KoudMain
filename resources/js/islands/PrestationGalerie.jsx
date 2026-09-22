import { AnimatePresence, motion } from 'motion/react';
import { ChevronLeft, ChevronRight, Expand, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { EASE } from '../components/motion/Reveal';
import { cn } from '../lib/cn';
import { useDialogue } from '../lib/hooks';

/** Photo en grand : Échap ferme, flèches gauche / droite changent de photo, le focus reste dans la boîte. */
function Agrandissement({ photos, index, titre, surChanger, surFermer }) {
    const boite = useRef(null);
    const total = photos.length;
    useDialogue(boite, surFermer);

    const aller = useCallback((sens) => surChanger((index + sens + total) % total), [index, total, surChanger]);

    useEffect(() => {
        const surTouche = (e) => {
            if (e.key === 'ArrowRight') aller(1);
            else if (e.key === 'ArrowLeft') aller(-1);
        };
        document.addEventListener('keydown', surTouche);
        return () => document.removeEventListener('keydown', surTouche);
    }, [aller]);

    const bouton = 'absolute z-10 grid size-11 cursor-pointer place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';

    return createPortal(
        <motion.div
            ref={boite}
            role="dialog"
            aria-modal="true"
            aria-label={`Photos : ${titre}`}
            tabIndex={-1}
            className="fixed inset-0 z-[100] grid place-items-center bg-black/85 p-4 outline-none sm:p-8"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            onClick={surFermer}
        >
            <button type="button" data-autofocus onClick={surFermer} aria-label="Fermer" className={cn(bouton, 'right-4 top-4 sm:right-6 sm:top-6')}>
                <X className="size-5" aria-hidden="true" />
            </button>

            {total > 1 && (
                <>
                    <button type="button" onClick={(e) => { e.stopPropagation(); aller(-1); }} aria-label="Photo précédente" className={cn(bouton, 'left-3 top-1/2 -translate-y-1/2 sm:left-6')}>
                        <ChevronLeft className="size-5" aria-hidden="true" />
                    </button>
                    <button type="button" onClick={(e) => { e.stopPropagation(); aller(1); }} aria-label="Photo suivante" className={cn(bouton, 'right-3 top-1/2 -translate-y-1/2 sm:right-6')}>
                        <ChevronRight className="size-5" aria-hidden="true" />
                    </button>
                </>
            )}

            <motion.img
                key={index}
                src={photos[index].url}
                alt={`${titre}, photo ${index + 1} sur ${total}`}
                onClick={(e) => e.stopPropagation()}
                className="max-h-[86vh] max-w-full rounded-xl object-contain shadow-lift"
                initial={{ opacity: 0, scale: 0.98 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ duration: 0.25, ease: EASE }}
            />

            {total > 1 && (
                <p className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-black/50 px-3 py-1 text-sm text-white" aria-hidden="true">
                    {index + 1} / {total}
                </p>
            )}
        </motion.div>,
        document.body,
    );
}

/**
 * Galerie de la page d'une prestation : grande photo (fondu entre les photos), vignettes dont l'anneau glisse
 * de l'une à l'autre (layoutId), et agrandissement plein écran. `photos` : [{ url, largeur, hauteur }].
 */
export default function PrestationGalerie({ photos = [], titre = '' }) {
    const [actif, setActif] = useState(0);
    const [ouvert, setOuvert] = useState(false);

    if (photos.length === 0) return null;
    const total = photos.length;

    return (
        <div>
            <button
                type="button"
                onClick={() => setOuvert(true)}
                aria-label={`Agrandir la photo ${actif + 1} sur ${total}`}
                className="group relative block aspect-[4/3] w-full cursor-zoom-in overflow-hidden rounded-3xl border border-line bg-deep"
            >
                <AnimatePresence initial={false}>
                    <motion.img
                        key={actif}
                        src={photos[actif].url}
                        alt=""
                        decoding="async"
                        className="absolute inset-0 size-full object-cover"
                        initial={{ opacity: 0, scale: 1.02 }}
                        animate={{ opacity: 1, scale: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.4, ease: EASE }}
                    />
                </AnimatePresence>
                <span className="absolute bottom-3 right-3 grid size-9 place-items-center rounded-xl bg-black/45 text-white opacity-0 transition-opacity duration-300 group-hover:opacity-100 group-focus-visible:opacity-100" aria-hidden="true">
                    <Expand className="size-4" />
                </span>
            </button>

            {total > 1 && (
                <ul className="mt-3 flex gap-2.5 overflow-x-auto pb-1" aria-label="Photos de la prestation">
                    {photos.map((photo, i) => (
                        <li key={photo.url} className="shrink-0">
                            <button type="button" onClick={() => setActif(i)} aria-label={`Voir la photo ${i + 1}`} aria-current={i === actif} className="relative block h-16 w-[5.25rem] cursor-pointer overflow-hidden rounded-xl bg-deep">
                                <img src={photo.url} alt="" loading="lazy" decoding="async" className={cn('size-full object-cover transition-opacity duration-300', i === actif ? 'opacity-100' : 'opacity-70 hover:opacity-100')} />
                                {i === actif && <motion.span layoutId="galerie-actif" className="absolute inset-0 rounded-xl ring-2 ring-inset ring-accent" transition={{ type: 'spring', bounce: 0.15, duration: 0.4 }} />}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <AnimatePresence>{ouvert && <Agrandissement photos={photos} index={actif} titre={titre} surChanger={setActif} surFermer={() => setOuvert(false)} />}</AnimatePresence>
        </div>
    );
}
