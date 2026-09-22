import { motion, useMotionTemplate, useMotionValue, useReducedMotion, useSpring } from 'motion/react';
import { cn } from '../../lib/cn';

/**
 * Carte 3D : elle pivote vers le curseur et un reflet lumineux suit le pointeur.
 * `className` s'applique à la carte elle-même (celle qui tourne), pas au conteneur.
 */
export function Tilt({ max = 12, perspective = 1000, reflet = true, className, children, ...reste }) {
    const reduit = useReducedMotion();
    const rx = useMotionValue(0);
    const ry = useMotionValue(0);
    const srx = useSpring(rx, { stiffness: 190, damping: 18, mass: 0.6 });
    const sry = useSpring(ry, { stiffness: 190, damping: 18, mass: 0.6 });
    const gx = useMotionValue(50);
    const gy = useMotionValue(50);
    const eclat = useMotionTemplate`radial-gradient(360px circle at ${gx}% ${gy}%, rgb(255 255 255 / 0.14), transparent 62%)`;

    const bouger = (e) => {
        if (reduit || e.pointerType === 'touch') return;
        const b = e.currentTarget.getBoundingClientRect();
        const px = (e.clientX - b.left) / b.width;
        const py = (e.clientY - b.top) / b.height;
        ry.set((px - 0.5) * 2 * max);
        rx.set(-(py - 0.5) * 2 * max);
        gx.set(px * 100);
        gy.set(py * 100);
    };
    const quitter = () => {
        rx.set(0);
        ry.set(0);
        gx.set(50);
        gy.set(50);
    };

    return (
        <div style={{ perspective }} onPointerMove={bouger} onPointerLeave={quitter} {...reste}>
            <motion.div className={cn('relative', className)} style={{ rotateX: srx, rotateY: sry, transformStyle: 'preserve-3d' }}>
                {children}
                {reflet && (
                    <motion.span
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 rounded-[inherit] mix-blend-soft-light"
                        style={{ background: eclat }}
                    />
                )}
            </motion.div>
        </div>
    );
}
