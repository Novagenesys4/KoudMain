import { Marquee } from '../motion/Marquee';
import { Reveal, Stagger, StaggerItem } from '../motion/Reveal';
import { iconePour } from '../../lib/icones';

/**
 * Bandeau défilant en petites capitales mono : « Disponible à <quartier> » pour chaque quartier où un prestataire
 * validé propose une prestation (données réelles). Sans quartier, on liste les domaines.
 */
export function BandeauQuartiers({ quartiers, domaines }) {
    const elements = quartiers.length > 0 ? quartiers.map((q) => ({ prefixe: 'Disponible à', nom: q })) : domaines.map((d) => ({ prefixe: 'Domaine', nom: d.nom }));
    if (elements.length === 0) return null;
    // Assez d'éléments pour remplir un grand écran avant la boucle.
    const liste = elements.length < 6 ? [...elements, ...elements, ...elements] : elements.length < 10 ? [...elements, ...elements] : elements;

    return (
        <div className="border-y border-line bg-deep/60 py-4" aria-label={quartiers.length > 0 ? 'Quartiers desservis' : 'Domaines de services'} role="region">
            <Marquee duree={Math.max(liste.length * 4, 32)}>
                {liste.map((e, i) => (
                    <span key={`${e.nom}-${i}`} className="flex items-center font-mono text-xs tracking-wide text-soft">
                        <span className="pl-10">{e.prefixe}&nbsp;</span>
                        <b className="pr-10 font-medium text-ink">{e.nom}</b>
                        <span className="text-amber" aria-hidden="true">✦</span>
                    </span>
                ))}
            </Marquee>
        </div>
    );
}

/** Liste des domaines couverts (lecture seule : le catalogue s'ouvre depuis l'espace client). */
export function Domaines({ domaines }) {
    return (
        <section id="domaines" className="mx-auto mt-24 w-full max-w-6xl px-5 sm:px-8 lg:mt-32" aria-labelledby="titre-domaines">
            <div className="grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-20">
                <Reveal className="lg:sticky lg:top-28 lg:self-start">
                    <p className="etiquette">Les domaines</p>
                    <h2 id="titre-domaines" className="mt-4 text-[clamp(2rem,4vw,3.25rem)]">
                        Tout ce qu'il vous faut, <em>juste à côté.</em>
                    </h2>
                    <p className="mt-5 max-w-sm text-soft">Des prestataires vérifiés dans chacun de ces domaines, et d'autres à venir.</p>
                </Reveal>

                {domaines.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-line px-6 py-10 text-center text-soft">Les domaines de services seront bientôt disponibles.</p>
                ) : (
                    <Stagger as="ul" gap={0.05} className="border-t border-line">
                        {domaines.map((domaine, i) => {
                            const Icone = iconePour(domaine.nom);
                            const nb = domaine.services_count;
                            return (
                                <StaggerItem as="li" key={domaine.id} y={16} className="border-b border-line">
                                    <div className="group relative flex items-center gap-4 py-5 pr-2 sm:gap-6 sm:py-6">
                                        <span className="w-8 shrink-0 font-mono text-xs text-faint" aria-hidden="true">{String(i + 1).padStart(2, '0')}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-serif text-[clamp(1.35rem,2.6vw,1.9rem)] font-semibold leading-tight tracking-[-0.04em]">{domaine.nom}</span>
                                            <span className="mt-1 block font-mono text-[0.6875rem] uppercase tracking-[0.12em] text-faint">{nb > 0 ? `${nb} service${nb > 1 ? 's' : ''}` : 'Bientôt disponible'}</span>
                                        </span>
                                        <span className="grid size-10 shrink-0 place-items-center rounded-full border border-line text-ink">
                                            <Icone className="size-[1.125rem]" strokeWidth={1.5} aria-hidden="true" />
                                        </span>
                                    </div>
                                </StaggerItem>
                            );
                        })}
                    </Stagger>
                )}
            </div>
        </section>
    );
}
