export type UseInitialsReturn = {
    getInitials: (fullName?: string) => string;
};

function getInitial(name: string): string {
    return Array.from(name)[0] ?? '';
}

export function getInitials(fullName?: string): string {
    if (!fullName) {
        return '';
    }

    // Seuls les mots commençant par une lettre comptent : « Awa Diallo (test) » → « AD ».
    const words = fullName.trim().split(/\s+/u).filter(Boolean);
    const lettered = words.filter((word) => /^\p{L}/u.test(word));
    const names = lettered.length > 0 ? lettered : words;

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return getInitial(names[0]).toUpperCase();
    }

    return `${getInitial(names[0])}${getInitial(names[names.length - 1])}`.toUpperCase();
}

export function useInitials(): UseInitialsReturn {
    return { getInitials };
}
