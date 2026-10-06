import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import Button from './ui/button';
import Field from './ui/field';

const colors = [
    { name: 'Orange', value: '#e64b27' },
    { name: 'Blue', value: '#79a7cf' },
    { name: 'Green', value: '#4f9d69' },
    { name: 'Gold', value: '#d9a441' },
    { name: 'Purple', value: '#8b6fc4' },
    { name: 'Pink', value: '#d96a8f' },
    { name: 'Teal', value: '#3f8f8f' },
    { name: 'Gray', value: '#6b7280' },
];

type Props = { existingCount: number; onClose: () => void };

export default function ResponsibilityDialog({ existingCount, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [showDescription, setShowDescription] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        color: colors[existingCount % colors.length].value,
    });

    useEffect(() => { dialog.current?.showModal(); }, []);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/responsibilities', { preserveScroll: true, onSuccess: () => onClose() });
    }

    return (
        <dialog
            ref={dialog}
            aria-labelledby="responsibility-title"
            className="pm-dialog"
            onCancel={(event) => { if (processing) event.preventDefault(); else onClose(); }}
            onClose={onClose}
        >
            <form onSubmit={submit} className="space-y-5">
                <h2 id="responsibility-title" className="text-xl font-medium">New responsibility</h2>

                <Field
                    id="responsibility-name"
                    label="Name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    placeholder="Home, Capstone, Health…"
                    maxLength={255}
                    error={errors.name}
                    autoFocus
                    required
                />

                <fieldset>
                    <legend className="text-sm font-medium">Color</legend>
                    <div className="mt-2 flex flex-wrap gap-3">
                        {colors.map((color) => (
                            <label key={color.value} className="cursor-pointer">
                                <input
                                    type="radio"
                                    name="color"
                                    value={color.value}
                                    checked={data.color === color.value}
                                    onChange={() => setData('color', color.value)}
                                    className="peer sr-only"
                                />
                                <span
                                    className="block size-7 rounded-full ring-2 ring-transparent ring-offset-2 peer-checked:ring-[var(--pm-text)] peer-focus-visible:ring-[var(--pm-blue)]"
                                    style={{ background: color.value }}
                                />
                                <span className="sr-only">{color.name}</span>
                            </label>
                        ))}
                    </div>
                    {errors.color && <p role="alert" className="mt-1 text-sm text-red-700">{errors.color}</p>}
                </fieldset>

                {showDescription ? (
                    <div>
                        <label htmlFor="responsibility-description" className="text-sm font-medium">Description</label>
                        <textarea
                            id="responsibility-description"
                            rows={3}
                            maxLength={5000}
                            value={data.description}
                            onChange={(event) => setData('description', event.target.value)}
                            className="pm-input"
                        />
                        {errors.description && <p role="alert" className="mt-1 text-sm text-red-700">{errors.description}</p>}
                    </div>
                ) : (
                    <button type="button" onClick={() => setShowDescription(true)} className="pm-link text-sm">Add description</button>
                )}

                <div className="pm-button-group justify-end">
                    <Button type="button" variant="secondary" disabled={processing} onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={processing} loadingLabel="Saving responsibility" disabled={!data.name.trim()}>
                        Add responsibility
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
