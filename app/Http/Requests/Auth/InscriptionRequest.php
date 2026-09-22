<?php

namespace App\Http\Requests\Auth;

use App\Support\Saisie;
use App\Support\TelephoneCI;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de l'inscription (ex-validerInscription() de inscription_service.php).
 * Les messages sont ceux de l'ancienne application, affichés sous chaque champ.
 */
class InscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nom' => trim(Saisie::chaine($this->input('nom'))),
            'prenom' => trim(Saisie::chaine($this->input('prenom'))),
            'email' => mb_strtolower(trim(Saisie::chaine($this->input('email')))),
            'telephone' => TelephoneCI::normaliser(Saisie::chaine($this->input('telephone'))),
        ]);
    }

    public function rules(): array
    {
        $lettres = "/^[\\p{L}][\\p{L}\\s'’\\-]*$/u";

        return [
            'nom' => ['required', 'string', 'min:2', 'max:50', 'regex:'.$lettres],
            'prenom' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$lettres],
            // Volontairement AUCUNE vérification « adresse déjà inscrite » ici (règle 16) : un message « ce compte existe déjà » permettrait
            // à n'importe qui de tester si une personne a un compte. Le cas est traité par InscriptionController : même réponse, et
            // un e-mail envoyé au propriétaire de l'adresse. L'unicité reste garantie par l'index unique de la base.
            'email' => ['bail', 'required', 'string', 'max:150', 'email:rfc'],
            'telephone' => ['required', 'string', 'regex:'.TelephoneCI::MOTIF],
            // 8 à 72 caractères (72 = limite de bcrypt), au moins une lettre et un chiffre.
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
            'quartier_id' => ['required', 'integer', 'exists:quartiers,id'],
            'role' => ['required', 'in:client,prestataire'],
        ];
    }

    public function messages(): array
    {
        $mdp = 'Choisissez un mot de passe de 8 caractères minimum, avec au moins une lettre et un chiffre.';

        return [
            'nom.required' => 'Indiquez votre nom (2 à 50 lettres).',
            'nom.min' => 'Indiquez votre nom (2 à 50 lettres).',
            'nom.max' => 'Indiquez votre nom (2 à 50 lettres).',
            'nom.regex' => 'Indiquez votre nom (2 à 50 lettres).',
            'prenom.required' => 'Indiquez votre prénom (2 à 100 lettres).',
            'prenom.min' => 'Indiquez votre prénom (2 à 100 lettres).',
            'prenom.max' => 'Indiquez votre prénom (2 à 100 lettres).',
            'prenom.regex' => 'Indiquez votre prénom (2 à 100 lettres).',
            'email.required' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
            'email.email' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
            'email.max' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
            'telephone.required' => 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.',
            'telephone.regex' => 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.',
            'password.required' => $mdp,
            'password.min' => $mdp,
            'password.max' => $mdp,
            'password.regex' => $mdp,
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
            'quartier_id.required' => 'Choisissez votre quartier dans la liste.',
            'quartier_id.integer' => 'Choisissez votre quartier dans la liste.',
            'quartier_id.exists' => 'Choisissez votre quartier dans la liste.',
            'role.required' => 'Choisissez un type de compte.',
            'role.in' => 'Choisissez un type de compte.',
        ];
    }
}
