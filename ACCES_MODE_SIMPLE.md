# Accès au mode simple — Guide complet

## ✅ 3 façons d'accéder au mode simple

### 1️⃣ **URL directe (la plus rapide)**

Accès direct à l'accueil du mode simple :
```
https://diaba.cloud/simple
```

Ou sur dev :
```
https://dev.diaba.cloud/simple
```

**Écrans du mode simple** :
- `/simple` — Accueil
- `/simple/vendre` — Vendre
- `/simple/caisse` — Caisse
- `/simple/ventes` — Mes ventes
- `/simple/clients` — Clients & crédit
- `/simple/stock` — Stock
- `/simple/articles/nouveau` — Ajouter un article
- `/simple/achats` — Achats & réception
- `/simple/resultats` — Mes résultats
- `/simple/guide` — Premiers pas
- `/simple/voix` — Messages audio

---

### 2️⃣ **Via le menu (si tu es connecté)**

**Pour un vendeur** :
- ✅ Arrive **directement en mode simple** (Accueil mode simple)
- ✅ Menu gauche : tuiles Vendre, Caisse, Stock, Clients, etc.
- ✅ Barre bas (mobile) : 5 onglets (Accueil, Ventes, Vendre, Stock, Plus)

**Pour un administrateur** :
- ✅ Arrive en **mode expert** par défaut (Tableau de bord standard)
- ✅ Cherche le bouton « Mode simple » ou « Bascule » dans :
  - Menu Paramètres › Affichage
  - Ou bouton dédié dans l'en-tête
- ✅ Clique : bascule vers mode simple
- ✅ Pour revenir au mode expert : même bouton

---

### 3️⃣ **Via la bascule simple/expert (dans l'app)**

Une fois connecté en mode simple :
- Cherche le bouton « Basculer vers mode expert » (ou icône ⚙️)
- Clique : change le mode
- **La session est conservée** (tu restes connecté)

---

## 🔍 Où voir le bouton de bascule ?

### Dans le code (SimpleModeController)
```php
// Route POST pour basculer
POST /mode
  mode: 'simple' | 'expert'
```

### Dans l'interface (à chercher)
- **En-tête** : bouton ou menu déroulant
- **Paramètres** : section "Affichage" ou "Mode de travail"
- **Menu utilisateur** : profil/compte

---

## 💡 Rôles et modes par défaut

| Rôle | Mode par défaut | Peut basculer ? |
|-----|---|---|
| **Vendeur** | Mode simple | ✅ Oui (vers expert si besoin) |
| **Gestionnaire stock** | Mode simple | ✅ Oui |
| **Administrateur** | Mode expert | ✅ Oui (vers simple) |
| **Comptable** | Mode expert | ❌ Non (pas accès mode simple) |

---

## 📱 Sur mobile (360 px)

- Même interface que desktop
- Barre fixe **en bas** avec 5 onglets :
  1. 🏠 Accueil
  2. 📊 Mes ventes
  3. 🛒 Vendre (bouton central surélevé)
  4. 📦 Stock
  5. ➕ Plus (menu caché)
- Pas de défilement horizontal
- Tous les boutons ≥ 48 px

---

## 🔐 Pré-requis pour accéder au mode simple

1. ✅ **Être connecté** (`/login` ou `/register`)
2. ✅ **Avoir une entreprise** (créée lors de l'onboarding)
3. ✅ **Avoir le rôle "Vendeur" ou "Gestionnaire stock" ou "Administrateur"**
   - Comptables n'ont pas accès
4. ✅ **`DIABA_SIMPLE_MODE=true`** activé sur le serveur
   - Sinon : pages `/simple` retournent 404

---

## ✅ Vérification : est-ce que c'est activé ?

### Test 1 : URL directe
```bash
curl https://dev.diaba.cloud/simple -I
# Si activé → 200 OK
# Si désactivé → 404 Not Found
```

### Test 2 : Logs config
```bash
# Sur le serveur
grep DIABA_SIMPLE_MODE /opt/diaba/dev/.env
# Output: DIABA_SIMPLE_MODE=true ✓
```

### Test 3 : Depuis l'app
- Connecte-toi
- Cherche bouton "Mode simple" ou accès `/simple`
- S'il y a un bouton → activé ✓
- S'il n'y a rien → désactivé ✗

---

## 🚀 Cas d'usage

### Je suis un vendeur
```
1. Vais sur https://diaba.cloud
2. Connecte-moi (login)
3. J'arrive automatiquement en mode simple (Accueil)
4. Vois les tuiles : Vendre, Caisse, Stock, Clients, etc.
5. Clique Vendre → écran de vente en images
```

### Je suis un administrateur
```
1. Vais sur https://diaba.cloud
2. Connecte-moi (login)
3. J'arrive en mode expert (Tableau de bord standard)
4. Cherche le bouton "Mode simple" ou "Bascule"
5. Clique → bascule vers mode simple
6. Vois la même interface que le vendeur
7. Clique le même bouton pour revenir en mode expert
```

### Je teste en tant que commerçant
```
1. Crée un compte (https://diaba.cloud/register)
2. Remplis la fiche entreprise (Premiers pas)
3. Crée un produit
4. Bascule vers mode simple (si admin)
5. Vends un article (Vendre → panier → Encaisser)
6. Vois la vente dans Mes ventes
7. Ouvre la caisse (Caisse → Ouvrir → compte les billets)
8. Ferme la caisse
9. Crée des clients, ajoute du stock, etc.
```

---

## 📋 Checklist

- [ ] `DIABA_SIMPLE_MODE=true` sur le serveur
- [ ] Routes `/simple/*` retournent 200 (pas 404)
- [ ] Vendeur arrive en mode simple (Accueil)
- [ ] Admin peut basculer simple ↔ expert
- [ ] Bouton/lien de bascule visible
- [ ] Tous les écrans accessibles
- [ ] Mode simple = interface en images
- [ ] Mode expert = interface standard

---

## 🔗 URLs clés

| Page | URL | Notes |
|-----|-----|-------|
| **Accueil simple** | `/simple` | Landing mode simple |
| **Vendre** | `/simple/vendre` | Panier + paiement |
| **Caisse** | `/simple/caisse` | Ouverture, comptage, fermeture |
| **Mes ventes** | `/simple/ventes` | Jour choisi + détails |
| **Clients** | `/simple/clients` | Dettes, fiche, paiements |
| **Stock** | `/simple/stock` | Jauge 3 états, + et − |
| **Achats** | `/simple/achats` | Commandes, réception |
| **Articles** | `/simple/articles/nouveau` | Création avec photo + voix |
| **Résultats** | `/simple/resultats` | Mois, semaine, année |
| **Guide** | `/simple/guide` | 11 étapes (audio à étape 2) |
| **Voix** | `/simple/voix` | Enregistrement messages |

---

## Cas d'erreur courants

| Erreur | Cause | Solution |
|--------|-------|----------|
| 404 sur `/simple` | `DIABA_SIMPLE_MODE=false` | Activer le flag en `.env` |
| Pas de bouton bascule | Admin en mode expert, pas de toggle visible | Chercher dans Settings › Affichage |
| Arrive en mode expert | Admin, mode par défaut | Cherche bouton "Mode simple" |
| Comptable voit `/simple` 404 | Comptables n'ont pas accès | Assigner un autre rôle ou créer un utilisateur vendeur |

---

## 🎯 Summary

**Pour accéder au mode simple :**
1. **URL directe** : `https://diaba.cloud/simple`
2. **Menu connecté** : cherche "Mode simple" ou bouton bascule
3. **Par défaut** : vendeurs arrivent en simple, admins peuvent basculer

**Prérequis** :
- ✅ Connecté
- ✅ Entreprise créée
- ✅ Rôle vendeur/stock/admin
- ✅ `DIABA_SIMPLE_MODE=true` sur le serveur
