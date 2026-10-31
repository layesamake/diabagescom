# 🔍 DIAGNOSTIC — Erreur 500 persiste après le fix

## Situation

Le message d'erreur 500 persiste **après le déploiement** du fix :

```
Le serveur n'a pas pu répondre (erreur 500).
```

Le fix a été appliqué et poussé vers `main`, mais la production n'a peut-être pas été redeployée.

---

## Causes possibles

### 1️⃣ **Déploiement non effectué**

- ❓ Le workflow GitHub Actions n'a pas terminé
- ❓ Le déploiement s'est arrêté mi-chemin
- ❓ Production exécute encore l'ancienne version

**Vérification** :

```bash
# Sur le serveur
grep "Resolve tenant once" /var/www/html/app/Domain/Subscriptions/SubscriptionLifecycle.php
# Si absent → vieille version en production
```

### 2️⃣ **Cache Laravel non vidé**

- ❓ Les fichiers PHP sont à jour, mais le cache bootstrap persiste
- ❓ Le cache de configuration n'a pas été rafraîchi
- ❓ OPcache serveur a gardé l'ancienne version

**Vérification** :

```bash
# Sur le serveur
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose restart app
```

### 3️⃣ **Autre cause non identifiée**

- ❓ L'erreur 500 vient d'ailleurs
- ❓ `tenantOf()` n'est pas l'unique cause
- ❓ Plusieurs bugs imbriqués

**Approche** : Capturer l'erreur exacte dans les logs

---

## Plan d'action (Phases de débogage)

### Étape 1️⃣ : Vérifier le déploiement

```bash
# SSH sur le serveur
ssh root@diaba.cloud

# Affiche le dernier commit deployé
cd /var/www/html && git log -1 --oneline

# Cherche le fix dans le code
grep -n "Resolve tenant once" app/Domain/Subscriptions/SubscriptionLifecycle.php

# Si absent → déploiement non fait
```

### Étape 2️⃣ : Vider le cache

```bash
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:cache
docker compose restart app
```

### Étape 3️⃣ : Capturer les logs d'erreur

```bash
# Affiche les 50 dernières lignes
tail -50 storage/logs/laravel.log | grep -A 20 "extend_trial\|500\|Exception"

# Ou cherche l'erreur complète
grep -B 5 -A 20 "extend_trial" storage/logs/laravel.log | tail -100
```

### Étape 4️⃣ : Tester la route directement

```bash
# Teste avec curl (simulation formulaire)
curl -X POST https://diaba.cloud/platform/tenants/{id}/subscriptions/update \
  -H "Content-Type: application/json" \
  -d '{
    "action": "extend_trial",
    "reason": "Test diagnostic",
    "days": 7
  }' \
  -v

# -v = verbose (montre les headers et l'erreur)
```

---

## Actions recommandées (par ordre de probabilité)

### 🔴 **URGENT — Probable (90%)**

**Cause** : Déploiement incomplet ou cache non vidé

**Actions** :

1. Vérifier que `33b0b4a` est en production
2. Vider le cache Laravel
3. Redémarrer l'app
4. Tester

### 🟡 **Probable (50%)**

**Cause** : Erreur différente (pas `tenantOf()`)

**Actions** :

1. Lire les logs complets
2. Identifier l'exception exacte
3. Relancer le débogage avec nouvelle cause

### 🟢 **Peu probable (10%)**

**Cause** : Plusieurs bugs imbriqués

**Actions** :

1. Contacter Étienne pour accès SSH
2. Analyser les logs live
3. Debugger en production directement

---

## Checklist

- [ ] Vérifier que le commit `33b0b4a` est en production
- [ ] Chercher "Resolve tenant once" dans le fichier SubscriptionLifecycle.php
- [ ] Vider cache : `php artisan cache:clear`
- [ ] Redémarrer app : `docker compose restart app`
- [ ] Tester : prolonger essai → pas d'erreur 500
- [ ] Vérifier logs : aucune exception
- [ ] Tester audit log : vérifier enregistrement

---

## Commandes rapides (pour SSH)

```bash
ssh root@diaba.cloud

cd /var/www/html

# Vérifier le déploiement
git log -1 --oneline

# Chercher le fix
grep -n "Resolve tenant once" app/Domain/Subscriptions/SubscriptionLifecycle.php

# Vider tous les caches
php artisan cache:clear
php artisan config:clear
php artisan route:cache

# Redémarrer l'app
docker compose restart app

# Attendre 10s
sleep 10

# Vérifier que l'app répond
curl https://diaba.cloud/health

# Si 200 : app rebootée avec succès
```

---

## Si aucune de ces actions ne fonctionne

**Prochaine étape** : Contacter **Étienne** pour :

1. Accès SSH au serveur production
2. Logs complets de l'erreur 500
3. État du déploiement (en cours, terminé, échoué)
4. Configuration du serveur (cache, OPcache, etc.)

**Questions à poser à Étienne** :

- « Le workflow GitHub Actions a-t-il terminé ? »
- « Quel commit est actuellement en production ? »
- « Y a-t-il des logs d'erreur pour l'action extend_trial ? »
- « Le cache Laravel a-t-il été vidé après le déploiement ? »

---

## Documentation créée

- `BUG_500_EXTEND_TRIAL.md` — Root cause analysis complète
- `FIX_SUMMARY_TRIAL_EXTENSION.md` — Résumé du fix
- `TRIAL_EXTENSION_DIAGNOSTIC.md` — Ce document (diagnostic)

---

**Status** : 🟡 Investigation en cours  
**Prochaine étape** : Vérifier le déploiement en production
