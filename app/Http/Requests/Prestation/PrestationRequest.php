<?php

namespace App\Http\Requests\Prestation;

use App\Support\Saisie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'une prestation (reprend validerPrestation() de l'ancien prestation_service.php).
 */
class PrestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // l'accès est déjà limité aux prestataires validés par les routes (middleware « role »)
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'titre' => trim(Saisie::chaine($this->input('titre'))),
            'description' => $this->nettoyerDescription($this->input('description')),
            // « 15 000 », « 15.000 » ou « 15000 FCFA » -> 15000
            'prix' => preg_replace('/\D+/', '', Saisie::chaine($this->input('prix'))),
            'duree_minutes' => $this->filled('duree_minutes') ? $this->input('duree_minutes') : null,
        ]);
    }

    public function rules(): array
    {
        $prix = config('koudmain.prestation');

        return [
            'titre' => ['required', 'string', 'min:3', 'max:150'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'prix' => ['required', 'integer', 'min:'.$prix['prix_min'], 'max:'.$prix['prix_max']],
            'duree_minutes' => ['nullable', 'integer', Rule::in($prix['durees'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:'.config('koudmain.media.photos_par_prestation')],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('koudmain.media.envoi_max_ko')],
        ];
    }

    public function messages(): array
    {
        $prix = config('koudmain.prestation');
        $intervalle = 'Le prix doit être compris entre '.number_format($prix['prix_min'], 0, ',', ' ').' et '.number_format($prix['prix_max'], 0, ',', ' ').' FCFA.';
        $max = config('koudmain.media.photos_par_prestation');
        $poids = intdiv((int) config('koudmain.media.envoi_max_ko'), 1024);

        return [
            'titre.required' => 'Le titre doit contenir entre 3 et 150 caractères.',
            'titre.min' => 'Le titre doit contenir entre 3 et 150 caractères.',
            'titre.max' => 'Le titre doit contenir entre 3 et 150 caractères.',
            'service_id.required' => 'Choisissez un service dans la liste.',
            'service_id.integer' => 'Choisissez un service dans la liste.',
            'service_id.exists' => 'Choisissez un service dans la liste.',
            'prix.required' => $intervalle,
            'prix.integer' => $intervalle,
            'prix.min' => $intervalle,
            'prix.max' => $intervalle,
            'duree_minutes.in' => 'Choisissez une durée dans la liste.',
            'duree_minutes.integer' => 'Choisissez une durée dans la liste.',
            'description.max' => 'La description ne doit pas dépasser 5 000 caractères.',
            'photos.max' => "Vous pouvez envoyer $max photos au maximum.",
            'photos.*.file' => "L'envoi de la photo a échoué. Réessayez.",
            'photos.*.mimetypes' => 'Format non supporté : JPG, PNG ou WebP uniquement.',
            'photos.*.max' => "Photo trop lourde ($poids Mo maximum).",
            'photos.*.uploaded' => "Photo trop lourde ($poids Mo maximum).",
        ];
    }

    /** Les champs de la prestation, sans les photos. */
    public function donneesPrestation(): array
    {
        return $this->safe()->only(['titre', 'service_id', 'prix', 'duree_minutes', 'description']);
    }

    /** @return list<\Illuminate\Http\UploadedFile> */
    public function photos(): array
    {
        return array_values(array_filter((array) $this->file('photos', [])));
    }

    /** Sauts de ligne conservés, mais pas plus de deux d'affilée ; pas de balises HTML. */
    private function nettoyerDescription(mixed $texte): ?string
    {
        $texte = trim(strip_tags(Saisie::chaine($texte)));
        $texte = preg_replace("/\R{3,}/u", "\n\n", $texte) ?? '';

        return $texte === '' ? null : $texte;
    }
}
