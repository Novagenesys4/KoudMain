/* ==========================================================================
   Données de DÉMONSTRATION.
   --------------------------------------------------------------------------
   Tout ce qui est ici est fictif : noms, notes, prix, transactions. Ces données servent à
   montrer les composants avant que le catalogue réel n'existe (lot 2) et que les
   commandes / porte-monnaie ne soient branchés (lot 3). Chaque endroit qui les affiche le
   dit clairement à l'écran. Ne jamais les présenter comme de vrais avis ou de vrais profils.
   ========================================================================== */

export const PRESTATIONS_DEMO = [
    {
        id: 'demo-1',
        titre: 'Tresses et coiffure à domicile',
        prestataire: 'Awa Kouassi',
        metier: 'Coiffure',
        note: 4.9,
        avis: 128,
        quartier: 'Cocody',
        ville: 'Abidjan',
        prix: 15000,
        duree: '2 h',
        verifie: true,
        dispo: "Aujourd'hui",
        teinte: 'amber',
    },
    {
        id: 'demo-2',
        titre: 'Dépannage plomberie et fuites',
        prestataire: 'Yao Konan',
        metier: 'Plomberie',
        note: 4.8,
        avis: 86,
        quartier: 'Yopougon',
        ville: 'Abidjan',
        prix: 10000,
        duree: '1 h 30',
        verifie: true,
        dispo: 'Demain',
        teinte: 'teal',
    },
    {
        id: 'demo-3',
        titre: 'Pressing et repassage, ramassage inclus',
        prestataire: 'Fatou Traoré',
        metier: 'Laverie',
        note: 4.7,
        avis: 203,
        quartier: 'Marcory',
        ville: 'Abidjan',
        prix: 5000,
        duree: '24 h',
        verifie: true,
        dispo: "Aujourd'hui",
        teinte: 'rose',
    },
    {
        id: 'demo-4',
        titre: "Garde d'enfants en soirée",
        prestataire: 'Marie-Claire Bamba',
        metier: "Garde d'enfants",
        note: 5.0,
        avis: 41,
        quartier: 'Riviera',
        ville: 'Abidjan',
        prix: 8000,
        duree: '4 h',
        verifie: true,
        dispo: 'Cette semaine',
        teinte: 'sable',
    },
    {
        id: 'demo-5',
        titre: 'Cuisine ivoirienne pour vos repas de fête',
        prestataire: 'Ibrahim Diallo',
        metier: 'Cuisine',
        note: 4.9,
        avis: 67,
        quartier: 'Angré',
        ville: 'Abidjan',
        prix: 25000,
        duree: '5 h',
        verifie: true,
        dispo: 'Cette semaine',
        teinte: 'amber',
    },
    {
        id: 'demo-6',
        titre: 'Peinture intérieure et petites rénovations',
        prestataire: 'Koffi Yao',
        metier: 'Rénovation',
        note: 4.6,
        avis: 54,
        quartier: 'Plateau',
        ville: 'Abidjan',
        prix: 30000,
        duree: '1 jour',
        verifie: true,
        dispo: 'Demain',
        teinte: 'teal',
    },
];

export const TRANSACTIONS_DEMO = [
    { id: 't1', type: 'depot', libelle: 'Dépôt Orange Money', detail: "Aujourd'hui, 09:12", montant: 30000 },
    { id: 't2', type: 'sequestre', libelle: 'Coiffure à domicile', detail: 'Awa K. · en séquestre', montant: -15000, statut: 'sequestre' },
    { id: 't3', type: 'paiement', libelle: 'Pressing et repassage', detail: 'Fatou T. · hier', montant: -5000 },
    { id: 't4', type: 'remboursement', libelle: 'Commande annulée', detail: 'Remboursement · lundi', montant: 10000 },
    { id: 't5', type: 'depot', libelle: 'Dépôt Wave', detail: 'Samedi, 17:40', montant: 20000 },
];

export const SOLDE_DEMO = 42500;
export const SEQUESTRE_DEMO = 15000;

export const SOURCES_DEPOT = ['Orange Money', 'MTN MoMo', 'Wave'];
export const MONTANTS_DEPOT = [5000, 10000, 25000];

const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

/** Les n prochains jours à partir d'aujourd'hui, pour le sélecteur de date. */
export function prochainsJours(n = 8) {
    const base = new Date();
    return Array.from({ length: n }, (_, i) => {
        const d = new Date(base.getFullYear(), base.getMonth(), base.getDate() + i);
        return {
            cle: `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`,
            court: i === 0 ? 'Auj.' : i === 1 ? 'Dem.' : JOURS[d.getDay()],
            numero: d.getDate(),
            mois: MOIS[d.getMonth()],
            long: `${['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][d.getDay()]} ${d.getDate()} ${MOIS[d.getMonth()]}`,
        };
    });
}

export const CRENEAUX = {
    Matin: ['08:00', '09:00', '10:00', '11:00'],
    'Après-midi': ['13:00', '14:30', '16:00', '17:30'],
};

/** Disponibilité fictive mais stable : la même prestation / date donne toujours les mêmes créneaux libres. */
export function creneauLibre(idPrestation, cleJour, heure) {
    const graine = `${idPrestation}|${cleJour}|${heure}`;
    let h = 0;
    for (let i = 0; i < graine.length; i++) h = (h * 31 + graine.charCodeAt(i)) >>> 0;
    return h % 5 !== 0; // environ un créneau sur cinq est complet
}
