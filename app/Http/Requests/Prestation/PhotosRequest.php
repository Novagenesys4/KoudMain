<?php

namespace App\Http\Requests\Prestation;

use Illuminate\Foundation\Http\FormRequest;

/** Ajout de photos à une prestation existante. */
class PhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.config('koudmain.media.photos_par_prestation')],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('koudmain.media.envoi_max_ko')],
        ];
    }

    public function messages(): array
    {
        $max = config('koudmain.media.photos_par_prestation');
        $poids = intdiv((int) config('koudmain.media.envoi_max_ko'), 1024);

        return [
            'photos.required' => 'Choisissez au moins une photo.',
            'photos.min' => 'Choisissez au moins une photo.',
            'photos.array' => 'Choisissez au moins une photo.',
            'photos.max' => "Vous pouvez envoyer $max photos au maximum.",
            'photos.*.file' => "L'envoi de la photo a échoué. Réessayez.",
            'photos.*.mimetypes' => 'Format non supporté : JPG, PNG ou WebP uniquement.',
            'photos.*.max' => "Photo trop lourde ($poids Mo maximum).",
            'photos.*.uploaded' => "Photo trop lourde ($poids Mo maximum).",
        ];
    }

    /** @return list<\Illuminate\Http\UploadedFile> */
    public function photos(): array
    {
        return array_values(array_filter((array) $this->file('photos', [])));
    }
}
