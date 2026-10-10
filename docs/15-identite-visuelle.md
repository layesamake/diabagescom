# Identité visuelle — DIABA GESCOM

## Logo officiel

Le logo validé de DIABA GESCOM associe :

- un monogramme `D` bleu nuit ;
- un livre comptable ouvert, représentant le grand livre et les écritures ;
- des lignes/colonnes de registre ;
- une coche turquoise, symbole de validation et de fiabilité ;
- un accent or discret ;
- le nom `DIABA GESCOM`, affiché plus petit que l’icône.

L’icône seule est la marque à utiliser pour le favicon, l’icône de l’application PWA, les petits écrans et les emplacements où le texte serait illisible.

## Couleurs de référence

Les valeurs suivantes constituent la palette de départ à confirmer par prélèvement exact lors de l’export final du logo.

| Jeton | Couleur | Usage |
|---|---|---|
| `brand-navy` | `#062C70` | marque, navigation principale, textes forts |
| `brand-teal` | `#00A88A` | validation, actions positives, coche |
| `brand-gold` | `#F4B723` | accent ponctuel uniquement |
| `brand-white` | `#FFFFFF` | fond du livre et surfaces sur fond sombre |
| `ink` | `#10213D` | contenu texte principal |
| `surface` | `#F7F9FC` | arrière-plan doux de l’application |

Le bleu nuit et le turquoise doivent rester les seules couleurs de marque dominantes. L’or ne doit jamais être utilisé pour du texte courant, une erreur ou une action critique.

## Déclinaisons à exporter

Lors de l’intégration, exporter et versionner les fichiers suivants dans `public/brand/` :

| Fichier | Rôle |
|---|---|
| `logo-diaba-gescom-horizontal.svg` | logo principal ; format de référence privilégié |
| `logo-diaba-gescom-horizontal.png` | solution de secours, fond transparent, haute définition |
| `diaba-icon.svg` | symbole seul, fond transparent |
| `favicon.ico` | favicon multi-tailles 16/32/48 px |
| `icon-192.png` | icône PWA Android/chrome |
| `icon-512.png` | icône PWA haute définition |
| `apple-touch-icon.png` | icône Apple, 180 × 180 px |
| `og-diaba-gescom.png` | visuel de partage social, 1200 × 630 px |

Le SVG doit être redessiné ou vectorisé proprement à partir du visuel validé avant son usage final. Ne pas convertir une petite image matricielle en SVG automatique de mauvaise qualité.

## Règles d’utilisation

- Préserver l’intégralité du symbole et du mot-symbole ; ne jamais les déformer, recolorer arbitrairement ou ajouter d’ombre.
- Laisser autour du logo un espace minimal égal à la hauteur de la coche turquoise.
- Sur un fond clair, utiliser le logo standard. Sur fond bleu nuit, utiliser une variante blanche/turquoise validée, sans perdre la lisibilité du livre.
- Ne pas réduire le logo horizontal sous une largeur à laquelle `DIABA GESCOM` devient difficile à lire ; utiliser alors l’icône seule avec un libellé texte à côté si besoin.
- Dans l’application, le logo doit renvoyer à l’accueil uniquement lorsque cela n’interrompt pas une saisie en cours ; avertir si un brouillon non sauvegardé serait perdu.
- Toute image de logo possède un texte alternatif : `DIABA GESCOM` ; une icône décorative voisine utilise `alt=""`.

## Intégration interface

| Emplacement | Variante |
|---|---|
| Écran de connexion | logo horizontal, largeur cible 220–260 px |
| Navigation desktop | icône + nom, hauteur maximale 32 px |
| Barre mobile | icône seule, 28–32 px ; nom texte de l’app dans l’en-tête si nécessaire |
| Favicon / onglet navigateur | icône seule |
| PWA installée | icône seule, fond bleu nuit si le système exige un fond opaque |
| Factures et PDF | logo horizontal, largeur maximale 150 px, résolution d’impression suffisante |
| E-mails transactionnels | logo horizontal en image avec alternative textuelle |

## Variables CSS de départ

```css
:root {
  --color-brand-navy: #062C70;
  --color-brand-teal: #00A88A;
  --color-brand-gold: #F4B723;
  --color-brand-white: #FFFFFF;
  --color-ink: #10213D;
  --color-surface: #F7F9FC;
}
```

La palette doit être complétée par des couleurs d’état accessibles (`success`, `warning`, `danger`, `info`) testées pour le contraste. Une couleur de marque ne doit pas automatiquement devenir une couleur d’erreur ou d’avertissement.

## Critères d’acceptation

- [ ] Le logo et l’icône sont visibles sans pixellisation sur écran haute définition et documents PDF.
- [ ] Le favicon est identifiable à 16 × 16 px.
- [ ] Les icônes PWA existent aux tailles requises et respectent une zone de sécurité visuelle.
- [ ] Le logo possède une alternative textuelle utile et ne dégrade pas l’accessibilité.
- [ ] Le contraste du logo et du texte environnant est validé sur fond clair et sombre.
- [ ] Les fichiers de marque sont versionnés dans le dépôt ; aucune image n’est chargée depuis un répertoire temporaire ou l’historique de conversation.
