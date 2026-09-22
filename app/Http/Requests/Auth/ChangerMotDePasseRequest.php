<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changement de mot de passe d'un utilisateur connecté (plan, phase 0, étape 3 :
 * l'administrateur doit pouvoir remplacer son mot de passe initial).
 */
class ChangerMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mot_de_passe_actuel' => ['bail', 'required', 'string', 'current_password'],
            'password' => [
                'required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed',
                'different:mot_de_passe_actuel',
            ],
        ];
    }

    public function messages(): array
    {
        $mdp = 'Choisissez un mot de passe de 8 caractères minimum, avec au moins une lettre et un chiffre.';

        return [
            'mot_de_passe_actuel.required' => 'Saisissez votre mot de passe actuel.',
            'mot_de_passe_actuel.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.required' => $mdp,
            'password.min' => $mdp,
            'password.max' => $mdp,
            'password.regex' => $mdp,
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ];
    }
}
