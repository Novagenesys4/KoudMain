import { motion } from 'motion/react';

/** Courbe « expo out » : départ vif, arrivée très douce. Même courbe que --ease dans la CSS. */
export const EASE = [0.22, 1, 0.36, 1];

/** Fait apparaître son contenu (fondu + montée + léger flou) quand il entre dans l'écran. */
/*
 * Le flou d'entrée (blur) est facultatif : un élément qui garde `filter` en style après l'animation
 * empêche le verre dépoli (backdrop-filter) de ses enfants de fonctionner. On ne l'active donc que
 * sur du texte simple.
 */
export function Reveal({ as = 'div', delay = 0, y = 24, blur = 0, amount = 0.2, className, children, ...reste }) {
    const Balise = motion[as] ?? motion.div;
    return (
        <Balise
            className={className}
            initial={{ opacity: 0, y, ...(blur ? { filter: `blur(${blur}px)` } : {}) }}
            whileInView={{ opacity: 1, y: 0, ...(blur ? { filter: 'blur(0px)' } : {}) }}
            viewport={{ once: true, amount, margin: '0px 0px -6% 0px' }}
            transition={{ duration: 0.9, ease: EASE, delay }}
            {...reste}
        >
            {children}
        </Balise>
    );
}

/** Conteneur : ses <StaggerItem> apparaissent l'un après l'autre. */
export function Stagger({ as = 'div', gap = 0.08, delay = 0, amount = 0.15, className, children, ...reste }) {
    const Balise = motion[as] ?? motion.div;
    return (
        <Balise
            className={className}
            initial="cache"
            whileInView="visible"
            viewport={{ once: true, amount, margin: '0px 0px -6% 0px' }}
            variants={{ cache: {}, visible: { transition: { staggerChildren: gap, delayChildren: delay } } }}
            {...reste}
        >
            {children}
        </Balise>
    );
}

export function StaggerItem({ as = 'div', y = 28, blur = 0, className, children, ...reste }) {
    const Balise = motion[as] ?? motion.div;
    return (
        <Balise
            className={className}
            variants={{
                cache: { opacity: 0, y, ...(blur ? { filter: `blur(${blur}px)` } : {}) },
                visible: { opacity: 1, y: 0, ...(blur ? { filter: 'blur(0px)' } : {}), transition: { duration: 0.85, ease: EASE } },
            }}
            {...reste}
        >
            {children}
        </Balise>
    );
}
