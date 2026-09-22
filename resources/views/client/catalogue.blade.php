<x-layouts.espace titre="Catalogue" :recherche="false">
    <x-espace.entete etiquette="Catalogue" titre="Les services" suite="près de chez vous"
                     intro="Comparez les prix, les quartiers et les avis, puis choisissez un prestataire vérifié." />

    @include('catalogue._recherche', ['nomRoute' => 'client.catalogue', 'espace' => true])
</x-layouts.espace>
