<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\CarteVirtuelle;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CarteBancaire;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Les cartes bancaires d'un wallet.
 *
 * Elles sont saisies par la personne (numéro, expiration, code de sécurité, titulaire, adresse de facturation). Le numéro complet et le
 * code de sécurité ne servent qu'à être contrôlés (réseau, longueur, clé de Luhn, date) : ils ne sont JAMAIS enregistrés ni journalisés.
 * On garde le réseau, les 4 derniers chiffres, l'expiration, le titulaire, l'adresse et une empreinte à sens unique (pour refuser une carte
 * ajoutée deux fois). Un nouvel utilisateur n'a aucune carte ; la première ajoutée devient la carte par défaut.
 *
 * Ce sont des moyens de paiement INTERNES à KoudMain : ils organisent les paiements dans l'application, mais aucun prélèvement n'est
 * envoyé à une banque tant qu'un service de paiement (agrégateur) n'est pas relié. Le solde reste UN SEUL, celui du wallet ;
 * une carte gelée refuse de payer, de recharger et de retirer.
 */
class CarteVirtuelleService
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    /**
     * Les cartes de l'utilisateur, la carte par défaut en premier. Aucune carte n'est jamais créée automatiquement.
     *
     * @return Collection<int, CarteVirtuelle>
     */
    public function pour(User $utilisateur): Collection
    {
        $wallet = $this->wallets->pour($utilisateur);

        return CarteVirtuelle::query()->where('wallet_id', $wallet->id)->orderByDesc('est_principale')->orderBy('id')->get();
    }

    /**
     * Ajoute une carte que la personne vient de saisir.
     *
     * @param  array{numero: string, expiration: string, cvv: string, prenom: string, nom: string, adresse: string, ville: string, pays: string, libelle?: ?string, couleur: string}  $donnees
     *
     * @throws OperationRefusee
     */
    public function ajouter(User $utilisateur, array $donnees): CarteVirtuelle
    {
        $numero = CarteBancaire::nettoyer($donnees['numero'] ?? '');
        $reseau = CarteBancaire::reseau($numero);

        // Le formulaire a déjà contrôlé tout cela ; on le refait ici parce que le service ne fait confiance à personne.
        if ($reseau === null) {
            throw new OperationRefusee('Seules les cartes Visa, Mastercard et American Express sont acceptées.');
        }

        if (! CarteBancaire::numeroValide($numero)) {
            throw new OperationRefusee('Ce numéro de carte n\'est pas valide. Vérifiez chaque chiffre.');
        }

        if (! CarteBancaire::cvvValide($donnees['cvv'] ?? null, $reseau)) {
            throw new OperationRefusee('Le code de sécurité doit avoir '.CarteBancaire::longueurCvv($reseau).' chiffres.');
        }

        if (! CarteBancaire::expirationValide($donnees['expiration'] ?? null)) {
            throw new OperationRefusee('La date d\'expiration est incorrecte ou dépassée (format MM/AA).');
        }

        if (! array_key_exists($donnees['couleur'] ?? '', config('koudmain.cartes.couleurs'))) {
            throw new OperationRefusee('Choisissez une couleur proposée.');
        }

        $titulaire = mb_substr(mb_strtoupper($this->propre(($donnees['prenom'] ?? '').' '.($donnees['nom'] ?? ''))), 0, 120);
        $adresse = mb_substr($this->propre(($donnees['adresse'] ?? '').', '.($donnees['ville'] ?? '').', '.($donnees['pays'] ?? '')), 0, 255);

        if (mb_strlen($titulaire) < 3 || ! str_contains($titulaire, ' ')) {
            throw new OperationRefusee('Indiquez le prénom ET le nom du titulaire, comme sur la carte.');
        }

        $libelle = $this->propre((string) ($donnees['libelle'] ?? ''));
        $libelle = $libelle !== '' ? $libelle : config('koudmain.cartes.reseaux')[$reseau].' '.substr($numero, -4);

        if (mb_strlen($libelle) < 2 || mb_strlen($libelle) > 30) {
            throw new OperationRefusee('Le nom de la carte doit avoir entre 2 et 30 caractères.');
        }

        $empreinte = CarteBancaire::empreinte($numero);
        $masque = CarteBancaire::masque($numero, $reseau);
        $expiration = CarteBancaire::formaterExpiration($donnees['expiration']);
        $couleur = $donnees['couleur'];
        unset($numero, $donnees); // le numéro complet et le code de sécurité ne vont pas plus loin

        $this->wallets->pour($utilisateur); // le wallet est créé à la demande : il doit exister avant d'être verrouillé

        return DB::transaction(function () use ($utilisateur, $libelle, $reseau, $couleur, $empreinte, $masque, $titulaire, $adresse, $expiration): CarteVirtuelle {
            // Verrou sur le wallet : deux clics simultanés ne peuvent pas dépasser la limite ni ajouter deux fois la même carte.
            $wallet = Wallet::query()->where('user_id', $utilisateur->id)->lockForUpdate()->firstOrFail();
            $max = (int) config('koudmain.cartes.max');

            if (CarteVirtuelle::query()->where('wallet_id', $wallet->id)->count() >= $max) {
                throw new OperationRefusee("Vous ne pouvez pas enregistrer plus de $max cartes. Supprimez-en une d'abord.");
            }

            if (CarteVirtuelle::query()->where('wallet_id', $wallet->id)->where('empreinte', $empreinte)->exists()) {
                throw new OperationRefusee('Cette carte est déjà enregistrée dans votre wallet.');
            }

            $premiere = ! CarteVirtuelle::query()->where('wallet_id', $wallet->id)->exists();

            $carte = new CarteVirtuelle([
                'libelle' => $libelle,
                'type_carte' => $reseau,
                'couleur' => $couleur,
                'numero_masque' => $masque,
                'nom_titulaire' => $titulaire,
                'date_expiration' => $expiration,
                'adresse_facturation' => $adresse,
            ]);
            $carte->forceFill(['wallet_id' => $wallet->id, 'empreinte' => $empreinte, 'est_principale' => $premiere, 'est_gelee' => false])->save();

            Journal::info('carte.ajoutee', ['carte' => $carte->id, 'utilisateur' => $utilisateur->id, 'reseau' => $reseau, 'fin' => substr($masque, -4)]);

            return $carte;
        });
    }

    /** Gèle la carte si elle est active, la dégèle sinon. */
    public function basculerGel(User $utilisateur, int $carteId): CarteVirtuelle
    {
        return DB::transaction(function () use ($utilisateur, $carteId): CarteVirtuelle {
            $carte = $this->deLUtilisateur($utilisateur, $carteId, true);
            $carte->forceFill(['est_gelee' => ! $carte->est_gelee])->save();
            Journal::info($carte->est_gelee ? 'carte.gelee' : 'carte.degelee', ['carte' => $carte->id, 'utilisateur' => $utilisateur->id]);

            return $carte;
        });
    }

    /** @throws OperationRefusee */
    public function supprimer(User $utilisateur, int $carteId): void
    {
        DB::transaction(function () use ($utilisateur, $carteId): void {
            $carte = $this->deLUtilisateur($utilisateur, $carteId, true);
            $etaitPrincipale = $carte->est_principale;
            $walletId = $carte->wallet_id;

            // L'historique garde ses lignes : elles perdent seulement le nom de la carte (carte_id devient vide).
            $carte->delete();

            // La carte par défaut disparaît : la plus ancienne des cartes restantes prend sa place.
            if ($etaitPrincipale) {
                $suivante = CarteVirtuelle::query()->where('wallet_id', $walletId)->orderBy('id')->lockForUpdate()->first();
                $suivante?->forceFill(['est_principale' => true])->save();
            }

            Journal::info('carte.supprimee', ['carte' => $carteId, 'utilisateur' => $utilisateur->id]);
        });
    }

    /**
     * La carte à utiliser pour un paiement, une recharge ou un retrait par carte.
     * Sans identifiant : la carte par défaut. La carte doit appartenir à l'utilisateur et ne pas être gelée.
     *
     * @throws OperationRefusee
     */
    public function utilisable(User $utilisateur, ?int $carteId): CarteVirtuelle
    {
        $cartes = $this->pour($utilisateur);

        if ($cartes->isEmpty()) {
            throw new OperationRefusee('Vous n\'avez encore aucune carte. Ajoutez d\'abord une carte bancaire dans votre wallet.');
        }

        $carte = $carteId === null ? $cartes->firstWhere('est_principale', true) : $cartes->firstWhere('id', $carteId);

        if ($carte === null) {
            throw new OperationRefusee('Cette carte est introuvable.');
        }

        if ($carte->est_gelee) {
            throw new OperationRefusee("La carte « {$carte->libelle} » est gelée : dégelez-la ou choisissez une autre carte.");
        }

        return $carte;
    }

    /** @throws OperationRefusee */
    private function deLUtilisateur(User $utilisateur, int $carteId, bool $verrou = false): CarteVirtuelle
    {
        $wallet = $this->wallets->pour($utilisateur);
        $requete = CarteVirtuelle::query()->where('wallet_id', $wallet->id)->whereKey($carteId);
        $carte = ($verrou ? $requete->lockForUpdate() : $requete)->first();

        if ($carte === null) {
            throw new OperationRefusee('Cette carte est introuvable.');
        }

        return $carte;
    }

    /** Espaces multiples réduits, bords retirés. */
    private function propre(string $texte): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texte) ?? '');
    }
}
