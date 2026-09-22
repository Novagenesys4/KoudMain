{{--
    Écran de chargement d'ouverture, façon application : plein écran sombre, compteur géant, mots qui défilent,
    puis le rideau se lève sur le site. Affiché UNE fois par session de navigation (cookie « km_intro », posé par
    resources/js/chargement.js) ; jamais sans JavaScript, jamais si le visiteur préfère moins d'animations.
--}}
@unless (request()->cookie('km_intro'))
    <div id="chargement" class="chargement" data-ecran-ouverture role="status" aria-live="polite" aria-label="KoudMain se charge">
        <div class="chargement-haut">
            <span class="chargement-mono">KoudMain<span aria-hidden="true"> ✦ </span>Services à domicile</span>
            <span class="chargement-mono"><span data-heure>Abidjan</span></span>
        </div>

        <div class="chargement-centre" aria-hidden="true">
            <p class="chargement-mono chargement-legende">Un prestataire pour</p>
            <div class="chargement-mots">
                @foreach (['Coiffure', 'Plomberie', 'Laverie', "Garde d'enfants"] as $mot)
                    <span class="chargement-mot" data-mot>{{ $mot }}</span>
                @endforeach
                <span class="chargement-mot chargement-mot-marque" data-mot>Koud<em>Main.</em></span>
            </div>
        </div>

        <div class="chargement-bas">
            <p class="chargement-compteur" aria-hidden="true"><span data-compteur>0</span><small>%</small></p>
            <div class="chargement-droite">
                <p class="chargement-mono">Des prestataires de confiance,<br>près de chez vous.</p>
                <button type="button" class="chargement-passer" data-passer>Passer <span aria-hidden="true">→</span></button>
            </div>
        </div>

        <div class="chargement-ligne" aria-hidden="true"><span data-ligne></span></div>
    </div>
@endunless
