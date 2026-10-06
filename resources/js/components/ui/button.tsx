import type { ComponentProps } from 'react';
import Spinner from './spinner';

type Props = ComponentProps<'button'> & {
    variant?: 'primary' | 'secondary' | 'danger';
    size?: 'default' | 'small';
    loading?: boolean;
    loadingLabel?: string;
};

export default function Button({
    variant = 'primary',
    size = 'default',
    loading = false,
    loadingLabel = 'Loading',
    disabled,
    className,
    children,
    ...props
}: Props) {
    const classes = [
        'pm-button',
        variant !== 'primary' && `pm-button--${variant}`,
        size === 'small' && 'pm-button--small',
        className,
    ].filter(Boolean).join(' ');

    return (
        <button {...props} disabled={disabled || loading} className={classes}>
            {loading ? <Spinner label={loadingLabel} /> : children}
        </button>
    );
}
