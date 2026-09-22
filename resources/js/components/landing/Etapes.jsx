import { CalendarCheck, Search, ShieldCheck } from 'lucide-react';
import { Reveal, Stagger, StaggerItem } from '../motion/Reveal';

const ETAPES = [
    { icone: Search, titre: 'Choisir', texte: 'Parcourez les prestations proches de chez vous et comparez les prix, les disponibilités et les avis des autres clients.' },
    { icone: CalendarCheck, titre: 'Réserver', texte: 'Vous commandez et payez depuis votre porte-monnaie. La somme est mise de côté : le prestataire ne la reçoit pas encore.' },
    { icone: ShieldCheck, titre: 'Valider', texte: 'La prestation terminée, vous confirmez la réception : le paiement est libéré et vous pouvez noter le prestataire.' },
];

/** Trois cartes numérotées : « Simple comme un message ». */
export function Etapes() {
    return (
        <section id="etapes" className="mx-auto mt-24 w-full max-w-6xl px-5 sm:px-8 lg:mt-32" aria-labelledby="titre-etapes">
            <Reveal>
                <p className="etiquette">Comment ça marche</p>
                <h2 id="titre-etapes" className="mt-4 max-w-2xl text-[clamp(2rem,4vw,3.25rem)]">
                    Du besoin à la solution, <em>sans détour.</em>
                </h2>
                <p className="mt-5 max-w-lg text-soft">Chaque commande suit le même chemin : vous savez toujours où en est votre argent.</p>
            </Reveal>

            <Stagger as="ol" gap={0.1} className="mt-12 grid gap-4 md:grid-cols-3">
                {ETAPES.map(({ icone: Icone, titre, texte }, i) => (
                    <StaggerItem as="li" key={titre}>
                        <div className="group relative h-full overflow-hidden rounded-[var(--radius)] border border-line bg-surface p-7 shadow-soft transition-[transform,border-color,box-shadow] duration-500 ease-[var(--ease)] hover:-translate-y-1 hover:border-accent hover:shadow-lift">
                            <span aria-hidden="true" className="pointer-events-none absolute right-6 top-5 select-none font-serif text-[4.5rem] font-semibold leading-none tracking-[-0.06em] text-deep transition-colors duration-500 group-hover:text-amber-tint">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <span className="relative grid size-12 place-items-center rounded-xl bg-amber-tint text-accent">
                                <Icone className="size-5" strokeWidth={1.6} aria-hidden="true" />
                            </span>
                            <h3 className="relative mt-16 text-2xl font-semibold">{titre}</h3>
                            <p className="relative mt-3 text-[0.9375rem] text-soft">{texte}</p>
                        </div>
                    </StaggerItem>
                ))}
            </Stagger>
        </section>
    );
}
