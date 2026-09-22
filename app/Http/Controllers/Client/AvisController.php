<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Services\AvisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** /client/commandes/{commande}/avis : noter une prestation d'une commande terminée. */
class AvisController extends Controller
{
    public function __construct(private readonly AvisService $avis)
    {
    }

    public function enregistrer(Request $request, Commande $commande): RedirectResponse
    {
        // Une commande qui n'est pas la vôtre : « introuvable », comme partout.
        abort_unless($commande->client_id === $request->user()->id, 404);

        $donnees = $request->validate([
            'prestation_id' => ['required', 'integer'],
            'note' => ['required', 'integer', 'between:1,5'],
            'commentaire' => ['nullable', 'string', 'max:'.AvisService::COMMENTAIRE_MAX],
        ], [
            'note.required' => 'Choisissez une note de 1 à 5 étoiles.',
            'note.between' => 'Choisissez une note de 1 à 5 étoiles.',
            'commentaire.max' => 'Votre commentaire ne peut pas dépasser '.AvisService::COMMENTAIRE_MAX.' caractères.',
        ]);

        $retour = redirect(route('client.commandes.voir', $commande).'#avis');

        try {
            $this->avis->donner($commande, $request->user(), (int) $donnees['prestation_id'], (int) $donnees['note'], $donnees['commentaire'] ?? null);
        } catch (OperationRefusee $e) {
            return $retour->with('erreur', $e->getMessage())->withInput();
        }

        return $retour->with('succes', 'Merci ! Votre avis est publié.');
    }
}
