# Rapport Phase 3 — Prototype Safe Native et non-régression

**Date :** 29 octobre 2026  
**Statut :** validé pour la **preuve de concept en laboratoire**, non conforme à toutes les exigences du cahier des charges et **non intégré au bundle publié**.  
**Branche :** `feature/safe-native-prototype-v3` du dépôt `PsiGuardian/profiler-shield-real-test`.  
**Référence publiée du bundle :** `omegaguardian/profiler-shield v1.0.0`, inchangée et toujours testée comme baseline.

## 1. Résultats prouvés par CI

| Campagne | Symfony/PHP | Résultat | Preuve |
|---|---|---|---|
| Prototype ciblé, 24 sondes × 6 combinaisons mode/debug × 5 versions | 5 couples | **720 PASS / 0 FAIL** | [Run prototype #40802852147](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40802852147) |
| Matrice avant/après, 84 cas × 6 variantes × 5 versions × 2 noyaux guarded | 5 couples | **210 améliorations, 0 régression** | [Run comparative #40803145810](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40803145810) |
| Concurrence : 960 transactions chiffrées et 24 tentatives simultanées × 5 versions | 5 couples | **5 jobs PASS** | [Run concurrence #40803121051](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40803121051) |

### Comparaison exhaustive des résultats de matrice

| PHP | Symfony | Baseline PASS/FAIL/NOT_RUN | Prototype PASS/FAIL/NOT_RUN | FAIL → PASS | PASS → autre |
|---|---|---|---|---:|---:|
| 8.3 | 5.4 | 349 / 90 / 65 | **391 / 48 / 65** | 42 | 0 |
| 8.3 | 6.4 | 361 / 90 / 53 | **403 / 48 / 53** | 42 | 0 |
| 8.3 | 7.4 | 361 / 90 / 53 | **403 / 48 / 53** | 42 | 0 |
| 8.4 | 7.4 | 361 / 90 / 53 | **403 / 48 / 53** | 42 | 0 |
| 8.4 | 8.1 | 361 / 90 / 53 | **403 / 48 / 53** | 42 | 0 |
| **Total** | | **1 793 / 450 / 277** | **2 003 / 240 / 277** | **210** | **0** |

**Comptabilité :** les 42 améliorations de chaque version correspondent à 7 scénarios auparavant non conformes, répétés dans les trois modes d'erreur et deux configurations de débogage : `BASE-04`, `BASE-05`, `HTTP-10`, `HTTP-11`, `HTTP-12`, `HTTP-13`, `HTTP-20`.

Cette amélioration est **une comparaison d'évaluations**, pas la correction de 210 vulnérabilités distinctes.

## 2. Démonstration de la conception

La preuve de concept est circonscrite à `tests/Prototype/`. Elle comprend :

- un `PublicErrorDescriptor` immuable, validant statut, code, message et allowlist d'en-têtes ;
- un `TrustedErrorMapper` n'autorisant qu'une exception métier 422 de la route synthétique explicitement connue ;
- un `SafeNativeRenderer` qui fournit les fallbacks neutres JSON/XML, Problem Details JSON/XML et la sélection d'un format par `Accept;q=` ;
- un `SafeNativeSubscriber` de **laboratoire** placé avant l'intercepteur Guardian pour étudier le mécanisme. Le prototype n'est jamais installé dans `profiler-shield`.

Invariants vérifiés : réponse métier saine intacte ; 422 métier avec code public inchangé ; exception inconnue neutralisée ; secret fictif absent ; mapper qui lève une erreur traité sans fuite ; statut 401/405/429 et en-têtes obligatoires maintenus ; API JSON/XML ; RFC 9457 minimal ; routes profiler refusées à l'anonyme ; en-têtes debug supprimés ; contrôle des CRLF, des headers interdits et des statuts de descripteur.

## 3. Concurrence, limites et nouvelles preuves

Le test `bin/concurrency.php` ouvre **8 processus simultanés** par ronde pour écrire un compteur chiffré, 40 écritures par processus, 3 rondes, soit **960 opérations par version**. Il vérifie après chaque ronde l'exactitude du compteur (320, 640, 960) et rejette les pertes d'écritures.

Ensuite 24 processus concurrents essaient de réserver une tentative de connexion pour le même identifiant et la même IP de fixture ; le nombre accepté ne doit pas dépasser le seuil de 10, les autres sont bloqués. Enfin une enveloppe JSON chiffrée volontairement corrompue doit être rejetée, puis le contenu authentique restauré et relu.

**Résultat : 5 configurations réussies.** Cela renforce `FAULT-07` **dans une campagne indépendante**, sans requalifier rétroactivement les `NOT_RUN` de la matrice initiale.

Limitations : pas de multi-nœud, pas de NFS, pas de charge d'attaque volumétrique, pas de preuve d'absence de race condition dans tous les interleavings théoriques, et les opérations de concurrence concernent le coffre et le throttle, pas toutes les fonctionnalités HTTP.

## 4. Exigences qui restent ouvertes

**240 évaluations sont encore FAIL** en prototype sur les cinq versions, et **277 NOT_RUN** dans la matrice principale. Elles comprennent notamment :

- `SAFE-04/05/06` : les **interfaces publiques dans le bundle publié** ne sont pas créées ; seule une classe de démonstration est présente dans le laboratoire ;
- `AUTH-07` : mécanisme volontaire de révocation **sélective des autres sessions** non implémenté dans le bundle ;
- `DOCTOR-01/02/03/05` : futur doctor HTTP, sortie JSON, mode strict, audit d'interventions d'erreur non implémentés dans le bundle ;
- `HTTP-05` : dans le noyau en mémoire la transmission sur le fil est marquée `NOT_RUN`, alors qu'elle a déjà été vérifiée séparément en vrai HTTP ;
- `FAULT-04/05/09` et certains volets de `DOCTOR-04/07/08` : injection de défaillances, uninstall/rollback et traduction non entièrement couverts ;
- `FAULT-07` : concurrence marquée `NOT_RUN` dans la matrice, mais renforcée par la campagne parallèle précisée plus haut.

**Les 720 PASS du prototype ne remplacent pas ces exigences.**

## 5. Décision architecturale

La preuve de faisabilité est positive : la préservation d'erreurs métier approuvées et la confidentialité des exceptions inconnues sont conciliables.

Néanmoins, la solution de laboratoire repose sur un listener Symfony dédié à priorité élevée. Une telle solution ne doit pas être copiée aveuglément dans le bundle, car elle pourrait court-circuiter d'autres décisions de sécurité. Le portage doit intégrer le mécanisme dans la politique de confidentialité Guardian, avec un contrôle prioritaire des routes du profiler, une validation centrale des mappers et des tests de non-régression multi-version.

Voir [Plan d'intégration Safe Native](plan-integration-safe-native.md).

## 6. Position de sécurité

Aucun secret réel ni donnée de production n'a été utilisé. Les routes de test sont limitées aux fixtures jetables. Aucun service externe ne reçoit de télémétrie ; aucune modification du bundle original n'a été réalisée. La base de test reste `v1.0.0` inchangée.

**Condition de publication future :** revue approfondie de l'interface publique, des règles de confiance, des évènements Symfony et des comportements API réels, validation de l'intégralité des P0, tests de rollback et audit de déploiement. Aucun merge ni transfert vers le dépôt original n'est autorisé par la seule réussite de la preuve de concept.
