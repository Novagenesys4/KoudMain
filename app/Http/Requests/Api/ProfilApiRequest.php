<?php

namespace App\Http\Requests\Api;

use App\Support\Saisie;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/auth/me — « Informations personnelles » de l'application (Phase 10).
 *
 * Mêmes règles et mêmes messages que le site (Compte\IdentiteRequest, Compte\ProfilRequest), à deux différences près :
 *  - le NUMÉRO ne se modifie pas ici : il est vérifié par SMS et sert d'identifiant ; le changer exigerait un nouvel OTP ;
 *  - l'e-mail non plus (identifiant de connexion, comme sur le site).
 * « bio » (présentation publique) est réservée aux prestataires ; « notifications_email » est facultatif.
 */
class ProfilApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $donnees = [
            'prenom' => trim(Saisie::chaine($this->input('prenom'))),
            'nom' => trim(Saisie::chaine($this->input('nom'))),
        ];

        if ($this->exists('bio')) {
            $bio = trim(strip_tags(Saisie::chaine($this->input('bio'))));
            $donnees['bio'] = $bio === '' ? null : (preg_replace("/\R{3,}/u", "\n\n", $bio) ?? $bio);
        }

        $this->merge($donnees);
    }

    public function rules(): array
    {
        $lettres = "/^[\\p{L}][\\p{L}\\s'’\\-]*$/u";

        return [
            'prenom' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$lettres],
            'nom' => ['required', 'string', 'min:2', 'max:50', 'regex:'.$lettres],
            'quartier_id' => ['required', 'integer', 'exists:quartiers,id'],
            'notifications_email' => ['sometimes', 'boolean'],
            'bio' => [$this->user()?->est_prestataire ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:600'],
            // Jamais modifiables ici : refusés explicitement plutôt qu'ignorés en silence.
            'telephone' => ['prohibited'],
            'email' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $prenom = 'Indiquez votre prénom (2 à 100 lettres).';
        $nom = 'Indiquez votre nom (2 à 50 lettres).';
        $quartier = 'Choisissez votre quartier dans la liste.';

        return [
            'prenom.required' => $prenom, 'prenom.min' => $prenom, 'prenom.max' => $prenom, 'prenom.regex' => $prenom,
            'nom.required' => $nom, 'nom.min' => $nom, 'nom.max' => $nom, 'nom.regex' => $nom,
            'quartier_id.required' => $quartier, 'quartier_id.integer' => $quartier, 'quartier_id.exists' => $quartier,
            'notifications_email.boolean' => 'Préférence de notification invalide.',
            'bio.max' => 'Votre présentation ne doit pas dépasser 600 caractères.',
            'bio.prohibited' => 'La présentation publique est réservée aux prestataires.',
            'telephone.prohibited' => 'Le numéro ne se modifie pas depuis cet écran.',
            'email.prohibited' => 'L\'adresse e-mail ne se modifie pas depuis cet écran.',
        ];
    }
}
