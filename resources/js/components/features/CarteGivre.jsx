import { animate, motion, useReducedMotion } from 'motion/react';
import { Snowflake } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

/**
 * Gel d'une carte : le givre gagne la carte depuis le coin haut droit (un cercle qui grandit), les cristaux se dessinent
 * du bord vers le centre, la carte se ternit, un flocon apparaît. Au dégel, le givre recule et quelques gouttes tombent.
 *
 *  - `gelee`   : l'état voulu. Au premier affichage, la carte est gelée (ou non) tout de suite, sans animation.
 *  - `enfants` : la face de la carte (elle reste dessous, seulement désaturée).
 * Sans animation (« moins d'animations ») : le givre apparaît et disparaît en fondu.
 */

// Cristaux : des branches qui partent des coins et des bords, dans un repère de 100 × 63 (le format d'une carte).
const BRANCHES = [
    // depuis le coin haut droit
    'M100 0 L74 22 M88 12 L92 26 M88 12 L74 8 M80 18 L84 32 M80 18 L64 16 M74 22 L76 38 M74 22 L58 24',
    // depuis le coin bas gauche
    'M0 63 L24 42 M12 53 L8 40 M12 53 L28 56 M20 46 L16 32 M20 46 L36 48 M24 42 L22 26 M24 42 L40 38',
    // depuis le bord haut
    'M46 0 L52 16 M49 8 L40 12 M49 8 L58 6 M52 16 L46 26 M52 16 L62 22',
    // depuis le bord bas
    'M70 63 L64 48 M67 55 L76 52 M67 55 L58 58 M64 48 L70 40 M64 48 L54 42',
    // depuis le bord gauche
    'M0 22 L16 26 M8 24 L6 14 M8 24 L12 32 M16 26 L26 22 M16 26 L20 36',
    // depuis le bord droit
    'M100 44 L84 40 M92 42 L94 52 M92 42 L88 34 M84 40 L76 46 M84 40 L80 32',
];

const ETINCELLES = [
    { x: '14%', y: '20%', d: 0 },
    { x: '82%', y: '34%', d: 0.4 },
    { x: '58%', y: '78%', d: 0.8 },
    { x: '30%', y: '68%', d: 1.2 },
    { x: '70%', y: '14%', d: 1.6 },
];

export function CarteGivre({ gelee, enfants, className = '' }) {
    const reduit = useReducedMotion();
    const identifiant = useId().replace(/:/g, '');
    const premier = useRef(true);
    const [degel, setDegel] = useState(0); // change à chaque dégel : relance les gouttes
    const scene = useRef(null);

    // Petit frisson quand la carte gèle.
    useEffect(() => {
        if (premier.current) {
            premier.current = false;
            return;
        }
        if (gelee) {
            if (!reduit && scene.current) animate(scene.current, { x: [0, -3, 3, -2, 2, -1, 0] }, { duration: 0.5, delay: 0.55, ease: 'easeOut' });
        } else if (!reduit) {
            setDegel((n) => n + 1);
        }
    }, [gelee, reduit]);

    const duree = reduit ? 0.3 : gelee ? 1.15 : 0.95;
    const ease = gelee ? [0.45, 0.05, 0.25, 1] : [0.5, 0, 0.3, 1];

    return (
        <div className={`relative ${className}`}>
            <motion.div ref={scene} initial={false} animate={{ filter: gelee ? 'saturate(0.45) brightness(1.06)' : 'saturate(1) brightness(1)' }} transition={{ duration: duree, ease }} className="rounded-[1.25rem]">
                {enfants}
            </motion.div>

            <motion.div
                className="carte-glace"
                aria-hidden="true"
                initial={false}
                animate={reduit ? { opacity: gelee ? 1 : 0, clipPath: 'circle(150% at 100% 0%)' } : { opacity: 1, clipPath: gelee ? 'circle(150% at 100% 0%)' : 'circle(0% at 100% 0%)' }}
                transition={{ duration: duree, ease }}
            >
                <div className="carte-glace-teinte" />

                {/* Givre : un bruit fractal converti en points blancs, plus dense vers les bords. */}
                <svg className="carte-glace-givre" viewBox="0 0 100 63" preserveAspectRatio="none">
                    <defs>
                        <filter id={`givre-${identifiant}`} x="0" y="0" width="100%" height="100%">
                            <feTurbulence type="fractalNoise" baseFrequency="2.6" numOctaves="3" seed="7" />
                            <feColorMatrix values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 -13 5.7" />
                        </filter>
                        <radialGradient id={`bord-${identifiant}`} cx="50%" cy="50%" r="72%">
                            <stop offset="48%" stopColor="#000" />
                            <stop offset="100%" stopColor="#fff" />
                        </radialGradient>
                        <mask id={`masque-${identifiant}`}>
                            <rect width="100" height="63" fill={`url(#bord-${identifiant})`} />
                        </mask>
                    </defs>
                    <rect width="100" height="63" filter={`url(#givre-${identifiant})`} mask={`url(#masque-${identifiant})`} opacity="0.95" />
                    {BRANCHES.map((d, i) => (
                        <motion.path
                            key={d}
                            d={d}
                            fill="none"
                            stroke="#f2fbff"
                            strokeWidth="0.32"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            initial={false}
                            animate={{ pathLength: gelee ? 1 : 0, opacity: gelee ? 0.85 : 0 }}
                            transition={{ duration: reduit ? 0.2 : gelee ? 0.9 : 0.4, delay: reduit || !gelee ? 0 : 0.25 + i * 0.09, ease: 'easeOut' }}
                        />
                    ))}
                </svg>

                <div className="carte-glace-bord" />

                {!reduit &&
                    gelee &&
                    ETINCELLES.map((e) => (
                        <motion.span
                            key={e.d}
                            className="absolute size-1.5 rounded-full bg-white"
                            style={{ left: e.x, top: e.y, boxShadow: '0 0 6px 2px rgb(220 242 255 / 0.9)' }}
                            initial={{ opacity: 0, scale: 0 }}
                            animate={{ opacity: [0, 1, 0], scale: [0, 1.2, 0] }}
                            transition={{ duration: 1.8, delay: 1 + e.d, repeat: Infinity, repeatDelay: 1.6, ease: 'easeInOut' }}
                        />
                    ))}

                <div className="carte-glace-centre">
                    <motion.span initial={false} animate={{ opacity: gelee ? 1 : 0, scale: gelee ? 1 : 0.4, rotate: gelee ? 0 : -90 }} transition={{ duration: reduit ? 0.2 : 0.7, delay: reduit || !gelee ? 0 : 0.6, ease: [0.22, 1, 0.36, 1] }}>
                        <Snowflake className="size-9" strokeWidth={1.5} aria-hidden="true" />
                    </motion.span>
                    <motion.span initial={false} animate={{ opacity: gelee ? 1 : 0, y: gelee ? 0 : 6 }} transition={{ duration: 0.4, delay: reduit || !gelee ? 0 : 0.85 }}>
                        Carte gelée
                    </motion.span>
                </div>
            </motion.div>

            {/* Dégel : trois gouttes qui tombent du bord haut. */}
            {!reduit && degel > 0 && (
                <div key={degel} className="pointer-events-none absolute inset-x-0 top-0 z-[5] h-full overflow-hidden rounded-[1.25rem]" aria-hidden="true">
                    {[
                        { x: '72%', d: 0 },
                        { x: '84%', d: 0.18 },
                        { x: '58%', d: 0.32 },
                    ].map((g) => (
                        <motion.span key={g.x} className="carte-goutte" style={{ left: g.x }} initial={{ y: -10, opacity: 0 }} animate={{ y: '110%', opacity: [0, 1, 1, 0] }} transition={{ duration: 0.9, delay: g.d, ease: 'easeIn' }} />
                    ))}
                </div>
            )}
        </div>
    );
}
