# 🔧 DIAGNOSTIC ENDPOINTS — Comment identifier le vrai problème

## 📍 Endpoints temporaires ajoutés pour déboguer

Ces endpoints vous aideront à identifier **exactement** quel est le problème.

---

## 🔍 Endpoint 1 : Vérifier la configuration

**URL** : `https://diaba.cloud/diagnostic/trial-extend`  
**Méthode** : GET  
**Authentification** : Non requise

**Ce qu'il fait** :
- Montre l'environnement (production)
- Montre la configuration de validation pour `days`
- Vérifie que la base de données répond
- Vérifie que le cache fonctionne

**Résultat attendu** :
```json
{
  "timestamp": "2026-10-28T...",
  "environment": "production",
  "config": {
    "subscriptions_trial_days": 14,
    "cache_driver": "redis",
    "queue_driver": "redis"
  },
  "database": {
    "connection": "pgsql",
    "status": "OK"
  },
  "validation_rules": {
    "days_field": "['required_if:action,extend_trial', 'integer', 'min:1', 'max:60']",
    "note": "No nullable here - fix is applied"
  }
}
```

**Si tu vois `No nullable here`** : ✅ Le fix est déployé  
**Si tu ne vois pas cet endpoint** : ❌ Le déploiement n'a pas fonctionné

---

## ✅ Endpoint 2 : Tester la validation directement

**URL** : `https://diaba.cloud/diagnostic/trial-extend-test`  
**Méthode** : POST  
**Authentification** : Non requise

**Envoie** :
```json
{
  "action": "extend_trial",
  "reason": "Test diagnostic",
  "days": 14,
  "subscription_id": "01a14a98-570b-71e6-8e9b-fa57be0a8da4"
}
```

**Résultat attendu** :
```json
{
  "status": "validation_passed",
  "data": {
    "action": "extend_trial",
    "reason": "Test diagnostic",
    "days": 14,
    "subscription_id": "01a14a98-570b-71e6-8e9b-fa57be0a8da4"
  },
  "message": "Validation successful - no errors here"
}
```

**Si validation passe** : ✅ Validation est OK  
**Si validation échoue** : ❌ Erreur de validation (montrer le message)

---

## 📋 Endpoint 3 : Voir les erreurs récentes

**URL** : `https://diaba.cloud/diagnostic/recent-errors?limit=20`  
**Méthode** : GET  
**Authentification** : Non requise

**Ce qu'il fait** :
- Liste les 20 dernières erreurs dans les logs

**Résultat** :
```json
{
  "count": 3,
  "errors": [
    "[2026-10-28 17:45:30] production.ERROR: ...",
    "[2026-10-28 17:44:15] production.ERROR: ...",
    "[2026-10-28 17:43:01] production.ERROR: ..."
  ]
}
```

**Cherche** : Y a-t-il une erreur "extend_trial" ?

---

## 🎯 Endpoint 4 : Erreurs spécifiques "extend_trial"

**URL** : `https://diaba.cloud/diagnostic/extend-trial-errors`  
**Méthode** : GET  
**Authentification** : Non requise

**Ce qu'il fait** :
- Cherche dans les logs toutes les lignes contenant "extend_trial"
- Montre les 50 dernières

**Résultat** :
```json
{
  "count": 12,
  "lines": [
    "[2026-10-28 17:45:30] ... extend_trial action called",
    "[2026-10-28 17:45:30] ... InvalidArgumentException: ...",
    "[2026-10-28 17:45:31] ... Stack trace: ..."
  ]
}
```

**C'est ici que tu trouveras l'erreur exacte !**

---

## 📝 Checklist de diagnostic

### Étape 1 : Vérifier le déploiement
```
GET /diagnostic/trial-extend
Cherche : "No nullable here - fix is applied"
Résultat : ✅ Fix est déployé ou ❌ Fix n'est pas déployé
```

### Étape 2 : Tester la validation
```
POST /diagnostic/trial-extend-test
Corps: {action: extend_trial, reason: Test, days: 14, subscription_id: ...}
Résultat : ✅ Validation passe ou ❌ Validation échoue
```

### Étape 3 : Chercher l'erreur
```
GET /diagnostic/extend-trial-errors
Cherche : Message d'erreur exact
Résultat : Identifie la VRAIE cause
```

---

## 🎯 Cas possibles

### Cas 1 : "No nullable here" + Validation passe
**Diagnostic** : Le fix est déployé et fonctionne  
**Cause de l'erreur 500** : Autre chose (pas la validation)  
**Action** : Vérifier les logs pour la vraie exception

### Cas 2 : "No nullable here" + Validation échoue
**Diagnostic** : Le fix est déployé mais validation échoue  
**Cause** : Données invalides envoyées par le formulaire  
**Action** : Vérifier l'onglet Network du navigateur pour voir la requête exacte

### Cas 3 : Endpoint introuvable (404)
**Diagnostic** : Le déploiement n'a pas fonctionné  
**Cause** : Le code n'est pas en production  
**Action** : Redéployer manuellement

### Cas 4 : "nullable" dans la validation
**Diagnostic** : Le fix n'est PAS déployé  
**Cause** : La production exécute l'ancienne version  
**Action** : Forcer le redéploiement

---

## 🚀 Utilisation

**Sur ton navigateur** :
1. Va sur https://diaba.cloud/diagnostic/trial-extend
2. Regarde le résultat JSON
3. Si "No nullable here" → Fix est OK
4. Puis va sur https://diaba.cloud/diagnostic/extend-trial-errors
5. Cherche le message d'erreur exact

**C'est cette erreur que nous devons fixer ensuite.**

---

## 🧹 Nettoyage

**IMPORTANT** : Ces endpoints sont temporaires et doivent être supprimés après debugging.

```bash
# Avant de merger en production finale
git remove app/Http/Controllers/DiagnosticController.php
git remove les routes diagnostic de routes/web.php
git commit -m "chore: remove diagnostic endpoints"
```

---

## ⏭️ Après avoir obtenu l'erreur

1. Copie le message d'erreur exact de `/diagnostic/extend-trial-errors`
2. Envoie-le-moi
3. Nous identifierons la VRAIE cause
4. Appliquerons le bon fix

---

**Le problème est que le code en production n'a pas ce que tu as modifié localement.**  
**Ces endpoints nous permettront de voir exactement ce qui se passe.**
