import { AnimatePresence, motion } from 'motion/react';
import { ChevronLeft, ChevronRight, Lock, Snowflake, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CarteGivre } from '../components/features/CarteGivre';
import { EASE } from '../components/motion/Reveal';
import { fcfa } from '../lib/format';

const RESEAUX = { visa: 'VISA', mastercard: 'Mastercard', amex: 'AMEX' };
const NOMS_RESEAUX = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express' };

/** Le dessin d'une carte (mêmes classes que le repli Blade : resources/views/components/carte.blade.php). Le gel est dessiné par CarteGivre. */
function Carte({ carte }) {
    return (
        <div className={`carte carte-${carte.couleur}`}>
            <div className="carte-haut">
                <div>
                    <p className="carte-nom">{carte.libelle}</p>
                    <p className="carte-sous-nom">{carte.principale ? 'Carte par défaut' : 'Carte bancaire'}</p>
                </div>
                <span className="carte-reseau" data-reseau={carte.reseau}>{RESEAUX[carte.reseau] ?? ''}</span>
            </div>
            <span className="carte-puce" aria-hidden="true" />
            <p className="carte-numero" aria-hidden="true">{carte.reseau === 'amex' ? '•••• •••••• •' : '•••• •••• •••• '}{carte.fin}</p>
            <div className="carte-bas">
                <div>
                    <span className="carte-legende">Titulaire</span>
                    <span className="carte-valeur">{carte.titulaire}</span>
                </div>
                <div>
                    <span className="carte-legende">Expire</span>
                    <span className="carte-valeur">{carte.expire}</span>
                </div>
                <span className="carte-marque" aria-hidden="true">koudmain</span>
            </div>
        </div>
    );
}

function Chiffre({ libelle, valeur }) {
    return (
        <div className="min-w-0 rounded-xl bg-deep px-3.5 py-3">
            <p className="text-xs text-faint">{libelle}</p>
            <p className="mt-1 truncate text-sm font-semibold tabular-nums">{valeur}</p>
        </div>
    );
}

const glissement = {
    entree: (sens) => ({ x: sens * 56, opacity: 0, scale: 0.97 }),
    centre: { x: 0, opacity: 1, scale: 1 },
    sortie: (sens) => ({ x: sens * -56, opacity: 0, scale: 0.97 }),
};

/**
 * Les cartes de l'utilisateur : UNE carte à la fois (aucun empilement), points de pagination, flèches, glissement au doigt ou à la souris,
 * clavier (← →). Geler / dégeler part en JSON vers le serveur (même règle métier que le formulaire) puis le givre s'anime sur la carte.
 * Supprimer une carte reste un vrai formulaire Blade qui suit le choix grâce à l'évènement « carte:choisie ».
 */
export default function CartesWallet({ cartes: cartesInitiales = [], initiale = null }) {
    const [cartes, setCartes] = useState(cartesInitiales);
    const debut = Math.max(0, cartesInitiales.findIndex((c) => c.id === initiale));
    const [actif, setActif] = useState(debut);
    const [sens, setSens] = useState(1);
    const [envoi, setEnvoi] = useState(false);
    const [erreur, setErreur] = useState('');
    const [annonce, setAnnonce] = useState('');
    const total = cartes.length;
    const carte = cartes[actif];
    const scene = useRef(null);

    const aller = useCallback(
        (indice) => {
            const cible = Math.min(total - 1, Math.max(0, indice));
            if (cible === actif) return;
            setSens(cible > actif ? 1 : -1);
            setActif(cible);
            setErreur('');
        },
        [total, actif],
    );

    useEffect(() => {
        if (carte) document.dispatchEvent(new CustomEvent('carte:choisie', { detail: { id: carte.id } }));
    }, [carte?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!carte) return null;

    const surTouche = (evenement) => {
        if (evenement.key === 'ArrowLeft') aller(actif - 1);
        else if (evenement.key === 'ArrowRight') aller(actif + 1);
        else return;
        evenement.preventDefault();
    };

    const basculerGel = async () => {
        if (envoi) return;
        setEnvoi(true);
        setErreur('');
        try {
            const reponse = await fetch(carte.urlGeler, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            });
            const donnees = await reponse.json().catch(() => ({}));
            if (!reponse.ok) throw new Error(donnees.erreur || 'Impossible de modifier la carte pour le moment.');
            setCartes((liste) => liste.map((c) => (c.id === carte.id ? { ...c, gelee: donnees.gelee } : c)));
            setAnnonce(donnees.message);
            document.dispatchEvent(new CustomEvent('carte:gel', { detail: { id: carte.id, gelee: donnees.gelee } }));
        } catch (e) {
            setErreur(e.message || 'Impossible de modifier la carte pour le moment.');
        } finally {
            setEnvoi(false);
        }
    };

    return (
        <div>
            <div className="carrousel-carte">
                {total > 1 && (
                    <>
                        <button type="button" className="carrousel-fleche carrousel-fleche-gauche" onClick={() => aller(actif - 1)} disabled={actif === 0} aria-label="Carte précédente">
                            <ChevronLeft className="size-[1.125rem]" aria-hidden="true" />
                        </button>
                        <button type="button" className="carrousel-fleche carrousel-fleche-droite" onClick={() => aller(actif + 1)} disabled={actif === total - 1} aria-label="Carte suivante">
                            <ChevronRight className="size-[1.125rem]" aria-hidden="true" />
                        </button>
                    </>
                )}

                <div ref={scene} className="carrousel-scene" role="group" aria-roledescription="carrousel" aria-label="Mes cartes bancaires" tabIndex={total > 1 ? 0 : -1} onKeyDown={total > 1 ? surTouche : undefined}>
                    <AnimatePresence mode="wait" initial={false} custom={sens}>
                        <motion.div
                            key={carte.id}
                            custom={sens}
                            variants={glissement}
                            initial="entree"
                            animate="centre"
                            exit="sortie"
                            transition={{ duration: 0.28, ease: EASE }}
                            className="absolute inset-0"
                            role="img"
                            aria-label={`${carte.libelle}, ${NOMS_RESEAUX[carte.reseau] ?? ''}, numéro se terminant par ${carte.fin}${carte.gelee ? ', gelée' : ''}`}
                            drag={total > 1 ? 'x' : false}
                            dragConstraints={{ left: 0, right: 0 }}
                            dragElastic={0.25}
                            onDragEnd={(_, info) => {
                                if (info.offset.x < -50 || info.velocity.x < -450) aller(actif + 1);
                                else if (info.offset.x > 50 || info.velocity.x > 450) aller(actif - 1);
                            }}
                            style={{ cursor: total > 1 ? 'grab' : 'default' }}
                            whileDrag={{ cursor: 'grabbing' }}
                        >
                            <CarteGivre gelee={carte.gelee} enfants={<Carte carte={carte} />} />
                        </motion.div>
                    </AnimatePresence>
                </div>

                {total > 1 && (
                    <div className="carrousel-points" role="group" aria-label="Choisir une carte">
                        {cartes.map((c, i) => (
                            <button key={c.id} type="button" className="carrousel-point" aria-current={i === actif ? 'true' : undefined} aria-label={`Carte ${i + 1} sur ${total} : ${c.libelle}`} onClick={() => aller(i)} />
                        ))}
                    </div>
                )}
            </div>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                <p className="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1 text-sm">
                    <strong className="truncate font-semibold">{carte.libelle}</strong>
                    <span className="text-faint">se termine par {carte.fin}</span>
                    {carte.principale && <span className="puce">Par défaut</span>}
                    {carte.gelee && (
                        <span className="statut statut-encours">
                            <Lock className="mr-1 inline size-3" aria-hidden="true" />
                            Gelée
                        </span>
                    )}
                </p>
                <button type="button" onClick={basculerGel} disabled={envoi} aria-pressed={carte.gelee} className={carte.gelee ? 'btn btn-petit' : 'btn btn-petit btn-plein'}>
                    {carte.gelee ? <Sun className="size-4" aria-hidden="true" /> : <Snowflake className="size-4" aria-hidden="true" />}
                    <span>{envoi ? 'Un instant…' : carte.gelee ? 'Dégeler la carte' : 'Geler la carte'}</span>
                </button>
            </div>

            {erreur && (
                <p className="champ-erreur mt-3" role="alert">
                    {erreur}
                </p>
            )}
            {carte.gelee && <p className="mt-3 text-sm text-faint">Une carte gelée ne peut plus payer de commande ni recharger le wallet. Dégelez-la quand vous voulez.</p>}
            <p className="sr-only" role="status" aria-live="polite">
                {annonce || `Carte ${actif + 1} sur ${total} : ${carte.libelle}, se terminant par ${carte.fin}${carte.gelee ? ', gelée' : ''}.`}
            </p>

            <AnimatePresence mode="wait" initial={false}>
                <motion.div key={carte.id} className="mt-5 grid grid-cols-3 gap-2.5" initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -4 }} transition={{ duration: 0.22, ease: EASE }}>
                    <Chiffre libelle="Entrées" valeur={fcfa(carte.entrees)} />
                    <Chiffre libelle="Sorties" valeur={fcfa(carte.sorties)} />
                    <Chiffre libelle="Opérations" valeur={carte.operations} />
                </motion.div>
            </AnimatePresence>
        </div>
    );
}
