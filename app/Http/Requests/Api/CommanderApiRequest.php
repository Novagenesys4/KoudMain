<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Commande\CommanderRequest;

/**
 * Commander depuis l'application : les règles du site (quantité, jour, heure, précisions, mode de paiement) + la prestation
 * et, facultatif, le quartier de l'intervention (sinon celui du client). Le prestataire et le prix ne viennent JAMAIS du client.
 */
class CommanderApiRequest extends CommanderRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'prestation_id' => ['required', 'integer', 'min:1'],
            'quartier_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'prestation_id.*' => 'Choisissez la prestation à réserver.',
            'quartier_id.*' => 'Choisissez le quartier où la prestation aura lieu.',
        ];
    }
}
