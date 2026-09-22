import { Ban, Bell, Check, Heart, MessageSquare, Package, ShieldCheck, Star, UserCheck, Wallet } from 'lucide-react';

/** Les icônes de notification choisies par le serveur (noms des icônes Blade) -> leur équivalent dans les îlots. */
const ICONES = {
    cloche: Bell,
    colis: Package,
    coche: Check,
    interdit: Ban,
    bouclier: ShieldCheck,
    portefeuille: Wallet,
    'utilisateur-valide': UserCheck,
    coeur: Heart,
    etoile: Star,
    message: MessageSquare,
};

export function iconeNotification(nom) {
    return ICONES[nom] ?? Bell;
}

/** « il y a 3 min », « hier », « 12 sept. » : une date courte et humaine. */
export function ilYA(dateIso, maintenant = Date.now()) {
    const t = new Date(dateIso).getTime();
    if (Number.isNaN(t)) return '';
    const s = Math.max(0, Math.round((maintenant - t) / 1000));
    if (s < 45) return "à l'instant";
    if (s < 3600) return `il y a ${Math.round(s / 60)} min`;
    if (s < 86400) return `il y a ${Math.round(s / 3600)} h`;
    if (s < 172800) return 'hier';
    return new Date(t).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}
