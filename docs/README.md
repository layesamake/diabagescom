# Diaba — dossier de spécifications

Ce dossier est la source de vérité fonctionnelle et technique du projet Diaba. Il est destiné à une équipe de développement ou à une IA qui implémente le produit de bout en bout.

## Produit

Diaba est un SaaS de gestion commerciale et comptable destiné aux TPE, PME et PMI. Il gère plusieurs entreprises clientes, utilisateurs, dépôts et droits. La devise de lancement est le franc CFA (XOF), la TVA par défaut est de 18 %, et l’interface est en français avec préparation du wolof.

## Environnements

| Environnement | Adresse | Usage |
|---|---|---|
| Développement | `https://dev.diaba.cloud` | Tests internes et validation |
| Production | `https://diaba.cloud` | Application client |

Les deux environnements utilisent des bases de données, comptes de paiement et secrets distincts.

## Documents à lire dans cet ordre

1. [Décisions produit](./01-decisions-produit.md)
2. [Exigences fonctionnelles](./02-exigences-fonctionnelles.md)
3. [Architecture technique](./03-architecture-technique.md)
4. [Modèle de données](./04-modele-donnees.md)
5. [Comptabilité SYSCOHADA](./05-comptabilite.md)
6. [API et intégrations](./06-api-integrations.md)
7. [Expérience utilisateur](./07-ux-ui.md)
8. [PWA et hors connexion](./08-pwa-hors-ligne.md)
9. [Sécurité et exploitation](./09-securite-exploitation.md)
10. [Plan de réalisation](./10-plan-realisation.md)
11. [Critères d’acceptation](./11-acceptation.md)
12. [Guide pour l’IA de développement](./12-guide-ia.md)
13. [Matrice de permissions](./13-permissions.md)
14. [Backlog d’implémentation](./14-backlog-implementation.md)
15. [Identité visuelle](./15-identite-visuelle.md)

## Règle de priorité

En cas de contradiction, appliquer dans cet ordre : sécurité et intégrité comptable, décisions produit, exigences fonctionnelles, architecture, autres documents. Ne jamais contourner une règle d’isolation d’entreprise ou modifier une écriture comptable validée.
