import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { LecteurAudio } from '@/lib/audioPlayer';

// Un seul lecteur pour toute l'application : un appui sur un haut-parleur
// coupe la phrase en cours, même venue d'un autre écran.
const lecteur = new LecteurAudio();

type Valeurs = Record<string, string | number>;

/**
 * Haut-parleurs du mode simple. Une icône n'est montrée que pour les phrases
 * que le serveur dit entièrement enregistrées (`audio.disponibles`), et la
 * lecture demande au serveur la liste ordonnée des morceaux (les nombres sont
 * dits avec les mots de chiffres). Jamais de voix de synthèse.
 */
export function useAudio() {
    const page = usePage();
    const disponibles = computed(
        () =>
            new Set(
                (page.props.audio as { disponibles: string[] } | null)
                    ?.disponibles ?? [],
            ),
    );

    function disponible(code?: string | null): boolean {
        return (
            code !== undefined && code !== null && disponibles.value.has(code)
        );
    }

    async function jouer(code: string, valeurs: Valeurs = {}): Promise<void> {
        const requete = new URLSearchParams(
            Object.entries(valeurs).map(([cle, valeur]) => [
                cle,
                String(valeur),
            ]),
        ).toString();

        try {
            const reponse = await fetch(
                `/simple/audio/${code}${requete === '' ? '' : `?${requete}`}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );

            if (!reponse.ok) {
                return;
            }

            const { clips } = (await reponse.json()) as { clips: string[] };
            await lecteur.jouer(clips);
        } catch {
            // Hors ligne ou lecture refusée : pas de bruit, l'écran reste utilisable.
        }
    }

    return { disponible, jouer, arreter: () => lecteur.arreter() };
}
