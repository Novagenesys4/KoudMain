import { cn } from '../../lib/cn';

/** Logo KoudMain (même rendu que le composant Blade <x-logo>) : « Koud » en encre, « Main. » en terracotta. */
export function Logo({ className }) {
    return (
        <span className={cn('logo', className)}>
            Koud<span className="logo-main">Main</span>
            <span className="logo-point" aria-hidden="true">.</span>
        </span>
    );
}
