<?php

namespace App\Http\Requests\Compte;

use App\Support\Saisie;
use Illuminate\Foundation\Http\FormRequest;

/** Présentation affichée sur le profil public d'un prestataire (600 caractères, comme avant). */
class ProfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $bio = trim(strip_tags(Saisie::chaine($this->input('bio'))));

        $this->merge(['bio' => $bio === '' ? null : (preg_replace("/\R{3,}/u", "\n\n", $bio) ?? $bio)]);
    }

    public function rules(): array
    {
        return ['bio' => ['nullable', 'string', 'max:600']];
    }

    public function messages(): array
    {
        return ['bio.max' => 'Votre présentation ne doit pas dépasser 600 caractères.'];
    }
}
