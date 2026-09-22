<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nouveau mot de passe choisi depuis le lien signé de « mot de passe oublié ».
 * Contrairement à ChangerMotDePasseRequest (compte connecté), il n'y a ici ni session ni mot de passe actuel à vérifier :
 * c'est le lien signé lui-même (contrôlé dans le contrôleur) qui prouve qu'il s'agit bien du propriétaire du compte.
 */
class NouveauMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        $mdp = 'Choisissez un mot de passe de 8 caractères minimum, avec au moins une lettre et un chiffre.';

        return [
            'password.required' => $mdp,
            'password.min' => $mdp,
            'password.max' => $mdp,
            'password.regex' => $mdp,
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
        ];
    }
}
