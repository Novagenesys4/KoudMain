import { motion } from 'motion/react';
import { EASE } from './Reveal';

/**
 * Titre animé mot à mot (préréglage « flou » de Motion Primitives : chaque mot monte en
 * se précisant). Les espaces restent de vrais espaces : le titre se lit normalement au
 * lecteur d'écran et se coupe proprement sur mobile.
 *
 * <TextEffect as="h1" parts={[{ t: 'Le bon prestataire, ' }, { t: 'près de chez vous.', em: true }]} />
 */
export function TextEffect({ as = 'p', parts, delay = 0, stagger = 0.055, className, ...reste }) {
    const Balise = motion[as] ?? motion.p;
    let indice = 0;

    return (
        <Balise
            className={className}
            initial="cache"
            whileInView="visible"
            viewport={{ once: true, amount: 0.4 }}
            variants={{ cache: {}, visible: { transition: { staggerChildren: stagger, delayChildren: delay } } }}
            {...reste}
        >
            {parts.map((partie, p) => {
                const mots = partie.t.split(/(\s+)/).filter((m) => m !== '');
                const contenu = mots.map((mot, m) =>
                    /^\s+$/.test(mot) ? (
                        mot
                    ) : (
                        <motion.span
                            key={`${p}-${m}`}
                            className="inline-block will-change-transform"
                            data-mot={indice++}
                            variants={{
                                cache: { opacity: 0, y: '0.55em', filter: 'blur(8px)' },
                                visible: { opacity: 1, y: 0, filter: 'blur(0px)', transition: { duration: 0.7, ease: EASE } },
                            }}
                        >
                            {mot}
                        </motion.span>
                    ),
                );
                return partie.em ? <em key={p}>{contenu}</em> : <span key={p}>{contenu}</span>;
            })}
        </Balise>
    );
}
