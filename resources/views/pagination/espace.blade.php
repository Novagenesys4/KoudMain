{{-- Pagination compacte pour les listes des espaces (dans un panneau). --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3.5">
        <p class="text-sm text-soft">Page {{ $paginator->currentPage() }} sur {{ $paginator->lastPage() }}</p>

        <ul class="flex flex-wrap items-center gap-1">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="inline-flex h-9 items-center rounded-lg px-3 text-sm text-faint" aria-disabled="true">Précédent</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm transition-colors hover:border-accent hover:text-accent">Précédent</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="inline-flex h-9 min-w-7 items-center justify-center text-sm text-soft" aria-hidden="true">…</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg bg-ink px-2.5 text-sm font-medium text-paper">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="Aller à la page {{ $page }}" class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg border border-line px-2.5 text-sm transition-colors hover:border-accent hover:text-accent">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm transition-colors hover:border-accent hover:text-accent">Suivant</a>
                @else
                    <span class="inline-flex h-9 items-center rounded-lg px-3 text-sm text-faint" aria-disabled="true">Suivant</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
