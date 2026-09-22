<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** L'e-mail de toutes les notifications : un titre, un court texte, un bouton. Volontairement simple et lisible partout. */
class NotificationMail extends Mailable
{
    public function __construct(
        public readonly string $prenom,
        public readonly string $titre,
        public readonly string $texte,
        public readonly string $lien,
        public readonly string $bouton,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titre.' · KoudMain');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification', text: 'emails.notification-texte');
    }
}
