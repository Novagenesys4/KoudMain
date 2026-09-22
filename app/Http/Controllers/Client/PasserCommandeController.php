<?php

namespace App\Http\Controllers\Client;

use App\Enums\ModePaiement;
use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commande\CommanderRequest;
use App\Models\Prestation;
use App\Services\CarteVirtuelleService;
use App\Services\CommandeService;
use App\Services\DisponibiliteService;
use App\Services\WalletService;
use App\Support\Duree;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /client/prestations/{slug}/commander : choisir le jour et l'heure, puis envoyer la commande (l'argent est mis en séquestre). */
class PasserCommandeController extends Controller
{
    public function __construct(
        private readonly CommandeService $commandes,
        private readonly DisponibiliteService $disponibilites,
        private readonly WalletService $wallets,
        private readonly CarteVirtuelleService $cartes,
    ) {
    }

    public function create(Request $request, Prestation $prestation): View|RedirectResponse
    {
        $this->charger($prestation);
        $client = $request->user();

        if ($prestation->prestataire_id === $client->id) {
            return redirect()->route('prestations.voir', $prestation)->with('erreur', 'Vous ne pouvez pas commander votre propre prestation.');
        }

        $prestataire = $prestation->prestataire;

        return view('client.commander', [
            'prestation' => $prestation,
            'prestataire' => $prestataire,
            'photo' => $prestation->medias->first(),
            'duree' => Duree::libelle($prestation->duree_minutes),
            'solde' => $this->wallets->solde($client),
            'lieu' => $client->quartier()->with('ville')->first(),
            'aDesHoraires' => $this->disponibilites->aDesHoraires($prestataire),
            'jours' => $this->disponibilites->creneaux($prestataire, $prestation->duree_minutes ?? 60),
            'donnees' => [
                'prix' => (float) $prestation->prix,
                'dureeUnite' => $prestation->duree_minutes,
                'quantiteMax' => (int) config('koudmain.finance.quantite_max'),
                'solde' => $this->wallets->solde($client),
                'urlRecharge' => route('client.wallet'),
                'cartes' => $this->cartes->pour($client)->map(fn ($c) => [
                    'id' => $c->id, 'libelle' => $c->libelle, 'fin' => substr($c->numero_masque, -4),
                    'reseau' => $c->type_carte, 'gelee' => $c->est_gelee, 'principale' => $c->est_principale,
                ])->values()->all(),
                'prenom' => $prestataire->prenom,
                'ancien' => [
                    'date' => old('date'),
                    'heure' => old('heure'),
                    'quantite' => (int) old('quantite', 1),
                    'precisions' => (string) old('precisions', ''),
                    'carte' => old('carte_id') !== null ? (int) old('carte_id') : null,
                    'mode' => old('mode_paiement'),
                ],
                'modes' => collect(ModePaiement::cases())->map(fn (ModePaiement $m) => ['cle' => $m->value, 'libelle' => $m->libelle()])->all(),
                'urlCartes' => route('client.wallet'),
            ],
        ]);
    }

    public function store(CommanderRequest $request, Prestation $prestation): RedirectResponse
    {
        $this->charger($prestation);

        try {
            $commande = $this->commandes->commander(
                $request->user(),
                $prestation,
                (int) $request->validated('quantite'),
                $request->debut(),
                $request->validated('precisions'),
                null,
                $request->mode() === ModePaiement::Carte && $request->validated('carte_id') !== null ? (int) $request->validated('carte_id') : null,
                $request->mode(),
            );
        } catch (OperationRefusee $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        $message = $commande->mode_paiement === ModePaiement::Physique
            ? 'Demande envoyée. Vous paierez '.Format::fcfa($commande->montant_total).' au prestataire en main propre, à la fin de la prestation.'
            : Format::fcfa($commande->montant_total).' sont mis de côté en séquestre : le prestataire n\'est payé qu\'une fois la prestation terminée et confirmée par vous.';

        return redirect()->route('client.commandes.voir', $commande)->with('succes', $message);
    }

    /** Une prestation masquée, ou d'un prestataire suspendu, n'existe pas pour le public : 404. */
    private function charger(Prestation $prestation): void
    {
        $prestation->load(['prestataire.quartier.ville', 'prestataire.avatar', 'service.categorie', 'medias']);

        abort_unless($prestation->est_active && $prestation->prestataire->aLeRole('prestataire'), 404);
    }
}
