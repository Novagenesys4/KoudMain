import { AnimatedNumber } from '../motion/AnimatedNumber';
import { Stagger, StaggerItem } from '../motion/Reveal';

/**
 * Chiffres réels, lus dans la base de données (domaines, services proposés, quartiers desservis).
 * Une valeur à zéro n'est jamais affichée : on ne met pas en avant un « 0 ».
 */
export function Chiffres({ stats }) {
    const affichees = stats.filter((s) => s.valeur > 0);
    if (affichees.length === 0) return null;

    return (
        <section className="mx-auto mt-24 w-full max-w-6xl px-5 sm:px-8 lg:mt-32" aria-label="KoudMain en chiffres">
            <Stagger as="ul" gap={0.12} className="grid divide-y divide-line border-y border-line sm:divide-x sm:divide-y-0 sm:[grid-template-columns:repeat(var(--n),minmax(0,1fr))]" style={{ '--n': affichees.length }}>
                {affichees.map((s) => (
                    <StaggerItem as="li" key={s.libelle} className="px-2 py-10 sm:px-8 sm:first:pl-0 sm:last:pr-0">
                        <p className="font-serif text-[clamp(3rem,6vw,4.5rem)] font-semibold leading-none tracking-[-0.06em]">
                            <AnimatedNumber value={s.valeur} duration={1.8} />
                        </p>
                        <p className="mt-3 font-mono text-[0.6875rem] uppercase tracking-[0.13em] text-faint">{s.libelle}</p>
                    </StaggerItem>
                ))}
            </Stagger>
        </section>
    );
}
