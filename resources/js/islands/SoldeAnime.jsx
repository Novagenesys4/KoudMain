import { AnimatedNumber } from '../components/motion/AnimatedNumber';

/**
 * Le solde de la bannière du wallet. Après une recharge ou un retrait, il compte de l'ancien solde jusqu'au nouveau
 * (AnimatedNumber, déclenché quand il entre dans l'écran) ; sinon il s'affiche tel quel.
 */
export default function SoldeAnime({ valeur = 0, depart = 0 }) {
    return <AnimatedNumber value={valeur} depart={depart} duration={2} />;
}
