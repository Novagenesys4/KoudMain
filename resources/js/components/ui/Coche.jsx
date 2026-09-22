import { motion } from 'motion/react';
import { EASE } from '../motion/Reveal';

/** Coche qui se dessine (le trait se trace de gauche à droite). */
export function Coche({ taille = 18, epaisseur = 2.5, delai = 0, className }) {
    return (
        <svg viewBox="0 0 24 24" width={taille} height={taille} fill="none" stroke="currentColor" strokeWidth={epaisseur} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <motion.path d="M5 12.5l4.5 4.5L19 7.5" initial={{ pathLength: 0 }} animate={{ pathLength: 1 }} transition={{ duration: 0.45, ease: EASE, delay: delai }} />
        </svg>
    );
}

/** Anneau de chargement. */
export function Chargement({ taille = 18, className }) {
    return (
        <svg viewBox="0 0 24 24" width={taille} height={taille} fill="none" aria-hidden="true" className={className}>
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2.5" />
            <motion.path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" animate={{ rotate: 360 }} transition={{ duration: 0.8, ease: 'linear', repeat: Infinity }} style={{ originX: '12px', originY: '12px' }} />
        </svg>
    );
}
