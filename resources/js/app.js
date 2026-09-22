import './bootstrap';
import { basculerTheme, themeActuel } from './lib/theme';
import { initialiserChargement } from './chargement';
import { brancherSaisieCarte } from './carteSaisie';
import { initialiserInscriptionEtapes } from './inscriptionEtapes';

/* ==========================================================================
   Comportements communs de l'interface. Aucune dépendance.
   Chaque fonction est indépendante et ne fait rien si l'élément visé est absent.
   ========================================================================== */

const mouvementReduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---- Thème clair / sombre -------------------------------------------------
   Le thème initial est posé dans le <head> AVANT l'affichage (pas de flash de couleur).
   Ici : le bouton de bascule des pages qui n'ont pas l'en-tête React (connexion, inscription).
   Les pages avec en-tête utilisent le bouton React, qui appelle la même fonction (lib/theme.js). */
function initialiserTheme() {
    const bouton = document.querySelector('[data-theme-toggle]');
    if (!bouton) return;

    const libelle = bouton.querySelector('[data-theme-libelle]');

    const afficher = () => {
        const sombre = themeActuel() === 'dark';
        bouton.setAttribute('aria-pressed', sombre ? 'true' : 'false');
        if (libelle) libelle.textContent = sombre ? 'Thème sombre' : 'Thème clair';
    };

    afficher();
    bouton.addEventListener('click', () => {
        basculerTheme(bouton);
        afficher();
    });
}

/* ---- Titres : découpe en mots pour l'animation --------------------------------
   <h1 data-mots> : chaque mot est enveloppé dans deux <span> (masque + mot), en gardant
   les balises internes (<em>). Les espaces restent de vrais espaces : un lecteur d'écran
   lit la phrase normalement. */
function decouperEnMots(element) {
    let indice = 0;

    const traiter = (noeud) => {
        Array.from(noeud.childNodes).forEach((enfant) => {
            if (enfant.nodeType === Node.TEXT_NODE) {
                const fragment = document.createDocumentFragment();
                enfant.textContent.split(/(\s+)/).forEach((morceau) => {
                    if (morceau === '') return;
                    if (/^\s+$/.test(morceau)) {
                        fragment.append(document.createTextNode(morceau));
                        return;
                    }
                    const masque = document.createElement('span');
                    masque.className = 'mot';
                    const mot = document.createElement('span');
                    mot.className = 'mot-int';
                    mot.style.setProperty('--i', String(indice++));
                    mot.textContent = morceau;
                    masque.append(mot);
                    fragment.append(masque);
                });
                enfant.replaceWith(fragment);
            } else if (enfant.nodeType === Node.ELEMENT_NODE) {
                traiter(enfant);
            }
        });
    };

    traiter(element);
}

/* ---- Apparition au défilement ---------------------------------------------- */
function initialiserApparitions() {
    const titres = document.querySelectorAll('[data-mots]');
    const blocs = document.querySelectorAll('[data-reveal]');

    if (mouvementReduit || !('IntersectionObserver' in window)) {
        titres.forEach((t) => t.classList.add('est-visible'));
        blocs.forEach((b) => b.classList.add('est-visible'));
        return;
    }

    titres.forEach(decouperEnMots);

    const observateur = new IntersectionObserver(
        (entrees) => {
            entrees.forEach((entree) => {
                if (entree.isIntersecting) {
                    entree.target.classList.add('est-visible');
                    observateur.unobserve(entree.target);
                }
            });
        },
        { threshold: 0.15, rootMargin: '0px 0px -8% 0px' },
    );

    titres.forEach((t) => observateur.observe(t));
    blocs.forEach((b) => observateur.observe(b));
}

/* ---- Envoi de formulaire : bouton désactivé + « aria-busy » ------------------
   Empêche le double envoi (double paiement, double inscription...). */
function initialiserEnvois(racine = document) {
    racine.querySelectorAll('form:not([data-envoi-lie])').forEach((formulaire) => {
        formulaire.dataset.envoiLie = '';
        formulaire.addEventListener('submit', (evenement) => {
            const bouton = formulaire.querySelector('[type="submit"]');
            if (!bouton) return;

            if (bouton.getAttribute('aria-busy') === 'true') {
                evenement.preventDefault();
                return;
            }

            bouton.setAttribute('aria-busy', 'true');
            const texte = bouton.querySelector('[data-libelle]');
            const chargement = bouton.getAttribute('data-chargement');
            if (texte && chargement) {
                texte.dataset.libelleInitial = texte.textContent;
                texte.textContent = chargement;
            }
        });
    });

    // Retour arrière : le navigateur peut restaurer la page telle qu'elle était, bouton « occupé » compris.
    if (racine !== document) return;
    window.addEventListener('pageshow', (evenement) => {
        if (!evenement.persisted) return;

        document.querySelectorAll('[type="submit"][aria-busy="true"]').forEach((bouton) => {
            bouton.removeAttribute('aria-busy');
            const texte = bouton.querySelector('[data-libelle]');
            if (texte?.dataset.libelleInitial) texte.textContent = texte.dataset.libelleInitial;
        });
        document.querySelectorAll('[data-vide-desactive]').forEach((champ) => {
            champ.disabled = false;
            delete champ.dataset.videDesactive;
        });
    });
}

/* ---- Formulaires de recherche (GET) -------------------------------------------------
   <form data-get-propre> : les champs vides ne sont pas envoyés, l'adresse reste courte (/prestations?q=coiffure).
   <select data-auto-submit> : un changement relance la recherche tout de suite. */
function initialiserRecherche() {
    document.querySelectorAll('form[data-get-propre]').forEach((formulaire) => {
        formulaire.addEventListener('submit', () => {
            formulaire.querySelectorAll('input:not([type="checkbox"]):not([type="submit"]), select').forEach((champ) => {
                if (champ.name && champ.value.trim() === '') {
                    champ.disabled = true;
                    champ.dataset.videDesactive = '1';
                }
            });
        });
    });

    document.querySelectorAll('[data-auto-submit]').forEach((champ) => {
        champ.addEventListener('change', () => champ.form?.requestSubmit());
    });

    // Filtres : toujours déployés sur grand écran ; repliés sur téléphone tant qu'aucun filtre n'est actif.
    document.querySelectorAll('details[data-filtres]').forEach((details) => {
        // Seuil « grand écran » : 1024 px sur le site, 1280 px dans l'espace client (le menu latéral prend de la place).
        const large = window.matchMedia(`(min-width: ${Number(details.dataset.filtresSeuil) || 1024}px)`);
        const ajuster = () => {
            if (large.matches) details.open = true;
            else if (!details.hasAttribute('data-actif')) details.open = false;
        };
        ajuster();
        large.addEventListener('change', ajuster);
    });
}

/* ---- Envoi de photos : contrôle avant l'envoi -----------------------------------------
   <input type="file" data-photos data-max="6" data-poids-max="5120"> : trop de fichiers, mauvais format ou
   fichier trop lourd -> message immédiat (le serveur vérifie de toute façon), et la sélection est annulée. */
function initialiserPhotos() {
    document.querySelectorAll('input[type="file"][data-photos]').forEach((champ) => {
        const message = champ.closest('.champ')?.querySelector('[data-photos-erreur]');
        const maximum = Number(champ.dataset.max) || 6;
        const poidsMax = (Number(champ.dataset.poidsMax) || 5120) * 1024;
        const formats = ['image/jpeg', 'image/png', 'image/webp'];

        champ.addEventListener('change', () => {
            const fichiers = Array.from(champ.files ?? []);
            let erreur = '';

            if (fichiers.length > maximum) {
                erreur = maximum === 1 ? 'Choisissez une seule photo.' : `Vous pouvez envoyer ${maximum} photos au maximum.`;
            } else if (fichiers.some((f) => !formats.includes(f.type))) {
                erreur = 'Format non supporté : JPG, PNG ou WebP uniquement.';
            } else if (fichiers.some((f) => f.size > poidsMax)) {
                erreur = `Photo trop lourde (${Math.round(poidsMax / 1048576)} Mo maximum).`;
            }

            if (erreur) champ.value = '';
            if (message) {
                message.textContent = erreur;
                message.hidden = erreur === '';
            }
            if (erreur) champ.setAttribute('aria-invalid', 'true');
            else champ.removeAttribute('aria-invalid');
        });
    });
}

/* ---- Compteur de caractères : <textarea data-compteur="600"> ------------------------ */
function initialiserCompteurs() {
    document.querySelectorAll('textarea[data-compteur]').forEach((zone) => {
        const maximum = Number(zone.dataset.compteur);
        const compteur = document.createElement('p');
        compteur.className = 'champ-aide text-right tabular-nums';
        compteur.setAttribute('aria-hidden', 'true');
        zone.closest('.champ')?.append(compteur);

        const afficher = () => {
            compteur.textContent = `${zone.value.length} / ${maximum}`;
        };
        zone.addEventListener('input', afficher);
        afficher();
    });
}

/* ---- Afficher / masquer le mot de passe -------------------------------------- */
function initialiserMotsDePasse() {
    document.querySelectorAll('[data-toggle-mdp]').forEach((bouton) => {
        const champ = document.getElementById(bouton.getAttribute('aria-controls'));
        if (!champ) return;

        bouton.addEventListener('click', () => {
            const visible = champ.type === 'text';
            champ.type = visible ? 'password' : 'text';
            bouton.setAttribute('aria-pressed', visible ? 'false' : 'true');
            bouton.textContent = visible ? 'Afficher' : 'Masquer';
        });
    });
}

/* ---- Force du mot de passe -----------------------------------------------------
   Simple aide visuelle : la vraie règle (8 caractères, une lettre, un chiffre) est vérifiée
   par le serveur. La jauge ne dit jamais « refusé », elle encourage. */
function initialiserForceMotDePasse() {
    const etiquettes = ['', 'Faible', 'Moyen', 'Bon', 'Solide'];

    document.querySelectorAll('input[name="password"][autocomplete="new-password"]').forEach((champ) => {
        const conteneur = champ.closest('.champ');
        if (!conteneur) return;

        const jauge = document.createElement('div');
        jauge.className = 'force';
        jauge.dataset.niveau = '0';
        jauge.setAttribute('aria-hidden', 'true');
        jauge.innerHTML = '<i></i><i></i><i></i><i></i>';

        const texte = document.createElement('p');
        texte.className = 'force-texte';
        texte.setAttribute('role', 'status');

        conteneur.append(jauge, texte);

        let dernier = 0;
        champ.addEventListener('input', () => {
            const v = champ.value;
            let niveau = 0;
            if (v.length > 0) niveau = 1;
            if (v.length >= 8 && /[a-zA-Z]/.test(v) && /\d/.test(v)) niveau = 2;
            if (niveau === 2 && (v.length >= 10 || (/[a-z]/.test(v) && /[A-Z]/.test(v)))) niveau = 3;
            if (niveau === 3 && (v.length >= 12 || /[^A-Za-z0-9]/.test(v))) niveau = 4;

            jauge.dataset.niveau = String(niveau);
            if (niveau !== dernier) {
                texte.textContent = niveau ? `Sécurité : ${etiquettes[niveau]}` : '';
                dernier = niveau;
            }
        });
    });
}

/* ---- Progression du formulaire ---------------------------------------------------
   <div class="progres" data-progres-barre><i></i></div> dans un <form> : la barre se remplit à mesure
   que les champs obligatoires sont renseignés. Purement visuel (aria-hidden dans le Blade). */
function initialiserProgressionFormulaire() {
    document.querySelectorAll('form').forEach((formulaire) => {
        const barre = formulaire.querySelector('[data-progres-barre]');
        if (!barre) return;

        const calculer = () => {
            const groupes = new Set();
            let remplis = 0;

            formulaire.querySelectorAll('input[required], select[required], textarea[required]').forEach((champ) => {
                if (champ.type === 'radio') {
                    if (groupes.has(champ.name)) return;
                    groupes.add(champ.name);
                    if (formulaire.querySelector(`input[name="${champ.name}"]:checked`)) remplis++;
                    return;
                }
                groupes.add(champ);
                if (champ.value.trim() !== '') remplis++;
            });

            const total = Math.max(groupes.size, 1);
            barre.style.setProperty('--p', String(remplis / total));
        };

        formulaire.addEventListener('input', calculer);
        formulaire.addEventListener('change', calculer);
        calculer();
    });
}

/* ---- Menu latéral des espaces (tiroir sur téléphone et tablette) --------------------
   Le bouton ☰ (data-espace-ouvrir) ouvre le tiroir (data-espace-menu) ; le voile, la croix, Échap
   ou un clic sur un lien le referment. Quand il est fermé, le CSS le rend invisible ET inatteignable au
   clavier (visibility). Le focus va sur le tiroir à l'ouverture et revient sur le bouton à la fermeture. */
function initialiserMenuEspace() {
    const menu = document.querySelector('[data-espace-menu]');
    const ouvrir = document.querySelector('[data-espace-ouvrir]');
    if (!menu || !ouvrir) return;

    const voile = document.querySelector('[data-espace-voile]');
    const bureau = window.matchMedia('(min-width: 64rem)');

    const definir = (ouvert, rendreLeFocus = true) => {
        menu.toggleAttribute('data-ouvert', ouvert);
        voile?.toggleAttribute('data-visible', ouvert);
        ouvrir.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        document.body.style.overflow = ouvert ? 'hidden' : '';

        if (ouvert) {
            (menu.querySelector('[aria-current="page"]') ?? menu.querySelector('a, button'))?.focus();
        } else if (rendreLeFocus) {
            ouvrir.focus();
        }
    };

    ouvrir.addEventListener('click', () => definir(true));
    voile?.addEventListener('click', () => definir(false));
    menu.querySelector('[data-espace-fermer]')?.addEventListener('click', () => definir(false));
    menu.addEventListener('click', (evenement) => {
        if (evenement.target.closest('a')) definir(false, false);
    });
    document.addEventListener('keydown', (evenement) => {
        if (evenement.key === 'Escape' && menu.hasAttribute('data-ouvert')) definir(false);
    });
    // Passage en mode bureau (rotation, redimensionnement) : le tiroir n'a plus lieu d'être ouvert.
    bureau.addEventListener('change', () => {
        if (bureau.matches && menu.hasAttribute('data-ouvert')) definir(false, false);
    });
}

/* ---- Confirmation avant une action grave : <form data-confirmer="Supprimer ce compte ?"> -------------
   Boîte <dialog> native (focus piégé, Échap gérés par le navigateur). Sans JavaScript, le formulaire part
   directement : c'est pourquoi le serveur ne se fie JAMAIS à cette confirmation pour se protéger. */
let dialogue = null; // la boîte de confirmation est partagée par toutes les pages (et par les zones rafraîchies en direct)

function initialiserConfirmations(racine = document) {
    const creer = () => {
        const boite = document.createElement('dialog');
        boite.className = 'dialogue';
        boite.setAttribute('aria-labelledby', 'dialogue-titre');
        boite.innerHTML =
            '<form method="dialog">' +
            '<h2 id="dialogue-titre">Confirmer</h2><p data-dialogue-texte></p>' +
            '<div class="dialogue-actions">' +
            '<button type="submit" value="non" class="btn btn-petit" autofocus><span>Annuler</span></button>' +
            '<button type="submit" value="oui" class="btn btn-petit btn-danger" data-dialogue-oui><span>Confirmer</span></button>' +
            '</div></form>';
        document.body.append(boite);
        // Un clic sur le fond (en dehors de la carte) équivaut à « Annuler ».
        boite.addEventListener('click', (evenement) => {
            if (evenement.target === boite) boite.close('non');
        });
        return boite;
    };

    racine.querySelectorAll('form[data-confirmer]:not([data-conf-lie])').forEach((formulaire) => {
        formulaire.dataset.confLie = '';
        formulaire.addEventListener('submit', (evenement) => {
            if (formulaire.dataset.confirme === '1' || typeof HTMLDialogElement === 'undefined') return;

            evenement.preventDefault();
            evenement.stopImmediatePropagation(); // le verrou anti double envoi ne doit pas se déclencher avant la confirmation

            dialogue ??= creer();
            dialogue.querySelector('[data-dialogue-texte]').textContent = formulaire.dataset.confirmer;
            dialogue.querySelector('[data-dialogue-oui] span').textContent = formulaire.dataset.confirmerBouton || 'Confirmer';
            // Une confirmation « neutre » (ex. confirmer la réception) n'est pas rouge : le rouge est réservé à ce qui détruit.
            dialogue.querySelector('[data-dialogue-oui]').classList.toggle('btn-danger', formulaire.dataset.confirmerTon !== 'neutre');
            dialogue.returnValue = '';
            dialogue.addEventListener(
                'close',
                () => {
                    if (dialogue.returnValue === 'oui') {
                        formulaire.dataset.confirme = '1';
                        formulaire.requestSubmit();
                    }
                },
                { once: true },
            );
            dialogue.showModal();
        });
    });
}

/* ---- Boîtes de formulaire : <button data-dialogue-ouvrir="recharge"> ouvre <dialog id="recharge"> -------------
   Échap et le clic sur le fond ferment ; <dialog data-ouvert-auto> s'ouvre au chargement (après une erreur de saisie,
   pour que la personne retrouve son formulaire). Les boutons [data-montant] remplissent le champ « montant » de la boîte. */
function initialiserBoitesFormulaire(racine = document) {
    racine.querySelectorAll('[data-dialogue-ouvrir]:not([data-ouvrir-lie])').forEach((declencheur) => {
        declencheur.dataset.ouvrirLie = '';
        declencheur.addEventListener('click', () => {
            const boite = document.getElementById(declencheur.dataset.dialogueOuvrir);
            if (boite && typeof boite.showModal === 'function') boite.showModal();
        });
    });

    racine.querySelectorAll('dialog[data-boite]:not([data-boite-lie])').forEach((boite) => {
        boite.dataset.boiteLie = '';
        boite.addEventListener('click', (evenement) => {
            if (evenement.target === boite) boite.close();
        });
        boite.querySelectorAll('[data-dialogue-fermer]').forEach((bouton) => bouton.addEventListener('click', () => boite.close()));
        boite.querySelectorAll('[data-montant]').forEach((bouton) => {
            bouton.addEventListener('click', () => {
                const champ = boite.querySelector('input[name="montant"]');
                if (!champ) return;
                champ.value = bouton.dataset.montant;
                champ.dispatchEvent(new Event('input', { bubbles: true }));
                champ.focus();
            });
        });
        // Champs qui dépendent du moyen de paiement choisi : data-si-methode="X" (visible seulement pour X), data-sauf-methode="X" (caché pour X).
        boite.querySelectorAll('form').forEach((formulaire) => {
            const conditionnels = formulaire.querySelectorAll('[data-si-methode], [data-sauf-methode]');
            const radios = formulaire.querySelectorAll('input[type="radio"][name="methode"]');
            if (conditionnels.length === 0 || radios.length === 0) return;
            const appliquer = () => {
                const choisie = formulaire.querySelector('input[type="radio"][name="methode"]:checked')?.value ?? '';
                conditionnels.forEach((bloc) => {
                    bloc.hidden = bloc.dataset.siMethode !== undefined ? bloc.dataset.siMethode !== choisie : bloc.dataset.saufMethode === choisie;
                });
            };
            radios.forEach((radio) => radio.addEventListener('change', appliquer));
            appliquer();
        });

        boite.querySelectorAll('form[data-saisie-carte]').forEach(brancherSaisieCarte);

        if (boite.hasAttribute('data-ouvert-auto') && typeof boite.showModal === 'function') boite.showModal();
    });
}

/* ---- Solde masqué (l'œil de la bannière du wallet) -----------------------------------------------------------
   Le choix est posé sur <html data-solde-masque> : il survit au rafraîchissement en direct de la bannière. Mémorisé (facultatif). */
function initialiserSoldeMasque() {
    const racine = document.documentElement;
    try {
        if (localStorage.getItem('km-solde-masque') === '1') racine.setAttribute('data-solde-masque', '');
    } catch (e) {
        /* stockage indisponible : le solde reste visible */
    }

    const synchroniser = () => {
        const masque = racine.hasAttribute('data-solde-masque');
        document.querySelectorAll('[data-masquer-solde]').forEach((bouton) => {
            bouton.setAttribute('aria-pressed', masque ? 'true' : 'false');
            bouton.setAttribute('aria-label', masque ? 'Afficher le solde' : 'Masquer le solde');
        });
    };

    // Délégation : la bannière peut être remplacée par le temps réel sans perdre le bouton.
    document.addEventListener('click', (evenement) => {
        if (!evenement.target.closest?.('[data-masquer-solde]')) return;
        racine.toggleAttribute('data-solde-masque');
        try {
            localStorage.setItem('km-solde-masque', racine.hasAttribute('data-solde-masque') ? '1' : '0');
        } catch (e) {
            /* rien */
        }
        synchroniser();
    });
    synchroniser();
}

/* ---- Cartes du wallet : la pile (îlot React) annonce la carte choisie, les formulaires du serveur la suivent -------------
   Chaque carte a son bloc d'actions <div data-carte-actions="ID"> (geler, supprimer, recharger avec...), rendu par Blade avec
   son jeton CSRF. Un seul bloc est visible : celui de la carte du dessus. Les listes « Carte utilisée » des boîtes de recharge
   et de retrait se placent aussi sur cette carte (sauf si elle est gelée : le serveur la refuserait). Sans JavaScript, tous
   les blocs restent visibles et les listes se règlent à la main. */
function initialiserCartes() {
    const zone = document.querySelector('[data-cartes-zone]');
    if (!zone) return;

    const selectionner = (id) => {
        zone.querySelectorAll('[data-carte-actions]').forEach((bloc) => {
            bloc.hidden = bloc.dataset.carteActions !== String(id);
        });
        document.querySelectorAll('select[data-carte-champ]').forEach((liste) => {
            const option = liste.querySelector(`option[value="${id}"]`);
            if (option && !option.disabled) liste.value = String(id);
        });
        // Un rafraîchissement de la page garde la même carte (rien à retenir quand il n'y a encore aucune carte).
        if (!id) return;
        const adresse = new URL(window.location.href);
        adresse.searchParams.set('carte', id);
        window.history.replaceState(null, '', adresse);
    };

    document.addEventListener('carte:choisie', (evenement) => selectionner(evenement.detail.id));

    // Une carte vient d'être gelée / dégelée par l'îlot : les listes « Carte utilisée » suivent (une carte gelée n'est plus proposée).
    document.addEventListener('carte:gel', (evenement) => {
        const { id, gelee } = evenement.detail;
        document.querySelectorAll('select[data-carte-champ]').forEach((liste) => {
            const option = liste.querySelector(`option[value="${id}"]`);
            if (!option) return;
            option.disabled = gelee;
            option.textContent = option.textContent.replace(/ \(gelée\)$/, '') + (gelee ? ' (gelée)' : '');
            if (gelee && liste.value === String(id)) {
                const libre = [...liste.options].find((o) => !o.disabled);
                if (libre) liste.value = libre.value;
            }
        });
    });

    selectionner(zone.dataset.carteInitiale);
}

/* ---- Zones rafraîchies en direct : on rebranche seulement ce qui vient d'être remplacé -------------------------- */
export function rebrancher(racine) {
    initialiserBoitesFormulaire(racine);
    initialiserConfirmations(racine);
    initialiserEnvois(racine);
    racine.querySelectorAll('[data-reveal], [data-mots]').forEach((element) => element.classList.add('est-visible'));
    if (racine.querySelector('[data-island]')) import('./islands.jsx').then((module) => module.monter(racine));
}

/* ---- Temps réel : une connexion par page (flux du serveur), partagée par les pastilles, les zones vivantes et les îlots ----
   La page annonce les adresses dans data-temps-reel / data-temps-reel-sonder de <body> (seulement pour une personne connectée). */
function initialiserTempsReel() {
    const { tempsReel, tempsReelSonder, tempsReelMode } = document.body.dataset;
    if (!tempsReel) return;

    Promise.all([import('./lib/tempsReel'), import('./lib/regions'), import('./lib/badges')])
        .then(([flux, regions, badges]) => {
            badges.brancherBadges();
            regions.brancherRegions(rebrancher);
            flux.demarrer({ flux: tempsReel, sonder: tempsReelSonder, mode: tempsReelMode });
        })
        .catch((erreur) => console.error('Temps réel indisponible', erreur));
}

/* ---- Diagrammes de la page « Métriques » : bulle au survol (chargée seulement s'il y a un diagramme) ---- */
function initialiserGraphes() {
    if (!document.querySelector('[data-graphe]')) return;
    import('./lib/graphes').then((module) => module.brancherGraphes());
}

/* ---- Îlots React : chargés seulement si la page en contient ------------------------ */
function initialiserIlots() {
    if (!document.querySelector('[data-island]')) return;
    import('./islands.jsx').then((module) => module.monter());
}

document.addEventListener('DOMContentLoaded', () => {
    initialiserChargement();
    initialiserTheme();
    initialiserApparitions();
    initialiserMenuEspace();
    initialiserBoitesFormulaire();
    initialiserCartes();
    initialiserSoldeMasque();
    initialiserConfirmations(); // avant initialiserEnvois : il doit voir l'envoi en premier
    initialiserEnvois();
    initialiserRecherche();
    initialiserPhotos();
    initialiserCompteurs();
    initialiserMotsDePasse();
    initialiserForceMotDePasse();
    initialiserProgressionFormulaire();
    initialiserInscriptionEtapes();
    initialiserIlots();
    initialiserGraphes();
    initialiserTempsReel();
});
