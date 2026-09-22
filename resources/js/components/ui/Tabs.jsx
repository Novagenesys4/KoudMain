import { motion } from 'motion/react';
import { useId } from 'react';
import { cn } from '../../lib/cn';

/**
 * Onglets à pastille partagée : la pastille GLISSE d'un onglet à l'autre (layoutId de Motion)
 * au lieu d'apparaître/disparaître. Accessible : rôle tablist, flèches gauche/droite, Début/Fin.
 * `passif` : onglets purement décoratifs (aperçu qui défile tout seul) : ni clic, ni focus, masqués aux lecteurs d'écran.
 */
export function Tabs({ items, valeur, onChange, etiquette, panneau, className, compact = false, passif = false }) {
    const id = useId();

    const surTouche = (e) => {
        const i = items.findIndex((it) => it.cle === valeur);
        let suivant = null;
        if (e.key === 'ArrowRight') suivant = (i + 1) % items.length;
        else if (e.key === 'ArrowLeft') suivant = (i - 1 + items.length) % items.length;
        else if (e.key === 'Home') suivant = 0;
        else if (e.key === 'End') suivant = items.length - 1;
        if (suivant === null) return;
        e.preventDefault();
        onChange(items[suivant].cle);
        e.currentTarget.querySelectorAll('[role=tab]')[suivant]?.focus();
    };

    return (
        <div
            role={passif ? 'presentation' : 'tablist'}
            aria-label={passif ? undefined : etiquette}
            aria-hidden={passif ? 'true' : undefined}
            onKeyDown={passif ? undefined : surTouche}
            className={cn('inline-flex max-w-full gap-1 overflow-x-auto rounded-xl border border-line bg-deep p-1 [scrollbar-width:none]', passif && 'pointer-events-none select-none', className)}
        >
            {items.map((item) => {
                const actif = item.cle === valeur;
                return (
                    <button
                        key={item.cle}
                        type="button"
                        role={passif ? undefined : 'tab'}
                        aria-selected={passif ? undefined : actif}
                        aria-controls={passif ? undefined : panneau}
                        tabIndex={passif || !actif ? -1 : 0}
                        disabled={passif}
                        onClick={passif ? undefined : () => onChange(item.cle)}
                        className={cn(
                            'relative shrink-0 cursor-pointer whitespace-nowrap disabled:cursor-default rounded-lg font-medium outline-offset-2 transition-colors duration-300 active:scale-[0.98]',
                            compact ? 'px-3.5 py-1.5 text-[0.8125rem]' : 'px-4 py-2 text-sm',
                            actif ? 'text-ink' : 'text-soft hover:text-ink',
                        )}
                    >
                        {actif && (
                            <motion.span
                                layoutId={`onglet-${id}`}
                                className="absolute inset-0 rounded-lg bg-surface shadow-soft ring-1 ring-line"
                                transition={{ type: 'spring', bounce: 0.18, duration: 0.55 }}
                            />
                        )}
                        <span className="relative flex items-center gap-2">
                            {item.icone}
                            {item.libelle}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
