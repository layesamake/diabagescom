# ✅ CHECKLIST DÉPLOIEMENT — Erreur 500 Fix

## 🚀 Déploiement en cours

**Workflow GitHub Actions** : https://github.com/layesamake/diabagescom/actions/runs/40736849222  
**Status** : En attente  
**Durée estimée** : 10–15 minutes  

---

## 📋 Tests à effectuer après déploiement

### ✅ Test 1 : Prolonger essai (cas normal)

**Étapes** :
1. Va sur https://diaba.cloud/platform/tenants
2. Sélectionne une entreprise en essai
3. Clique "Prolonger l'essai"
4. Remplis :
   - **Jours** : 14
   - **Raison** : "Customer request"
5. Clique "Appliquer"

**Résultats attendus** :
- ✅ Redirection sans erreur
- ✅ Toast success : "Souscription mise à jour"
- ✅ `trial_ends_at` augmenté de 14 jours
- ✅ Log d'audit créé

**Si ça fonctionne** : Le fix est bon ! 🎉

---

### ✅ Test 2 : Validation jours (min/max)

**Étapes** :
1. Teste avec jours = 0
2. Teste avec jours = 61
3. Teste avec jours vide (null)

**Résultats attendus** :
- ✅ Erreur validation 422
- ✅ Message d'erreur : "The days field must be between 1 and 60"
- ✅ Pas d'erreur 500

---

### ✅ Test 3 : Validation raison (min length)

**Étapes** :
1. Remplis raison = "abc" (< 5 caractères)
2. Clique "Appliquer"

**Résultats attendus** :
- ✅ Erreur validation 422
- ✅ Message : "The reason field must be at least 5 characters"
- ✅ Pas d'erreur 500

---

### ✅ Test 4 : Vérifier les logs

**Sur le serveur** :
```bash
tail -50 storage/logs/laravel.log
```

**Résultats attendus** :
- ✅ Aucune erreur 500
- ✅ Pas d'exceptions `InvalidArgumentException`
- ✅ Log d'audit pour `subscription.trial_extended`

---

### ✅ Test 5 : Vérifier la base de données

**Query** :
```sql
SELECT * FROM audit_logs 
WHERE action = 'subscription.trial_extended'
ORDER BY created_at DESC 
LIMIT 1;
```

**Résultats attendus** :
- ✅ Entry créée avec `reason` et metadata
- ✅ Timestamp correct
- ✅ Tenant ID valide

---

## 🔍 Diagnostic si ça ne marche pas

### Symptôme : Toujours erreur 500

**Actions** :
1. Vérifier que commit `c70045d` est en production
   ```bash
   grep -n "required_if:action,extend_trial" \
     app/Http/Controllers/Platform/TenantController.php
   # Doit NOT avoir 'nullable' après
   ```

2. Vider le cache
   ```bash
   docker compose exec app php artisan cache:clear
   docker compose exec app php artisan config:clear
   docker compose restart app
   ```

3. Vérifier les logs
   ```bash
   tail -100 storage/logs/laravel.log | grep -A 20 "extend_trial"
   ```

4. Si toujours buggé → Contacter Étienne pour logs complets

---

## 📊 Tableau de suivi

| Test | Status | Notes |
|------|--------|-------|
| Déploiement GitHub Actions | ⏳ En cours | Run #40736849222 |
| Prolonger essai (normal) | ⏳ À tester | Après déploiement |
| Validation jours | ⏳ À tester | min:1, max:60 |
| Validation raison | ⏳ À tester | min:5, max:500 |
| Logs vérifiés | ⏳ À tester | Pas de 500 |
| Base données | ⏳ À tester | Audit log créé |

---

## 🔗 Ressources

| Ressource | URL |
|-----------|-----|
| **Workflow** | https://github.com/layesamake/diabagescom/actions/runs/40736849222 |
| **Production** | https://diaba.cloud |
| **Platform** | https://diaba.cloud/platform/tenants |
| **Commits** | c70045d (fix principal) |
| **Documentation** | BUG_ROOT_CAUSE_500_FINAL.md |

---

## 📝 Notes

- **Temps déploiement** : ~15 min
- **Action requise** : Tester les 5 cas ci-dessus
- **Escalade** : Si ça ne marche pas, contacter Étienne
- **Succès** : Aucune erreur 500 lors de prolongation d'essai

---

**Mise à jour** : 28 Octobre 2026, 16:45 UTC  
**Workflow** : En attente (pending)  
**Prochaine action** : Attendre déploiement et tester
