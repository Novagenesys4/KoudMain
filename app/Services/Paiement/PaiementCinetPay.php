<?php

namespace App\Services\Paiement;

use App\Models\Paiement;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CinetPay (Orange Money, MTN MoMo, Wave, carte bancaire) : API « checkout » v2.
 *
 *  1. initier()  : on crée le paiement chez CinetPay, qui renvoie une page de paiement (payment_url) où on envoie le client ;
 *  2. le client paie sur cette page, puis revient sur /paiements/retour ; CinetPay appelle aussi /paiements/notification ;
 *  3. verifier() : on demande À CINETPAY l'état réel de la transaction (jamais confiance au navigateur ni à la notification) ;
 *  4. jetonValide() : en plus, la notification est signée (en-tête x-token, HMAC-SHA256 avec la clé secrète du compte).
 *
 * Documentation : https://docs.cinetpay.com/api/1.0-fr/checkout/initialisation (et /notification, /hmac).
 * ATTENTION : ce pilote suit la documentation mais n'a pas encore été essayé avec de vraies clés (elles se créent sur le
 * tableau de bord CinetPay). Le premier essai se fait avec un petit montant réel (voir docs/CINETPAY.md).
 */
class PaiementCinetPay implements FournisseurPaiement
{
    public function nom(): string
    {
        return 'cinetpay';
    }

    public function initier(Paiement $paiement, User $client): ResultatPaiement
    {
        $montant = (int) round((float) $paiement->montant);

        // CinetPay n'accepte en FCFA que des multiples de 5.
        if ($montant % 5 !== 0) {
            return ResultatPaiement::echoue('Le montant doit être un multiple de 5 FCFA.');
        }

        try {
            $reponse = Http::timeout(20)->acceptJson()->asJson()->post($this->url('/payment'), [
                'apikey' => $this->cle(),
                'site_id' => $this->site(),
                'transaction_id' => $paiement->reference,
                'amount' => $montant,
                'currency' => 'XOF',
                'description' => 'Recharge wallet KoudMain',
                'notify_url' => route('paiements.notification'),
                'return_url' => route('paiements.retour'),
                // Le client a déjà choisi son moyen chez nous : Mobile Money ou carte (le guichet CinetPay ne propose que celui-là).
                'channels' => $paiement->methode === 'Carte bancaire' ? 'CREDIT_CARD' : 'MOBILE_MONEY',
                'metadata' => (string) $paiement->id,
                'lang' => 'fr',
                'customer_id' => (string) $client->id,
                'customer_name' => $client->prenom,
                'customer_surname' => $client->nom,
                'customer_email' => $client->email,
                'customer_phone_number' => '+225'.($paiement->telephone ?? $client->telephone),
                'customer_address' => 'Abidjan',
                'customer_city' => 'Abidjan',
                'customer_country' => 'CI',
                'customer_state' => 'CI',
                'customer_zip_code' => '00225',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('paiement.cinetpay.injoignable', ['reference' => $paiement->reference, 'erreur' => $e->getMessage()]);

            return ResultatPaiement::echoue('Le service de paiement ne répond pas. Réessayez dans un instant.');
        }

        $url = $reponse->json('data.payment_url');

        if (! $reponse->successful() || ! is_string($url) || $url === '') {
            Log::warning('paiement.cinetpay.refuse', ['reference' => $paiement->reference, 'code' => $reponse->json('code'), 'message' => $reponse->json('message')]);

            return ResultatPaiement::echoue('Le paiement n\'a pas pu être ouvert ('.($reponse->json('message') ?: 'erreur du service').').');
        }

        return ResultatPaiement::enAttente($url, (string) $reponse->json('data.payment_token'));
    }

    public function verifier(Paiement $paiement): ResultatPaiement
    {
        try {
            $reponse = Http::timeout(20)->acceptJson()->asJson()->post($this->url('/payment/check'), [
                'apikey' => $this->cle(),
                'site_id' => $this->site(),
                'transaction_id' => $paiement->reference,
            ]);
        } catch (ConnectionException) {
            return ResultatPaiement::enAttente(); // on re-vérifiera : rien n'est décidé
        }

        $statut = (string) $reponse->json('data.status');
        $devise = $reponse->json('data.currency');

        // Une autre devise que le FCFA n'est jamais créditée comme du FCFA.
        if ($statut === 'ACCEPTED' && is_string($devise) && $devise !== '' && $devise !== 'XOF') {
            Log::error('paiement.cinetpay.devise_inattendue', ['reference' => $paiement->reference, 'devise' => $devise]);

            return ResultatPaiement::echoue('Devise inattendue.');
        }

        return match ($statut) {
            'ACCEPTED' => ResultatPaiement::reussi((float) $reponse->json('data.amount'), (string) $reponse->json('data.operator_id')),
            'REFUSED' => ResultatPaiement::echoue('Paiement refusé par l\'opérateur.'),
            default => ResultatPaiement::enAttente(),
        };
    }

    /**
     * La notification vient-elle vraiment de CinetPay ? L'en-tête x-token est le HMAC-SHA256, avec la clé secrète du compte,
     * de 16 champs de la notification collés dans un ordre fixe (https://docs.cinetpay.com/api/1.0-en/checkout/hmac).
     *
     * FAIL-CLOSED (règle 17) : si la vérification est active (toujours le cas en production) et qu'aucune clé secrète n'est
     * configurée, la notification est REFUSÉE, pas acceptée : sinon oublier une variable d'environnement désactiverait
     * silencieusement la protection. Seul le coupe-circuit explicite CINETPAY_VERIFIER_SIGNATURE=false (ignoré en production)
     * la désactive ; la vraie garantie de crédit reste alors PaiementService::confirmer(), qui redemande l'état à CinetPay.
     */
    public static function jetonValide(Request $requete): bool
    {
        if (! config('koudmain.paiement.cinetpay.verifier_signature')) {
            return true;
        }

        $cle = (string) config('koudmain.paiement.cinetpay.secret_key');

        if ($cle === '') {
            Log::error('paiement.cle_secrete_absente', ['detail' => 'CINETPAY_SECRET_KEY est vide : les notifications sont refusées.']);

            return false;
        }

        $recu = (string) $requete->header('x-token');

        if ($recu === '') {
            return false;
        }

        $champs = ['cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency', 'signature', 'payment_method', 'cel_phone_num',
            'cpm_phone_prefixe', 'cpm_language', 'cpm_version', 'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation', 'cpm_error_message'];

        $donnees = '';

        foreach ($champs as $champ) {
            $valeur = $requete->input($champ);
            $donnees .= is_scalar($valeur) ? (string) $valeur : '';
        }

        return hash_equals(hash_hmac('sha256', $donnees, $cle), $recu);
    }

    private function url(string $chemin): string
    {
        return rtrim((string) config('koudmain.paiement.cinetpay.url'), '/').$chemin;
    }

    private function cle(): string
    {
        return (string) config('koudmain.paiement.cinetpay.api_key');
    }

    private function site(): string
    {
        return (string) config('koudmain.paiement.cinetpay.site_id');
    }
}
