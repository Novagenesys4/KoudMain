<?php

namespace App\Http\Requests\Compte;

use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['avatar' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('koudmain.media.envoi_max_ko')]];
    }

    public function messages(): array
    {
        $poids = intdiv((int) config('koudmain.media.envoi_max_ko'), 1024);

        return [
            'avatar.required' => 'Choisissez une photo.',
            'avatar.file' => "L'envoi de la photo a échoué. Réessayez.",
            'avatar.mimetypes' => 'Format non supporté : JPG, PNG ou WebP uniquement.',
            'avatar.max' => "Photo trop lourde ($poids Mo maximum).",
            'avatar.uploaded' => "Photo trop lourde ($poids Mo maximum).",
        ];
    }
}
