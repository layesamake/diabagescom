# 🔧 VALIDATION FIXES — Tous les `nullable` + `required_if` bugs supprimés

## 🐛 Bugs trouvés et fixés

### Bug #1 : TenantController (ligne 310) ✅ FIXÉ

**Fichier** : `app/Http/Controllers/Platform/TenantController.php`

```php
// AVANT (BUGUÉ)
'days' => ['required_if:action,extend_trial', 'nullable', 'integer', 'min:1', 'max:60'],
                                              ^^^^^^^^

// APRÈS (CORRECT)
'days' => ['required_if:action,extend_trial', 'integer', 'min:1', 'max:60'],
```

**Impact** : Erreur 500 lors de prolongation d'essai

---

### Bug #2 : TenantController (ligne 309) ✅ FIXÉ

**Fichier** : `app/Http/Controllers/Platform/TenantController.php`

```php
// AVANT (BUGUÉ)
'plan_id' => ['required_if:action,change_plan', 'nullable', 'uuid', Rule::exists('plans', 'id')],
                                                 ^^^^^^^^

// APRÈS (CORRECT)
'plan_id' => ['required_if:action,change_plan', 'uuid', Rule::exists('plans', 'id')],
```

**Impact** : Erreur possible lors de changement de plan

---

### Bug #3 : TenantController (ligne 311) ✅ FIXÉ

**Fichier** : `app/Http/Controllers/Platform/TenantController.php`

```php
// AVANT (BUGUÉ)
'billing_cycle' => ['required_if:action,activate', 'nullable', Rule::in(['monthly', 'yearly'])],
                                                   ^^^^^^^^

// APRÈS (CORRECT)
'billing_cycle' => ['required_if:action,activate', Rule::in(['monthly', 'yearly'])],
```

**Impact** : Erreur possible lors d'activation manuelle

---

### Bug #4 : StockController (ligne 136) ✅ FIXÉ

**Fichier** : `app/Http/Controllers/Inventory/StockController.php`

```php
// AVANT (BUGUÉ)
'reason' => ['nullable', 'string', 'max:500', 'required_if:mode,adjustment'],
             ^^^^^^^^

// APRÈS (CORRECT)
'reason' => ['string', 'max:500', 'required_if:mode,adjustment'],
```

**Impact** : Erreur possible lors d'ajustement de stock

---

## 🔍 Audit complet

Scan effectué sur tous les controllers :

```bash
grep -r "required_if.*nullable\|nullable.*required_if" app/
# Résultat : 0 matches (tous les bugs corrigés)
```

---

## 📊 Résumé des corrections

| Fichier              | Ligne | Champ           | Action                      |
| -------------------- | ----- | --------------- | --------------------------- |
| TenantController.php | 310   | `days`          | ✅ Suppression `'nullable'` |
| TenantController.php | 309   | `plan_id`       | ✅ Suppression `'nullable'` |
| TenantController.php | 311   | `billing_cycle` | ✅ Suppression `'nullable'` |
| StockController.php  | 136   | `reason`        | ✅ Suppression `'nullable'` |

---

## 🎯 Explanation du pattern erroné

### Pourquoi `'nullable' + 'required_if'` est mauvais

```
required_if:action,extend_trial
  → "Si action = 'extend_trial', le champ est REQUIS"

nullable
  → "Le champ peut être null"

Conflit
  → Validation passe même si le champ est null
  → Code métier reçoit null
  → Exception non gérée
  → 500 Error
```

### Règle correcte

```
required_if:action,extend_trial
  → SEUL, sans 'nullable'
  → Si action = 'extend_trial', champ obligatoire et non-null
  → Validation rejette null → 422 Error (bon comportement)
```

---

## 📋 Tests à effectuer

### Test 1 : Prolonger essai

```bash
POST /platform/tenants/{id}/subscriptions/update
days = null  # Doit retourner 422, pas 500
```

### Test 2 : Changer de plan

```bash
POST /platform/tenants/{id}/subscriptions/update
plan_id = null  # Doit retourner 422, pas 500
```

### Test 3 : Activer souscription

```bash
POST /platform/tenants/{id}/subscriptions/update
billing_cycle = null  # Doit retourner 422, pas 500
```

### Test 4 : Ajustement stock

```bash
POST /inventory/warehouses/{id}/stock/adjust
mode = 'adjustment'
reason = null  # Doit retourner 422, pas 500
```

---

## 🚀 Déploiement

**Commit** : À appliquer avec c70045d (main fix)  
**Status** : Prêt pour production

```bash
git log --oneline -1
# Doit montrer les 4 corrections de validation
```

---

## 📝 Leçons apprises

1. **Pattern anti** : `'nullable' + 'required_if'` = contradictoire
2. **Audit complet** : Chercher tous les cas similaires
3. **Validation stricte** : Mieux vaut rejeter (422) que crasher (500)

---

**Fixes appliqués le 28 Octobre 2026**  
**Total bugs trouvés et fixés** : 4
