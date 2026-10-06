type Props = { label: string };

export default function Spinner({ label }: Props) {
    return (
        <span
            role="status"
            aria-label={label}
            className="block size-5 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
    );
}
