import { motion, useMotionValue, useReducedMotion, useSpring } from 'motion/react';

/** L'élément est légèrement attiré par le curseur quand il s'en approche (effet « magnétique »). */
export function Magnetic({ force = 0.3, className, children }) {
    const reduit = useReducedMotion();
    const x = useMotionValue(0);
    const y = useMotionValue(0);
    const sx = useSpring(x, { stiffness: 220, damping: 16, mass: 0.4 });
    const sy = useSpring(y, { stiffness: 220, damping: 16, mass: 0.4 });

    const approcher = (e) => {
        if (reduit || e.pointerType === 'touch') return;
        const b = e.currentTarget.getBoundingClientRect();
        x.set((e.clientX - (b.left + b.width / 2)) * force);
        y.set((e.clientY - (b.top + b.height / 2)) * force);
    };
    const relacher = () => {
        x.set(0);
        y.set(0);
    };

    return (
        <motion.div className={className} style={{ x: sx, y: sy }} onPointerMove={approcher} onPointerLeave={relacher}>
            {children}
        </motion.div>
    );
}
