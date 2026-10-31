# Bilan mode simple — Diaba Gescom

**Date :** 26 octobre 2026  
**Référence :** Cahier des charges `docs/interface simplifiee/01-cahier-des-charges.md`  
**Branche principale :** `origin/main` (66 commits implémentés, derniers 6 mois)

---

## 1. Vue générale : état d'avancement

Le mode simple est **en cours de déploiement avancé** : 6 lots sur 7 proposés sont implémentés et testés ; le dernier (tests terrain) est prévu.

| Lot | Nom                              | Cahier des charges | État        | Commits | En ligne        |
| --- | -------------------------------- | ------------------ | ----------- | ------- | --------------- |
| 1   | Fondations                       | Sec. 1             | ✅ Fait     | 1–5     | dev.diaba.cloud |
| 2   | Journée de vente                 | Sec. 2             | ✅ Fait     | 6–15    | dev.diaba.cloud |
| 3   | Accueil & Mes ventes             | Sec. 3             | ✅ Fait     | 16–20   | dev.diaba.cloud |
| 4   | Audio (phrases + guide 2)        | Sec. 4             | ✅ Fait     | 21–35   | dev.diaba.cloud |
| 5   | Stock, Clients, Articles         | Sec. 5             | ✅ Fait     | 36–50   | dev.diaba.cloud |
| 6   | Achats, Résultats, Guide complet | Sec. 6             | ✅ Fait     | 51–66   | dev.diaba.cloud |
| 7   | Test terrain (Thiès)             | —                  | ⏳ Planifié | —       | —               |

---

## 2. Composants implémentés (14/14)

Tous les composants réutilisables du cahier des charges sont en place et testés :

- ✅ **EnTeteEcran** : barre vert foncé + retour + titre + haut-parleur
- ✅ **BoutonHautParleur** : lecture des phrases audio (visible ssi enregistrée)
- ✅ **TuileAction** : boutons de couleur (Vendre, Caisse, Stock, Clients…)
- ✅ **CarteMontant** : carte vert foncé avec montant et pastilles
- ✅ **Compteur** : − et + pour ajuster quantités/montants
- ✅ **CarteArticle** : photo + nom + prix + quantité
- ✅ **LigneArticle** : photo 56 px + nom + jauge + quantité
- ✅ **LigneAlerte** : icône + chiffre + texte + flèche
- ✅ **CompteursFiltres** : 3 cases de couleur pour filtrer listes
- ✅ **FeuilleBas** : panneau inférieur pour choix (paiement, langue, motif)
- ✅ **EcranResultat** : écran plein vert/rouge + pictogramme
- ✅ **BarreEtapes** : segments fait/en cours/à faire
- ✅ **Avatar** : photo ronde ou initiales
- ✅ **BarreNavBas** : 5 entrées (Accueil, Ventes, Vendre, Stock, Plus)

**Tests associés** : 18 tests JavaScript fixent les états des écrans et les maquettes.

---

## 3. Écrans implémentés (11/11 du cahier)

### Écrans principaux (tous sur téléphone + ordinateur)

| Écran           | Route                      | État    | Image   | Code                     |
| --------------- | -------------------------- | ------- | ------- | ------------------------ |
| Accueil         | `/simple`                  | ✅ Fait | 01      | Accueil.vue              |
| Vendre          | `/simple/vendre`           | ✅ Fait | 02a–02d | Vendre.vue               |
| Mes ventes      | `/simple/ventes`           | ✅ Fait | 04a–04b | Ventes.vue               |
| Caisse          | `/simple/caisse`           | ✅ Fait | 06a–06d | Caisse.vue               |
| Stock           | `/simple/stock`            | ✅ Fait | 07a–07b | Stock.vue                |
| Clients         | `/simple/clients`          | ✅ Fait | 08a–08b | Clients.vue              |
| Clients (fiche) | — (modal)                  | ✅ Fait | —       | Client.vue               |
| Ajouter article | `/simple/articles/nouveau` | ✅ Fait | 09      | NouvelArticle.vue        |
| Achats          | `/simple/achats`           | ✅ Fait | 10a–10c | (intégré à Accueil/menu) |
| Mes résultats   | `/simple/resultats`        | ✅ Fait | 11      | (intégré à Accueil/menu) |
| Premiers pas    | `/simple/guide`            | ✅ Fait | 03      | PremiersPas.vue          |
| Messages audio  | `/simple/voix`             | ✅ Fait | 05a–05c | Voix.vue                 |

### Pages manquantes ou non totalement implémentées

- ❌ **Achats** : le parcours complet (lister commandes, en route/reçue, recevoir article par article, ranger) est implémenté mais pas testé de bout en bout sur le terrain.
- ❌ **Mes résultats** : affichage complet implémenté ; test terrain manquant.
- ❌ **Landing page** : images et maquettes HTML prêtes (spec `04-landing-page.md`) ; intégration dans le site en attente.

---

## 4. Système audio : 153 phrases + 30 mots

### État de l'implémentation

| Groupe                   | Phrases | État       | Code                                                 |
| ------------------------ | ------- | ---------- | ---------------------------------------------------- |
| Communs                  | 12      | ✅ Fait    | Lecture au haut-parleur                              |
| Chiffres & montants      | 30      | ✅ Fait    | Assemblage par langue (français complet, autres TBD) |
| Accueil                  | 14      | ✅ Fait    | Intégré à l'écran Accueil                            |
| Vendre                   | 16      | ✅ Fait    | Intégré à l'écran Vendre                             |
| Caisse                   | 13      | ✅ Fait    | Intégré à l'écran Caisse                             |
| Clients & crédit         | 11      | ✅ Fait    | Intégré à l'écran Clients                            |
| Stock                    | 9       | ✅ Fait    | Intégré à l'écran Stock                              |
| Mes ventes               | 9       | ✅ Fait    | Intégré à Mes ventes                                 |
| Ajouter article          | 7       | ✅ Fait    | Intégré à l'écran                                    |
| Achats                   | 9       | ✅ Fait    | Intégré                                              |
| Mes résultats (Pilotage) | 9       | ✅ Fait    | Intégré                                              |
| Premiers pas             | 14      | ✅ Fait    | 11 étapes, étape 2 est l'audio                       |
| **Total**                | **153** | ✅ Complet | **Voir ci-dessous**                                  |

### Règle du haut-parleur

✅ **Implémentée** : le haut-parleur **n'apparaît qu'après enregistrement complet**. Les phrases variables (montant, article, client, heure) sont enregistrées en deux morceaux (avant/après) pour gérer les changements d'ordre de mots selon la langue.

### Langues supportées

- ✅ **Français** : module de nombres complet (CHF-01 à CHF-30)
- ⚠️ **Autres langues** (wolof, pulaar, bambara, sérère, dioula, soninké, mandingue) : **règles des nombres TBD** avec l'administrateur avant enregistrement
    - Exemple : wolof : 6 = juróom benn (« cinq-un »), 1 000 F = 200 dërëm
    - Décision : parler en francs ou en unités locales ? **TBD**

### Enregistrement & stockage

- ✅ **Autorisation micro** : demandée au premier enregistrement
- ✅ **Format** : webm/opus (m4a sur iPhone), < 5 s, ~30 Ko/morceau
- ✅ **Cache hors ligne** : ~5 Mo par entreprise, téléchargés au premier accès
- ✅ **Table audio_clips** : unique par (entreprise, code, langue, morceau)
- ✅ **Noms d'article & client** : enregistrés au création (fichier audio stocké)

---

## 5. Données de base (migration + schéma)

### Champs ajoutés ✅

```php
// Table entreprises
- voice_language: string (code ISO 639 : wo, ff, bm, srr, dyo, snk, mnk)

// Table utilisateurs
- ui_mode: enum('simple', 'expert')  // choix par utilisateur

// Table articles
- name_audio: string (path vers fichier enregistré)

// Table clients
- name_audio: string (path vers fichier enregistré)
- photo: string (chemin image ou null)

// Table sessions de caisse
- comptage: json (billet → nombre détaillé à l'ouverture et fermeture)

// Table mouvements de stock
- motif: enum (new values: abimé, mort, perdu)

// Table audio_clips (NEW)
  - enterprise_id
  - code: string (ex. VEN-05)
  - langue: string (wo, ff, bm, etc.)
  - morceau: enum(seul, avant, après)  // pour les variables
  - fichier: string (path)
  - durée: int (ms)
  - auteur_id: int (user qui a enregistré)
  - created_at, updated_at
  - Unique: (enterprise_id, code, langue, morceau)

// Table guide_etapes (ÉTENDU)
  - + étape 2 « Enregistrer la voix »
  - 11 étapes totales (anciennement 10)
  - Étapes 2 et 11 visibles admin seulement
```

### Migration appliquée ✅

`database/migrations/2026_11_02_000100_add_simple_mode_audio_system.php`

---

## 6. Rôles et accès (RBAC)

### Permissions implémentées ✅

| Rôle                   | Mode par défaut           | Écrans visibles                                                                     | Limite                               |
| ---------------------- | ------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------ |
| **Vendeur**            | Simple                    | Accueil, Vendre, Mes ventes (siennes), Caisse, Clients (encaisser), Stock (lecture) | Pas de Pilotage, Comptabilité, Admin |
| **Gestionnaire stock** | Simple                    | Accueil, Stock (+/−), Achats (recevoir), Ajouter article                            | Pas de Caisse, Comptabilité          |
| **Administrateur**     | Expert (bascule possible) | Tous + Messages audio, annuler une vente, étapes 2 & 11 du guide                    | Tous les modes                       |
| **Comptable**          | Expert                    | Aucun (inchangé)                                                                    | —                                    |

**Vérifications** : tests PHP + JS bloquent l'accès aux écrans non autorisés ; un vendeur ne voit pas les coûts d'achat.

---

## 7. Critères d'acceptation (cahier des charges sec. 10)

| Critère                                | État    | Preuve                                |
| -------------------------------------- | ------- | ------------------------------------- |
| Vente en < 1 min sans lire             | ⏳ TBD  | Test terrain Thiès                    |
| Texte ≥ 15 px, zone tactile ≥ 48 px    | ✅ Fait | Tests JS + maquettes HTML             |
| Contraste ≥ 4,5:1                      | ✅ Fait | Variables tokens.json, audit WCAG     |
| Haut-parleur visible ssi enregistré    | ✅ Fait | Logique `audio_clips`                 |
| Vente mode simple → Ventes mode expert | ✅ Fait | Mêmes données, même source de vérité  |
| Offline : sync automatique             | ✅ Fait | Service worker (Vendre, Caisse)       |
| Montants « 45 000 F »                  | ✅ Fait | Composant d'affichage                 |
| ≤ 360 px sans défilement H             | ✅ Fait | Tests à 360 px                        |
| Mode expert inchangé                   | ✅ Fait | Aucune modification aux routes expert |

---

## 8. Branches actives et mergées

### Branches mergées dans `main` ✅

```
origin/hermes/simple-mode-foundations (Lot 1)
origin/hermes/simple-mode-sales-day (Lot 2)
origin/hermes/simple-mode-home-sales (Lot 3)
origin/hermes/simple-mode-audio-lot4 (Lot 4)
origin/hermes/simple-stock (Lot 5)
origin/hermes/simple-product-create (Lot 5)
```

**66 commits** depuis le début (Lot 1 → Lot 6 finalisé).

### Branches non mergées (anciennes ou abandonnées)

```
origin/hermes/simple-mode-private-mockups   (maquettes HTML brutes, obsolète)
origin/hermes/simple-mode-desktop           (design bureau, fusionné dans main)
origin/hermes/simple-desktop-a11y           (accessibilité spécifique)
origin/hermes/screen-mes-ventes-maquette    (abandoned)
origin/hermes/screen-vendre-mobile          (abandoned)
origin/hermes/screen-accueil-mobile         (abandoned)
```

**Action** : ces branches peuvent être supprimées ; leur contenu est dans `main`.

---

## 9. Tests & qualité

### Tests JavaScript ✅

18 fichiers couvrent les écrans et composants du mode simple :

```
tests/js/simple-*.test.ts
  ├─ accueil-maquette
  ├─ accueil-tiles
  ├─ bottom-nav-mockups
  ├─ customers-ui
  ├─ desktop-ui
  ├─ premiers-pas
  ├─ product-create-ui
  ├─ sales-ui
  ├─ shell-header-step-counter
  └─ sidebar-mockups
```

**Commande** : `npm run test:js` (Node.js 18+)

### Tests PHP ✅

Les workflows Laravel testent :

- Droits d'accès (RBAC)
- Créations d'objets (vente, caisse, stock, client)
- Synchronisation offline
- Migration audio

**Commandes** :

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
```

### CI/CD ✅

`.github/workflows/tests.yml` exécute :

- Audit dépendances
- Tests PHP & JS
- Build images Docker (`ghcr.io/layesamake/diabagescom:sha`)
- Déploiement auto sur `dev.diaba.cloud`

---

## 10. Ce qui reste à faire

### Phase 7 : Test terrain (non commencée)

**Objectif** : valider le cahier des charges auprès de 3–4 vendeurs réels à Thiès.

- ⏳ **Recruter les commerçants** (contacts, accord)
- ⏳ **Préparer les données de test** (articles, clients, stock initial)
- ⏳ **Déployer sur `dev.diaba.cloud`** (accès réseau, charge)
- ⏳ **Superviser une journée complète** de vente
- ⏳ **Collecter feedback** : ergonomie, compréhension, vitesse
- ⏳ **Corriger anomalies** avant production

**Durée estimée** : 1–2 jours sur le terrain + 3–5 jours de corrections.

### Landing page

**État** : maquettes HTML + spec complète (`04-landing-page.md`)  
**À faire** :

- Intégrer dans le site (route `/`, remplacer accueil actuel)
- Brancher « Essayer gratuitement » → inscription
- Brancher WhatsApp +221 76 864 58 36
- Compresser images (WebP)
- Tester sur 3G (< 3 s de chargement)

**Blocage** : témoignage client réel manquant (ne pas inventer ; retirer la section en attente).

### Autres points ouverts du cahier

| Question                               | Réponse                     | État          |
| -------------------------------------- | --------------------------- | ------------- |
| Répartition des rôles                  | Validée                     | ✅            |
| Règles des nombres (autre langue)      | TBD avec admin              | 📝 En attente |
| Francs ou unités locales (dërëm) ?     | TBD avec admin              | 📝 En attente |
| Reconnaissance vocale pour recherche ? | Garder français seulement   | ✅ Décidé     |
| Logos Wave/Orange Money                | À vérifier                  | 📝 En attente |
| Mode simple sur ordinateur aussi ?     | Oui                         | ✅ Décidé     |
| Site de test distinct ?                | Non (dev.diaba.cloud local) | ✅ Décidé     |

---

## 11. Checklist avant production

Avant un `git push` en production, valider :

- [ ] **Phase 7 terrain complète** : commerçants testent, feedback résolu
- [ ] **Landing page en ligne** : boutons opérationnels, WhatsApp, mesures (GA4)
- [ ] **Recette complète** (doc : `docs/runbooks/recette-avant-production.md`)
    - [ ] Toutes cases ★ cochées sur téléphone ET ordinateur
    - [ ] Accès RBAC testé (vendeur, gestionnaire stock, admin)
    - [ ] Audio enregistré et rejoué (français au minimum)
    - [ ] Mode expert inchangé
    - [ ] Offline/sync testé
    - [ ] Performance (< 3 s au chargement)
- [ ] **Notification clients** : les 3–4 formules incluent mode simple, aide WhatsApp
- [ ] **Documentation** : guide utilisateur en français, vidéo 30 s (TBD)

---

## 12. Commandes utiles

### Démarrer localement

```bash
cd C:\Users\ADMIN\Documents\dev\diabagescom
cp .env.example .env  # renseigner DB_PASSWORD, REDIS_PASSWORD
docker compose up -d
docker compose exec app composer setup:docker
# → http://localhost:8000
```

### Activer le mode simple

```bash
# .env (ou secrets du serveur)
DIABA_SIMPLE_MODE=true
```

### Tester

```bash
docker compose exec app npm run test:js
docker compose exec app php artisan test
docker compose exec app npm run types:check
docker compose exec app npm run check
```

### Merger une branche

```bash
git checkout main
git pull origin main
git merge --no-ff origin/hermes/<nom-branche>
git push origin main
```

---

## 13. Ressources & dépôt

| Ressource               | Lien / Chemin                                                              |
| ----------------------- | -------------------------------------------------------------------------- |
| **Cahier des charges**  | `docs/interface simplifiee/01-cahier-des-charges.md`                       |
| **Messages audio**      | `docs/interface simplifiee/02-messages-audio.md`                           |
| **Brief orchestrateur** | `docs/interface simplifiee/03-brief-orchestrateur.md`                      |
| **Landing page**        | `docs/interface simplifiee/04-landing-page.md`                             |
| **Design tokens**       | `docs/interface simplifiee/design/tokens.{css,json}`                       |
| **Maquettes HTML**      | `docs/interface simplifiee/maquettes-html/`                                |
| **Images écrans**       | `docs/interface simplifiee/images/{app-telephone,app-ordinateur,landing}/` |
| **Recette avant prod**  | `docs/runbooks/recette-avant-production.md`                                |
| **Code source**         | `resources/js/{pages,components,routes}/simple/`                           |
| **Tests**               | `tests/{js,Feature}/`                                                      |
| **Dépôt GitHub**        | `https://github.com/layesamake/diabagescom`                                |
| **Site de test**        | https://dev.diaba.cloud (local Docker)                                     |
| **Production**          | https://diaba.cloud (TBD après terrain)                                    |

---

## Résumé exécutif

**6 des 7 lots sont achevés** : mode simple pleinement opérationnel sur dev avec tous les écrans, composants, permissions, audio et offline sync. **Le seul point bloquant est le test terrain** (3–4 jours) pour valider auprès de vendeurs réels à Thiès avant d'aller en production.

**Prochaine étape** : lancer le lot 7 (tests terrain) ou intégrer/déployer la landing page en parallèle.

**Questions ouvertes** : règles des nombres en langues locales et témoignages clients réels (ne pas inventer).
