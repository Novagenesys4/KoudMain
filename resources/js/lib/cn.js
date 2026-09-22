/** Assemble des noms de classes en ignorant les valeurs vides : cn('a', cond && 'b', ['c']) */
export function cn(...valeurs) {
    return valeurs.flat(Infinity).filter(Boolean).join(' ');
}
