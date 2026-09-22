export const DEVISE = 'FCFA';

const nombre = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/** 15000 -> "15 000" (espace insécable : le montant ne se coupe jamais en fin de ligne). */
export function montant(valeur) {
    return nombre.format(Math.round(valeur)).replace(/[  ]/g, ' ');
}

/** 15000 -> "15 000 FCFA" */
export function fcfa(valeur) {
    return `${montant(valeur)} ${DEVISE}`;
}

/** 4.9 -> "4,9" */
export function note(valeur) {
    return valeur.toFixed(1).replace('.', ',');
}

/** "Awa Kouassi" -> "AK" */
export function initiales(nomComplet) {
    return nomComplet
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((mot) => mot[0].toUpperCase())
        .join('');
}
