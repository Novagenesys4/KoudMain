import { AnimatePresence } from 'motion/react';
import { useCallback, useState } from 'react';
import { ServiceCard } from '../components/features/ServiceCard';
import { Stagger, StaggerItem } from '../components/motion/Reveal';
import { envoyerJson } from '../lib/tempsReel';

/**
 * Grille de cartes du catalogue, du profil d'un prestataire et des favoris. Les données sont réelles (base de données,
 * via les props Blade) ; les cartes apparaissent l'une après l'autre. Filtres et pagination restent côté serveur.
 *
 * Le cœur (favoris) n'existe que pour un client connecté : le serveur ajoute alors `favori_url` à chaque carte.
 * Sur la page « Mes favoris » (`retirerAuRetrait`), une carte dont on retire le cœur quitte la liste.
 */
export default function Catalogue({ prestations = [], large = false, espace = false, retirerAuRetrait = false }) {
    const [liste, setListe] = useState(prestations);

    const basculer = useCallback(
        async (prestation) => {
            const { ok, donnees } = await envoyerJson(prestation.favori_url);
            if (!ok) return false;
            if (retirerAuRetrait && donnees?.favori === false) {
                setTimeout(() => setListe((l) => l.filter((p) => p.id !== prestation.id)), 350);
            }
            return true;
        },
        [retirerAuRetrait],
    );

    // Dans le catalogue, une colonne de filtres rétrécit la grille : elle passe à 3 colonnes plus tard (`large` : pleine largeur).
    // Dans l'espace client, le menu latéral prend aussi de la place : 3 colonnes seulement sur très grand écran.
    const grille = espace ? 'sm:grid-cols-2 2xl:grid-cols-3' : large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 xl:grid-cols-3';
    return (
        <Stagger as="ul" gap={0.05} amount={0.05} className={`grid gap-4 ${grille}`}>
            <AnimatePresence>
                {liste.map((prestation) => (
                    <StaggerItem as="li" key={prestation.id} y={20}>
                        <ServiceCard prestation={prestation} surFavori={prestation.favori_url ? basculer : undefined} />
                    </StaggerItem>
                ))}
            </AnimatePresence>
        </Stagger>
    );
}
