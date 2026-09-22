@php
    // Une page filtrée, triée ou au-delà de la première ne doit pas être indexée par Google (contenu quasi identique).
    $filtree = $criteres->q !== '' || $criteres->nombreFiltres() > 0 || $criteres->tri !== 'recent' || $page->currentPage() > 1;
@endphp
<x-layouts.app titre="Catalogue des prestations"
               description="Coiffure, plomberie, laverie, garde d'enfants : comparez les prestations, les prix et les avis, puis réservez chez un prestataire vérifié."
               :canonique="route('catalogue')" :robots="$filtree ? 'noindex,follow' : null">
    <section class="mx-auto w-full max-w-6xl px-5 pt-10 sm:px-8 lg:pt-14">
        <p class="etiquette">Catalogue</p>
        <h1 class="mt-4 text-4xl sm:text-5xl">Toutes les <em>prestations</em></h1>
        <p class="mt-3 max-w-2xl text-soft">Comparez les prix, les quartiers et les avis, puis choisissez un prestataire vérifié.</p>

        @include('catalogue._recherche', ['nomRoute' => 'catalogue', 'espace' => false])
    </section>
</x-layouts.app>
