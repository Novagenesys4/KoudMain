<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Auth\InscriptionRequest;

/**
 * Inscription depuis l'application : EXACTEMENT les règles du site (nom, prénom, e-mail, téléphone ivoirien, mot de passe de
 * 8 caractères avec lettre et chiffre + confirmation, quartier, rôle), plus le nom de l'appareil (étiquette du futur jeton).
 */
class InscriptionApiRequest extends InscriptionRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
