import { ArrowRight, Check } from 'lucide-react';
import { Magnetic } from '../motion/Magnetic';
import { Reveal } from '../motion/Reveal';

const POINTS = ['Profil vérifié par un administrateur', 'Vos prix, vos horaires, vos quartiers', 'Paiement libéré dès la prestation confirmée'];

/** Appel aux prestataires : panneau sombre (dans les deux thèmes), aplat uni, bouton magnétique. */
export function CtaPrestataire({ urls, connecte }) {
    return (
        <section className="mx-auto mt-24 w-full max-w-6xl px-3 sm:px-6 lg:mt-32" aria-labelledby="titre-prestataire">
            <Reveal y={24}>
                <div className="disque relative overflow-hidden rounded-[1.75rem] px-6 py-12 text-on-panel sm:px-14 sm:py-16">
                    <div className="grid grid-cols-[minmax(0,1fr)] items-end gap-10 lg:grid-cols-[minmax(0,1fr)_auto]">
                        <div>
                            <p className="etiquette etiquette-trait text-on-panel-accent">Vous êtes prestataire ?</p>
                            <h2 id="titre-prestataire" className="mt-5 max-w-2xl text-[clamp(2.1rem,4.2vw,3.5rem)] [&_em]:text-on-panel-accent">
                                Votre savoir-faire <em>mérite des clients.</em>
                            </h2>
                            <p className="mt-5 max-w-xl text-on-panel-soft">Créez votre profil, indiquez vos prix et vos horaires. Un administrateur vérifie chaque prestataire avant sa mise en ligne, et vous êtes payé sans courir après.</p>
                            <ul className="mt-6 grid gap-2.5 text-[0.9375rem]">
                                {POINTS.map((p) => (
                                    <li key={p} className="flex items-center gap-3">
                                        <span className="grid size-5 place-items-center rounded-full bg-on-panel-accent text-panel">
                                            <Check className="size-3" strokeWidth={3} aria-hidden="true" />
                                        </span>
                                        {p}
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {!connecte && (
                            <Magnetic force={0.3}>
                                <a href={urls.inscription} className="group inline-flex h-12 items-center gap-2.5 rounded-xl bg-on-panel-accent px-6 text-[0.9375rem] font-medium text-panel transition-[transform,box-shadow] duration-300 hover:shadow-[0_10px_28px_-12px_var(--on-panel-accent)] active:scale-[0.98]">
                                    Proposer mes services
                                    <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-1" aria-hidden="true" />
                                </a>
                            </Magnetic>
                        )}
                    </div>
                </div>
            </Reveal>
        </section>
    );
}
