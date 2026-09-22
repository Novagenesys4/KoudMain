import { AnimatePresence, motion } from 'motion/react';
import { Banknote, CalendarDays, Clock, CreditCard, Lock, Minus, Plus, Smartphone } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { EASE } from '../components/motion/Reveal';
import { cn } from '../lib/cn';
import { fcfa } from '../lib/format';

const MODES = [
    { cle: 'physique', libelle: 'Paiement physique', aide: 'En main propre, à la fin de la prestation. Rien n’est prélevé.', Icone: Banknote },
    { cle: 'mobile_money', libelle: 'Mobile Money', aide: 'Prélevé sur votre wallet, bloqué en séquestre.', Icone: Smartphone },
    { cle: 'carte', libelle: 'Carte bancaire', aide: 'Via une de vos cartes, bloqué en séquestre.', Icone: CreditCard },
];

/** 90 -> « 1 h 30 », 45 -> « 45 min » */
function duree(minutes) {
    if (!minutes) return null;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m} min`;
    return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

/** Regroupe les heures en matin / après-midi / soir, comme on les lit. */
function grouper(heures) {
    const groupes = { Matin: [], 'Après-midi': [], Soir: [] };
    heures.forEach((h) => {
        const heure = Number(h.slice(0, 2));
        groupes[heure < 12 ? 'Matin' : heure < 18 ? 'Après-midi' : 'Soir'].push(h);
    });
    return Object.entries(groupes).filter(([, liste]) => liste.length > 0);
}

/**
 * Le formulaire de commande : quantité, jour, heure, précisions, et le récapitulatif avec le solde du wallet.
 * C'est un vrai formulaire HTML (les champs ont un « name ») : le serveur reçoit quantite, date, heure, precisions
 * et refait TOUTES les vérifications (prix, solde, créneau). Rien de ce qui est calculé ici n'est cru par le serveur.
 */
export default function CommandeFormulaire({ jours = [], prix, dureeUnite, quantiteMax = 20, solde = 0, urlRecharge, prenom = 'le prestataire', lieu = '', cartes = [], ancien = {}, urlCartes = '' }) {
    const [quantite, setQuantite] = useState(Math.min(Math.max(1, ancien.quantite || 1), quantiteMax));
    const [cleJour, setCleJour] = useState(jours.some((j) => j.cle === ancien.date) ? ancien.date : jours[0]?.cle ?? null);
    const [heure, setHeure] = useState(ancien.heure ?? null);
    const [precisions, setPrecisions] = useState(ancien.precisions ?? '');
    // Carte à utiliser : celle qu'on avait choisie, sinon la principale, sinon la première carte non gelée.
    const [carteId, setCarteId] = useState(() => (cartes.find((c) => c.id === ancien.carte) ?? cartes.find((c) => c.principale && !c.gelee) ?? cartes.find((c) => !c.gelee) ?? cartes[0])?.id ?? '');
    const carte = cartes.find((c) => c.id === Number(carteId));
    const carteGelee = Boolean(carte?.gelee);
    // Mode de paiement : en main propre (rien ne bouge dans le wallet), Mobile Money ou carte (prélevé sur le wallet, bloqué en séquestre).
    const [mode, setMode] = useState(['physique', 'mobile_money', 'carte'].includes(ancien.mode) ? ancien.mode : 'mobile_money');
    const parWallet = mode !== 'physique';

    const jour = jours.find((j) => j.cle === cleJour);
    // Si l'heure choisie n'existe pas ce jour-là, on l'oublie.
    useEffect(() => {
        if (heure && jour && !jour.creneaux.includes(heure)) setHeure(null);
    }, [cleJour]); // eslint-disable-line react-hooks/exhaustive-deps

    const total = prix * quantite;
    const reste = solde - total;
    const suffisant = !parWallet || reste >= 0;
    const carteOk = mode !== 'carte' || Boolean(carte && !carteGelee);
    const pret = Boolean(jour && heure) && suffisant && carteOk;
    const dureeTotale = dureeUnite ? dureeUnite * quantite : null;
    const groupes = useMemo(() => (jour ? grouper(jour.creneaux) : []), [jour]);

    const titre = 'flex items-center gap-2 text-sm font-medium';

    if (jours.length === 0) {
        return (
            <div className="rounded-2xl border border-dashed border-line bg-surface px-6 py-10 text-center">
                <CalendarDays className="mx-auto size-6 text-faint" aria-hidden="true" />
                <h2 className="mt-3 text-lg font-semibold">Aucun créneau libre pour le moment</h2>
                <p className="mx-auto mt-2 max-w-md text-soft">{prenom} n'a aucune disponibilité dans les prochains jours. Revenez bientôt, ou choisissez un autre prestataire.</p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div className="min-w-0">
                {/* Quantité */}
                {quantiteMax > 1 && (
                    <div className="mb-8">
                        <label htmlFor="quantite" className={titre}>Quantité</label>
                        <div className="mt-3 inline-flex items-center rounded-xl border border-line bg-surface">
                            <button type="button" onClick={() => setQuantite((q) => Math.max(1, q - 1))} disabled={quantite <= 1} aria-label="Diminuer la quantité" className="grid size-11 cursor-pointer place-items-center rounded-l-xl text-soft transition-colors hover:bg-deep hover:text-ink disabled:cursor-not-allowed disabled:opacity-40">
                                <Minus className="size-4" aria-hidden="true" />
                            </button>
                            <input id="quantite" name="quantite" type="number" inputMode="numeric" min="1" max={quantiteMax} value={quantite} onChange={(e) => setQuantite(Math.min(quantiteMax, Math.max(1, Number(e.target.value) || 1)))} className="h-11 w-14 border-x border-line bg-transparent text-center text-base font-medium tabular-nums outline-none" />
                            <button type="button" onClick={() => setQuantite((q) => Math.min(quantiteMax, q + 1))} disabled={quantite >= quantiteMax} aria-label="Augmenter la quantité" className="grid size-11 cursor-pointer place-items-center rounded-r-xl text-soft transition-colors hover:bg-deep hover:text-ink disabled:cursor-not-allowed disabled:opacity-40">
                                <Plus className="size-4" aria-hidden="true" />
                            </button>
                        </div>
                        {dureeTotale && quantite > 1 && <p className="mt-2 text-sm text-faint">Durée totale prévue : {duree(dureeTotale)}. Les créneaux proposés sont calculés pour une seule prestation ; si la durée totale dépasse un créneau, l'application vous le dira.</p>}
                    </div>
                )}
                {quantiteMax <= 1 && <input type="hidden" name="quantite" value="1" />}

                {/* Jour */}
                <p className={titre} id="titre-jour"><CalendarDays className="size-4" aria-hidden="true" />Quel jour ?</p>
                <div role="radiogroup" aria-labelledby="titre-jour" className="-mx-1 mt-3 flex gap-2 overflow-x-auto px-1 pb-2">
                    {jours.map((j) => {
                        const actif = j.cle === cleJour;
                        return (
                            <button key={j.cle} type="button" role="radio" aria-checked={actif} onClick={() => setCleJour(j.cle)} title={j.long}
                                className={cn('relative shrink-0 cursor-pointer rounded-xl border px-4 py-2.5 text-sm font-medium capitalize transition-colors duration-300', actif ? 'border-transparent text-on-amber' : 'border-line bg-surface text-ink hover:border-accent')}>
                                {actif && <motion.span layoutId="jour-actif" className="absolute inset-0 rounded-xl bg-amber" transition={{ type: 'spring', bounce: 0.2, duration: 0.5 }} />}
                                <span className="relative">{j.court}</span>
                            </button>
                        );
                    })}
                </div>
                <input type="hidden" name="date" value={cleJour ?? ''} />

                {/* Heure */}
                <p className={cn(titre, 'mt-6')} id="titre-heure"><Clock className="size-4" aria-hidden="true" />Quelle heure ?</p>
                <motion.div key={cleJour} initial="cache" animate="visible" variants={{ cache: {}, visible: { transition: { staggerChildren: 0.03 } } }} className="mt-3 grid gap-4">
                    {groupes.map(([groupe, heures]) => (
                        <div key={groupe} role="radiogroup" aria-label={groupe}>
                            <p className="mb-2 text-[0.8125rem] text-faint">{groupe}</p>
                            <div className="grid grid-cols-4 gap-2 sm:grid-cols-6">
                                {heures.map((h) => {
                                    const actif = heure === h;
                                    return (
                                        <motion.button key={h} type="button" role="radio" aria-checked={actif} onClick={() => setHeure(h)}
                                            variants={{ cache: { opacity: 0, y: 8 }, visible: { opacity: 1, y: 0 } }} whileTap={{ scale: 0.96 }}
                                            className={cn('relative cursor-pointer rounded-lg border py-2 text-sm font-medium tabular-nums transition-colors duration-300', actif ? 'border-transparent text-on-amber' : 'border-line bg-surface text-ink hover:border-accent')}>
                                            {actif && <motion.span layoutId="heure-active" className="absolute inset-0 rounded-lg bg-amber" transition={{ type: 'spring', bounce: 0.2, duration: 0.5 }} />}
                                            <span className="relative">{h}</span>
                                        </motion.button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </motion.div>
                <input type="hidden" name="heure" value={heure ?? ''} />

                {/* Précisions */}
                <div className="champ mt-8">
                    <label htmlFor="precisions">Précisions pour {prenom} <span className="font-normal text-faint">(facultatif)</span></label>
                    <div className="champ-saisie">
                        <textarea id="precisions" name="precisions" rows="3" maxLength={500} value={precisions} onChange={(e) => setPrecisions(e.target.value)} placeholder="Repère pour trouver l'adresse, étage, consignes…" />
                    </div>
                </div>
            </div>

            {/* Récapitulatif */}
            <aside className="rounded-2xl border border-line bg-surface p-5 lg:sticky lg:top-24" aria-label="Récapitulatif de la commande">
                <h2 className="text-lg font-semibold">Récapitulatif</h2>
                <dl className="mt-4 grid gap-2.5 text-sm">
                    <div className="flex justify-between gap-4"><dt className="text-soft">Prix unitaire</dt><dd className="tabular-nums">{fcfa(prix)}</dd></div>
                    <div className="flex justify-between gap-4"><dt className="text-soft">Quantité</dt><dd className="tabular-nums">{quantite}</dd></div>
                    <div className="flex justify-between gap-4 border-t border-line pt-3 text-base font-semibold"><dt>Total</dt><dd className="tabular-nums">{fcfa(total)}</dd></div>
                </dl>

                <AnimatePresence initial={false}>
                    {jour && heure && (
                        <motion.p initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.4, ease: EASE }} className="overflow-hidden">
                            <span className="mt-4 block rounded-xl bg-amber-tint px-3.5 py-3 text-sm">
                                <strong className="font-semibold capitalize">{jour.long}</strong> à <strong className="font-semibold">{heure}</strong>
                                {lieu && <span className="mt-0.5 block text-soft">{lieu}</span>}
                            </span>
                        </motion.p>
                    )}
                </AnimatePresence>

                <fieldset className="mt-5">
                    <legend className={titre}>Comment voulez-vous payer ?</legend>
                    <input type="hidden" name="mode_paiement" value={mode} />
                    <div className="mt-3 grid gap-2" role="radiogroup" aria-label="Mode de paiement">
                        {MODES.map(({ cle, libelle, aide, Icone }) => (
                            <button
                                key={cle}
                                type="button"
                                role="radio"
                                aria-checked={mode === cle}
                                onClick={() => setMode(cle)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition-[border-color,background-color,transform] duration-300 active:scale-[0.99]',
                                    mode === cle ? 'border-accent bg-amber-tint' : 'border-line bg-surface hover:border-edge',
                                )}
                            >
                                <span className={cn('grid size-9 shrink-0 place-items-center rounded-lg transition-colors duration-300', mode === cle ? 'bg-accent text-white' : 'bg-deep text-soft')}>
                                    <Icone className="size-[1.125rem]" aria-hidden="true" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium leading-tight">{libelle}</span>
                                    <span className="mt-0.5 block text-[0.8125rem] leading-snug text-soft">{aide}</span>
                                </span>
                                <span className={cn('grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors duration-300', mode === cle ? 'border-accent' : 'border-edge')} aria-hidden="true">
                                    <span className={cn('size-2.5 rounded-full bg-accent transition-transform duration-300', mode === cle ? 'scale-100' : 'scale-0')} />
                                </span>
                            </button>
                        ))}
                    </div>
                </fieldset>

                {parWallet ? (
                    <div className="mt-4 rounded-xl border border-line px-3.5 py-3 text-sm">
                        <div className="flex justify-between gap-4"><span className="text-soft">Solde de votre wallet</span><span className="font-medium tabular-nums">{fcfa(solde)}</span></div>
                        {suffisant ? (
                            <div className="mt-1.5 flex justify-between gap-4"><span className="text-soft">Après la commande</span><span className="tabular-nums">{fcfa(reste)}</span></div>
                        ) : (
                            <p className="mt-2 text-danger" role="alert">
                                Il vous manque {fcfa(-reste)}.{' '}
                                <a href={urlRecharge} className="lien">Recharger mon wallet</a>
                            </p>
                        )}
                    </div>
                ) : (
                    <p className="mt-4 rounded-xl border border-line px-3.5 py-3 text-sm text-soft">Rien n'est prélevé sur votre wallet : vous réglez <strong className="font-semibold text-ink">{fcfa(total)}</strong> au prestataire, en main propre, à la fin de la prestation.</p>
                )}

                {mode === 'carte' && (
                    <div className="champ mt-4">
                        {cartes.length > 0 ? (
                            <>
                                <label htmlFor="carte_id">Carte utilisée</label>
                                <div className="champ-saisie">
                                    <select id="carte_id" name="carte_id" value={carteId} onChange={(e) => setCarteId(e.target.value)}>
                                        {cartes.map((c) => (
                                            <option key={c.id} value={c.id}>{c.libelle} · •••• {c.fin}{c.gelee ? ' (gelée)' : ''}</option>
                                        ))}
                                    </select>
                                </div>
                                {carteGelee && <p className="mt-2 text-sm text-danger" role="alert">Cette carte est gelée : dégelez-la dans votre wallet ou choisissez-en une autre.</p>}
                            </>
                        ) : (
                            <p className="text-sm text-danger" role="alert">
                                Vous n'avez encore aucune carte.{' '}
                                <a href={urlCartes} className="lien">Ajouter une carte</a>
                            </p>
                        )}
                    </div>
                )}

                <button type="submit" data-chargement="Envoi de la demande…" disabled={!pret} className="btn btn-plein mt-5 w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                    <span data-libelle>{pret ? `Envoyer la demande · ${fcfa(total)}` : !suffisant ? 'Solde insuffisant' : !carteOk ? (carte ? 'Carte gelée' : 'Ajoutez une carte') : 'Choisissez un jour et une heure'}</span>
                </button>
                <p className="mt-3 flex items-start justify-center gap-1.5 text-center text-[0.8125rem] text-faint">
                    <Lock className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    {parWallet ? `Le montant est bloqué en séquestre. ${prenom.charAt(0).toUpperCase() + prenom.slice(1)} n'est payé qu'après la prestation.` : 'Aucun argent ne passe par KoudMain : le prix est réglé directement au prestataire.'}
                </p>
            </aside>
        </div>
    );
}
