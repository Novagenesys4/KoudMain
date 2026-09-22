/* ==========================================================================
   Inscription en 3 étapes
   --------------------------------------------------------------------------
   <form data-etapes> contient plusieurs <div data-etape> (une par étape). Le formulaire reste un
   formulaire HTML normal, valable SANS JavaScript : sans lui, aucune étape n'est masquée, elles
   s'enchaînent comme avant sur une seule page, et les boutons Suivant/Précédent restent cachés
   (`hidden` posé dans le Blade) puisqu'ils ne servent à rien sans ce module.

   Ici, on masque toutes les étapes sauf une, on démasque les boutons de navigation, et on repart
   directement sur l'étape à corriger si le serveur a renvoyé une erreur de validation (aller-retour
   après un clic sur « Créer mon compte »).

   La ville (<select data-ville-select>, jamais envoyée au serveur) filtre le quartier
   (<select id="champ-quartier_id" name="quartier_id">) en masquant les <optgroup> des autres villes :
   sans JavaScript, ce <select> reste la liste complète groupée par ville, exactement comme avant.
   ========================================================================== */

function synchroniserQuartiers(quartierSelect, villeSelect) {
    const idVille = villeSelect.value;
    const valeurActuelle = quartierSelect.value;
    let auMoinsUnVisible = false;

    quartierSelect.querySelectorAll('optgroup').forEach((groupe) => {
        const correspond = idVille !== '' && groupe.dataset.villeId === idVille;
        groupe.hidden = !correspond;
        if (correspond) auMoinsUnVisible = true;
    });

    const placeholder = quartierSelect.querySelector('option[value=""]');
    if (placeholder) placeholder.textContent = idVille ? 'Choisissez un quartier' : "Choisissez d'abord une ville";

    // Le quartier gardé ne correspond plus à la ville choisie (changement de ville) : on revient au placeholder.
    const optionActuelle = valeurActuelle && quartierSelect.querySelector(`option[value="${valeurActuelle}"]`);
    if (!auMoinsUnVisible || !optionActuelle || optionActuelle.closest('optgroup')?.hidden) {
        quartierSelect.value = '';
    }
}

export function initialiserInscriptionEtapes() {
    const formulaire = document.querySelector('[data-etapes]');
    if (!formulaire) return;

    const etapes = [...formulaire.querySelectorAll('[data-etape]')];
    if (etapes.length < 2) return;

    const villeSelect = formulaire.querySelector('[data-ville-select]');
    const quartierSelect = formulaire.querySelector('#champ-quartier_id');
    if (villeSelect && quartierSelect) {
        synchroniserQuartiers(quartierSelect, villeSelect);
        villeSelect.addEventListener('change', () => synchroniserQuartiers(quartierSelect, villeSelect));
    }

    const infos = formulaire.querySelector('[data-etape-info]');
    const libelle = formulaire.querySelector('[data-etape-libelle]');
    let courante = 0;

    function champsInvalides(etape) {
        return etape.querySelectorAll('[aria-invalid="true"], .champ-erreur').length > 0;
    }

    function afficher(indice) {
        etapes.forEach((etape, i) => (etape.hidden = i !== indice));
        courante = indice;
        if (libelle) libelle.textContent = `Étape ${indice + 1} sur ${etapes.length}`;
        // Le focus part sur le premier champ de l'étape : utile au clavier comme au lecteur d'écran.
        etapes[indice].querySelector('input, select, textarea')?.focus();
    }

    function etapeValide(indice) {
        for (const champ of etapes[indice].querySelectorAll('input, select, textarea')) {
            if (champ.closest('[hidden]')) continue; // ex. un <optgroup> de quartier masqué par le filtre ville
            if (!champ.checkValidity()) {
                champ.focus();
                champ.reportValidity();
                return false;
            }
        }
        return true;
    }

    formulaire.querySelectorAll('[data-etape-suivant]').forEach((bouton) => {
        bouton.hidden = false;
        bouton.addEventListener('click', () => {
            if (!etapeValide(courante)) return;
            afficher(Math.min(courante + 1, etapes.length - 1));
        });
    });

    formulaire.querySelectorAll('[data-etape-precedent]').forEach((bouton) => {
        bouton.hidden = false;
        bouton.addEventListener('click', () => afficher(Math.max(courante - 1, 0)));
    });

    if (infos) infos.hidden = false;

    // Après un aller-retour de validation, on rouvre directement sur l'étape qui contient l'erreur.
    const etapeErreur = etapes.findIndex(champsInvalides);
    afficher(etapeErreur === -1 ? 0 : etapeErreur);
}
