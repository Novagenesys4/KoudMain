import { animate, useInView, useReducedMotion } from 'motion/react';
import { useEffect, useLayoutEffect, useRef } from 'react';
import { montant } from '../../lib/format';
import { EASE } from './Reveal';

/**
 * Nombre qui « compte » jusqu'à sa valeur : la première fois quand il entre dans l'écran,
 * puis à chaque changement de valeur (le solde du porte-monnaie qui monte après un dépôt).
 * `depart` : valeur de départ du comptage (0 par défaut). Si `depart === value`, le nombre s'affiche tel quel, sans animation.
 * Le texte est écrit directement dans le DOM à chaque image : pas de rendu React inutile.
 */
export function AnimatedNumber({ value, depart = 0, duration = 1.4, format = montant, className }) {
    const ref = useRef(null);
    const visible = useInView(ref, { once: true, amount: 0.6 });
    const reduit = useReducedMotion();
    const actuel = useRef(depart); // par défaut on part de 0 ; avec `depart`, on compte depuis l'ancienne valeur (le solde avant un dépôt)

    useLayoutEffect(() => {
        ref.current.textContent = format(actuel.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!visible) return undefined;
        if (reduit) {
            actuel.current = value;
            ref.current.textContent = format(value);
            return undefined;
        }
        const controle = animate(actuel.current, value, {
            duration,
            ease: EASE,
            onUpdate: (v) => {
                actuel.current = v;
                if (ref.current) ref.current.textContent = format(v);
            },
        });
        return () => controle.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visible, value]);

    return <span ref={ref} className={className} />;
}
