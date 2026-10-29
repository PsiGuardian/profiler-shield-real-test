# Cahier des charges — ΩGuardian Profiler Shield Real Test

**Référence :** GPS-RT-SPEC-1.0 — **Date :** 29 octobre 2026  
**Statut :** spécification, aucune conformité présumée  
**Dépôt sous test :** [PsiGuardian/profiler-shield](https://github.com/PsiGuardian/profiler-shield)  
**Périmètre :** PHP 8.3/8.4, Symfony 5.4, 6.4, 7.4 et 8.1 lorsque supportés par les contraintes Composer.

## 1. Contexte

Profiler Shield protège le profiler, la barre d'outils Symfony et les diagnostics techniques, avec portail indépendant, MFA TOTP, sessions, restrictions IP/horaires/JIT, coffre chiffré, audit et modes d'erreur \`native\`, \`generic\`, \`custom\`.

Une campagne antérieure ([GitHub Actions 40779982345](https://github.com/PsiGuardian/profiler-shield/actions/runs/40779982345)) a comparé une API Symfony clean et une API avec Guardian. Les **statuts HTTP ont été conservés**, mais une exception métier 422 produisant un JSON structuré sous un gestionnaire applicatif était transformée en \`{"message":"Request failed"}\`, y compris en mode \`native\`. Le listener \`PrivacySubscriber\` intercepte \`kernel.exception\` à haute priorité ; modifier naïvement cette priorité risque d'exposer des traces en mode debug.

Un workflow terminé avec succès signifie seulement que le banc s'est exécuté ; la **matrice du présent dépôt** doit inclure des assertions bloquantes, pas seulement des rapports de différences.

## 2. Mission et objectifs

1. **Sécurité :** ne jamais exposer aux visiteurs non autorisés une trace, un secret, un chemin serveur, un message d'exception interne, une requête SQL sensible ou un jeton de profiler via les réponses contrôlées par Guardian.
2. **Compatibilité :** préserver les réponses métier normales et les erreurs publiques explicitement approuvées, les statuts HTTP et les en-têtes protocolaire nécessaires.
3. **Robustesse :** une panne du coffre ou du bundle ne doit pas ouvrir les diagnostics et ne doit pas casser arbitrairement les routes métier.
4. **Observabilité :** diagnostiquer et journaliser l'intervention du bundle sans enregistrer de secrets.
5. **Reproductibilité :** démontrer les propriétés sur plusieurs branches Symfony, via des tests indépendants, déterministes et rejouables.

**Limites :** les données déjà divulguées directement par du code métier ou par Nginx/Apache/PHP-FPM, un proxy, un CDN, une extension ou un composant non intercepté ne sont pas automatiquement protégées par Guardian. Pas de prétention de sécurité absolue.

## 3. Principes invariants

| Identifiant | Règle impérative |
|---|---|
| INV-01 | Aucune information de diagnostic interne non approuvée dans la réponse publique Guardian |
| INV-02 | Code HTTP réel conservé (400–599), jamais converti automatiquement en 200 |
| INV-03 | Routes métier normales intactes, sauf suppression de métadonnées de diagnostic |
| INV-04 | Réponses d'erreur déjà construites préservées en \`native\`, hormis en-têtes sensibles |
| INV-05 | Exceptions inconnues : neutralisation systématique sans message original |
| INV-06 | Exceptions publiques : allowlist **déclarée dans le code fiable de l'hôte**, jamais confiance aveugle en \`getMessage()\` |
| INV-07 | Pas d'activation du profiler/collecteur en production |
| INV-08 | Échec de stockage : refus de diagnostics, pas de divulgation secondaire |
| INV-09 | Installation sans scripts/plugins Composer implicites ni télémétrie |
| INV-10 | Sessions MFA existantes conservées par défaut ; révocation des autres appareils volontaire |
| INV-11 | Respect des ajustements et suppressions documentaires existants |
| INV-12 | Chaque exigence P0 possède un test automatiquement **bloquant** |

## 4. Comportement par défaut — Safe Native

**Deux axes indépendants** :

- \`exception_policy=safe_native\` : autorise une représentation métier uniquement si un contrat public sûr est explicitement enregistré ; sinon fallback neutre.
- \`error_mode=native|generic|custom\` : décide seulement de l'apparence de la page HTML, sans autoriser les données techniques.

| Cas | Attendu |
|---|---|
| \`200\`, \`201\`, \`204\`, JSON, XML, HTML métier | inchangés |
| Erreur \`422\` déjà construite en JSON | corps, statut et contrat public conservés |
| Exception \`422\` connue avec mapper public validé | code métier, statut et forme déclarée conservés |
| Exception \`422\` inconnue | statut 422 et erreur sûre générique |
| Exception \`500\` interne | statut 500 et message neutre, sans trace |
| API déclarée JSON/XML/Problem Details | MIME de la réponse de secours adapté au format demandé/contractuel |
| Erreur HTML en \`generic\`/\`custom\` | remplacement de présentation autorisé uniquement sur cible HTML |
| Diagnostic sans session valide | accès refusé, tokens et headers supprimés |
| Incident Guardian | diagnostics fermés, métier fonctionnel autant que possible |

**Compatibilité ascendante :** ne pas modifier le fonctionnement des installations existantes sans migration explicitement documentée. Si aucun mapper public n'est installé, conserver le fallback sécurisé actuel, avec corrections de MIME et des en-têtes justifiées par les tests.

## 5. Contrats publics d'erreur

Créer des composants PHP de responsabilité séparée :

- \`ExceptionPolicy\` : classifier les exceptions et décider de l'accès au diagnostic.
- \`PublicErrorMapperInterface\` : interface explicite \`supports(Throwable, Request)\` / \`map(...)\`.
- \`PublicErrorDescriptor\` : valeur immuable comprenant statut HTTP, code métier public, message public déclaré, champs et headers autorisés, format.
- \`PublicErrorRegistry\` : enregistrer les mappers via services DI sans autodécouverte des exceptions publiables.
- \`PublicErrorRenderer\` : sérialiser des descripteurs validés, sans recevoir de message d'exception arbitraire.

La validation porte sur statut, formats, champs, tailles, encodage et en-têtes. Un mapper qui lève une exception, produit une structure invalide ou s'exécute trop longtemps ne doit jamais provoquer de publication d'une trace. Les mappers représentent une **frontière explicite de confiance du code hôte** : un mapper délibérément dangereux ne peut être rendu sûr par une simple regex.

Le bundle ne doit rendre ni API Platform ni Serializer ni Twig obligatoires à l'exécution.

## 6. Compatibilité HTTP et formats

- **Statuts** : 200/201/204, 301/302/303, 400/401/403/404/405/409/422/429/500/503 ; aucune mutation non justifiée.
- **Formats** : JSON, XML, HTML, \`application/problem+json\`, \`application/problem+xml\` (RFC 9457), erreurs neutres et contrats publics déclarés.
- **Accept** : pondérations \`q\`, \`*/*\`, \`+json\`, \`+xml\`, route déclarée, \`Vary\` lorsque nécessaire.
- **Méthodes** : GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS ; preflight CORS ; pas de corps \`HEAD\` ou statut sans contenu.
- **Headers** : conserver \`Allow\`, \`Retry-After\`, \`WWW-Authenticate\` ; préserver les headers métier explicitement sûrs et les CORS d'un contrat fiable. Ne pas recopier aveuglément des headers d'une exception.
- **Intégrité** : recalculer/supprimer \`Content-Length\`, \`Content-Encoding\`, \`ETag\`, \`Last-Modified\` si le corps est remplacé ; ne pas altérer \`Set-Cookie\` ou \`Location\` en l'absence d'intervention.
- **Streaming** : \`StreamedResponse\`, SSE, téléchargements et réponses binaires ne doivent pas être capturés/réécrits en l'absence d'erreur.

## 7. Architecture

Séparer sans créer obligatoirement plusieurs packages :

**Guardian Security Gateway** (autorisation profiler, identité, IP, horaires, JIT, MFA, verrouillage, collecte) ; **Diagnostic Protection** (suppression métadonnées et exceptions internes) ; **HTTP Compatibility** (contrats publics, format, MIME, statut, headers) ; **Intervention Audit** (métadonnées expurgées) ; **HTTP Doctor** (diagnostic local).

Conserver les protections prioritaires sur \`kernel.exception\` : **ne jamais** simplement décaler le listener derrière la page d'exception debug de Symfony. Tester l'ordre effectif des subscribers à chaque version.

## 8. Nouveaux outils

### \`guardian:doctor --http\`

- Analyse versions, \`APP_ENV\`, \`APP_DEBUG\`, collecteur, toolbar, règles de routing, pont de collecte, listeners et priorités.
- Vérifie contrats de mappers, configuration et permissions coffre **sans révéler la clé**.
- Rapports texte et \`--json\` avec identifiants stables et états \`PASS\`/\`WARN\`/\`FAIL\`/\`SKIP\`.
- Aucun HTTP sortant ou écriture métier par défaut ; tests actifs seulement en fixture ou mode explicite strictement borné.
- Codes de sortie : \`0\` OK ; \`1\` FAIL ; \`2\` erreur du diagnostic ; \`--strict\` transforme WARN en échec.

### Journal d'intervention

Événement unique par intervention pertinente : catégorie, timestamp, identifiant aléatoire, statut/MIME avant-après, code de décision ; rétention bornée, aucun cookie, token, corps, IP brute inutile, secret, SQL ni trace. Aucun service distant par défaut.

### MFA/sessions

Maintenir la session actuelle lors d'enrôlement TOTP, garder les autres sessions ouvertes par défaut, proposer la révocation volontaire des autres sessions après ré-authentification et journalisation. Vérifier la promesse « appareil reconnu » ; ne jamais la confondre avec une preuve d'authentification.

### Collecte profiler

\`framework.profiler.collect=false\` de base ; le pont facultatif doit être validé pour chaque version dans l'ordre effectif des listeners. Aucune collecte sans session/politique valide ; désactivation en prod. Si le pont n'a pas fait l'objet de tests positifs et négatifs, il reste désactivé.

## 9. Infrastructure expérimentale

Créer deux applications équivalentes :

- **A clean** : installation Symfony sans Guardian, contrôleurs, listeners, Serializer/Twig facultatifs et API métier complète.
- **B guarded** : même état et mêmes dépendances, mais avec Guardian installé/configuré. Matériel de test simulé exclusivement.

L'API de test simule catalogue, utilisateurs fictifs, commandes, stocks, paiements fictifs, erreurs de validation, authentification et concurrence. Des clients JSON/XML vérifient leur propre fonctionnement au-delà des seuls statuts HTTP.

Une troisième fixture **C hostile** injecte des sentinelles dans des exceptions et des en-têtes afin de prouver l'absence de publication, y compris en debug et en cas de panne.

Les différences sont classées **autorisées**, **non autorisées**, **indécidables** ; la CI échoue pour toute différence P0 non autorisée, aucune comparaison « seulement informative » ne valide la recette.

## 10. Stratégie de tests

**Unitaire :** décision, mappers, validation, négociation MIME, RFC 9457, headers, logs.

**Intégration :** kernels réels avec/sans Guardian ; gestionnaires d'exception personnalisés ; routage, sessions, autorisation, collecte ; versions Symfony multiples.

**E2E :** application métier, client consommateur, persistance, redirections, cookie, API, effets de bord, installation/désinstallation.

**Robustesse :** panne de stockage, clé invalide, changement de droits, fichier corrompu, concurrence, entrées malformées.

**Sécurité :** sentinelles de secrets, refus d'accès aux chemins diagnostics et variantes encodées, cookies expirés, contournement IP, CSP, anti-CSRF, MFA et throttle.

**Performance :** p50/p95/p99, CPU/mémoire/I/O après warmup, répétitions, comparaison baseline ; signaler >10 % de régression p95 et confirmer statistiquement.

**Isolation :** aucun test destructif ni fuzzing volumétrique sur un tiers, seulement sur fixtures contrôlées.

Consulter la [matrice numérotée](matrice-de-tests.md) pour les préconditions, actions, assertions, oracles, sévérités et conditions d'échec.

## 11. Paliers et livrables

| Lot | Livrable | Entrée / sortie |
|---|---|---|
| L0 | Cahier des charges et matrice de recette | scénario P0 = oracle et test définis |
| L1 | App clean + guarded + clients ; comparateur strict | la CI échoue à la moindre rupture P0 |
| L2 | Safe Native et contrats d'erreurs publiques | conformité confidentialité/contrat |
| L3 | XML/JSON/RFC 9457, statuts, headers, CORS | tests de négociation, méthodes et clients |
| L4 | Doctor HTTP sécurisé, sortie JSON et CLI | tests de diagnostic et code retour |
| L5 | Audit d'intervention sans secrets | tests fuite/rétention/I/O |
| L6 | MFA/appareils/collecte multi-versions | tests d'accès complets |
| L7 | Régression, performances, documentation FR/EN et 28 locales UI | recette intégrale et rollback |

Pas de fusion ni publication automatisée du bundle depuis ce dépôt de test.

## 12. Acceptation et qualité

- **AC-01** : 100 % des sentinelles internes absentes des sorties publiques contrôlées.
- **AC-02** : zéro différence non approuvée de statut, headers, contrat d'erreur ou effets métier.
- **AC-03** : codes/structures des erreurs explicitement approuvées préservés.
- **AC-04** : exceptions inconnues neutralisées avec MIME contractuel.
- **AC-05** : aucun accès/collecte profiler non autorisé.
- **AC-06** : panne Guardian fermée pour diagnostic mais non destructive pour métier.
- **AC-07** : assertions **bloquantes** avec traces de preuve et baseline clean.
- **AC-08** : compatibilité ascendante, installation/retrait, retour arrière.
- **AC-09** : documentation cohérente et traductions contrôlées.
- **AC-10** : tests sur la matrice PHP/Symfony prise en charge, Composer/CI et audit des dépendances.

**Définition de terminé :** tous les P0 sont verts sur toutes les combinaisons supportées ; tests P1 pertinents réussis, anomalies résiduelles documentées ; recette indépendamment revue. Les résultats historiques sont des observations, pas des preuves automatiques de cette nouvelle spécification.

## 13. Gouvernance, intégrité et confidentialité

Dépôt public : uniquement faux secrets, fausses données et environnements jetables ; aucun compte réel. Les tests produisent des rapports sans traces privées, sans stockage de dumps de production. Les modifications restent exclusivement dans ce dépôt de test jusqu'à une décision de portage explicite vers le bundle.

**Références :** [Symfony Kernel Events](https://symfony.com/doc/current/reference/events.html), [Profiler](https://symfony.com/doc/current/profiler.html), [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html), code source du bundle et campagne expérimentale citée ci-dessus.
