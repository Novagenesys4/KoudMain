import { BadgeCheck, Lock, Wallet } from 'lucide-react';
import { Reveal, Stagger, StaggerItem } from '../motion/Reveal';
import { WalletApercu } from '../features/WalletApercu';

const ATOUTS = [
    { icone: Lock, titre: 'Le séquestre', texte: "Le montant est mis de côté au moment de la commande. Le prestataire ne le reçoit qu'une fois la prestation terminée et confirmée.", teinte: 'var(--amber-tint)' },
    { icone: Wallet, titre: 'Un seul porte-monnaie', texte: 'Alimentez-le par Mobile Money (Orange, MTN, Wave) et suivez chaque mouvement, du dépôt jusqu’au retrait.', teinte: 'var(--teal-tint)' },
    { icone: BadgeCheck, titre: 'Des prestataires vérifiés', texte: 'Un administrateur contrôle chaque prestataire avant sa mise en ligne. Vous ne voyez que des profils validés.', teinte: 'var(--rose-tint)' },
];

export function Argent() {
    return (
        <section id="securite" className="mx-auto mt-24 w-full max-w-6xl px-3 sm:px-6 lg:mt-32" aria-labelledby="titre-argent">
            <div className="rounded-[1.75rem] border border-line bg-deep px-5 py-10 sm:px-10 sm:py-14 lg:px-14">
                <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-12 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:gap-14">
                    <div>
                        <Reveal>
                            <p className="etiquette">La confiance</p>
                            <h2 id="titre-argent" className="mt-4 text-[clamp(2rem,3.8vw,3rem)]">
                                Votre argent ne bouge <em>qu'une fois la prestation faite.</em>
                            </h2>
                        </Reveal>

                        <Stagger as="ul" gap={0.08} delay={0.1} className="mt-8 grid gap-5">
                            {ATOUTS.map(({ icone: Icone, titre, texte, teinte }) => (
                                <StaggerItem as="li" key={titre} className="flex gap-4">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl" style={{ background: teinte }}>
                                        <Icone className="size-[1.125rem] text-ink" strokeWidth={1.7} aria-hidden="true" />
                                    </span>
                                    <span>
                                        <span className="block text-base font-semibold leading-tight">{titre}</span>
                                        <span className="mt-1 block text-[0.9375rem] text-soft">{texte}</span>
                                    </span>
                                </StaggerItem>
                            ))}
                        </Stagger>
                    </div>

                    <Reveal delay={0.1} y={30}>
                        <WalletApercu />
                    </Reveal>
                </div>
            </div>
        </section>
    );
}
