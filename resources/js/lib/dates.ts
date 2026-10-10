const dateFormatter = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' });

export function formatDate(iso: string | null | undefined): string {
    return iso ? dateFormatter.format(new Date(iso)) : '';
}
