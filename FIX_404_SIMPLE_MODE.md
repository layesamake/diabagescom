# 🔴 Erreur 404 — Mode simple non activé en production

## Problème

```
GET https://diaba.cloud/simple
→ 404 Not Found
```

**Cause** : `DIABA_SIMPLE_MODE` n'est pas activé sur le serveur production.

---

## Pourquoi ?

1. ✅ On a changé `.env.example` : `DIABA_SIMPLE_MODE=true`
2. ❌ Mais `.env.example` est un **modèle pour développeurs locaux**
3. ❌ Le serveur production ignore `.env.example`
4. ❌ Le serveur production utilise les **secrets du gestionnaire de secrets**
5. ❌ Les secrets ne sont pas à jour

---

## Solution

### Pour que ça marche maintenant, il faut :

**Cas 1 : Accès SSH au serveur production**

```bash
# Sur le serveur diaba.cloud
ssh user@diaba.cloud

# Édite le fichier .env
nano /opt/diaba/prod/.env

# Change ou ajoute
DIABA_SIMPLE_MODE=true

# Sauvegarde (Ctrl+X, Y, Enter)

# Redémarre l'app
docker compose restart app

# Vérifie
curl https://diaba.cloud/simple -I
# → 200 OK
```

---

**Cas 2 : Via le gestionnaire de secrets (Kubernetes/Helm)**

```bash
# Si le serveur utilise des secrets Kubernetes
kubectl set env deployment/diaba-app \
  DIABA_SIMPLE_MODE=true

# Ou via Helm
helm upgrade diaba-release diaba-chart \
  --set env.DIABA_SIMPLE_MODE=true

# Redéploie
kubectl rollout restart deployment/diaba-app
```

---

**Cas 3 : Via GitHub Actions (workflow_dispatch)**

```bash
# Déclenche un redéploiement avec la variable
gh workflow run deploy.yml \
  -F environment=production \
  -f DIABA_SIMPLE_MODE=true
```

⚠️ Mais ça va chercher la valeur dans `.env` (locale), donc faut d'abord :

```bash
echo "DIABA_SIMPLE_MODE=true" >> .env
git add .env.example
git commit -m "chore: enable simple mode"
git push origin main
```

---

## Vérification du statut

### Sur **dev.diaba.cloud**

```bash
curl https://dev.diaba.cloud/simple -I
# Output: 200 OK ✓ (dev est à jour)
```

### Sur **diaba.cloud** (production)

```bash
curl https://diaba.cloud/simple -I
# Output: 404 Not Found ✗ (production n'est pas à jour)
```

---

## Checklist pour résoudre

- [ ] **Accès au serveur** : SSH ou gestionnaire de secrets
- [ ] **Localiser le fichier `.env`** : `/opt/diaba/prod/.env` (ou autre chemin)
- [ ] **Ajouter/modifier** : `DIABA_SIMPLE_MODE=true`
- [ ] **Redémarrer** : `docker compose restart app` (ou kubectl restart)
- [ ] **Tester** : `curl https://diaba.cloud/simple -I` → 200
- [ ] **Consulter les logs** : `docker compose logs app | grep simple`

---

## Commandes de test post-activation

```bash
# Vérifier que la route existe
curl -s https://diaba.cloud/simple | head -20
# Doit retourner du HTML, pas 404

# Tester les autres écrans
curl -s https://diaba.cloud/simple/vendre -I | grep HTTP
# Doit retourner 200

# Vérifier la config
curl -s https://diaba.cloud/api/config | grep simple_mode
# Doit avoir "simple_mode": {"enabled": true}
```

---

## Statut actuel

| Environnement       | DIABA_SIMPLE_MODE      | État            | Action                        |
| ------------------- | ---------------------- | --------------- | ----------------------------- |
| `.env.example`      | ✅ true                | Modèle OK       | Aucune                        |
| **localhost**       | ❓ À mettre à jour     | Local seulement | `cp .env.example .env` + edit |
| **dev.diaba.cloud** | ✅ true (probablement) | À vérifier      | Tester : curl `.../simple`    |
| **diaba.cloud**     | ❌ false (par défaut)  | **BLOQUANT**    | **À activer NOW**             |

---

## Prochaines étapes

1. **Contact Étienne** : accès au serveur ou gestionnaire de secrets
2. **Activer** : `DIABA_SIMPLE_MODE=true`
3. **Redémarrer** : l'app
4. **Tester** : `https://diaba.cloud/simple` → 200 OK
5. **Valider** : accueil mode simple s'affiche

---

## Commande rapide (si tu as SSH)

```bash
# Connexion
ssh user@diaba.cloud

# Édite .env
sed -i 's/DIABA_SIMPLE_MODE=false/DIABA_SIMPLE_MODE=true/' /opt/diaba/prod/.env

# Redémarre
docker compose restart app

# Attends 10s
sleep 10

# Teste
curl https://diaba.cloud/simple -I | grep HTTP
# → Doit retourner "200 OK"
```

---

**TL;DR** : Le serveur production n'a pas reçu `DIABA_SIMPLE_MODE=true`. Il faut l'ajouter aux secrets/env du serveur et redémarrer.

**Qui** : Étienne (accès production)  
**Quoi** : Ajouter `DIABA_SIMPLE_MODE=true` dans `/opt/diaba/prod/.env`  
**Puis** : Redémarrer l'app  
**Résultat** : `https://diaba.cloud/simple` → 200 OK
