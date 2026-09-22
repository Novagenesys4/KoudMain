import { motion } from 'motion/react';
import { ArrowRight } from 'lucide-react';
import { Magnetic } from '../motion/Magnetic';
import { EASE } from '../motion/Reveal';
import { TextEffect } from '../motion/TextEffect';
import { Avatar } from '../ui/Avatar';
import { HeroDisque } from './HeroDisque';

const apparition = (delai) => ({ initial: { opacity: 0, y: 16 }, animate: { opacity: 1, y: 0 }, transition: { duration: 0.7, ease: EASE, delay: delai } });

/** Les prestataires distincts des dernières prestations (données réelles), pour la pile d'avatars. */
function prestatairesVus(prestations) {
    const vus = new Map();
    prestations.forEach((p) => {
        if (p.prestataire && !vus.has(p.prestataire)) vus.set(p.prestataire, p);
    });
    return [...vus.values()].slice(0, 3);
}

export function Hero({ urls, connecte, prestations = [], quartiers = [], nbPrestataires = 0 }) {
    const visages = prestatairesVus(prestations);
    const photo = prestations.find((p) => p.photo)?.photo ?? null;

    return (
        <section className="halo-hero relative z-10 w-full overflow-x-clip" aria-labelledby="titre-accueil">
            <div className="mx-auto grid w-full max-w-6xl grid-cols-[minmax(0,1fr)] items-center gap-x-10 gap-y-14 px-5 pb-16 pt-12 sm:px-8 lg:grid-cols-[minmax(0,6fr)_minmax(0,5fr)] lg:pb-24 lg:pt-16">
                <div>
                    <motion.p {...apparition(0)} className="etiquette etiquette-trait">
                        Services à domicile · Côte d'Ivoire
                    </motion.p>

                    <TextEffect as="h1" id="titre-accueil" delay={0.1} className="titre-hero mt-6" parts={[{ t: 'Le bon prestataire, ' }, { t: 'près de chez vous.', em: true }]} />

                    <motion.p {...apparition(0.4)} className="mt-6 max-w-xl text-[1.0625rem] text-soft">
                        Coiffure, plomberie, laverie, garde d'enfants : KoudMain met en relation des clients et des prestataires de confiance. Vous payez en sécurité, et le prestataire n'est réglé qu'une fois la prestation terminée.
                    </motion.p>

                    <motion.div {...apparition(0.55)} className="mt-9 flex flex-wrap items-center gap-3">
                        {connecte ? (
                            <Magnetic>
                                <a href={urls.espace} className="btn btn-plein">
                                    <span>Aller à mon espace</span>
                                    <ArrowRight className="fleche size-4" aria-hidden="true" />
                                </a>
                            </Magnetic>
                        ) : (
                            <>
                                <Magnetic>
                                    <a href={urls.inscription} className="btn btn-plein">
                                        <span>Créer mon compte</span>
                                        <ArrowRight className="fleche size-4" aria-hidden="true" />
                                    </a>
                                </Magnetic>
                                <a href={urls.connexion} className="btn">
                                    <span>Se connecter</span>
                                </a>
                            </>
                        )}
                    </motion.div>

                    {nbPrestataires > 0 && (
                        <motion.div {...apparition(0.85)} className="mt-9 flex items-center gap-4">
                            {visages.length > 0 && (
                                <span className="flex -space-x-2" aria-hidden="true">
                                    {visages.map((p, i) => (
                                        <Avatar key={p.id} nom={p.prestataire} teinte={['amber', 'teal', 'sable'][i % 3]} src={p.avatar} className="size-9 text-xs ring-2 ring-paper" />
                                    ))}
                                </span>
                            )}
                            <p className="text-sm text-soft">
                                <strong className="font-semibold text-ink">{nbPrestataires}</strong> prestataire{nbPrestataires > 1 ? 's' : ''} vérifié{nbPrestataires > 1 ? 's' : ''} sur la plateforme
                            </p>
                        </motion.div>
                    )}
                </div>

                <div className="pb-4 lg:pb-0">
                    <HeroDisque quartiers={quartiers} nbPrestataires={nbPrestataires} photo={photo} />
                </div>
            </div>
        </section>
    );
}
