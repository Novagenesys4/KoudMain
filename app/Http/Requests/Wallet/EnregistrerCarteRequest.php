<?php

namespace App\Http\Requests\Wallet;

use App\Support\Saisie;
use App\Support\CarteBancaire;
use App\Support\Journal;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le formulaire « Ajouter une carte » : numéro, expiration, code de sécurité, titulaire (prénom et nom), adresse de facturation.
 *
 * Le numéro complet (numero_carte) et le code de sécurité (cvv) ne sont jamais rejoués dans le formulaire après une erreur
 * (voir dontFlash dans bootstrap/app.php), jamais journalisés (Journal les masque), jamais enregistrés (voir CarteVirtuelleService).
 */
class EnregistrerCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // le middleware d'espace a déjà filtré : un client ou un prestataire
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'numero_carte' => CarteBancaire::nettoyer(Saisie::chaine($this->input('numero_carte'))),
            'cvv' => preg_replace('/\D+/', '', Saisie::chaine($this->input('cvv'))),
            'expiration' => trim(Saisie::chaine($this->input('expiration'))),
        ]);
    }

    /**
     * Après un refus, on revient TOUJOURS sur le wallet du bon espace (client ou prestataire), avec la boîte rouverte.
     * Par défaut Laravel revient à l'adresse d'où l'on vient (l'en-tête « Referer ») : quand un navigateur, une extension ou une
     * protection de la vie privée ne l'envoie pas, la personne atterrissait sur l'accueil, sans carte ni message.
     */
    protected function getRedirectUrl(): string
    {
        $role = (string) ($this->route()?->defaults['role'] ?? 'client');

        return route("$role.wallet");
    }

    /** Un refus laisse une trace (seulement les NOMS des champs refusés, jamais leurs valeurs) : « la carte n'est pas apparue » se diagnostique alors dans le journal. */
    protected function failedValidation(Validator $validator): void
    {
        Journal::info('carte.refusee', ['utilisateur' => $this->user()?->id, 'champs' => array_keys($validator->errors()->messages())]);

        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        $reseau = CarteBancaire::reseau(Saisie::chaine($this->input('numero_carte')));
        $nom = ['required', 'string', 'min:2', 'max:40', "regex:/^[\p{L}][\p{L} '’.-]*$/u"];

        return [
            'numero_carte' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec) use ($reseau): void {
                if ($reseau === null) {
                    $echec('Seules les cartes Visa, Mastercard et American Express sont acceptées.');
                } elseif (strlen($valeur) !== CarteBancaire::longueur($reseau)) {
                    $echec('Le numéro doit avoir '.CarteBancaire::longueur($reseau).' chiffres pour une carte '.config('koudmain.cartes.reseaux')[$reseau].'.');
                } elseif (! CarteBancaire::luhn($valeur)) {
                    $echec('Ce numéro de carte n\'est pas valide. Vérifiez chaque chiffre.');
                }
            }],
            'expiration' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (CarteBancaire::lireExpiration($valeur) === null) {
                    $echec('Indiquez la date au format MM/AA, par exemple 08/28.');
                } elseif (! CarteBancaire::expirationValide($valeur)) {
                    $echec('Cette carte est expirée, ou la date est trop lointaine.');
                }
            }],
            'cvv' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec) use ($reseau): void {
                if (! CarteBancaire::cvvValide($valeur, $reseau)) {
                    $echec('Le code de sécurité a '.CarteBancaire::longueurCvv($reseau).' chiffres'.($reseau === CarteBancaire::AMEX ? ' (au recto pour American Express).' : ', au dos de la carte.'));
                }
            }],
            'prenom' => $nom,
            'nom' => $nom,
            'adresse' => ['required', 'string', 'min:5', 'max:120'],
            'ville' => ['required', 'string', 'min:2', 'max:60'],
            'pays' => ['required', 'string', 'min:2', 'max:60'],
            'libelle' => ['nullable', 'string', 'min:2', 'max:30'],
            'couleur' => ['required', Rule::in(array_keys(config('koudmain.cartes.couleurs')))],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_carte.required' => 'Indiquez le numéro de la carte.',
            'expiration.required' => 'Indiquez la date d\'expiration.',
            'cvv.required' => 'Indiquez le code de sécurité (CVV / CVC).',
            'prenom.required' => 'Indiquez le prénom du titulaire, comme sur la carte.',
            'nom.required' => 'Indiquez le nom du titulaire, comme sur la carte.',
            'prenom.regex' => 'Le prénom ne peut contenir que des lettres.',
            'nom.regex' => 'Le nom ne peut contenir que des lettres.',
            'prenom.*' => 'Le prénom doit avoir entre 2 et 40 caractères.',
            'nom.*' => 'Le nom doit avoir entre 2 et 40 caractères.',
            'adresse.*' => 'Indiquez l\'adresse de facturation (5 à 120 caractères).',
            'ville.*' => 'Indiquez la ville.',
            'pays.*' => 'Indiquez le pays.',
            'libelle.*' => 'Le nom de la carte doit avoir entre 2 et 30 caractères.',
            'couleur.*' => 'Choisissez une couleur proposée.',
        ];
    }

    /** Les données pour le service, dans l'ordre où il les attend. Le numéro et le CVV sortent d'ici pour être contrôlés, puis oubliés. */
    public function carte(): array
    {
        return [
            'numero' => Saisie::chaine($this->input('numero_carte')),
            'expiration' => Saisie::chaine($this->input('expiration')),
            'cvv' => Saisie::chaine($this->input('cvv')),
            'prenom' => Saisie::chaine($this->input('prenom')),
            'nom' => Saisie::chaine($this->input('nom')),
            'adresse' => Saisie::chaine($this->input('adresse')),
            'ville' => Saisie::chaine($this->input('ville')),
            'pays' => Saisie::chaine($this->input('pays')),
            'libelle' => $this->input('libelle'),
            'couleur' => Saisie::chaine($this->input('couleur')),
        ];
    }
}
