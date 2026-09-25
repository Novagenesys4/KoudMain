<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Développement : le SMS n'est pas envoyé, il est écrit dans le journal (storage/logs). À ne jamais utiliser en production
 * (SMS_DRIVER vaut « aucun » par défaut en production).
 */
class SmsJournal implements EnvoiSms
{
    public function envoyer(string $telephone, string $message): void
    {
        Log::info('sms.journal', ['telephone' => substr($telephone, 0, 2).'••••••'.substr($telephone, -2), 'message' => $message]);
    }
}
