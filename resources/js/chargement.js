/* ==========================================================================
   Écran de chargement d'ouverture.
   - Une seule fois par session de navigation : le serveur ne l'affiche que si le cookie « km_intro » manque,
     et ce script le pose dès le début (même si l'on quitte la page en cours de route, pas de rejeu).
   - Le compteur avance avec le temps mais attend le vrai chargement (page + polices) avant d'atteindre 100.
   - Échap, un clic sur « Passer » ou la touche Entrée sur ce bouton l'ignorent tout de suite.
   - « Moins d'animations » : il est retiré immédiatement, sans rien afficher.
   ========================================================================== */

const DUREE = 2400; // ms pour aller de 0 à 100, si la page est déjà prête
const SEUILS_MOTS = [0, 0.2, 0.4, 0.62, 0.86]; // progression à laquelle chaque mot prend la place du précédent

const facile = (t) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);

function poserCookie() {
    try {
        const securise = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `km_intro=1; path=/; SameSite=Lax${securise}`;
    } catch (e) {
        /* cookies bloqués : l'écran reviendra à chaque page, c'est acceptable */
    }
}

export function initialiserChargement() {
    const racine = document.querySelector('#chargement[data-ecran-ouverture]');
    if (!racine) return;

    poserCookie();

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        racine.remove();
        return;
    }

    const compteur = racine.querySelector('[data-compteur]');
    const ligne = racine.querySelector('[data-ligne]');
    const mots = Array.from(racine.querySelectorAll('[data-mot]'));
    const heure = racine.querySelector('[data-heure]');

    // Heure d'Abidjan, comme un détail de « lieu » : discret, jamais bloquant.
    try {
        const formater = () => new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Abidjan' }).format(new Date());
        if (heure) heure.textContent = `Abidjan · ${formater()}`;
    } catch (e) {
        /* Intl indisponible : on laisse « Abidjan » */
    }

    let pret = false;
    let debut = null;
    let mot = -1;
    let fini = false;
    let cadre = 0;

    // « Prêt » = page chargée ET polices arrivées. Au-delà de 5 s (réseau lent), on n'attend plus.
    const chargee = new Promise((resoudre) => (document.readyState === 'complete' ? resoudre() : window.addEventListener('load', resoudre, { once: true })));
    Promise.all([chargee, document.fonts?.ready ?? Promise.resolve()]).then(() => {
        pret = true;
    });
    setTimeout(() => {
        pret = true;
    }, 5000);

    const afficherMot = (i) => {
        if (i === mot) return;
        mots.forEach((el, k) => {
            el.classList.toggle('actif', k === i);
            el.classList.toggle('passe', k < i);
        });
        mot = i;
    };

    const terminer = () => {
        if (fini) return;
        fini = true;
        cancelAnimationFrame(cadre);
        window.removeEventListener('keydown', surTouche);
        if (compteur) compteur.textContent = '100';
        if (ligne) ligne.style.transform = 'scaleX(1)';
        afficherMot(mots.length - 1);
        racine.classList.add('sortie');
        const retirer = () => racine.remove();
        racine.addEventListener('transitionend', (e) => e.target === racine && retirer(), { once: true });
        setTimeout(retirer, 1600); // filet de sécurité si la transition ne se déclenche pas
    };

    function surTouche(e) {
        if (e.key === 'Escape') terminer();
    }
    window.addEventListener('keydown', surTouche);
    racine.querySelector('[data-passer]')?.addEventListener('click', terminer);

    const image = (maintenant) => {
        if (debut === null) debut = maintenant;
        const t = Math.min((maintenant - debut) / DUREE, 1);
        // Tant que la page n'est pas vraiment prête, le compteur s'arrête à 92 : il ne ment pas.
        const progression = pret ? facile(t) : Math.min(facile(t), 0.92);
        const valeur = Math.round(progression * 100);
        if (compteur) compteur.textContent = String(valeur);
        if (ligne) ligne.style.transform = `scaleX(${progression})`;
        let i = 0;
        SEUILS_MOTS.forEach((seuil, k) => {
            if (progression >= seuil) i = k;
        });
        afficherMot(Math.min(i, mots.length - 1));

        if (progression >= 1) {
            setTimeout(terminer, 380); // le temps de lire la marque
            return;
        }
        cadre = requestAnimationFrame(image);
    };

    requestAnimationFrame(() => {
        racine.classList.add('ouvert'); // déclenche l'entrée des lettres et des légendes
        cadre = requestAnimationFrame(image);
    });
}
