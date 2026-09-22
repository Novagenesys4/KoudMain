<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['titre' => 'Paiement en cours', 'robots' => 'noindex,nofollow'])
    {{-- Envoie le client chez l'agrégateur dans une navigation « normale » (et non à la suite d'un envoi de formulaire, que notre CSP limite à notre propre site). --}}
    <meta http-equiv="refresh" content="1;url={{ $paiement->url_paiement }}">
</head>
<body class="grid min-h-dvh place-items-center px-6">
    <main class="max-w-md text-center">
        <h1 class="text-2xl">Un instant…</h1>
        <p class="mt-3 text-soft">Nous vous redirigeons vers la page de paiement sécurisée de votre opérateur pour {{ \App\Support\Format::fcfa($paiement->montant) }}.</p>
        <a href="{{ $paiement->url_paiement }}" rel="noopener" class="btn btn-plein mt-6"><span>Continuer vers le paiement</span></a>
    </main>
</body>
</html>
