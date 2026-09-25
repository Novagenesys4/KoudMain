<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envoi de la vérification d'identité (multipart/form-data) :
 *   categories[]  : identifiants de catégories (« Vos services »), au moins un ;
 *   villes[]      : identifiants de villes / communes (« Zones d'intervention »), au moins une ;
 *   recto, verso, selfie : trois photos JPG, PNG ou WebP (8 Mo maximum chacune).
 * Les messages reprennent ceux du prototype.
 */
class KycApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kyc = config('koudmain.api.kyc');
        $photo = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.$kyc['taille_max_ko']];

        return [
            'categories' => ['required', 'array', 'min:1', 'max:'.$kyc['categories_max']],
            'categories.*' => ['integer', 'distinct', 'exists:categories,id'],
            'villes' => ['required', 'array', 'min:1', 'max:'.$kyc['villes_max']],
            'villes.*' => ['integer', 'distinct', 'exists:villes,id'],
            'recto' => $photo,
            'verso' => $photo,
            'selfie' => $photo,
        ];
    }

    public function messages(): array
    {
        return [
            'categories.required' => 'Choisissez au moins un service.',
            'categories.min' => 'Choisissez au moins un service.',
            'categories.max' => 'Choisissez :max services au maximum.',
            'categories.*' => 'Un des services choisis n\'existe plus. Rechargez la liste.',
            'villes.required' => 'Choisissez au moins une zone d\'intervention.',
            'villes.min' => 'Choisissez au moins une zone d\'intervention.',
            'villes.max' => 'Choisissez :max zones au maximum.',
            'villes.*' => 'Une des zones choisies n\'existe plus. Rechargez la liste.',
            'recto.required' => 'Ajoutez la photo du recto de votre pièce.',
            'verso.required' => 'Ajoutez la photo du verso de votre pièce.',
            'selfie.required' => 'Ajoutez votre selfie avec la pièce.',
            '*.max' => 'Photo trop lourde (8 Mo maximum).',
            '*.mimes' => 'Format non supporté : JPG, PNG ou WebP uniquement.',
            '*.mimetypes' => 'Format non supporté : JPG, PNG ou WebP uniquement.',
            '*.file' => 'L\'envoi de la photo a échoué. Réessayez.',
        ];
    }
}
