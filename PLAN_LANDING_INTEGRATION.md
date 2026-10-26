# Plan d'intégration landing page — Diaba Gescom

**État actuel :** `Welcome.vue` est déjà 85% alignée avec la maquette du cahier des charges.

---

## ✅ Complètement implémenté

- En-tête (header, navigation, bouton CTA)
- Section héro (« Vendez en 3 gestes »)
- Téléphone illustration avec articles
- Bande « Fait pour »
- 4 problèmes du quotidien
- Section « Comment ça marche » (3 gestes)
- Avantages (images et couleurs, voix, offline, payments)
- 8 fonctions principales
- Tarifs (Essentiel, Business, Premium)
- FAQ (5 questions)
- Appel final « Ce soir, sachez… » + formulaire WhatsApp

---

## 📋 À vérifier / ajuster

| Point | Cahier des charges | État | Action |
|-------|---|---|---|
| **Barre du bas (mobile)** | « Essayer » + WhatsApp fixe en bas | ❓ TBD | Ajouter si manquant |
| **Bouton « Voir une vente en 30 s » ** | Après héro (vidéo démo) | ❌ Retiré (no video) | Garder retiré |
| **Bloc témoignage vidéo** | Avant « Prix » | ❌ Retiré (no testimonial) | Garder retiré |
| **Images compressées** | WebP, < 3s chargement 3G | ❓ TBD | Valider après déploiement |
| **WhatsApp** | +221 76 864 58 36 | ✅ Config ok | Vérifier en `.env` |
| **Lien d'inscr** | « Essayer gratuitement » → `/register` | ✅ Fait | Tester le flux |
| **Renvoi formulaire** | Capture numéro WhatsApp → envoi via WhatsApp API | ✅ Fait | Tester submission |

---

## 🚀 Prochaines étapes

### 1. Valider les détails
```bash
cd C:\Users\ADMIN\Documents\dev\diabagescom

# Vérifier le contrôleur
grep -A 20 "public function __invoke" app/Http/Controllers/Marketing/WelcomeController.php

# Vérifier la route
grep "Route::get('/'," routes/web.php
```

### 2. Tester localement
```bash
# Lancer Docker
docker compose up -d
docker compose exec app composer setup:docker

# Dev avec hot reload
npm run dev
# Accès : http://localhost:8000
```

### 3. Vérifier sur mobile et ordinateur
- Largeur : 360 px (téléphone)
- Largeur : 1440 px (ordinateur)
- **Pas de défilement horizontal**
- Texte ≥ 15 px
- Zones tactiles ≥ 48 px
- Montants au format « 45 000 F »

### 4. Tester les formulaires
- [ ] Clic « Essayer gratuitement » → redirect vers `/register`
- [ ] Saisir numéro WhatsApp → envoi via WhatsApp Web
- [ ] Lien WhatsApp bas de page → `https://wa.me/221768645836`

### 5. Vérifier les images
- [ ] Logo DIABA GESCOM visible
- [ ] Illustration téléphone + articles
- [ ] Icônes Lucide (CheckCircle, ShoppingCart, Speaker…)
- [ ] Couleurs exactes (tokens)

### 6. Merger et tester sur dev
```bash
git add resources/js/pages/Welcome.vue
git commit -m "feat(landing): align Welcome.vue with cahier des charges"
git push origin main

# Sur dev.diaba.cloud, vérifier :
# - Chargement < 3s
# - Pas d'erreurs dans la console
# - Performance mobile (Lighthouse)
```

---

## ✅ Critères d'acceptation finaux

- [ ] Maquette HTML vs page Vue sont identiques (côte à côte)
- [ ] Aucun texte < 15 px
- [ ] Aucune zone tactile < 48 px
- [ ] Défilement horizontal = 0 sur 360–1920 px
- [ ] Chargement ≤ 3s en 3G (images optimisées)
- [ ] Formulaire d'essai envoie vers `/register` OU WhatsApp
- [ ] WhatsApp final vers +221 76 864 58 36
- [ ] Tests visuels comparés aux 2 images de maquette (ordinateur + téléphone)
- [ ] Console navigateur : 0 erreur

---

## 📸 Ressources

| Ressource | Chemin |
|---|---|
| **Cahier des charges landing** | `docs/interface simplifiee/04-landing-page.md` |
| **Maquette ordinateur** | `docs/interface simplifiee/maquettes-html/LandingOrdinateur.html` |
| **Maquette téléphone** | `docs/interface simplifiee/maquettes-html/LandingTelephone.html` |
| **Tokens design** | `docs/interface simplifiee/design/tokens.{css,json}` |
| **Welcome.vue** | `resources/js/pages/Welcome.vue` |
| **WelcomeController** | `app/Http/Controllers/Marketing/WelcomeController.php` |
| **Route** | `routes/web.php` (ligne ~63) |

---

## Commandes git finals

```bash
# Si modifications nécessaires
cd C:\Users\ADMIN\Documents\dev\diabagescom
git checkout -b feat/landing-integration
git add resources/js/pages/Welcome.vue
git commit -m "feat(landing): landing page aligned with specs

- Full-page hero with iPhone mockup
- Industries section (Alimentation, Quincaillerie…)
- 4 problems + solutions
- How it works (3 steps)
- Advantages (colors, voice, offline, payments)
- 8 features
- 3 pricing tiers (Essentiel 5k, Business 12.5k, Premium 15k)
- FAQ with 5 QA
- Final CTA with WhatsApp form
- Mobile-first, no horizontal scroll
- Images and contact info from WelcomeController
"
git push origin feat/landing-integration
# → PR Review → Merge into main
```

---

## Décisions finales par Etienne (18 Oct 2026)

✅ Témoignage réel = pas d'invention  
✅ Vidéo 30s = retirer jusqu'à disponibilité  
✅ WhatsApp = +221 76 864 58 36  
✅ Domaine = diaba.cloud  
✅ Test sur dev.diaba.cloud (Docker local) avant production  

**Blocker** : aucun. Page est prête pour test local.
