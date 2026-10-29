# Validation réelle de `dev-1.0.1` — comparaison indépendante

**Date :** 29 octobre 2026  
**Objet :** confronter le vrai code `PsiGuardian/profiler-shield` branche `dev-1.0.1` au package Packagist `v1.0.0`, sans utiliser le prototype du laboratoire.  
**SHA vérifié de la branche testée :** `3595f1e483e4ea1ee2dcbd6ddbabe7e2976e1783`  
**Périmètre d'écriture :** seulement `PsiGuardian/profiler-shield-real-test` (branche `feature/validate-actual-dev-1.0.1`). Ni `main`, ni les tags, ni la branche source du bundle n'ont été modifiés.

[Preuve CI complète — matrice + HTTP réel + fuzzing](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40812412215)

## Méthode reproductible

Dans chaque job, GitHub Actions :

1. récupère **la véritable branche GitHub `dev-1.0.1`**, enregistre son SHA ;
2. installe **`v1.0.0` depuis Packagist** dans une application de test indépendante ;
3. installe **une copie locale non liée du vrai checkout GitHub**, avec une version Composer de test `1.0.1-dev`, dans un second environnement indépendant ; le SHA256 du `PrivacySubscriber.php` installé est vérifié contre le checkout ;
4. compare les mêmes **84 scénarios × 3 modes (`native`, `generic`, `custom`) × 2 états de débogage** avec les mêmes versions Symfony/PHP ;
5. refuse toute transition `PASS → FAIL` ou `PASS → NOT_RUN` ; publie les résultats `PASS`, `FAIL`, `NOT_RUN` et le SHA source ;
6. exerce le vrai transport HTTP (HEAD, SSE, flux et CORS), puis **900 mutations HTTP déterministes** contre la branche réelle.

Le mapper fictif d'erreur métier est explicitement déclaré **seulement dans l'application de test candidate**, selon le mécanisme public de `dev-1.0.1`. Le prototype `RealTest\Prototype\SafeNativeSubscriber` est **désactivé** dans les deux environnements. Le projet clean de comparaison ne contient aucun bundle Guardian.

## Résultats — 84 scénarios

| PHP | Symfony | PASS v1.0.0 | PASS dev | FAIL dev | NOT_RUN dev | Améliorations | Régressions |
|---|---|---:|---:|---:|---:|---:|---:|
| 8.3 | 5.4 | 349 | **409** | 30 | 65 | +60 | 0 |
| 8.3 | 6.4 | 361 | **421** | 30 | 53 | +60 | 0 |
| 8.3 | 7.4 | 361 | **421** | 30 | 53 | +60 | 0 |
| 8.4 | 7.4 | 361 | **421** | 30 | 53 | +60 | 0 |
| 8.4 | 8.1 | 361 | **421** | 30 | 53 | +60 | 0 |
| **Total** | | **1 793** | **2 093** | **150** | **277** | **+300** | **0** |

Il s'agit de 2 520 **évaluations de scénario**, pas de 2 520 scénarios distincts. Les 300 améliorations représentent **10 identifiants corrigés × 6 variantes × 5 combinaisons**.

### Améliorations effectives

Les dix cas devenus conformes (auparavant non satisfaits) :

- `BASE-04`, `BASE-05` : contrat d'erreur API approuvé, code métier `API_DOMAIN_ERROR` rétabli ;
- `SAFE-04`, `SAFE-05`, `SAFE-06` : interface mapper, registre et descripteur publics présents dans le code réel ;
- `HTTP-10`, `HTTP-11`, `HTTP-12` : erreurs neutres XML, Problem Details JSON et Problem Details XML ;
- `HTTP-13`, `HTTP-20` : gestion des pondérations `Accept` et formats explicitement demandés.

Le bundle conserve ses codes HTTP dans le jeu testé ; aucun secret factice d'exception n'a été retrouvé dans les réponses publiques observées.

### Ce qui reste ouvert

Les **150 FAIL** sont cinq cas distincts répétés sur les 30 combinaisons mode/debug/version : `AUTH-07` (révocation sélective des autres sessions) et `DOCTOR-01/02/03/05` (options et journalisation de diagnostic futurs). Il s'agit d'exigences de la matrice dont les fonctionnalités ne sont pas encore livrées, pas de vulnérabilités de sécurité confirmées.

Parmi les **277 NOT_RUN**, `HTTP-05` ne se mesure pas convenablement à travers `Kernel::handle` : il est **vérifié séparément au niveau du réseau réel** dans la même exécution CI. Les autres limites recouvrent notamment la gestion de panne, le protocole de désinstallation complète, certaines traductions et les scénarios nécessitant des composants de production.

## Contrôle de transport HTTP réel

**5/5 jobs PASS**. Les essais du script `bin/wire.php --strict` couvrent 12 variantes par couple PHP/Symfony (clean/guarded × modes erreur × debug), soit **60 variantes d'environnement**, avec :

- `HEAD /api/health` : 200, aucun corps transmis ;
- `HEAD /api/not-found` : 404, aucun corps transmis ;
- `OPTIONS /api/cors` : 204 et en-têtes CORS ;
- `GET /api/stream` : contenu transmis intact ;
- `GET /api/sse` : 10 événements ;
- accès anonyme au profiler refusé.

## Mutation HTTP / fuzzing

**4 500 PASS, 0 FAIL, 0 NOT_RUN** (5 versions × 900 mutations).

Corpus déterministe, graine `20261029` : méthodes, chemins techniques et métier, variantes d'encodage, entêtes de routage, jetons/cookies falsifiés, formats Accept et exceptions factices. Le test recherche les divergences de statut des routes métier, l'exposition d'un endpoint technique et la publication de marqueurs internes fictifs.

Un corpus de 4 500 cas ne constitue pas une preuve universelle d'absence de vulnérabilité. Les données sont synthétiques, les tests ne visent aucun système tiers.

## Verdict de ce lot

**RÉSULTAT POSITIF POUR LA NON-RÉGRESSION ET LA COMPATIBILITÉ DE SAFE NATIVE DANS LE PÉRIMÈTRE TESTÉ.**

La branche de développement réalise les améliorations attendues sur 10 scénarios sans régression par rapport à `v1.0.0`. Les essais HTTP et fuzzing sont verts. La **conformité globale aux 84 exigences n'est pas atteinte**, car des fonctionnalités prévues restent absentes et des scénarios ne sont pas exécutés.

**Recommandation :** garder `dev-1.0.1` non publiée. Traiter les fonctionnalités manquantes, puis renforcer les `NOT_RUN` sans affaiblir la politique de confidentialité.

**Aucune modification au dépôt source du bundle par ce lot de validation.**
