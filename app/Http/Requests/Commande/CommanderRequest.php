<?php

namespace App\Http\Requests\Commande;

use App\Enums\ModePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Le formulaire de commande : quantité, jour, heure, précisions, mode de paiement. Le prestataire et le prix ne viennent JAMAIS du navigateur. */
class CommanderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // le middleware « role:client » a déjà filtré
    }

    public function rules(): array
    {
        return [
            'quantite' => ['required', 'integer', 'min:1', 'max:'.config('koudmain.finance.quantite_max')],
            'date' => ['required', 'date_format:Y-m-d'],
            'heure' => ['required', 'date_format:H:i'],
            'precisions' => ['nullable', 'string', 'max:500'],
            // Sans choix explicite, Mobile Money (le mode d'origine). Par carte, la carte est obligatoire.
            'mode_paiement' => ['nullable', Rule::enum(ModePaiement::class)],
            'carte_id' => ['nullable', 'integer', 'min:1', 'required_if:mode_paiement,carte'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantite.*' => 'La quantité doit être un nombre entre 1 et '.config('koudmain.finance.quantite_max').'.',
            'date.required' => 'Choisissez un jour.',
            'date.date_format' => 'Le jour choisi n\'est pas valide.',
            'heure.required' => 'Choisissez une heure.',
            'heure.date_format' => 'L\'heure choisie n\'est pas valide.',
            'mode_paiement.*' => 'Choisissez comment vous voulez payer : en main propre, par Mobile Money ou par carte bancaire.',
            'carte_id.required_if' => 'Choisissez la carte bancaire qui paie cette commande.',
            'carte_id.*' => 'Cette carte n\'est pas valide.',
            'precisions.max' => 'Les précisions ne peuvent pas dépasser 500 caractères.',
        ];
    }

    public function mode(): ModePaiement
    {
        return ModePaiement::tryFrom((string) $this->validated('mode_paiement')) ?? ModePaiement::MobileMoney;
    }

    public function debut(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->validated('date').' '.$this->validated('heure'));
    }
}
