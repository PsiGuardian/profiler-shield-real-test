# Rapport initial du laboratoire différentiel

**Date :** 29 octobre 2026  
**Commit testé :** `9d66bdcac4dc355d9e6ef6d03381da599d2938be` (branche `feature/real-test-lab-v1`)  
**Source du bundle :** version Packagist `v1.0.0`, installée par Composer `--no-plugins --no-scripts`  
**Exécution :** https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40798208572

## Statut

**FAIL — écarts de compatibilité reproductibles ; aucune conclusion de conformité globale.**

Le laboratoire fonctionne sur les cinq configurations prises en charge par cette première campagne :

| PHP | Symfony | Vérifications | PASS | FAIL |
|---|---|---:|---:|---:|
| 8.3 | 5.4 | 126 | 108 | 18 |
| 8.3 | 6.4 | 126 | 108 | 18 |
| 8.3 | 7.4 | 126 | 108 | 18 |
| 8.4 | 7.4 | 126 | 108 | 18 |
| 8.4 | 8.1 | 126 | 108 | 18 |
| **Total** | | **630** | **540** | **90** |

Les 21 scénarios exécutables sont déclinés selon les 3 modes `native`, `generic`, `custom` et `APP_DEBUG=0/1`. Les **84 scénarios du cahier des charges ne sont pas encore tous implémentés** : ceux qui manquent restent `NOT_RUN`.

## Échecs confirmés et répétés

| ID | Gravité | Observation | Attendu cible |
|---|---|---|---|
| `BASE-04` | Compatibilité P0 | Sur l'exception métier 422, Guardian remplace le JSON du gestionnaire hôte et supprime son header applicatif sûr | Conserver la représentation **si le contrat est explicitement approuvé par un mapper de confiance** |
| `SAFE-03` | Contrat P0 | Même transformation d'une HttpException 422, alors qu'une réponse métier contrôlée est disponible dans le noyau clean | Après ajout du mapper public, conserver le contrat approuvé sans jamais afficher le message interne |
| `HTTP-10` | Format P0 | Une exception inconnue appelée avec `Accept: application/xml` reçoit une réponse non XML | Produire un XML neutre bien formé ou un contrat XML approuvé, sans divulgation |

**Important :** la disparition d'une réponse métier non approuvée n'est pas une faille de confidentialité ; le test matérialise l'objectif de compatibilité **futur**. Il ne faut pas corriger les deux premiers échecs en autorisant aveuglément tous les listeners ou messages d'exception.

Ces 3 identifiants × 3 modes × 2 états debug = 18 échecs pour chaque configuration.

## Tests ayant donné un résultat conforme dans ce périmètre

Codes HTTP conservés sur les scénarios présents, réponses JSON et XML déjà construites préservées, routes métier accessibles, en-tête X-Debug-Token supprimé pour anonyme et refus du chemin profiler. **Cela ne démontre pas** l'absence de fuite dans tous les cas ni la conformité des 84 scénarios.

## Prochain lot prioritaire

1. Implémenter `PublicErrorMapperInterface` + `PublicErrorDescriptor` et un contrat explicitement approuvé ; convertir `BASE-04` et `SAFE-03` en PASS sans abaisser la protection de `kernel.exception`.
2. Ajouter le renderer XML neutre et la négociation MIME ; faire passer `HTTP-10`.
3. Étendre les fixtures à authentification réelle Guardian, collection profiler, MFA, CORS et méthode HEAD/OPTIONS, puis convertir progressivement la matrice complète en assertions.
4. Conserver la CI **bloquante** : les échecs sont réels et attendus avant correction du bundle ; ne jamais modifier les assertions pour masquer les ruptures.

Les données sensibles dans ce banc sont uniquement des sentinelles factices. Aucun système tiers n'a été testé. Aucun changement au bundle d'origine n'est réalisé par cette branche.
