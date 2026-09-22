<?php

namespace App\Http\Requests\Compte;

use App\Support\Saisie;
use App\Support\TelephoneCI;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Informations personnelles modifiables depuis « Mon profil » : prénom, nom, téléphone, quartier.
 * Mêmes règles et mêmes messages qu'à l'inscription. L'e-mail (identifiant de connexion) ne change pas ici.
 */
class IdentiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'prenom' => trim(Saisie::chaine($this->input('prenom'))),
            'nom' => trim(Saisie::chaine($this->input('nom'))),
            'telephone' => TelephoneCI::normaliser(Saisie::chaine($this->input('telephone'))),
        ]);
    }

    public function rules(): array
    {
        $lettres = "/^[\\p{L}][\\p{L}\\s'’\\-]*$/u";

        return [
            'prenom' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$lettres],
            'nom' => ['required', 'string', 'min:2', 'max:50', 'regex:'.$lettres],
            'telephone' => ['required', 'string', 'regex:'.TelephoneCI::MOTIF],
            'quartier_id' => ['required', 'integer', 'exists:quartiers,id'],
        ];
    }

    public function messages(): array
    {
        $prenom = 'Indiquez votre prénom (2 à 100 lettres).';
        $nom = 'Indiquez votre nom (2 à 50 lettres).';
        $tel = 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.';
        $quartier = 'Choisissez votre quartier dans la liste.';

        return [
            'prenom.required' => $prenom, 'prenom.min' => $prenom, 'prenom.max' => $prenom, 'prenom.regex' => $prenom,
            'nom.required' => $nom, 'nom.min' => $nom, 'nom.max' => $nom, 'nom.regex' => $nom,
            'telephone.required' => $tel, 'telephone.regex' => $tel,
            'quartier_id.required' => $quartier, 'quartier_id.integer' => $quartier, 'quartier_id.exists' => $quartier,
        ];
    }
}
