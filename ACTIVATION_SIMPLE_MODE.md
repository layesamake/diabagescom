# Activation interface simplifiée — Plan d'action

**Question** : Pourquoi l'interface simplifiée n'est pas activée par défaut ?

**Réponse** : Par design — c'est un drapeau de contrôle (`DIABA_SIMPLE_MODE`) qui permet une validation progressive avant de mettre en production.

---

## Explication du design

### Configuration actuelle

```
.env.example (ligne 13) : DIABA_SIMPLE_MODE=false
config/diaba.php (ligne 111) : 'enabled' => (bool) env('DIABA_SIMPLE_MODE', false)
routes/web.php (ligne 121) : Routes /simple/* retournent 404 tant que le flag est false
```

### Objectif

Éviter qu'un lot en développement (ex. mode simple lot 3) n'apparaisse chez les clients en production avant validation complète.

**Workflow** :

1. ✅ Développer sur branche feature
2. ✅ Tester sur dev.diaba.cloud (`DIABA_SIMPLE_MODE=true`)
3. ✅ Valider auprès des commerçants (lot 7 : tests terrain Thiès)
4. ✅ Activer en production (`DIABA_SIMPLE_MODE=true`)

---

## Pour activer maintenant

### Environnement de développement (localhost)

```bash
# Edit .env
DIABA_SIMPLE_MODE=true

# Restart
docker compose down
docker compose up -d
npm run dev
# → http://localhost:8000/simple
```

### Environnement dev.diaba.cloud

```bash
# Via le gestionnaire de secrets (Étienne)
# Ajouter ou modifier : DIABA_SIMPLE_MODE=true
# Redéployer

# Ou via GitHub Actions (workflow_dispatch avec variable)
gh workflow run deploy.yml -F environment=dev -F DIABA_SIMPLE_MODE=true
```

### Environnement production (diaba.cloud)

```bash
# Attendre l'approbation d'Etienne après tests terrain
# Puis activer via le gestionnaire de secrets
DIABA_SIMPLE_MODE=true
# Redéployer avec approbation production
```

---

## Statut actuel

| Environnement       | DIABA_SIMPLE_MODE | État         | Routes /simple |
| ------------------- | ----------------- | ------------ | -------------- |
| **localhost**       | false             | ❌ Désactivé | 404            |
| **dev.diaba.cloud** | false             | ❌ Désactivé | 404            |
| **diaba.cloud**     | false             | ❌ Désactivé | 404            |

---

## Commandes pour activer

### Localement (Docker)

```bash
cd C:\Users\ADMIN\Documents\dev\diabagescom
echo "DIABA_SIMPLE_MODE=true" >> .env
docker compose restart app
curl http://localhost:8000/simple
# Devrait afficher l'accueil mode simple
```

### Sur dev.diaba.cloud

Contacte Étienne pour :

1. Ajouter `DIABA_SIMPLE_MODE=true` au gestionnaire de secrets
2. Redéployer

Ou pousse via git + attend la CI :

```bash
git add .env.example  # Change DIABA_SIMPLE_MODE=true
git commit -m "feat(env): enable simple mode on dev"
git push origin main
```

### Avant production

Attendre :

- [ ] Tests terrain complétés (lot 7)
- [ ] Feedback commerçants validé
- [ ] Approbation Étienne
- [ ] Alors : `DIABA_SIMPLE_MODE=true` en prod

---

## Vérification

Une fois activé, tester :

```bash
# Interface simple doit être accessible
curl https://dev.diaba.cloud/simple | grep -o "Accueil\|Vendre\|Caisse"

# User doit pouvoir basculer via menu Paramètres › Affichage
# Mode simple par défaut pour vendeur et admin
# Le comptable reste en mode expert (sans mode simple)
```

---

## Décision

**Recommandation** : Laisser `DIABA_SIMPLE_MODE=false` jusqu'à la fin des tests terrain (lot 7).

Ensuite, l'activer progressivement :

1. ✅ dev.diaba.cloud (tests UAT complets)
2. ✅ production (après approbation finale)

Ceci garantit une livraison sans risque de régression.

---

## Fichiers concernés

- `.env.example` (ligne 13)
- `config/diaba.php` (ligne 111)
- `routes/web.php` (ligne 121)
- `app/Http/Controllers/Simple/SimpleModeController.php` (vérification du flag)
- `app/Http/Middleware/HandleInertiaRequests.php` (injection du flag en frontend)
