<?php

namespace App\Services\Sms;

/**
 * Un fournisseur d'envoi de SMS. OtpService ne connaît que ce contrat : brancher un vrai fournisseur (opérateur ivoirien,
 * agrégateur...) = écrire une classe qui l'implémente et l'ajouter dans OtpService::fournisseur() (SMS_DRIVER).
 */
interface EnvoiSms
{
    /** @param  string  $telephone  numéro ivoirien normalisé (10 chiffres, sans +225) */
    public function envoyer(string $telephone, string $message): void;
}
