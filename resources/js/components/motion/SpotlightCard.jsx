import { motion } from 'motion/react';
import { cn } from '../../lib/cn';

/**
 * Carte « projecteur » : au survol, une lueur ambre suit le curseur et la bordure s'éclaire
 * là où il passe. La carte se soulève légèrement. Les effets visuels sont dans la CSS (.spot).
 */
export function SpotlightCard({ as = 'div', lift = 3, className, children, ...reste }) {
    const Balise = motion[as] ?? motion.div;

    const suivre = (e) => {
        const boite = e.currentTarget.getBoundingClientRect();
        e.currentTarget.style.setProperty('--mx', `${e.clientX - boite.left}px`);
        e.currentTarget.style.setProperty('--my', `${e.clientY - boite.top}px`);
    };

    return (
        <Balise
            className={cn('spot transition-shadow duration-500 hover:shadow-lift', className)}
            onPointerMove={suivre}
            whileHover={lift ? { y: -lift } : undefined}
            transition={{ type: 'spring', stiffness: 320, damping: 26 }}
            {...reste}
        >
            <span className="spot-lueur" aria-hidden="true" />
            <span className="spot-bord" aria-hidden="true" />
            {children}
        </Balise>
    );
}
