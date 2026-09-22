/* Bulle au survol des diagrammes en barres (components/espace/graphique.blade.php).
   Une seule bulle pour toute la page, posée au-dessus de la barre survolée. Sans JavaScript, le tableau
   « Voir les données » donne les mêmes chiffres : la bulle est un confort, pas la seule source. */
export function brancherGraphes() {
    const graphes = document.querySelectorAll('[data-graphe]');
    if (graphes.length === 0) return;

    const bulle = document.createElement('div');
    bulle.className = 'graphe-bulle';
    bulle.hidden = true;
    bulle.setAttribute('aria-hidden', 'true');
    document.body.append(bulle);

    const afficher = (colonne) => {
        const { libelle, valeur } = colonne.dataset;
        bulle.innerHTML = '';
        const ligne = document.createElement('span');
        ligne.textContent = valeur;
        const date = document.createElement('small');
        date.textContent = libelle;
        bulle.append(ligne, date);
        bulle.hidden = false;

        const boite = colonne.getBoundingClientRect();
        const largeur = bulle.offsetWidth;
        // Reste dans l'écran : la bulle est centrée sur la barre sauf près des bords.
        const x = Math.min(Math.max(boite.left + boite.width / 2, largeur / 2 + 8), window.innerWidth - largeur / 2 - 8);
        bulle.style.left = `${x}px`;
        bulle.style.top = `${boite.top + boite.height - (colonne.querySelector('.graphe-barre')?.offsetHeight ?? 0)}px`;
    };

    const cacher = () => {
        bulle.hidden = true;
    };

    graphes.forEach((graphe) => {
        graphe.addEventListener('pointerover', (evenement) => {
            const colonne = evenement.target.closest('.graphe-col');
            if (colonne) afficher(colonne);
        });
        graphe.addEventListener('pointerleave', cacher);
    });

    window.addEventListener('scroll', cacher, { passive: true });
}
