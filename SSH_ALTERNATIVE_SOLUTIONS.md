# 🔑 Impossible d'accéder au serveur SSH — Solutions alternatives

## Problème
```
ssh root@diaba.cloud
→ Permission denied (publickey)
```

**Cause** : Les clés SSH ne sont pas configurées ou pas autorisées sur le serveur.

---

## Solutions alternatives

### ✅ Option 1 : Via GitHub Actions (workflow_dispatch) — RECOMMANDÉE

**Avantage** : Pas besoin d'accès SSH, utilise les secrets GitHub déjà configurés.

```bash
# Depuis ta machine locale
cd C:\Users\ADMIN\Documents\dev\diabagescom

# 1. Ajoute la variable à .env.example
echo "DIABA_SIMPLE_MODE=true" >> .env.example

# 2. Committe
git add .env.example
git commit -m "chore: enable simple mode for production"
git push origin main

# 3. Le workflow GitHub Actions se déclenche automatiquement
# - Tests passent
# - Images Docker buildées
# - Déploiement auto sur dev.diaba.cloud

# 4. Pour production, trigger manuellement
gh workflow run deploy.yml -F environment=production

# Accès au workflow : https://github.com/layesamake/diabagescom/actions
```

---

### ✅ Option 2 : Via Hostinger Control Panel

**Si le serveur est sur Hostinger** :

1. Accès : https://hstgr.cloud (Hostinger client area)
2. Sélectionne le VPS : `srv1824837.hstgr.cloud` (diaba.cloud)
3. Onglet : **File Manager** ou **Terminal**
4. Navigue vers : `/opt/diaba/prod/` (ou `/root/diaba/prod/`)
5. Édite `.env` :
   ```
   DIABA_SIMPLE_MODE=true
   ```
6. Sauvegarde
7. Terminal : `docker compose restart app`
8. Teste : `curl https://diaba.cloud/simple -I`

---

### ✅ Option 3 : Contacte Étienne directement

**Message** :
> « Le serveur production ne répond pas en SSH. Peux-tu activer DIABA_SIMPLE_MODE=true dans /opt/diaba/prod/.env et redémarrer l'app ? »

---

### ❌ Option 4 : Configurer la clé SSH (long terme)

```bash
# Génère une nouvelle clé si besoin
ssh-keygen -t ed25519 -f ~/.ssh/diaba_cloud_ed25519 -N ""

# Récupère la clé publique
cat ~/.ssh/diaba_cloud_ed25519.pub

# Ajoute-la au serveur (via Hostinger panel ou Étienne)
# Puis teste :
ssh -i ~/.ssh/diaba_cloud_ed25519 root@diaba.cloud
```

---

## Prochaines étapes

### **La plus rapide** : GitHub Actions

```bash
# 1. Change le fichier
sed -i 's/DIABA_SIMPLE_MODE=false/DIABA_SIMPLE_MODE=true/' .env.example

# 2. Committe
git add .env.example
git commit -m "feat: enable simple mode on production"
git push origin main

# 3. Le CI/CD se déclenche automatiquement
# Attends 5-10 min → dev.diaba.cloud est à jour

# 4. Pour production, déclenche manuellement
# Accès : https://github.com/layesamake/diabagescom/actions/workflows/deploy.yml
# Clique "Run workflow" → environment = production → Run
```

---

## Vérification du statut

```bash
# Tester dev (auto déployé par CI/CD)
curl https://dev.diaba.cloud/simple -I
# Doit retourner 200 OK

# Tester production (après activation manuelle)
curl https://diaba.cloud/simple -I
# Retourne 404 tant que DIABA_SIMPLE_MODE=false
```

---

## Résumé

| Méthode | Temps | Complexité | Recommandé |
|---------|-------|-----------|-----------|
| GitHub Actions (workflow) | 10 min | Facile | ✅ **OUI** |
| Hostinger Panel | 5 min | Moyen | ✅ **OUI** |
| SSH (clé config) | 30 min | Complexe | ❌ Non |
| Contact Étienne | ? | Facile | ⚠️ En attente |

**Je recommande : GitHub Actions ou Hostinger Panel.**

---

## Commande rapide (GitHub Actions)

```bash
cd C:\Users\ADMIN\Documents\dev\diabagescom

# Change le fichier
echo "DIABA_SIMPLE_MODE=true" >> .env.example

# Committe et pousse
git add .env.example && \
git commit -m "feat: enable simple mode" && \
git push origin main

# Attends le déploiement auto (5-10 min)
# Puis teste
curl https://dev.diaba.cloud/simple -I
# → 200 OK
```

---

**Statut** : 🟡 En attente d'activation manuelle sur production (Étienne ou toi via Hostinger)

**Action** : Choisis une méthode ci-dessus et procède.
