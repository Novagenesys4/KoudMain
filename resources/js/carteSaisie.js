/* ==========================================================================
   Formulaire « Ajouter une carte »
   - le numéro se met en forme pendant la frappe (4-4-4-4, ou 4-6-5 pour American Express) ;
   - le réseau se lit sur les premiers chiffres, la longueur du numéro et du code de sécurité s'adaptent ;
   - la date prend son « / » toute seule ;
   - l'aperçu de la carte se remplit en direct.
   Ces contrôles ne sont qu'un confort : le serveur refait TOUS les contrôles (Luhn, date, code de sécurité).
   Rien de ce qui est tapé ici n'est stocké côté navigateur.
   ========================================================================== */

const RESEAUX = { visa: 'VISA', mastercard: 'Mastercard', amex: 'AMEX' };

export function reseauDe(chiffres) {
    if (/^3[47]/.test(chiffres)) return 'amex';
    if (/^4/.test(chiffres)) return 'visa';
    const quatre = Number(chiffres.slice(0, 4));
    if (/^5[1-5]/.test(chiffres) || (chiffres.length >= 4 && quatre >= 2221 && quatre <= 2720)) return 'mastercard';
    return null;
}

export function grouper(chiffres, reseau) {
    if (reseau === 'amex') return [chiffres.slice(0, 4), chiffres.slice(4, 10), chiffres.slice(10, 15)].filter(Boolean).join(' ');
    return (chiffres.match(/.{1,4}/g) || []).join(' ');
}

export function luhn(chiffres) {
    if (!chiffres) return false;
    let somme = 0;
    let double = false;
    for (let i = chiffres.length - 1; i >= 0; i--) {
        let c = Number(chiffres[i]);
        if (double) {
            c *= 2;
            if (c > 9) c -= 9;
        }
        somme += c;
        double = !double;
    }
    return somme % 10 === 0;
}

const NOM_VALIDE = /^[\p{L}][\p{L} '’.-]*$/u;

/** « MM/AA » (ou « MM/AAAA ») → [mois, année], ou null si le format est faux. Même règle que le serveur. */
export function lireExpiration(texte) {
    const m = /^\s*(0[1-9]|1[0-2])\s*\/\s*(\d{2}|\d{4})\s*$/.exec(texte ?? '');
    if (!m) return null;
    const annee = Number(m[2]);
    return [Number(m[1]), annee < 100 ? 2000 + annee : annee];
}

/**
 * Les mêmes contrôles que le serveur, faits AVANT l'envoi : la personne voit tout de suite ce qui ne va pas, sans recharger la page
 * et sans perdre le numéro qu'elle vient de taper. Le serveur les refait tous : ceci n'est qu'un confort.
 * Renvoie la liste des champs refusés : [{ champ: 'numero_carte', message: '…' }].
 */
export function controlerCarte(v, { anneesMax = 10, maintenant = new Date() } = {}) {
    const erreurs = [];
    const refuser = (champ, message) => erreurs.push({ champ, message });

    const chiffres = (v.numero ?? '').replace(/\D/g, '');
    const reseau = reseauDe(chiffres);
    const longueur = reseau === 'amex' ? 15 : 16;
    const nomReseau = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express' }[reseau];

    if (!chiffres) refuser('numero_carte', 'Indiquez le numéro de la carte.');
    else if (!reseau) refuser('numero_carte', 'Seules les cartes Visa, Mastercard et American Express sont acceptées.');
    else if (chiffres.length !== longueur) refuser('numero_carte', `Le numéro doit avoir ${longueur} chiffres pour une carte ${nomReseau}.`);
    else if (!luhn(chiffres)) refuser('numero_carte', "Ce numéro de carte n'est pas valide. Vérifiez chaque chiffre.");

    const expiration = (v.expiration ?? '').trim();
    const lue = lireExpiration(expiration);
    if (!expiration) refuser('expiration', "Indiquez la date d'expiration.");
    else if (!lue) refuser('expiration', 'Indiquez la date au format MM/AA, par exemple 08/28.');
    else {
        const [mois, annee] = lue;
        const finDuMois = new Date(annee, mois, 0, 23, 59, 59);
        const limite = new Date(maintenant.getFullYear() + anneesMax, maintenant.getMonth() + 1, 0, 23, 59, 59);
        if (finDuMois < maintenant || finDuMois > limite) refuser('expiration', 'Cette carte est expirée, ou la date est trop lointaine.');
    }

    const codeAttendu = reseau === 'amex' ? 4 : 3;
    const cvv = (v.cvv ?? '').replace(/\D/g, '');
    if (!cvv) refuser('cvv', 'Indiquez le code de sécurité (CVV / CVC).');
    else if (cvv.length !== codeAttendu) refuser('cvv', `Le code de sécurité a ${codeAttendu} chiffres${reseau === 'amex' ? ' (au recto pour American Express).' : ', au dos de la carte.'}`);

    for (const [champ, libelle] of [['prenom', 'le prénom'], ['nom', 'le nom']]) {
        const valeur = (v[champ] ?? '').trim();
        if (!valeur) refuser(champ, `Indiquez ${libelle} du titulaire, comme sur la carte.`);
        else if (!NOM_VALIDE.test(valeur)) refuser(champ, `${champ === 'nom' ? 'Le nom' : 'Le prénom'} ne peut contenir que des lettres.`);
        else if (valeur.length < 2 || valeur.length > 40) refuser(champ, `${champ === 'nom' ? 'Le nom' : 'Le prénom'} doit avoir entre 2 et 40 caractères.`);
    }

    const adresse = (v.adresse ?? '').trim();
    if (adresse.length < 5 || adresse.length > 120) refuser('adresse', "Indiquez l'adresse de facturation (5 à 120 caractères).");
    if ((v.ville ?? '').trim().length < 2) refuser('ville', 'Indiquez la ville.');
    if ((v.pays ?? '').trim().length < 2) refuser('pays', 'Indiquez le pays.');

    const libelle = (v.libelle ?? '').trim();
    if (libelle !== '' && (libelle.length < 2 || libelle.length > 30)) refuser('libelle', 'Le nom de la carte doit avoir entre 2 et 30 caractères.');

    return erreurs;
}

export function brancherSaisieCarte(formulaire) {
    const numero = formulaire.querySelector('[data-champ-numero]');
    const expiration = formulaire.querySelector('[data-champ-expiration]');
    const cvv = formulaire.querySelector('[data-champ-cvv]');
    const prenom = formulaire.querySelector('[data-champ-prenom]');
    const nom = formulaire.querySelector('[data-champ-nom]');
    const libelle = formulaire.querySelector('[data-champ-libelle]');
    const aideNumero = formulaire.querySelector('[data-aide-numero]');
    const aideCvv = formulaire.querySelector('[data-aide-cvv]');
    const apercu = formulaire.querySelector('[data-apercu]');
    if (!numero || !apercu) return;

    const sortieNumero = apercu.querySelector('[data-apercu-numero]');
    const sortieReseau = apercu.querySelector('[data-apercu-reseau]');
    const sortieNom = apercu.querySelector('[data-apercu-nom]');
    const sortieTitulaire = apercu.querySelector('[data-apercu-titulaire]');
    const sortieExpire = apercu.querySelector('[data-apercu-expire]');

    const AIDE_NUMERO = aideNumero?.textContent ?? '';
    const AIDE_CVV = aideCvv?.textContent ?? '';

    const mettreAJour = () => {
        const chiffres = numero.value.replace(/\D/g, '');
        const reseau = reseauDe(chiffres);
        const longueur = reseau === 'amex' ? 15 : 16;

        // Numéro mis en forme (le curseur reste à la fin : on tape toujours à la suite).
        const formate = grouper(chiffres.slice(0, longueur), reseau);
        if (numero.value !== formate) numero.value = formate;
        numero.maxLength = reseau === 'amex' ? 17 : 19;

        if (cvv) {
            cvv.maxLength = reseau === 'amex' ? 4 : 3;
            cvv.placeholder = reseau === 'amex' ? '••••' : '•••';
            cvv.value = cvv.value.replace(/\D/g, '').slice(0, cvv.maxLength);
        }

        // Retour à l'écran, sans jamais faire peur avant la fin de la saisie.
        if (aideNumero) {
            if (!chiffres) aideNumero.textContent = AIDE_NUMERO;
            else if (!reseau) aideNumero.textContent = 'Seules les cartes Visa, Mastercard et American Express sont acceptées.';
            else if (chiffres.length < longueur) aideNumero.textContent = `Carte ${reseau === 'amex' ? 'American Express' : reseau === 'visa' ? 'Visa' : 'Mastercard'} · encore ${longueur - chiffres.length} chiffre${longueur - chiffres.length > 1 ? 's' : ''}.`;
            else aideNumero.textContent = luhn(chiffres) ? `Carte ${reseau === 'amex' ? 'American Express' : reseau === 'visa' ? 'Visa' : 'Mastercard'} · numéro valide.` : "Ce numéro ne semble pas valide : vérifiez chaque chiffre.";
        }
        if (aideCvv) aideCvv.textContent = reseau === 'amex' ? '4 chiffres au recto de la carte (American Express).' : AIDE_CVV;

        // Aperçu.
        // Chiffres saisis, complétés par des points : « 4242 •••• •••• •••• ».
        const forme = reseau === 'amex' ? [4, 6, 5] : [4, 4, 4, 4];
        const plein = chiffres.padEnd(forme.reduce((a, b) => a + b, 0), '•');
        let position = 0;
        sortieNumero.textContent = forme.map((n) => plein.slice(position, (position += n))).join(' ');
        sortieNumero.toggleAttribute('data-vide', !chiffres);
        sortieReseau.textContent = reseau ? RESEAUX[reseau] : '';
        sortieReseau.dataset.reseau = reseau ?? '';

        const titulaire = [prenom?.value, nom?.value].map((v) => (v ?? '').trim()).filter(Boolean).join(' ');
        sortieTitulaire.textContent = titulaire || 'Prénom Nom';
        sortieTitulaire.toggleAttribute('data-vide', !titulaire);
        sortieExpire.textContent = expiration?.value || 'MM/AA';
        sortieExpire.toggleAttribute('data-vide', !expiration?.value);
        sortieNom.textContent = libelle?.value.trim() || (reseau ? `${RESEAUX[reseau] === 'AMEX' ? 'American Express' : RESEAUX[reseau]}` : 'Nouvelle carte');
    };

    expiration?.addEventListener('input', () => {
        let v = expiration.value.replace(/\D/g, '').slice(0, 4);
        // « 2 » devient « 02 » ; « 13 » devient « 1 » puis « 3 » n'est pas gardé : le mois va de 01 à 12.
        if (v.length === 1 && Number(v) > 1) v = `0${v}`;
        if (v.length >= 2 && Number(v.slice(0, 2)) > 12) v = `12${v.slice(2)}`;
        expiration.value = v.length > 2 ? `${v.slice(0, 2)}/${v.slice(2)}` : v.length === 2 && !/Backspace|Delete/.test(expiration.dataset.touche || '') ? `${v}/` : v;
        mettreAJour();
    });
    expiration?.addEventListener('keydown', (e) => {
        expiration.dataset.touche = e.key;
    });

    [numero, cvv, prenom, nom, libelle].forEach((champ) => champ?.addEventListener('input', mettreAJour));

    // Couleur : l'aperçu prend la couleur choisie.
    formulaire.querySelectorAll('[data-champ-couleur]').forEach((radio) => {
        const appliquer = () => {
            if (!radio.checked) return;
            apercu.className = apercu.className.replace(/\bcarte-(?!apercu)[a-z]+\b/g, '').trim();
            apercu.classList.add(`carte-${radio.value}`);
        };
        radio.addEventListener('change', appliquer);
        appliquer();
    });

    // ---- Contrôle avant l'envoi : erreurs en rouge, sous chaque champ, et un bandeau en haut de la boîte. ----
    const anneesMax = Number(formulaire.dataset.anneesMax) || 10;
    const champDe = (nomChamp) => formulaire.querySelector(`[name="${nomChamp}"]`);
    const bloc = (nomChamp) => champDe(nomChamp)?.closest('.champ');

    const effacerErreur = (nomChamp) => {
        const champ = champDe(nomChamp);
        champ?.removeAttribute('aria-invalid');
        bloc(nomChamp)?.querySelectorAll('.champ-erreur').forEach((e) => e.remove());
    };

    const bandeau = () => formulaire.querySelector('[data-dialogue-alerte]');

    const afficherErreurs = (erreurs) => {
        ['numero_carte', 'expiration', 'cvv', 'prenom', 'nom', 'adresse', 'ville', 'pays', 'libelle'].forEach(effacerErreur);
        erreurs.forEach(({ champ, message }) => {
            champDe(champ)?.setAttribute('aria-invalid', 'true');
            const p = document.createElement('p');
            p.className = 'champ-erreur';
            p.setAttribute('role', 'alert');
            p.textContent = message;
            bloc(champ)?.append(p);
        });

        const alerte = bandeau();
        if (!alerte) return;
        const liste = alerte.querySelector('[data-alerte-liste]');
        if (liste) {
            liste.replaceChildren(
                ...erreurs.map(({ message }) => {
                    const li = document.createElement('li');
                    li.textContent = message;
                    return li;
                }),
            );
        }
        alerte.hidden = erreurs.length === 0;
    };

    // « capture » : ce contrôle passe avant le verrou anti double envoi (sinon le bouton resterait bloqué sur « Vérification… »).
    formulaire.addEventListener(
        'submit',
        (evenement) => {
            const erreurs = controlerCarte(
                {
                    numero: numero.value,
                    expiration: expiration?.value,
                    cvv: cvv?.value,
                    prenom: prenom?.value,
                    nom: nom?.value,
                    adresse: champDe('adresse')?.value,
                    ville: champDe('ville')?.value,
                    pays: champDe('pays')?.value,
                    libelle: libelle?.value,
                },
                { anneesMax },
            );
            afficherErreurs(erreurs);
            if (erreurs.length === 0) return;

            evenement.preventDefault();
            evenement.stopImmediatePropagation();
            const premier = champDe(erreurs[0].champ);
            premier?.focus();
            premier?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
        true,
    );

    // Dès qu'on corrige un champ, son erreur disparaît.
    ['numero_carte', 'expiration', 'cvv', 'prenom', 'nom', 'adresse', 'ville', 'pays', 'libelle'].forEach((nomChamp) => {
        champDe(nomChamp)?.addEventListener('input', () => effacerErreur(nomChamp));
    });

    // À la fermeture ou après un envoi : le numéro et le code de sécurité ne restent pas dans le formulaire.
    const effacer = () => {
        if (numero) numero.value = '';
        if (cvv) cvv.value = '';
        mettreAJour();
    };
    formulaire.closest('dialog')?.addEventListener('close', effacer);
    formulaire.addEventListener('submit', () => setTimeout(effacer, 0));

    mettreAJour();
}
