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

## Favicon livré

Les déclinaisons raster du favicon comptable validé sont déjà présentes dans `public/brand/` :

| Fichier | Usage |
|---|---|
| `favicon.ico` | favicon navigateur multi-compatibilité |
| `favicon-32.png` | favicon PNG 32 × 32 px |
| `apple-touch-icon.png` | raccourci iOS, 180 × 180 px |
| `icon-192.png` | manifeste PWA, 192 × 192 px |
| `icon-512.png` | manifeste PWA, 512 × 512 px |
| `diaba-favicon-source.png` | source PNG haute définition à conserver |

Préférer `favicon.ico` et `favicon-32.png` dans le document HTML, et déclarer les icônes 192/512 dans le manifeste PWA. Le fichier SVG existant n’est pas remplacé sans validation explicite de sa conformité avec le logo officiel.

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

## Intégration réalisée (2026-10-10)

### Couleurs relevées sur les fichiers officiels

| Jeton | Valeur relevée | Source | Remarque |
|---|---|---|---|
| `brand-navy` | `#002456` | logo horizontal | remplace `#062C70` |
| `brand-teal` | `#018270` | logo horizontal (texte, lignes) | contraste ≈ 4,6:1 sur blanc : utilisable pour du texte (WCAG AA) |
| `brand-teal-light` | `#01A985` | favicon | décoratif ou sur fond sombre uniquement (≈ 3:1 sur blanc) |
| `brand-gold` | `#E8A304` | étoile du logo | remplace `#F4B723` |
| bleu nuit du favicon | `#032D6B` | favicon | conservé dans l'icône pour rester fidèle au fichier fourni |

Les variables CSS correspondantes sont définies dans `resources/css/app.css`.

### Deux symboles validés

- **Logo horizontal** : D plein, coche dans un cercle, étoile or. Utilisé sur l'écran de connexion, les documents et le visuel de partage.
- **Icône seule** (favicon fourni) : D évidé, livre à colonnes de grand livre, grande coche. Utilisée pour le favicon, la PWA, l'icône Apple et la barre latérale (avec le nom en texte).

### Fichiers

- `public/brand/` : fichiers servis (SVG redessinés, PNG, favicon, icônes PWA, visuel 1200 × 630) ; `public/favicon.ico` et `public/apple-touch-icon.png` en sont des copies.
- `resources/brand/source/` : originaux fournis et SVG source du logo (texte modifiable et texte vectorisé avec Montserrat ExtraBold, licence OFL).
- `resources/brand/build.sh` : régénère toutes les déclinaisons raster depuis les SVG (rsvg-convert + ImageMagick, exécuté dans un conteneur Alpine).

Les SVG sont redessinés à la main d'après les visuels fournis, sans vectorisation automatique.
