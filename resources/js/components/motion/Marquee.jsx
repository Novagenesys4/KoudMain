import { cn } from '../../lib/cn';

/**
 * Bandeau défilant en boucle (CSS pur : aucune charge pour le processeur). Le contenu est
 * dupliqué une fois ; la copie est masquée aux lecteurs d'écran. Pause au survol.
 */
export function Marquee({ duree = 40, className, children }) {
    return (
        <div className={cn('bandeau', className)} style={{ '--duree': `${duree}s` }}>
            <div className="bandeau-piste">
                <div className="flex shrink-0 items-center">{children}</div>
                <div className="flex shrink-0 items-center" aria-hidden="true">
                    {children}
                </div>
            </div>
        </div>
    );
}
