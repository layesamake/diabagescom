/**
 * Mode simple — palette et tailles.
 *
 * Les couleurs viennent des variables CSS déclarées dans app.css (.mode-simple) :
 * jamais de valeur hexadécimale dans un composant. Les couleurs ne changent
 * jamais de sens : Vendre vert, Caisse orange, Stock bleu, Clients violet,
 * alerte rouge.
 */

export type CouleurSimple =
    | 'vert'
    | 'vert-fonce'
    | 'orange'
    | 'bleu'
    | 'violet'
    | 'rouge'
    | 'marque';

/** Fond plein + texte lisible dessus, pour les boutons et tuiles d'action. */
export const fondPlein: Record<CouleurSimple, string> = {
    vert: 'background-color: var(--s-vert); color: var(--s-sur-vert);',
    'vert-fonce':
        'background-color: var(--s-vert-fonce); color: var(--s-sur-vert-fonce);',
    orange: 'background-color: var(--s-orange); color: var(--s-sur-orange);',
    bleu: 'background-color: var(--s-bleu); color: var(--s-sur-bleu);',
    violet: 'background-color: var(--s-violet); color: var(--s-sur-violet);',
    rouge: 'background-color: var(--s-rouge); color: var(--s-sur-rouge);',
    marque: 'background-color: var(--s-marque); color: var(--s-sur-marque);',
};

/** Fond clair + texte foncé, pour les ronds d'icônes et les pastilles. */
export const fondClair: Record<CouleurSimple, string> = {
    vert: 'background-color: var(--s-vert-clair); color: var(--s-sur-vert-clair);',
    'vert-fonce':
        'background-color: var(--s-vert-clair); color: var(--s-sur-vert-clair);',
    orange: 'background-color: var(--s-orange-clair); color: var(--s-sur-orange-clair);',
    bleu: 'background-color: var(--s-bleu-clair); color: var(--s-sur-bleu-clair);',
    violet: 'background-color: var(--s-violet-clair); color: var(--s-sur-violet-clair);',
    rouge: 'background-color: var(--s-rouge-clair); color: var(--s-sur-rouge-clair);',
    marque: 'background-color: var(--s-marque); color: var(--s-sur-marque);',
};

/** Couleur de jauge de stock : assez, bientôt fini, presque fini. */
export const jaugeStock = {
    assez: 'vert-fonce',
    bientot: 'orange',
    presque: 'rouge',
} as const satisfies Record<string, CouleurSimple>;

/**
 * Teinte des initiales d'un nom sans photo : stable pour un même nom, afin
 * qu'un client ou un article garde toujours la même couleur.
 */
export function teinteDeNom(nom: string): CouleurSimple {
    const palette: CouleurSimple[] = [
        'violet',
        'bleu',
        'vert-fonce',
        'orange',
        'rouge',
    ];
    let somme = 0;

    for (const caractere of nom) {
        somme += caractere.codePointAt(0) ?? 0;
    }

    return palette[somme % palette.length];
}

/** Initiales affichées quand il n'y a pas de photo (« Awa Diallo » → « AD »). */
export function initiales(nom: string): string {
    return nom
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((mot) => mot.charAt(0).toLocaleUpperCase('fr-FR'))
        .join('');
}
