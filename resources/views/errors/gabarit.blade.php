<x-layouts.app :titre="$code.' — '.$titre">
    <section class="mx-auto w-full max-w-3xl px-5 pt-20 sm:px-8 lg:pt-32">
        <p class="etiquette">Erreur {{ $code }}</p>
        <h1 class="mt-5 text-4xl sm:text-5xl">{{ $titre }}</h1>
        <p class="mt-6 max-w-xl text-lg text-soft">{{ $message }}</p>
        <div class="mt-10 flex flex-wrap gap-x-8 gap-y-4">
            <a href="{{ url('/') }}" class="btn btn-plein"><span>Retour à l'accueil</span></a>
            @if ($code !== 503)
                <a href="javascript:history.back()" class="lien self-center">Page précédente</a>
            @endif
        </div>
    </section>
</x-layouts.app>
