import { Baby, ChefHat, Hammer, Laptop, Leaf, PaintRoller, Scissors, Shirt, Sparkles, Truck, Wrench, Zap } from 'lucide-react';

/**
 * Associe un nom de domaine à une icône. On compare des mots-clés (sans accents) plutôt que
 * des identifiants : les catégories viennent de la base et peuvent changer de nom.
 */
const TABLE = [
    [/coiff|beaut|maquill|ongle|esthe|massage/, Scissors],
    [/plomb/, Wrench],
    [/transport|demenag|livrais|auto|mecani/, Truck],
    [/lav|press|linge|repass|nettoy|menage/, Shirt],
    [/enfant|garde|nounou|baby/, Baby],
    [/cuisin|traiteur|patiss|restaur/, ChefHat],
    [/peintur|renov|batiment|macon|carrel/, PaintRoller],
    [/electri/, Zap],
    [/jardin|espace vert/, Leaf],
    [/menuis|bricol|repar|meuble/, Hammer],
    [/informat|numer|depann|reseau/, Laptop],
];

const sansAccents = (t) =>
    String(t ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

export function iconePour(nom) {
    const cle = sansAccents(nom);
    return TABLE.find(([motif]) => motif.test(cle))?.[1] ?? Sparkles;
}
