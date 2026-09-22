{{-- Messages « flash » : succès (role=status) et erreur générale (role=alert), annoncés par les lecteurs d'écran.
     `cadre` : centre le message et lui donne les marges de la page (désactivé dans un conteneur qui les fournit déjà). --}}
@props(['cadre' => true])
@if (session('succes'))
    <div @class(['mb-6', 'mx-auto mt-6 w-full max-w-6xl px-5 sm:px-8' => $cadre])><p class="message" role="status">{{ session('succes') }}</p></div>
@endif
@if (session('erreur'))
    <div @class(['mb-6', 'mx-auto mt-6 w-full max-w-6xl px-5 sm:px-8' => $cadre])><p class="message message-erreur" role="alert">{{ session('erreur') }}</p></div>
@endif
