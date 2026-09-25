<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le code à 6 chiffres de l'application mobile, envoyé par e-mail (SMS_DRIVER=email) : inscription, vérification du compte,
 * mot de passe oublié. Aucun lien cliquable : le code se tape dans l'application (un lien serait une porte pour l'hameçonnage).
 */
class CodeVerificationMail extends Mailable
{
    public function __construct(
        public readonly string $prenom,
        public readonly string $code,
        public readonly string $pourquoi,
        public readonly int $minutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre code KoudMain : '.$this->code);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.code-verification', text: 'emails.code-verification-texte');
    }
}
