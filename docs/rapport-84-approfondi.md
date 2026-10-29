# Rapport d'audit approfondi — 84 scénarios et variantes HTTP

**Date :** 29 octobre 2026  
**Statut :** audit expérimental indépendant en environnement contrôlé ; **non conforme à la recette finale**, améliorations non livrées dans le bundle  
**Périmètre d'écriture :** uniquement `PsiGuardian/profiler-shield-real-test`, branche `feature/matrix-84-deep-validation`. **Le dépôt `PsiGuardian/profiler-shield` n'a pas été modifié.**  
**Bundle sous test :** version publiée `omegaguardian/profiler-shield v1.0.0` via Composer ; pas de scripts ni plugins d'installation.  
**Exigences :** [cahier des charges](cahier-des-charges.md), [matrice à 84 scénarios](matrice-de-tests.md).

## 1. Méthodologie et honnêteté des oracles

Trois niveaux complémentaires :

1. **Noyaux différentiels :** Symfony clean / Symfony+Guardian, mêmes contrôleurs, 84 identifiants de scénarios. Chaque scénario est demandé en `native/generic/custom` et avec `APP_DEBUG=0/1`, soit 504 évaluations par couple PHP/Symfony. Les résultats sont `PASS`, `FAIL`, `NOT_RUN` : un cas indisponible n'est jamais déclaré `PASS`.
2. **HTTP réel :** deux serveurs PHP internes par configuration, requêtes avec cURL (HEAD, streaming, SSE, CORS, profiler), afin de distinguer les erreurs de banc des erreurs réseau.
3. **Mutations reproductibles :** générateur de requêtes à graine fixe `20261029`, 22 chemins, 7 méthodes, 8 types `Accept`, 8 variantes d'en-têtes et 3 états de cookie Guardian, en tirage combinatoire borné ; assertion de non-divulgation, de refus des diagnostics et de stabilité des requêtes métier.

**Limite :** `toutes les variantes possibles` est une infinité non énumérable en pratique (en-têtes libres, corps arbitraires, exécutions concurrentes). Ce dispositif couvre une sélection large, *pas* tous les états théoriques du serveur HTTP.

## 2. Matrice des 84 scénarios

| PHP | Symfony | Évaluations | PASS | FAIL | NOT_RUN |
|---|---|---:|---:|---:|---:|
| 8.3 | 5.4 | 504 | 349 | 90 | 65 |
| 8.3 | 6.4 | 504 | 361 | 90 | 53 |
| 8.3 | 7.4 | 504 | 361 | 90 | 53 |
| 8.4 | 7.4 | 504 | 361 | 90 | 53 |
| 8.4 | 8.1 | 504 | 361 | 90 | 53 |
| **Total** | | **2520** | **1793** | **450** | **277** |

**Source vérifiable :** [GitHub Actions — matrice complète](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40801915182), artefacts JSON `matrix84-php*-symfony*`.

Ces nombres comptent des **évaluations** de scénarios sous plusieurs configurations, non des vulnérabilités distinctes. Un échec répété six fois est généralement une seule incompatibilité reproduite dans six états, pas six défauts distincts.

**Non-conformités P0 récurrentes :**

| Identifiant | Observation reproductible | Nature |
|---|---|---|
| BASE-04 | L'erreur métier JSON `422` perd le code public `API_DOMAIN_ERROR` | Incompatibilité de contrat |
| BASE-05 | Le client API de test ne peut plus exploiter ce code métier | Régression fonctionnelle |
| SAFE-04/05/06 | Interface mapper, registre et descripteur d'erreur publique non présents dans v1.0.0 | Fonctionnalités à implémenter, pas failles avérées |
| HTTP-05 | En mode **in-kernel** la transmission HEAD n'est pas mesurable | Non exécuté dans ce banc ; **validé séparément sur un serveur HTTP réel** |
| HTTP-10 | Une exception inconnue demandée en XML ne reçoit pas une représentation XML | Compatibilité MIME |
| HTTP-11/12 | `application/problem+json` et `application/problem+xml` RFC 9457 non disponibles | Fonctionnalités futures |
| HTTP-13 | Les facteurs `Accept;q=` ne sont pas respectés dans le cas testé | Négociation MIME |
| HTTP-20 | La politique format/Accept explicite n'est pas respectée dans le scénario expérimental | Compatibilité |

**P1 significatifs :** `AUTH-07` (révocation sélective des autres sessions non présente), `DOCTOR-01/02/03` (options `--http`, `--json`, `--strict` du futur doctor absentes), `DOCTOR-05` (journal d'intervention HTTP futur absent). Les cas `FAULT-04/05/07/09`, `DOCTOR-04/07/08` restent partiellement ou entièrement non exécutés. Les performances ont fait l'objet d'une sonde de 1000 requêtes en `native/debug=0` ; il ne s'agit pas encore d'un benchmark de charge complet. Les flux Symfony 5.4 internes non capturés ne sont **pas** déclarés des échecs Guardian.

### Sécurité observée

Sur les scénarios réellement passés, les messages internes synthétiques `GPS_PRIVATE_*` n'ont pas été retrouvés dans les réponses publiques contrôlées, les routes métiers normales continuent de répondre, les en-têtes `X-Debug` non autorisés sont supprimés et les chemins classiques du profiler refusent les visites anonymes. Cela ne prouve pas l'absence de toutes les vulnérabilités : tests proxy, fuite hors Symfony, charge longue et concurrence complexe restent des limites.

## 3. Vérification de transport HTTP réel

La campagne séparée [HTTP wire](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40801329159) a réussi **5/5 jobs**, chacun sur les trois modes d'erreur, deux états debug et deux noyaux, soit **60 sessions de serveur HTTP testées**. Les assertions incluent :

- `HEAD /api/health` 200 sans corps transmis ;
- `HEAD /api/not-found` 404 sans corps transmis ;
- `OPTIONS /api/cors` et son en-tête d'origine ;
- `GET /api/stream` avec octets attendus ;
- `GET /api/sse` avec dix événements ;
- refus du profiler sans autorisation dans le serveur protégé.

Cela lève **sur le transport réel** le doute associé à `HTTP-05`, `HTTP-17` et `HTTP-18` : l'absence de capture de flux in-kernel sous Symfony 5.4 était un défaut du banc, non une régression démontrée du bundle. Ces résultats sont un complément au rapport de matrice, et non une modification silencieuse des statuts historiques.

## 4. Mutations de sécurité

La campagne de mutation `bin/fuzz.php` est bornée à **900 requêtes par couple PHP/Symfony**, réparties sur les six configurations de mode/debug ; elle crée une fixture éphémère. Elle compare strictement les statuts des **routes métier** clean/protégées. Pour les **routes techniques**, les oracles sont le refus `403/404` et l'absence de fuite : une application clean sans profiler n'est pas un oracle fonctionnel valide pour les ressources du profiler. Cette séparation évite de confondre la protection intentionnelle avec une régression.

Graine reproductible : `20261029`.

**Résultat confirmé : 5/5 jobs réussis, 4 500 mutations exécutées, 4 500 PASS, 0 FAIL et 0 NOT_RUN.** Chaque couple PHP/Symfony a exécuté 900 variantes (150 par mode et état de débogage). Le corpus n'a révélé ni divergence interdite du statut métier, ni accès anonyme au profiler, ni fuite de sentinelle. Cela ne prouve pas l'absence de toute vulnérabilité. **Source :** [GitHub Actions — fuzzing validé](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40801988731), rapports JSON téléchargeables par version. Les anciennes exécutions de mise au point ne doivent pas être prises pour des régressions du bundle.

## 5. Risques techniques du laboratoire et corrections

Les premières itérations ont révélé des défauts d'instrumentation, corrigés uniquement ici :

- Contrainte Composer exacte refusée par `composer validate --strict` → contrainte SemVer.
- Autoload PSR-4 des fixtures incohérent → mappage corrigé.
- Dépendances Symfony YAML et Twig absentes du projet de test → ajoutées.
- Arguments mal ordonnés dans une ancienne fixture `HttpException` → correction.
- Sortie Symfony 5.4 polluant le JSON des résultats → isolation de stdout pour ne pas fabriquer de faux rapports.
- Chemin avec antislash rejeté par HttpFoundation avant d'atteindre Guardian → contrôle direct de la normalisation, aucun faux rapport de bypass.
- Routage différé Symfony dépendant d'une variable globale de fixture → sélection du noyau clean/guarded fixée avant chaque requête.
- Comparaison incorrecte de codes clean/protégés sur les routes du profiler → oracle distinct refus/absence de fuite.
- Test de transport séparé pour les requêtes HEAD et flux.
- Restauration des données MFA du compte factice après chaque scénario ; test d'anti-rejeu TOTP exécuté par le vrai portail.

Les rapports ne doivent **jamais** masquer un véritable problème en modifiant l'oracle pour forcer un feu vert. Chaque changement d'oracle doit être motivé par une distinction entre sémantique métier et transformation de sécurité attendue.

## 6. Définition du prochain palier

Ce dépôt sert de **laboratoire externe**, non de fork modifiant `profiler-shield`. Aucune correction au bundle n'est effectuée ici.

La conformité finale exigera : tous les P0 exécutés et satisfaits, fonctionnalités Safe Native effectivement implémentées dans une future version du bundle, continuité des contrats d'erreurs approuvées, fidélité des formats RFC 9457, sécurité des diagnostics, essais de concurrence/ressources, installation et désinstallation complètes, audit des traductions et contrôle de charge.

**Statut de publication : non conforme, revue en brouillon ; aucune fusion ni publication du bundle d'origine.**
