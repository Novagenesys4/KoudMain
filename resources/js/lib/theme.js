/* ==========================================================================
   Thème clair / sombre (sans React : utilisé par app.js ET par les îles).
   Le thème initial est posé dans le <head> avant l'affichage ; ici on gère le changement.
   ========================================================================== */

export function themeActuel() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

function poser(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try {
        localStorage.setItem('km-theme', theme);
    } catch (e) {
        /* navigation privée : le choix ne sera simplement pas mémorisé */
    }
}

/**
 * Bascule le thème. Si le navigateur sait faire des transitions de page (View Transitions),
 * la nouvelle couleur s'étend en cercle depuis l'élément cliqué ; sinon, le changement est immédiat.
 */
export function basculerTheme(origine) {
    const suivant = themeActuel() === 'dark' ? 'light' : 'dark';
    const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!document.startViewTransition || reduit) {
        poser(suivant);
        return suivant;
    }

    const boite = origine?.getBoundingClientRect?.();
    const x = boite ? boite.left + boite.width / 2 : window.innerWidth / 2;
    const y = boite ? boite.top + boite.height / 2 : 0;
    const rayon = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));

    const transition = document.startViewTransition(() => poser(suivant));
    transition.ready
        .then(() => {
            document.documentElement.animate(
                { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${rayon}px at ${x}px ${y}px)`] },
                { duration: 650, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' },
            );
        })
        .catch(() => {});

    return suivant;
}
