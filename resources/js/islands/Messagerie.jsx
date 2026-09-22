import { AnimatePresence, motion } from 'motion/react';
import { ArrowDown, ArrowLeft, Check, CheckCheck, MessageSquare, Send } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Avatar } from '../components/ui/Avatar';
import { EASE } from '../components/motion/Reveal';
import { cn } from '../lib/cn';
import { ilYA } from '../lib/iconesNotif';
import { envoyerJson, lireJson, sur } from '../lib/tempsReel';

/* ==========================================================================
   Messagerie : la liste des discussions (une par commande) et le fil de la discussion ouverte.
   --------------------------------------------------------------------------
   Le serveur écrit déjà la page complète (elle marche sans JavaScript : formulaire d'envoi classique). Ici, tout devient vivant :
   - un message envoyé apparaît tout de suite chez soi (envoi « optimiste »), puis chez l'autre en direct ;
   - « X est en train d'écrire… », accusés de lecture (✓ envoyé, ✓✓ lu) ;
   - la liste se réordonne, les compteurs de non-lus suivent, sans jamais recharger la page.
   Toutes les règles (qui peut écrire, longueur, « lu ») restent sur le serveur : ce composant ne fait que les afficher.
   ========================================================================== */

const MAX = 2000;
const TEMPS_ECRIT = 4000;
const jour = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
const heure = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });

const cle = (date) => new Date(date).toDateString();
function libelleJour(date) {
    const d = new Date(date);
    const aujourdhui = new Date();
    const hier = new Date(aujourdhui.getTime() - 86400000);
    if (d.toDateString() === aujourdhui.toDateString()) return "Aujourd'hui";
    if (d.toDateString() === hier.toDateString()) return 'Hier';
    return jour.format(d);
}

const adresse = (modele, id) => modele.replace('__ID__', String(id));
const idDepuisUrl = () => Number(window.location.pathname.match(/^\/messages\/(\d+)/)?.[1]) || null;

/** Les infos d'une discussion ouverte : « dernier message » de l'autre, statut... */
function Entete({ echange, urls, surRetour }) {
    return (
        <header className="msg-entete">
            <button type="button" className="msg-retour" onClick={surRetour} aria-label="Retour à la liste des discussions">
                <ArrowLeft className="size-5" aria-hidden="true" />
            </button>
            <Avatar nom={echange.autre.nom} teinte={echange.autre.teinte} src={echange.autre.avatar} className="size-10 text-sm" />
            <div className="min-w-0 flex-1">
                <p className="msg-nom">{echange.autre.nom} <span className="msg-role">{echange.autre.role}</span></p>
                <p className="msg-sujet">{echange.titre}</p>
            </div>
            <span className={cn('statut', `statut-${echange.nuance}`)}>{echange.statut}</span>
            <a className="btn btn-petit shrink-0" href={adresse(urls.commande, echange.commande_id)}><span><span className="max-sm:hidden">Voir la </span><span className="sm:hidden">C</span><span className="max-sm:hidden">c</span>ommande</span></a>
        </header>
    );
}

function Bulle({ message, moi }) {
    const mien = message.expediteur_id === moi;
    return (
        <motion.div
            layout="position"
            className={cn('msg-ligne', mien ? 'msg-ligne-moi' : 'msg-ligne-autre')}
            initial={message.neuf ? { opacity: 0, y: 10, scale: 0.97 } : false}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            transition={{ duration: 0.25, ease: EASE }}
        >
            <div className={cn('msg-bulle', mien ? 'msg-bulle-moi' : 'msg-bulle-autre', message.etat === 'echec' && 'msg-bulle-echec')}>
                <p className="msg-texte">{message.contenu}</p>
                <p className="msg-meta">
                    <time dateTime={message.date}>{heure.format(new Date(message.date))}</time>
                    {mien && (
                        <span className={cn('msg-coche', message.lu && 'msg-coche-lu')} title={message.lu ? 'Lu' : message.etat === 'envoi' ? 'Envoi…' : 'Envoyé'}>
                            {message.lu ? <CheckCheck className="size-3.5" aria-label="Lu" /> : <Check className="size-3.5" aria-label={message.etat === 'envoi' ? 'Envoi en cours' : 'Envoyé'} />}
                        </span>
                    )}
                </p>
            </div>
        </motion.div>
    );
}

export default function Messagerie({ echanges: echangesInitiaux = [], courante = null, moi, urls }) {
    const [echanges, setEchanges] = useState(echangesInitiaux);
    const [actif, setActif] = useState(courante?.echange.commande_id ?? null);
    const [fil, setFil] = useState(courante); // { echange, messages, plus_anciens }
    const [chargement, setChargement] = useState(false);
    const [ecrit, setEcrit] = useState(false);
    const [brouillon, setBrouillon] = useState('');
    const [erreur, setErreur] = useState('');
    const [nouveaux, setNouveaux] = useState(0);
    const [ancienChargement, setAncienChargement] = useState(false);

    const defilement = useRef(null);
    const champ = useRef(null);
    const epingle = useRef(true); // la personne est en bas du fil : on la suit
    const actifRef = useRef(actif);
    const minuteurEcrit = useRef(null);
    const dernierEcrit = useRef(0);
    const minuteurLu = useRef(null);
    const compteur = useRef(0);
    const aDefiler = useRef('instant'); // 'instant' (ouverture) | 'bas' (en douceur) | 'garder' | null

    const echangesRef = useRef(echanges);

    actifRef.current = actif;
    echangesRef.current = echanges;

    // ------------------------------------------------------------ Outils

    const majEchange = useCallback((id, modif) => {
        setEchanges((liste) => {
            const indice = liste.findIndex((e) => e.commande_id === id);
            if (indice === -1) return liste;
            const modifie = { ...liste[indice], ...(typeof modif === 'function' ? modif(liste[indice]) : modif) };
            return [modifie, ...liste.filter((_, i) => i !== indice)]; // la plus récente activité en haut
        });
    }, []);

    const majSansReordonner = useCallback((id, modif) => {
        setEchanges((liste) => liste.map((e) => (e.commande_id === id ? { ...e, ...(typeof modif === 'function' ? modif(e) : modif) } : e)));
    }, []);

    const marquerLu = useCallback(() => {
        const id = actifRef.current;
        if (!id || document.visibilityState !== 'visible') return;
        clearTimeout(minuteurLu.current);
        minuteurLu.current = setTimeout(() => {
            envoyerJson(adresse(urls.lu, id));
            majSansReordonner(id, { non_lus: 0 });
        }, 350);
    }, [urls.lu, majSansReordonner]);

    const ajouterMessage = useCallback((message, { neuf = true } = {}) => {
        setFil((f) => {
            if (!f || f.messages.some((m) => m.id === message.id)) return f;
            return { ...f, messages: [...f.messages, { ...message, neuf }] };
        });
    }, []);

    // ---------------------------------------------------- Ouvrir une discussion

    const ouvrir = useCallback(
        async (id, { historique = true } = {}) => {
            setActif(id);
            setErreur('');
            setBrouillon('');
            setNouveaux(0);
            setEcrit(false);
            setChargement(true);
            aDefiler.current = 'instant';
            epingle.current = true;

            if (historique && idDepuisUrl() !== id) window.history.pushState({ commande: id }, '', adresse(urls.page, id));

            const { ok, donnees } = await lireJson(adresse(urls.fil, id));
            // Entre-temps, la personne a peut-être ouvert une autre discussion : on ne mélange pas.
            if (actifRef.current !== id) return;
            setChargement(false);

            if (!ok) {
                setFil(null);
                setErreur("Cette discussion n'a pas pu être ouverte.");
                return;
            }
            setFil(donnees);
            majSansReordonner(id, { ...donnees.echange, non_lus: 0 });
            if (window.matchMedia('(min-width: 64rem)').matches) setTimeout(() => champ.current?.focus({ preventScroll: true }), 50);
        },
        [urls.fil, urls.page, majSansReordonner],
    );

    const retourListe = useCallback(() => {
        setActif(null);
        setFil(null);
        if (idDepuisUrl() !== null) window.history.pushState({}, '', urls.liste);
    }, [urls.liste]);

    useEffect(() => {
        const surRetour = () => {
            const id = idDepuisUrl();
            if (id) ouvrir(id, { historique: false });
            else {
                setActif(null);
                setFil(null);
            }
        };
        window.addEventListener('popstate', surRetour);
        return () => window.removeEventListener('popstate', surRetour);
    }, [ouvrir]);

    // ---------------------------------------------------------- Temps réel

    useEffect(() => {
        const arrets = [];

        arrets.push(
            sur('message', (d) => {
                const { commande_id: id, message } = d;
                const monMessage = message.expediteur_id === moi;
                const ouverte = actifRef.current === id;
                const vu = ouverte && document.visibilityState === 'visible';

                if (ouverte) {
                    if (!monMessage) {
                        setEcrit(false);
                        if (!epingle.current) setNouveaux((n) => n + 1);
                    }
                    aDefiler.current = epingle.current || monMessage ? 'bas' : 'garder';
                    ajouterMessage(message);
                    if (!monMessage) marquerLu();
                }

                const connu = echangesRef.current.some((e) => e.commande_id === id);
                if (!connu) {
                    // Une conversation d'une commande que la liste ne connaît pas encore : on la récupère.
                    lireJson(adresse(urls.fil, id)).then(({ ok, donnees }) => {
                        if (ok) setEchanges((l) => (l.some((e) => e.commande_id === id) ? l : [{ ...donnees.echange, non_lus: monMessage || vu ? 0 : 1 }, ...l]));
                    });
                    return;
                }

                majEchange(id, (e) => ({
                    apercu: message.contenu.length > 70 ? `${message.contenu.slice(0, 69)}…` : message.contenu,
                    apercu_moi: monMessage,
                    date: message.date,
                    a_des_messages: true,
                    non_lus: monMessage || vu ? e.non_lus : e.non_lus + 1,
                }));
            }),
        );

        // L'autre personne a lu mes messages : ✓✓
        arrets.push(
            sur('messages_lus', (d) => {
                if (actifRef.current !== d.commande_id) return;
                setFil((f) => (f ? { ...f, messages: f.messages.map((m) => (m.expediteur_id === moi ? { ...m, lu: true, neuf: false } : m)) } : f));
            }),
        );

        // J'ai lu (dans un autre onglet) : la pastille de cette discussion tombe à zéro.
        arrets.push(sur('non_lus', (d) => majSansReordonner(d.commande_id, { non_lus: 0 })));

        arrets.push(
            sur('ecrit', (d) => {
                if (actifRef.current !== d.commande_id) return;
                setEcrit(true);
                clearTimeout(minuteurEcrit.current);
                minuteurEcrit.current = setTimeout(() => setEcrit(false), TEMPS_ECRIT);
            }),
        );

        // Le statut de la commande change (acceptée, terminée...) : l'en-tête et la liste le montrent aussitôt.
        arrets.push(
            sur('commande', async (d) => {
                const { ok, donnees } = await lireJson(adresse(urls.fil, d.commande_id) + '?avant=1'); // « avant=1 » : pas de marquage de lecture
                if (!ok) return;
                majSansReordonner(d.commande_id, (e) => ({ ...donnees.echange, apercu: e.apercu, apercu_moi: e.apercu_moi, date: e.date, a_des_messages: e.a_des_messages, non_lus: e.non_lus }));
                setFil((f) => (f && f.echange.commande_id === d.commande_id ? { ...f, echange: { ...f.echange, statut: donnees.echange.statut, nuance: donnees.echange.nuance } } : f));
            }),
        );

        return () => {
            arrets.forEach((arret) => arret());
            clearTimeout(minuteurEcrit.current);
            clearTimeout(minuteurLu.current);
        };
    }, [moi, urls.fil, ajouterMessage, marquerLu, majEchange, majSansReordonner]);

    // Retour sur l'onglet : ce qui est arrivé pendant qu'il était caché est lu maintenant.
    useEffect(() => {
        const surVisible = () => {
            if (document.visibilityState === 'visible') marquerLu();
        };
        document.addEventListener('visibilitychange', surVisible);
        return () => document.removeEventListener('visibilitychange', surVisible);
    }, [marquerLu]);

    // ------------------------------------------------------ Défilement du fil

    useLayoutEffect(() => {
        const zone = defilement.current;
        if (!zone || !fil || aDefiler.current === null) return;
        if (aDefiler.current === 'bas' || aDefiler.current === 'instant') zone.scrollTo({ top: zone.scrollHeight, behavior: aDefiler.current === 'instant' ? 'auto' : 'smooth' });
        aDefiler.current = null;
    }, [fil]);

    const surDefilement = () => {
        const zone = defilement.current;
        if (!zone) return;
        epingle.current = zone.scrollHeight - zone.scrollTop - zone.clientHeight < 90;
        if (epingle.current && nouveaux > 0) setNouveaux(0);
    };

    const descendre = () => {
        defilement.current?.scrollTo({ top: defilement.current.scrollHeight, behavior: 'smooth' });
        setNouveaux(0);
    };

    const chargerAnciens = async () => {
        const premier = fil?.messages.find((m) => typeof m.id === 'number');
        if (!premier || ancienChargement) return;
        setAncienChargement(true);
        const zone = defilement.current;
        const avant = zone ? zone.scrollHeight - zone.scrollTop : 0;
        const { ok, donnees } = await lireJson(`${adresse(urls.fil, actif)}?avant=${premier.id}`);
        setAncienChargement(false);
        if (!ok) return;
        aDefiler.current = null;
        setFil((f) => (f ? { ...f, plus_anciens: donnees.plus_anciens, messages: [...donnees.messages.map((m) => ({ ...m, neuf: false })), ...f.messages] } : f));
        // On garde à l'écran le message qu'on regardait.
        requestAnimationFrame(() => {
            if (zone) zone.scrollTop = zone.scrollHeight - avant;
        });
    };

    // -------------------------------------------------------------- Envoi

    const envoyer = async (contenuEnvoye, idProvisoire = null) => {
        const contenu = contenuEnvoye.trim();
        if (!contenu || !actif) return;
        const id = actif;

        compteur.current += 1;
        const provisoire = idProvisoire ?? `tmp-${compteur.current}`;
        setErreur('');

        if (idProvisoire) {
            setFil((f) => (f ? { ...f, messages: f.messages.map((m) => (m.id === provisoire ? { ...m, etat: 'envoi' } : m)) } : f));
        } else {
            aDefiler.current = 'bas';
            epingle.current = true;
            setBrouillon('');
            setFil((f) => (f ? { ...f, messages: [...f.messages, { id: provisoire, expediteur_id: moi, contenu, lu: false, date: new Date().toISOString(), etat: 'envoi', neuf: true }] } : f));
        }

        const { ok, statut, donnees } = await envoyerJson(adresse(urls.envoyer, id), { contenu });

        if (ok) {
            const reel = donnees.message;
            setFil((f) => {
                if (!f) return f;
                // Le message peut déjà être arrivé par le flux (mon autre onglet, ou la même réponse) : jamais deux fois.
                const sansProvisoire = f.messages.filter((m) => m.id !== provisoire);
                return { ...f, messages: sansProvisoire.some((m) => m.id === reel.id) ? sansProvisoire : [...sansProvisoire, { ...reel, neuf: false }] };
            });
            majEchange(id, { apercu: reel.contenu.length > 70 ? `${reel.contenu.slice(0, 69)}…` : reel.contenu, apercu_moi: true, date: reel.date, a_des_messages: true });
            return;
        }

        if (statut === 422 || statut === 404 || statut === 419 || statut === 429) {
            // Refus du serveur : le message n'existe pas ; on le retire et on rend le texte à la personne.
            setFil((f) => (f ? { ...f, messages: f.messages.filter((m) => m.id !== provisoire) } : f));
            setBrouillon(contenu);
            setErreur(donnees?.message ?? (statut === 429 ? 'Vous écrivez trop vite : patientez un instant.' : "Le message n'a pas pu être envoyé."));
            return;
        }

        // Réseau coupé : le message reste affiché, marqué « non envoyé », avec « Réessayer ».
        setFil((f) => (f ? { ...f, messages: f.messages.map((m) => (m.id === provisoire ? { ...m, etat: 'echec' } : m)) } : f));
    };

    const surSaisie = (e) => {
        const valeur = e.target.value.slice(0, MAX);
        setBrouillon(valeur);
        setErreur('');
        // Auto-agrandissement du champ (jusqu'à 7 lignes)
        e.target.style.height = 'auto';
        e.target.style.height = `${Math.min(e.target.scrollHeight, 168)}px`;

        // « En train d'écrire » : au plus un signal toutes les 2,5 s
        if (valeur.trim() !== '' && Date.now() - dernierEcrit.current > 2500) {
            dernierEcrit.current = Date.now();
            envoyerJson(adresse(urls.ecrit, actif));
        }
    };

    const surTouche = (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            envoyer(brouillon);
        }
    };

    useEffect(() => {
        if (champ.current && brouillon === '') champ.current.style.height = 'auto';
    }, [brouillon]);

    // --------------------------------------------------------------- Rendu

    const messages = fil?.messages ?? [];
    const groupes = useMemo(() => {
        const sortie = [];
        let dernierJour = null;
        messages.forEach((m, i) => {
            const j = cle(m.date);
            if (j !== dernierJour) {
                sortie.push({ type: 'jour', cle: `j-${j}`, date: m.date });
                dernierJour = j;
            }
            sortie.push({ type: 'message', cle: `m-${m.id}`, message: m, suite: i > 0 && messages[i - 1].expediteur_id === m.expediteur_id && cle(messages[i - 1].date) === j });
        });
        return sortie;
    }, [messages]);

    const totalNonLus = echanges.reduce((somme, e) => somme + e.non_lus, 0);

    return (
        <div className="msg" data-vue={actif ? 'fil' : 'liste'}>
            {/* ----------------------------------------------------- Liste des discussions */}
            <aside className="msg-liste" aria-label="Vos discussions">
                <div className="msg-liste-haut">
                    <h2>Discussions</h2>
                    {totalNonLus > 0 && <span className="msg-total">{totalNonLus} non lu{totalNonLus > 1 ? 's' : ''}</span>}
                </div>

                {echanges.length === 0 ? (
                    <p className="msg-vide-liste">Aucune commande pour le moment. Dès que vous commandez (ou recevez une commande), la discussion s'ouvre ici.</p>
                ) : (
                    <ul role="list" className="msg-items">
                        {echanges.map((e) => (
                            <li key={e.commande_id}>
                                <a
                                    href={e.url}
                                    className={cn('msg-item', actif === e.commande_id && 'msg-item-actif')}
                                    aria-current={actif === e.commande_id ? 'true' : undefined}
                                    onClick={(ev) => {
                                        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
                                        ev.preventDefault();
                                        ouvrir(e.commande_id);
                                    }}
                                >
                                    <Avatar nom={e.autre.nom} teinte={e.autre.teinte} src={e.autre.avatar} className="size-11 text-sm" />
                                    <span className="min-w-0 flex-1">
                                        <span className="msg-item-haut">
                                            <span className={cn('msg-item-nom', e.non_lus > 0 && 'msg-item-nom-fort')}>{e.autre.nom}</span>
                                            <time className="msg-item-date" dateTime={e.date}>{e.a_des_messages ? ilYA(e.date) : ''}</time>
                                        </span>
                                        <span className="msg-item-sujet">{e.titre}</span>
                                        <span className={cn('msg-item-apercu', e.non_lus > 0 && 'msg-item-apercu-fort')}>
                                            {e.a_des_messages ? (e.apercu_moi ? <><span className="msg-vous">Vous : </span>{e.apercu}</> : e.apercu) : 'Aucun message pour le moment'}
                                        </span>
                                    </span>
                                    {e.non_lus > 0 && <span className="msg-pastille" aria-label={`${e.non_lus} non lu${e.non_lus > 1 ? 's' : ''}`}>{e.non_lus}</span>}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </aside>

            {/* ----------------------------------------------------------- Le fil ouvert */}
            <section className="msg-fil" aria-label="Discussion">
                {!fil && !chargement && (
                    <div className="msg-vide-fil">
                        <span className="msg-vide-icone"><MessageSquare className="size-6" aria-hidden="true" /></span>
                        <p className="font-medium">{erreur || 'Choisissez une discussion'}</p>
                        <p className="text-sm text-soft">{erreur ? 'Retournez à la liste et réessayez.' : 'Vos messages arrivent ici en direct, sans actualiser la page.'}</p>
                    </div>
                )}

                {actif && (
                    <>
                        {fil ? (
                            <Entete echange={fil.echange} urls={urls} surRetour={retourListe} />
                        ) : (
                            <header className="msg-entete">
                                <button type="button" className="msg-retour" onClick={retourListe} aria-label="Retour à la liste des discussions"><ArrowLeft className="size-5" aria-hidden="true" /></button>
                                <p className="text-sm text-soft">Chargement…</p>
                            </header>
                        )}

                        <div className="msg-defilement" ref={defilement} onScroll={surDefilement} role="log" aria-live="polite" aria-label="Messages de la discussion">
                            {fil?.plus_anciens && (
                                <div className="msg-anciens">
                                    <button type="button" className="btn btn-petit" onClick={chargerAnciens} disabled={ancienChargement}>
                                        <span>{ancienChargement ? 'Chargement…' : 'Messages plus anciens'}</span>
                                    </button>
                                </div>
                            )}

                            {fil && messages.length === 0 && <p className="msg-debut">C'est le début de votre discussion. Écrivez le premier message.</p>}

                            {groupes.map((g) =>
                                g.type === 'jour' ? (
                                    <p key={g.cle} className="msg-jour"><span>{libelleJour(g.date)}</span></p>
                                ) : (
                                    <div key={g.cle} className={cn(g.suite && 'msg-suite')}>
                                        <Bulle message={g.message} moi={moi} />
                                        {g.message.etat === 'echec' && (
                                            <p className="msg-echec">
                                                Non envoyé.{' '}
                                                <button type="button" className="msg-reessayer" onClick={() => envoyer(g.message.contenu, g.message.id)}>Réessayer</button>
                                            </p>
                                        )}
                                    </div>
                                ),
                            )}

                            <AnimatePresence>
                                {ecrit && fil && (
                                    <motion.p key="ecrit" className="msg-ecrit" initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.2 }}>
                                        <span className="msg-points" aria-hidden="true"><i /><i /><i /></span>
                                        {fil.echange.autre.prenom} est en train d'écrire…
                                    </motion.p>
                                )}
                            </AnimatePresence>
                        </div>

                        <AnimatePresence>
                            {nouveaux > 0 && (
                                <motion.button
                                    type="button"
                                    className="msg-nouveaux"
                                    onClick={descendre}
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: 8 }}
                                    transition={{ duration: 0.2, ease: EASE }}
                                >
                                    <ArrowDown className="size-4" aria-hidden="true" /> {nouveaux} nouveau{nouveaux > 1 ? 'x' : ''} message{nouveaux > 1 ? 's' : ''}
                                </motion.button>
                            )}
                        </AnimatePresence>

                        <form
                            className="msg-composer"
                            onSubmit={(e) => {
                                e.preventDefault();
                                envoyer(brouillon);
                            }}
                        >
                            {erreur && fil && <p className="msg-erreur" role="alert">{erreur}</p>}
                            <div className="msg-saisie">
                                <label htmlFor="msg-contenu" className="sr-only">Votre message</label>
                                <textarea
                                    id="msg-contenu"
                                    ref={champ}
                                    rows={1}
                                    value={brouillon}
                                    onChange={surSaisie}
                                    onKeyDown={surTouche}
                                    placeholder="Écrivez votre message…"
                                    maxLength={MAX}
                                    disabled={!fil}
                                />
                                <button type="submit" className="msg-envoyer" disabled={!fil || brouillon.trim() === ''} aria-label="Envoyer le message">
                                    <Send className="size-4" aria-hidden="true" />
                                </button>
                            </div>
                            <p className="msg-aide">
                                <span>Entrée pour envoyer, Maj + Entrée pour aller à la ligne.</span>
                                {brouillon.length > MAX - 300 && <span className="tabular-nums">{brouillon.length} / {MAX}</span>}
                            </p>
                        </form>
                    </>
                )}
            </section>
        </div>
    );
}
