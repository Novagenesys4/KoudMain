import { cn } from '../../lib/cn';

/** Bloc gris à reflet mobile (classe .squelette) : annonce qu'un contenu arrive, sans faire sauter la page. */
export function Skeleton({ className, ...reste }) {
    return <div className={cn('squelette', className)} aria-hidden="true" {...reste} />;
}
