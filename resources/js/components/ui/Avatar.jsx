import { initiales } from '../../lib/format';
import { cn } from '../../lib/cn';

/*
 * Sans photo de profil : initiales sur pastille pâle (le texte a toujours un contraste suffisant).
 * Avec `src` : la photo du prestataire, en rond.
 */
const STYLES = {
    amber: { background: 'var(--amber-tint)', color: 'var(--accent)' },
    teal: { background: 'var(--teal-tint)', color: 'var(--teal)' },
    rose: { background: 'var(--rose-tint)', color: 'var(--rose)' },
    sable: { background: 'var(--gold-tint)', color: 'var(--gold)' },
};

export function Avatar({ nom, teinte = 'amber', src, className }) {
    if (src) {
        return <img src={src} alt="" loading="lazy" decoding="async" className={cn('shrink-0 rounded-full bg-deep object-cover', className ?? 'size-10')} />;
    }

    return (
        <span
            aria-hidden="true"
            className={cn('grid shrink-0 place-items-center rounded-full font-semibold leading-none', className ?? 'size-10 text-base')}
            style={STYLES[teinte] ?? STYLES.amber}
        >
            {initiales(nom)}
        </span>
    );
}

export const TEINTES_FOND = {
    amber: 'var(--amber-tint)',
    teal: 'var(--teal-tint)',
    rose: 'var(--rose-tint)',
    sable: 'var(--deep)',
};
