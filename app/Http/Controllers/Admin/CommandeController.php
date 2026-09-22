<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Services\CommandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * L'administrateur tranche un litige. La liste et le détail des commandes sont ceux de l'espace
 * (App\Http\Controllers\Espace\CommandeController, rôle « admin »).
 */
class CommandeController extends Controller
{
    public function arbitrer(Request $request, Commande $commande, CommandeService $service): RedirectResponse
    {
        $donnees = $request->validate([
            'decision' => ['required', 'in:prestataire,client'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'decision.*' => 'Choisissez qui reçoit l\'argent : le prestataire ou le client.',
            'note.max' => 'La note ne peut pas dépasser 500 caractères.',
        ]);

        try {
            $service->arbitrer($commande, $request->user(), $donnees['decision'] === 'prestataire', $donnees['note'] ?? null);
        } catch (OperationRefusee $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', $donnees['decision'] === 'prestataire'
            ? 'Litige tranché : le prestataire a été payé.'
            : 'Litige tranché : le client a été remboursé.');
    }
}
