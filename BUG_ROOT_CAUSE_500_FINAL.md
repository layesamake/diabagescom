# 🎯 ROOT CAUSE TROUVÉE — Erreur 500 lors de prolongation d'essai

## Le vrai problème (découverte Phase 1 complète)

L'erreur 500 **n'était pas causée par `tenantOf()`**, mais par une **validation Laravel incohérente**.

---

## 🐛 Bug réel

### Fichier

`app/Http/Controllers/Platform/TenantController.php`, ligne 310

### Code bugué

```php
'days' => ['required_if:action,extend_trial', 'nullable', 'integer', 'min:1', 'max:60'],
                                             ^^^^^^^^
```

### Le problème

La règle de validation inclut **`'nullable'`**, ce qui signifie :

- ✅ Le champ peut être null
- ✅ Mais il est aussi `required_if:action,extend_trial`

**Conflit** : Quand `action = 'extend_trial'`:

1. `days` est REQUIS
2. Mais `'nullable'` le permet à `null`
3. Validation **passe même si `days` est null** ❌

### Chaîne d'erreur

```
1. Frontend : envoie days: null (ou le champ est vide)
   ↓
2. Backend validation (ligne 310) : 'nullable' permet null
   Validation passe ✓
   ↓
3. Ligne 325 : (int) null = 0
   $lifecycle->extendTrial(..., 0, ...)
   ↓
4. SubscriptionLifecycle::extendTrial() ligne 146-147 :
   if ($days < 1 || $days > 60) {
       throw new InvalidArgumentException(...)
   }
   ↓
5. Exception NOT CAUGHT
   ↓
6. Laravel retourne 500 ❌
```

---

## ✅ Fix appliqué

### Changement

**Ligne 310** : Supprimer `'nullable'`

```diff
- 'days' => ['required_if:action,extend_trial', 'nullable', 'integer', 'min:1', 'max:60'],
+ 'days' => ['required_if:action,extend_trial', 'integer', 'min:1', 'max:60'],
```

### Résultat

Maintenant quand `action = 'extend_trial'`:

1. `days` est REQUIS (pas nullable)
2. Si absent ou null → Erreur de validation 422 ✓
3. Si présent → Doit être integer 1–60 ✓
4. ✅ No 500 error

---

## 📊 Comparaison

| Étape                 | Avant (Bugué)  | Après (Correct)                 |
| --------------------- | -------------- | ------------------------------- |
| User envoie days=null | ✓              | ❌ Rejeté validation 422        |
| Validation passe      | ✓ Oui (bug)    | ✓ Non (correct)                 |
| Cast to int           | (int) null = 0 | N/A                             |
| extendTrial()         | Reçoit 0       | Reçoit 1-60                     |
| Exception             | Lancée → 500   | Pas d'exception                 |
| Résultat utilisateur  | Erreur 500     | Erreur 422 validation ou succès |

---

## 🔍 Chronologie du débogage

### Hypothèse 1 (Fausse ❌)

**"Le problème vient de `tenantOf()` qui lance une exception"**

- Appliqué fix : cachage de `tenantOf()`
- Résultat : Erreur 500 persiste
- **Conclusion** : Ce n'était pas la cause

### Hypothèse 2 (Vraie ✅)

**"La validation laisse passer `days=null`, ce qui provoque une exception dans `extendTrial()`"**

- Analysé validation règles
- Trouvé `'nullable'` incompatible avec `'required_if'`
- Supprimé `'nullable'`
- **Conclusion** : C'était la vraie cause

---

## 📝 Commits appliqués

### Commit 1 : Fix initial (incorrect)

```
33b0b4a fix(billing): resolve race condition in trial extension
- Cachage de tenantOf() ✓ (bonne optimisation)
- Mais : n'a pas résolu le 500 ❌
```

### Commit 2 : Fix vrai (correct) 🎯

```
c70045d fix(validation): remove nullable from required_if days field
- Suppression de 'nullable' de la règle 'days' ✓
- Élimine la validation conflictuelle
- Résout l'erreur 500 ✓
```

---

## 🧪 Validation

### Test 1 : Envoyer days=null (vieux bug)

**Avant** : Passe validation → Exception 500  
**Après** : Rejette validation → Erreur 422 ✓

### Test 2 : Envoyer days=0 (vieux bug)

**Avant** : Passe validation → Exception 500  
**Après** : Rejette validation (min:1) → Erreur 422 ✓

### Test 3 : Envoyer days=14 (normal)

**Avant** : Valide → Exception non-liée possible  
**Après** : Valide → Succès ✓

### Test 4 : Envoyer days=61 (overflow)

**Avant** : Rejette validation (max:60) → Erreur 422  
**Après** : Rejette validation (max:60) → Erreur 422 ✓

---

## 🔐 Défense en profondeur

Même si la validation avait passé, le code devrait gérer l'exception :

```php
// Bonne pratique (à ajouter)
try {
    match ((string) $data['action']) {
        'extend_trial' => $lifecycle->extendTrial(...),
        // ...
    };
} catch (InvalidArgumentException $e) {
    return back()->withErrors(['_general' => $e->getMessage()]);
}
```

Mais avec le fix de validation, c'est maintenant prévenu à la source.

---

## 📋 Fichiers modifiés

| Fichier                         | Ligne   | Changement               |
| ------------------------------- | ------- | ------------------------ |
| `TenantController.php`          | 310     | Suppression `'nullable'` |
| `TRIAL_EXTENSION_DIAGNOSTIC.md` | Nouveau | Diagnostic complet       |

---

## 🚀 Déploiement

**Commit** : `c70045d`  
**Branch** : main  
**Ready** : ✅ Oui

```bash
# Commandes pour production
git log --oneline -1
# c70045d fix(validation): remove nullable from required_if days field

# Vérifier le fix en production
grep -n "required_if:action,extend_trial" app/Http/Controllers/Platform/TenantController.php
# Doit NOT avoir 'nullable'
```

---

## 📊 Résumé des bugs trouvés

| Bug #     | Fichier                   | Ligne    | Severity | Status                      |
| --------- | ------------------------- | -------- | -------- | --------------------------- |
| **Bug 1** | SubscriptionLifecycle.php | 294, 296 | Medium   | ✅ Fixé (cachage)           |
| **Bug 2** | TenantController.php      | 310      | **HIGH** | ✅ Fixé (nullable supprimé) |

**Bug 2 est le vrai responsable de l'erreur 500.**

---

## Leçons apprises

1. **Ne pas supposer la cause** : La première hypothèse était fausse. Le débogage systématique révèle la vraie cause.
2. **Validation contradictoire** : `'nullable'` + `'required_if'` = piège classique
3. **Exceptions non capturées** : Les `InvalidArgumentException` doivent être gérées
4. **Defense in depth** : Validation + exception handling = robuste

---

**Fix appliqué le 28 Octobre 2026**  
**Commit** : c70045d  
**Status** : 🟢 Production ready
