import type { ComponentProps } from 'react';

type Props = Omit<ComponentProps<'input'>, 'id'> & {
    id: string;
    label: string;
    error?: string;
};

export default function Field({ id, label, error, ...input }: Props) {
    return (
        <div>
            <label htmlFor={id} className="text-sm font-medium">{label}</label>
            <input
                {...input}
                id={id}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="pm-input"
            />
            {error && <p id={`${id}-error`} role="alert" className="mt-1 text-sm text-red-700">{error}</p>}
        </div>
    );
}
