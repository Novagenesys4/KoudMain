/** "Beauté et Coiffure" -> "beaute et coiffure" : la recherche ignore accents et majuscules. */
export function normaliser(texte) {
    return String(texte ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[’']/g, ' ')
        .toLowerCase()
        .trim();
}

/**
 * Filtre et classe des éléments selon une saisie. Tous les mots saisis doivent être trouvés
 * (dans le titre, le sous-titre ou les mots-clés). Un mot en début de titre pèse plus lourd.
 */
export function rechercher(elements, saisie, limite = 50) {
    const mots = normaliser(saisie).split(/\s+/).filter(Boolean);
    if (mots.length === 0) return elements.slice(0, limite);

    const notes = [];
    for (const element of elements) {
        const titre = normaliser(element.titre);
        const reste = normaliser(`${element.sous ?? ''} ${(element.motsCles ?? []).join(' ')}`);
        let score = 0;
        let tous = true;

        for (const mot of mots) {
            if (titre.startsWith(mot)) score += 6;
            else if (titre.split(' ').some((m) => m.startsWith(mot))) score += 4;
            else if (titre.includes(mot)) score += 2;
            else if (reste.includes(mot)) score += 1;
            else {
                tous = false;
                break;
            }
        }
        if (tous) notes.push([score, element]);
    }

    return notes
        .sort((a, b) => b[0] - a[0])
        .slice(0, limite)
        .map(([, element]) => element);
}
