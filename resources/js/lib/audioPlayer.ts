/**
 * Lecteur des messages audio du mode simple : joue les morceaux d'une phrase
 * à la suite, et un nouvel appui coupe la lecture en cours (cahier des
 * charges, « Système audio » › Lecture).
 */
export interface ElementAudio {
    play(): Promise<void>;
    pause(): void;
    onended: (() => void) | null;
    onerror: (() => void) | null;
}

export class LecteurAudio {
    private lecture = 0;
    private courant: ElementAudio | null = null;
    private liberer: (() => void) | null = null;

    private readonly creer: (url: string) => ElementAudio;

    constructor(
        creer: (url: string) => ElementAudio = (url) =>
            new Audio(url) as unknown as ElementAudio,
    ) {
        this.creer = creer;
    }

    /** Coupe la lecture en cours, s'il y en a une. */
    arreter(): void {
        this.lecture++;
        this.courant?.pause();
        this.courant = null;
        this.liberer?.();
        this.liberer = null;
    }

    /**
     * Joue les morceaux dans l'ordre, sans blanc ajouté entre eux. Renvoie
     * vrai si toute la phrase a été jouée, faux si elle a été coupée ou si un
     * morceau n'a pas pu être lu.
     */
    async jouer(urls: string[]): Promise<boolean> {
        this.arreter();
        const identifiant = this.lecture;

        for (const url of urls) {
            if (identifiant !== this.lecture) {
                return false;
            }

            const element = this.creer(url);
            this.courant = element;

            try {
                await new Promise<void>((resoudre, rejeter) => {
                    this.liberer = resoudre;
                    element.onended = () => resoudre();
                    element.onerror = () => rejeter(new Error('audio'));
                    element.play().catch(rejeter);
                });
            } catch {
                return false;
            }
        }

        return identifiant === this.lecture;
    }
}
