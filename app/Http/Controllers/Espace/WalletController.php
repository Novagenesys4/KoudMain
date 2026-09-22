<?php

namespace App\Http\Controllers\Espace;

use App\Support\Saisie;
use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\Paiement;
use App\Models\Retrait;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CarteVirtuelleService;
use App\Services\PaiementService;
use App\Services\WalletService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le wallet, côté client (/client/wallet : recharger) et côté prestataire (/prestataire/wallet : demander un retrait).
 * Toute l'arithmétique est dans WalletService et PaiementService ; ici, seulement le HTTP et l'affichage.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaiementService $paiements,
        private readonly CarteVirtuelleService $cartes,
    )
    {
    }

    public function show(Request $request): View
    {
        $role = (string) $request->route()->defaults['role']; // la route le dit : « client » ou « prestataire »
        $utilisateur = $request->user();
        $wallet = $this->wallets->pour($utilisateur);

        $q = Saisie::texte($request->query('q'));
        $type = in_array($request->query('type'), ['credit', 'debit', 'retrait'], true) ? $request->query('type') : null;

        $operations = WalletTransaction::query()->with('carte')->where('wallet_id', $wallet->id)->latest('id');

        if ($type !== null) {
            $operations->where('type', $type);
        }

        if ($q !== '') {
            $operations->whereRaw('immutable_unaccent(lower(libelle)) LIKE immutable_unaccent(?)', ['%'.addcslashes(mb_strtolower($q), '%_\\').'%']);
        }

        // Ce qui est « en route » : l'argent des commandes en cours (client : déjà payé, bloqué ; prestataire : sera libéré).
        $colonne = $role === 'client' ? 'client_id' : 'prestataire_id';
        $bloque = (float) Escrow::query()->where($colonne, $utilisateur->id)->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])->sum('montant');

        $totaux = WalletTransaction::query()->where('wallet_id', $wallet->id)->toBase()
            ->selectRaw("coalesce(sum(montant) filter (where type = 'credit'), 0) as credits, coalesce(sum(montant) filter (where type <> 'credit'), 0) as sorties")
            ->first();

        // « Ce mois-ci » : ce qui est entré, sorti, et la différence.
        $mensuel = WalletTransaction::query()->where('wallet_id', $wallet->id)->where('created_at', '>=', now()->startOfMonth())->toBase()
            ->selectRaw("coalesce(sum(montant) filter (where type = 'credit'), 0) as entrees, coalesce(sum(montant) filter (where type <> 'credit'), 0) as sorties")
            ->first();

        return view('espace.wallet', [
            'role' => $role,
            'ceMois' => ['entrees' => (float) $mensuel->entrees, 'sorties' => (float) $mensuel->sorties, 'net' => (float) $mensuel->entrees - (float) $mensuel->sorties],
            // Le dernier mouvement (recharge ou retrait) : la page compte les chiffres jusqu'à sa valeur (voir l'île SuiviMouvement).
            'mouvement' => session('mouvement'),
            'cartes' => $this->cartesPourLaVue($utilisateur, $wallet->id, $role),
            'carteChoisie' => $request->integer('carte') ?: null,
            'maxCartes' => (int) config('koudmain.cartes.max'),
            'reseaux' => config('koudmain.cartes.reseaux'),
            'couleursCartes' => config('koudmain.cartes.couleurs'),
            'solde' => (float) $wallet->solde,
            'bloque' => $bloque,
            'credits' => (float) $totaux->credits,
            'sorties' => (float) $totaux->sorties,
            'mois' => $this->creditsParMois($wallet->id),
            'operations' => $operations->paginate(10)->withQueryString(),
            'q' => $q,
            'type' => $type,
            'peutRecharger' => $utilisateur->aLeRole('client'),
            'rechargeActive' => $this->paiements->actif(),
            'simulation' => $this->paiements->simulation(),
            'peutRetirer' => $utilisateur->aLeRole('prestataire'),
            'retraits' => $utilisateur->aLeRole('prestataire') ? Retrait::query()->where('user_id', $utilisateur->id)->latest('id')->limit(5)->get() : collect(),
            'enAttente' => Paiement::query()->where('user_id', $utilisateur->id)->where('statut', Paiement::EN_ATTENTE)->latest('id')->limit(3)->get(),
            'methodesRecharge' => config('koudmain.finance.methodes_recharge'),
            'methodesRetrait' => config('koudmain.finance.methodes_retrait'),
            'montantsRapides' => config('koudmain.finance.montants_rapides'),
            'rechargeMin' => (int) config('koudmain.finance.recharge_min'),
            'retraitMin' => (int) config('koudmain.finance.retrait_min'),
        ]);
    }

    public function recharger(Request $request): RedirectResponse
    {
        $validateur = Validator::make($request->all(), [
            'montant' => ['required', 'integer', 'min:1', 'max:'.config('koudmain.finance.mouvement_max')],
            'methode' => ['required', 'string', 'max:40'],
            'telephone' => ['nullable', 'string', 'max:25'],
            'carte_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'montant.*' => 'Indiquez un montant entier en FCFA (minimum '.Format::fcfa(config('koudmain.finance.recharge_min')).').',
            'methode.required' => 'Choisissez un moyen de paiement.',
        ]);

        // La boîte « Recharger » se rouvre avec ses erreurs : sans cela, un champ refusé ne montrait rien du tout.
        if ($validateur->fails()) {
            return redirect()->route('client.wallet')->withErrors($validateur)->withInput()->with('ouvrir', 'recharge');
        }

        $donnees = $validateur->validated();

        $avant = $this->wallets->solde($request->user());

        try {
            $paiement = $this->paiements->recharger($request->user(), $donnees['montant'], $donnees['methode'], $donnees['telephone'] ?? null, isset($donnees['carte_id']) ? (int) $donnees['carte_id'] : null);
        } catch (OperationRefusee $e) {
            return redirect()->route('client.wallet')->withInput()->with('erreur', $e->getMessage())->with('ouvrir', 'recharge');
        }

        if ($paiement->statut === Paiement::EN_ATTENTE && $paiement->url_paiement) {
            // Pas de redirection externe directe : la CSP « form-action 'self' » la bloquerait dans Chrome après un envoi de formulaire.
            // On passe par une petite page de notre site, qui envoie ensuite le client chez l'agrégateur.
            return redirect()->route('paiements.continuer', $paiement->reference);
        }

        if ($paiement->statut === Paiement::REUSSI) {
            return redirect()->route('client.wallet')
                ->with('succes', 'Recharge de '.Format::fcfa($paiement->montant).' reçue'.($this->paiements->simulation() ? ' (simulation : aucun argent réel n\'a été débité).' : '.'))
                ->with('mouvement', ['type' => 'recharge', 'montant' => (float) $paiement->montant, 'avant' => $avant, 'apres' => $this->wallets->solde($request->user()), 'moyen' => $paiement->methode, 'simulation' => $this->paiements->simulation()]);
        }

        return redirect()->route('client.wallet')->with('erreur', $paiement->motif_echec ?: 'Le paiement n\'a pas abouti. Rien n\'a été débité.');
    }

    public function retirer(Request $request): RedirectResponse
    {
        $validateur = Validator::make($request->all(), [
            'montant' => ['required', 'integer', 'min:1'],
            'methode' => ['required', 'string', 'max:40'],
            'destination' => ['required', 'string', 'max:60'],
            'carte_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'montant.*' => 'Indiquez un montant entier en FCFA.',
            'methode.required' => 'Choisissez un mode de retrait.',
            'destination.required' => 'Indiquez le numéro ou le RIB qui doit recevoir l\'argent.',
        ]);

        if ($validateur->fails()) {
            return redirect()->route('prestataire.wallet')->withErrors($validateur)->withInput()->with('ouvrir', 'retrait');
        }

        $donnees = $validateur->validated();

        $avant = $this->wallets->solde($request->user());

        try {
            // Un retrait part vers un Mobile Money ou un RIB : aucune carte n'est nécessaire (si l'une est indiquée, elle ne doit pas être gelée).
            $carte = isset($donnees['carte_id']) ? $this->cartes->utilisable($request->user(), (int) $donnees['carte_id']) : null;
            $retrait = $this->wallets->demanderRetrait($request->user(), $donnees['montant'], $donnees['methode'], $donnees['destination'], $carte);
        } catch (OperationRefusee $e) {
            return redirect()->route('prestataire.wallet')->withInput()->with('erreur', $e->getMessage())->with('ouvrir', 'retrait');
        }

        return redirect()->route('prestataire.wallet')
            ->with('succes', 'Demande de retrait de '.Format::fcfa($retrait->montant).' enregistrée. Le virement est effectué sous 24 à 48 h.')
            ->with('mouvement', ['type' => 'retrait', 'montant' => (float) $retrait->montant, 'avant' => $avant, 'apres' => $this->wallets->solde($request->user()), 'moyen' => $donnees['methode'], 'simulation' => false]);
    }

    /** Exporte l'historique en CSV (séparateur « ; », accents lisibles dans Excel). Ne sort que les opérations de la personne connectée. */
    public function exporter(Request $request): StreamedResponse
    {
        $wallet = $this->wallets->pour($request->user());
        $role = (string) $request->route()->defaults['role'];
        $nom = 'koudmain-wallet-'.now()->format('Y-m-d').'.csv';
        $libelles = ['credit' => 'Entrée', 'debit' => 'Sortie', 'retrait' => 'Retrait'];

        return response()->streamDownload(function () use ($wallet, $libelles): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // marque UTF-8 : Excel lit correctement les accents
            fputcsv($sortie, ['Date', 'Type', 'Libellé', 'Montant (FCFA)', 'Solde après (FCFA)', 'Carte'], ';');

            WalletTransaction::query()->with('carte')->where('wallet_id', $wallet->id)->latest('id')->limit(5000)
                ->cursor()
                ->each(function (WalletTransaction $t) use ($sortie, $libelles): void {
                    fputcsv($sortie, [
                        $t->created_at->format('Y-m-d H:i'),
                        $libelles[$t->type] ?? $t->type,
                        // Un libellé qui commencerait par = + - @ serait lu comme une formule par Excel : on le neutralise.
                        preg_match('/^[=+\-@\t\r]/', $t->libelle) ? "'".$t->libelle : $t->libelle,
                        (int) round(($t->type === 'credit' ? 1 : -1) * (float) $t->montant),
                        (int) round((float) $t->solde_apres),
                        $t->carte ? $t->carte->libelle.' '.substr($t->carte->numero_masque, -4) : '',
                    ], ';');
                });

            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Les crédits des 6 derniers mois (le mois courant compris), pour le petit graphique.
     *
     * @return list<array{libelle: string, montant: float}>
     */
    private function creditsParMois(int $walletId): array
    {
        $debut = Carbon::now()->startOfMonth()->subMonths(5);

        $parMois = WalletTransaction::query()
            ->where('wallet_id', $walletId)->where('type', 'credit')->where('created_at', '>=', $debut)
            ->toBase()
            ->selectRaw("to_char(created_at, 'YYYY-MM') as mois, sum(montant) as total")
            ->groupBy('mois')->pluck('total', 'mois');

        $liste = [];

        for ($i = 0; $i < 6; $i++) {
            $mois = $debut->copy()->addMonths($i);
            $liste[] = ['libelle' => $mois->translatedFormat('M'), 'montant' => (float) ($parMois[$mois->format('Y-m')] ?? 0)];
        }

        return $liste;
    }

    /**
     * Les cartes, prêtes pour l'îlot React : identité visuelle, état, et ce que chaque carte a fait passer (entrées / sorties).
     *
     * @return list<array<string, mixed>>
     */
    private function cartesPourLaVue(User $utilisateur, int $walletId, string $role): array
    {
        $stats = WalletTransaction::query()->where('wallet_id', $walletId)->whereNotNull('carte_id')->toBase()
            ->selectRaw("carte_id, count(*) as nb, coalesce(sum(montant) filter (where type = 'credit'), 0) as entrees, coalesce(sum(montant) filter (where type <> 'credit'), 0) as sorties")
            ->groupBy('carte_id')->get()->keyBy('carte_id');

        return $this->cartes->pour($utilisateur)->map(function ($c) use ($stats, $role): array {
            $s = $stats->get($c->id);

            return [
                'id' => $c->id,
                'libelle' => $c->libelle,
                'reseau' => $c->type_carte,
                'couleur' => $c->couleur,
                'fin' => substr($c->numero_masque, -4),
                'titulaire' => $c->nom_titulaire,
                'expire' => $c->date_expiration,
                'adresse' => $c->adresse_facturation,
                'principale' => $c->est_principale,
                'gelee' => $c->est_gelee,
                'operations' => (int) ($s->nb ?? 0),
                'entrees' => (float) ($s->entrees ?? 0),
                'sorties' => (float) ($s->sorties ?? 0),
                'urlGeler' => route("$role.wallet.cartes.geler", $c->id),
                'urlSupprimer' => route("$role.wallet.cartes.supprimer", $c->id),
            ];
        })->values()->all();
    }
}
