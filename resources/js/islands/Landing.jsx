import { Argent } from '../components/landing/Argent';
import { Chiffres } from '../components/landing/Chiffres';
import { CtaPrestataire } from '../components/landing/CtaPrestataire';
import { BandeauQuartiers, Domaines } from '../components/landing/Domaines';
import { Etapes } from '../components/landing/Etapes';
import { Hero } from '../components/landing/Hero';

/**
 * Page d'accueil. Tout vient de la base de données (props Blade) : domaines, chiffres, quartiers. Pas de catalogue ici : il s'ouvre depuis l'espace client.
 * Aucun contenu inventé : sans prestation, la section le dit.
 */
export default function Landing({ domaines = [], stats = [], prestations = [], quartiers = [], nbPrestataires = 0, urls, connecte = false }) {
    return (
        <>
            <Hero urls={urls} connecte={connecte} prestations={prestations} quartiers={quartiers} nbPrestataires={nbPrestataires} />
            <BandeauQuartiers quartiers={quartiers} domaines={domaines} />
            <Etapes />
            <Domaines domaines={domaines} />
            <Argent />
            <Chiffres stats={stats} />
            <CtaPrestataire urls={urls} connecte={connecte} />
        </>
    );
}
