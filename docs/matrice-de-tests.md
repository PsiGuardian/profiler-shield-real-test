# Matrice de tests — Profiler Shield Real Test

**Référence :** GPS-RT-TEST-1.0 · **Date :** 29 octobre 2026 · **Statut :** à exécuter

Document dérivé du [cahier des charges](cahier-des-charges.md). **Aucun scénario ci-dessous n'est marqué comme réussi** par le seul fait de sa description.

## 1. Protocole commun

**A :** noyau Symfony clean ; **B :** même noyau et mêmes fixtures avec Guardian ; **C :** B avec données synthétiques adversariales (sentinelles factices). Données, horloge, versions, variables métier, seed et dépendances sont alignées. Chaque cas enregistre un identifiant de version PHP/Symfony, commit bundle, configuration, requête et résumé expurgé des réponses.

**Oracle de comparaison :** statut strict, schéma/format et champs métier selon contrat, headers protocolaire stricts, cookies/redirects selon scénario, effets base/état, latence et absence de secrets. Les différences autorisées sont définies pour chaque famille : suppression d'un header X-Debug pour anonyme, page neutre pour exception inconnue, HTML generic/custom explicitement configuré. **Toute différence non prévue P0 = échec bloquant.**

**Classification :** P0 bloque la fusion ; P1 bloque la release selon son impact et les règles de sortie du lot ; SKIP doit être motivé et ne compte jamais comme PASS. Un résultat `NOT_RUN` n'est pas une preuve de conformité. Les tests de sécurité manipulent seulement des secrets fictifs et des installations isolées.

**Sortie machine prescrite :** `test_id`, `priority`, `php_version`, `symfony_version`, `guardian_commit`, `status` parmi PASS/FAIL/SKIP/NOT_RUN, `assertions`, `expected`, `observed_redacted`, `artifact_ref`. La CI quitte avec un code non nul si un P0 échoue ou manque d'exécution, excepté une exclusion de version explicitement documentée.


## BASE — Référence clean et reproduction

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **BASE-01** | **Installation de référence** — Symfony clean et Guardian sur mêmes dépendances et fixtures | Exécuter une requête GET /api/health sur A et B | 200, corps, headers et effets identiques hors retrait X-Debug-* | statut, données ou effet métier différents |
| **BASE-02** | **Réponse JSON métier 422** — Route de validation retourne JsonResponse structuré sans exception | GET /api/validation-response | 422, schéma code/fields/message identique et JSON valide | JSON modifié, champ absent ou statut changé |
| **BASE-03** | **Réponse XML métier 422** — Route XML déjà construite | GET Accept application/xml | 422, XML strictement équivalent et Content-Type adéquat | HTML/JSON substitué ou XML invalide |
| **BASE-04** | **Erreur métier levée 422** — Exception métier + listener API custom sur A ; mapper public prévu sur B | Déclencher la validation par exception | code métier et champs allowlist restitués, sans trace | code métier écrasé ou message privé exposé |
| **BASE-05** | **API consommateur** — Client test attend code métier API_DOMAIN_ERROR | Exécuter client sur A puis B | Le client réalise les mêmes transitions métier | client échoue uniquement avec Guardian |
| **BASE-06** | **Réponse 429 construite** — Contrôleur renvoie Retry-After=37 et JSON | GET /api/rate-response | 429, Retry-After et code métier inchangés | en-tête perdu ou corps altéré |
| **BASE-07** | **Méthode POST interdite** — Route GET seule | POST /api/health | 405, Allow correct, aucun changement métier | mauvais statut ou Allow perdu |
| **BASE-08** | **Erreurs HTTP créées par router** — Routes inconnues, méthodes refusées | GET /unknown et OPTIONS route GET | 404/405, fallback sûr, absence de marqueurs internes | statut changé, sentinelle ou trace publique |

## SAFE — Exceptions, sécurité et contrats

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **SAFE-01** | **RuntimeException interne** — Throwable contient SENTINEL_PRIVATE_RUNTIME | Déclencher GET /api/throw/fatal non connecté | 500, corps neutre, aucune sentinelle sur contenu et headers | texte interne accessible |
| **SAFE-02** | **Erreur SQL simulée** — Exception texte contient DSN/SQL fictif | Déclencher exception 500 | 500, aucun DSN, nom de table, mot de passe ou chemin | fuite d'une sentinelle |
| **SAFE-03** | **Exception inconnue 4xx** — HttpException 422 avec secret dans message | GET /api/throw/untrusted-validation | 422, contenu neutre, message interne absent | message de l'exception publié |
| **SAFE-04** | **Erreur publique validée** — Mapper allowlist code PRODUCT_OUT_OF_STOCK | GET /api/stock/missing | 409, code public déclaré, pas de champ privé | code manquant ou champ privé transmis |
| **SAFE-05** | **Mapper malveillant ou invalide** — Mapper renvoie un header interdit ou donnée non conforme | Déclencher mapper dans fixture hostile | Fallback neutre, aucun secret ni header interdit | header privé ou corps brute transmis |
| **SAFE-06** | **Mapper qui lève Throwable** — Mapper lance RuntimeException interne | Exécuter route associée | Fallback sûr, statut attendu, absence d'exception secondaire publique | double-fault, message divulgué, 500 injustifié |
| **SAFE-07** | **Exception imbriquée** — Throwable::getPrevious et trace ont sentinelles | Déclencher erreur publique | Aucune sentinelle dans HTML/JSON/XML/headers | chaîne de cause visible |
| **SAFE-08** | **Réponse construite avec X-Debug** — Un contrôleur retourne JSON métier + X-Debug-Token | Appel anonyme | Corps inchangé, header technique expurgé | header conservé ou JSON altéré |
| **SAFE-09** | **Utilisateur autorisé** — Compte/sessions/policy Guardian pleinement valides | Demander page d'exception en dev | Diagnostic disponible selon configuration | droit inutilement refusé ou accès public ouvert |
| **SAFE-10** | **Panne du coffre pendant exception** — Répertoire vault non lisible | Appeler une route qui lève une exception | Diagnostics refusés, fallback sûr, pas d'exception secondaire publique | fuite ou 2xx erroné |
| **SAFE-11** | **Mode HTML native** — Page 404 host sûre avec contrat HTML | GET /missing Accept text/html | Page publique host conservée lorsque contrôlée et sûre, statut 404 | page métier sûre modifiée inutilement |
| **SAFE-12** | **Modes generic/custom** — Configurer mode + texte personnalisé non dangereux | GET /missing Accept text/html | HTML neutre/personnalisé échappé, statut réel | XSS ou modification du statut |
| **SAFE-13** | **Texte public contenant HTML** — error_custom_text contient <script> fictif | Déclencher exception HTML | Script échappé, CSP cohérente | exécution d'injection |
| **SAFE-14** | **Policy MFA et préexistence** — Compte MFA activé avec session courante | Inscrire TOTP, continuer session | Session courante valide, MFA future effective selon politique | déconnexion courante non voulue ou accès bypass |

## HTTP — Méthodes, formats, protocoles

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **HTTP-01** | **Codes exacts** — Exceptions 400 401 403 404 405 409 422 429 500 503 | Déclencher chaque statut | Statut inchangé, aucun 200 masquant erreur | au moins un statut changé |
| **HTTP-02** | **WWW-Authenticate** — HttpException 401 avec Bearer realm de test | GET route protégée | 401 + WWW-Authenticate validé | header absent ou statut erroné |
| **HTTP-03** | **Retry-After** — HttpException 429 avec Retry-After=37 | Déclencher throttle | 429 et 37 conservés | header perdu |
| **HTTP-04** | **Allow** — HttpException 405 avec Allow=GET, HEAD | POST route GET | 405 et header validé | Allow supprimé |
| **HTTP-05** | **HEAD** — Route HEAD et GET, exception 404 | HEAD /api/missing | Statut correct, corps HTTP transmis vide, headers conformes | corps transmis ou statut altéré |
| **HTTP-06** | **204/304 sans corps** — Fixtures explicitement retournent 204, 304 | GET fixtures | Aucun corps et headers cohérents | corps inséré |
| **HTTP-07** | **OPTIONS CORS preflight** — API hôte a preflight OPTIONS + autorisation origine fictive | OPTIONS Origin + ACR-Method | statut, CORS et Vary de l'hôte conservés | preflight modifié ou headers perdus |
| **HTTP-08** | **CORS d'une erreur publique** — Mapper explicitement approuve Access-Control-Allow-Origin | GET route publique qui lève 422 | Header CORS explicitement déclaré conservé | header supprimé ou origine non approuvée injectée |
| **HTTP-09** | **JSON Accept simple** — Client envoie application/json | Déclencher exception inconnue | JSON syntaxiquement valide et Content-Type JSON | HTML transmis |
| **HTTP-10** | **XML Accept** — Client envoie application/xml | Déclencher exception inconnue | XML bien formé, statut préservé, MIME XML | HTML, JSON ou XML invalide |
| **HTTP-11** | **Problem JSON** — Accept application/problem+json | Déclencher exception inconnue | RFC 9457, status correct, title sûr | trace, mauvais MIME ou status interne différent |
| **HTTP-12** | **Problem XML** — Accept application/problem+xml | Déclencher exception inconnue | Problem XML conforme sans entité externe | mauvais MIME ou fuite |
| **HTTP-13** | **Accept qualité q** — Accept text/html;q=0.1,application/json;q=0.9 | Déclencher 500 | JSON choisi si aucune contrainte route contraire | HTML choisi à tort |
| **HTTP-14** | **Accept wildcards** — Accept */* et application/*+json | Déclencher erreurs connues/inconnues | Fallback déterministe et format validé | choix instable ou réponse non parsable |
| **HTTP-15** | **Longueur ETag compression** — Réponse HTML modifiée et headers initiaux longueur/etag/encoding | Activer generic/custom | Aucun header périmé après changement de contenu | ETag ou longueur invalides |
| **HTTP-16** | **Redirections et cookies** — Route 303 avec Location et Set-Cookie de test | POST /api/session/redirect | 303, Location, cookie intacts hors politique existante | mutation non prévue |
| **HTTP-17** | **Streaming** — StreamedResponse et téléchargement binaire | Lire flux depuis A/B | octets et headers identiques hors métadonnées techniques | flux corrompu ou bloqué |
| **HTTP-18** | **SSE** — Route text/event-stream simulée | Consommer 10 événements | événements et durée de streaming cohérents | buffering/réécriture introduits |
| **HTTP-19** | **Mode custom sur API** — error_mode=custom, API JSON 422 | GET /api/validation-response | JSON métier intact, aucune page HTML | API remplacée par page custom |
| **HTTP-20** | **Content-Type explicite de route** — RequestFormat JSON/XML + Accept contradictoire | Tester les préférences documentées | Format conforme à la politique de négociation publiée | substitution arbitraire |

## PROF — Profiler, collecte et accès

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **PROF-01** | **Chemins standards** — Visiteur anonyme | GET /_profiler et /_wdt | 403/404 sans token ni détails | profil accessible |
| **PROF-02** | **Double encoding** — Variantes %5fprofiler, %255fprofiler, séparateurs et index.php | Rejouer corpus borné | Aucun 200 de diagnostic non autorisé | contournement |
| **PROF-03** | **Sous-chemin légitime** — Route métier /docs/_profiler/guide | GET route métier | route non bloquée par simple substring | faux positif |
| **PROF-04** | **Session Guardian expirée** — Cookie valide mais session expirée | GET /_profiler | Refus strict | accès autorisé |
| **PROF-05** | **Compte désactivé** — Session existante, compte désactivé | GET /_profiler | Refus immédiat | profiler visible |
| **PROF-06** | **IP non autorisée** — Client hors allowlist | GET /_profiler | Refus | accès accordé |
| **PROF-07** | **TimeLock et JIT** — Horaire fermé/JIT absent | GET /_profiler | Refus | accès accordé |
| **PROF-08** | **Lockdown** — Verrouillage actif | GET /_profiler puis /api/health | profiler refusé, API 200 | API métier interrompue |
| **PROF-09** | **Pont désactivé** — GUARDIAN_COLLECTION_BRIDGE=0 | Requête anonyme de fixture | Aucun profil collecté si framework collect=false | collecte |
| **PROF-10** | **Pont activé anonyme** — Bridge=1, cookie absent | Requête métier puis toolbar | Aucun token, profil ni toolbar | collecte publique |
| **PROF-11** | **Pont activé autorisé** — Bridge=1, policy validée | Requête métier et accès profil | Collecte possible uniquement autorisé | collecte impossible ou ouverte à tous |
| **PROF-12** | **Logout/expiration** — Collecte autorisée puis logout | Requête anonyme successive | Collecte coupée et tokens effacés | collecte persistante |
| **PROF-13** | **Ordre listeners** — Toutes versions supportées | debug:event-dispatcher + test HTTP | Ordre et confidentialité mesurés et cohérents | ordre inconnu mais pont activé |
| **PROF-14** | **Production** — APP_ENV=prod, APP_DEBUG=0 | Boot et demande routes diagnostic | Profiler/collecte absents, aucune exposition | profiler actif |

## AUTH — Auth, MFA, cookies et contrôle

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **AUTH-01** | **Connexion valide** — Compte de test local Guardian | Login puis route autorisée | Session créée et accès selon policy | session refusée sans motif |
| **AUTH-02** | **Échec login** — Identifiants invalides | Répéter dans limites puis au seuil | throttle appliqué, aucun énumération de comptes | contournement du seuil |
| **AUTH-03** | **CSRF login** — Cookie/token absent ou faux | POST login | Refus sans session | session créée |
| **AUTH-04** | **CSRF admin** — Session admin, token faux | POST action réglage | Aucune modification de settings | mutation effective |
| **AUTH-05** | **TOTP anti-replay** — OTP valide utilisé une fois | Réutiliser même OTP | Réutilisation rejetée | OTP accepté deux fois |
| **AUTH-06** | **Session MFA en cours** — Session existante, inscription MFA réussie | Enrôler et continuer la navigation | Session actuelle conservée | logout imposé |
| **AUTH-07** | **Révocation autres sessions** — Deux sessions, action opt-in | Révoquer les autres avec reauth | autre session refusée, actuelle valide | déconnexion de la courante ou session distante valide |
| **AUTH-08** | **Changement mot de passe** — Deux sessions actives | Modifier mot de passe | sessions anciennes révoquées selon politique publiée | ancien cookie garde droits indus |
| **AUTH-09** | **Cookie protection** — HTTPS et réponse login | Vérifier Secure,HttpOnly,SameSite,__Host- | attributs robustes | cookie insuffisamment protégé |
| **AUTH-10** | **Appareil mémorisé** — Cookie trusted-device | Tester reconnexion après expiration session | ne suffit jamais seul à être authentifié | bypass du login |

## FAULT — Défaillances et opérations

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **FAULT-01** | **Vault inaccessible** — Rendre vault illisible en fixture | GET /api/health, puis /_profiler | 200 API, profiler refusé | API 500 ou profiler ouvert |
| **FAULT-02** | **Clé de chiffrement invalide** — Modifier la clé de test | Demander diagnostic puis requête métier | diagnostic fermé, métier non dépendant intact | secret déchiffré ou API cassée |
| **FAULT-03** | **Fichier corrompu** — Écrire faux JSON/valeurs hors schéma | Relire settings et session | refus sûr, aucune exception publique sensible | fuite ou fail-open |
| **FAULT-04** | **Rendu d'erreur défaillant** — Simuler erreur dans renderer | Déclencher 500 | fallback neutre sans boucle | récursion/double exception |
| **FAULT-05** | **Écriture audit indisponible** — Forcer défaut I/O audit | Requête métier + intervention | comportement conforme invariant sécurité, pas d'injection | erreur de site non justifiée |
| **FAULT-06** | **Charge modérée** — 1000 requêtes locales contrôlées | Comparer p50,p95,p99 et RAM A/B | pas de fuite ni dégradation non expliquée | latence excessive persistante, mémoire non bornée |
| **FAULT-07** | **Concurrence** — Sessions, login et changements simultanés | Exécuter parallèlement | cohérence stockage, pas de replay | race condition exploitable |
| **FAULT-08** | **Installation sans scripts** — Composer install --no-plugins --no-scripts | Installer clean puis bundle | aucun effet secondaire implicite | modifications à l'installation |
| **FAULT-09** | **Uninstall réversible** — Installation et données factices | Désinstaller sans purge | fichiers hôte modifiés conservés, contrôle explicite | perte de données |
| **FAULT-10** | **Production et dépendances** — Variantes php et symfony de matrice | composer validate, audit, test | résolution et tests OK | version non compatible annoncée |

## DOCTOR — Doctor, observabilité et documentation

| ID | Test et préconditions | Action | Résultat attendu / oracle | Échec explicite |
|---|---|---|---|---|
| **DOCTOR-01** | **Doctor HTTP nominal** — Fixture valide | guardian:doctor --http | codes stables PASS/WARN/FAIL/SKIP | résultat inexploitable |
| **DOCTOR-02** | **Doctor JSON** — Même fixture | guardian:doctor --http --json | JSON parsable, schéma stable, aucun secret | champ secret ou JSON invalide |
| **DOCTOR-03** | **Doctor erreur** — Profiler dangereux en prod | doctor --http --strict | exit non nul et diagnostic explicite | exit 0 alors qu'invariant P0 violé |
| **DOCTOR-04** | **Doctor sans réseau** — Sortie réseau bloquée | doctor --http | réussite sans requête externe | connexion tentée |
| **DOCTOR-05** | **Audit intervention** — Exception neutralisée puis réponse saine | Inspecter logs après chaque | un événement normalisé utile seulement pour intervention | corps/cookie/token/secret journalisé |
| **DOCTOR-06** | **Audit rétention** — Evénements > quota et âge maxi | Purger | limites de conservation respectées | croissance non bornée |
| **DOCTOR-07** | **Docs 28 locales** — Générateur et catalogues existants | audit de traduction et docs | liens complets, chaînes présentes, modifications manuelles préservées | fichiers supprimés recréés ou liens brisés |
| **DOCTOR-08** | **Diff release** — Comparer bundle avant/après mise à jour | Tester settings, coffre, commandes, cookies | migration et rollback documentés, aucun écrasement | migration destructive |

## 2. Matrice d'exécution obligatoire

| PHP | Symfony | Modules obligatoires |
|---|---|---|
| 8.3 | 5.4 | BASE, SAFE, HTTP, PROF, AUTH, FAULT, DOCTOR |
| 8.3 | 6.4 | identique |
| 8.3 | 7.4 | identique |
| 8.4 | 7.4 | identique |
| 8.4 | 8.1 | identique |

Les combinaisons supplémentaires ne sont admises qu'après résolution Composer vérifiée. Chaque ligne est exécutée avec `APP_DEBUG=0` et `1` quand applicable, anonyme/autorisé pour PROF et `native`/`generic`/`custom` pour les familles SAFE/HTTP pertinentes. Les tests prod simulés imposent `APP_DEBUG=0`.

## 3. Jeux de données et indicateurs

- **Secrets fictifs :** `GPS_PRIVATE_SQL_SENTINEL_2026`, `GPS_PRIVATE_ENV_SENTINEL_2026`, `GPS_PRIVATE_PATH_SENTINEL_2026`, `GPS_PRIVATE_TOKEN_SENTINEL_2026` ; générer les valeurs aléatoirement pour éviter les faux positifs.
- **Erreurs publiques validées :** `API_DOMAIN_ERROR`, `PRODUCT_OUT_OF_STOCK`, `VALIDATION_FAILED`.
- **États :** client non connecté, session Guardian autorisée/expirée/désactivée, JIT actif/inactif, heures fermées/ouvertes, IP acceptée/refusée, MFA enrôlée/non enrôlée.
- **Tolérance métier :** strictement zéro différence non approuvée des effets, codes et des champs contractuels.
- **Tolérance sécurité :** strictement zéro occurrence de sentinelle dans body, en-têtes et pages publiquement visibles.
- **Tolérance performance :** mesurer p50/p95/p99 et ressources sur minimum 3 répétitions après warmup ; signaler une régression p95 de plus de 10 %, réexécuter avant décision bloquante.
- **Protection du banc :** journaux expurgés, faux comptes, coffre éphémère, aucune URL externe, aucun paiement réel.

## 4. Exemples d'assertions bloquantes (pseudocode)

```php
assertSame(422, $response->getStatusCode());
assertSame('API_DOMAIN_ERROR', $json['error']['code']);
assertArrayHasKey('fields', $json['error']);
assertStringNotContainsString($privateSentinel, $response->getContent());
assertFalse($response->headers->has('X-Debug-Token'));
assertSame('37', $rateLimitResponse->headers->get('Retry-After'));
```

Ne jamais utiliser une simple assertion « status = 422 » pour prétendre à la préservation d'un contrat API : vérifier également type MIME, structure, champs et comportement du client.

## 5. Traçabilité cahier des charges → tests

| Exigence | Tests de recette |
|---|---|
| INV-01, AC-01 | SAFE-01 à SAFE-13, PROF-01, PROF-02, DOCTOR-05 |
| INV-02, AC-02 | BASE-01 à BASE-08, HTTP-01 à HTTP-20 |
| INV-03/04, AC-02 | BASE-01/02/03/06, HTTP-16 à HTTP-19 |
| INV-05/06, AC-03/04 | BASE-04/05, SAFE-03 à SAFE-07, HTTP-09 à HTTP-14 |
| INV-07/08, AC-05/06 | PROF-01 à PROF-14, FAULT-01 à FAULT-05 |
| INV-09, AC-08 | FAULT-08 à FAULT-10 |
| INV-10 | AUTH-01 à AUTH-10 |
| INV-11, AC-09 | DOCTOR-07/08 |
| INV-12, AC-07 | totalité P0, sortie CI non nulle |
| Doctor / audit | DOCTOR-01 à DOCTOR-06 |
| Robustesse/performance | FAULT-01 à FAULT-10 |

## 6. Conditions d'arrêt et critères de livraison

**Arrêt immédiat de la validation** si une sentinelle interne est exposée publiquement, si un accès au profiler est possible sans autorisation, si un statut d'erreur est changé en succès, si la CI accepte un P0 en échec, ou si une action du banc touche des données réelles.

**Livrable acceptable :** 100 % des P0 exécutés et PASS sur chaque combinaison supportée ; aucune régression métier non autorisée ; P1 traités ou exception justifiée avec risque résiduel accepté ; journal CI reproductible ; contrôle des dépendances ; procédure de rollback ; documentation et traductions revues.

## 7. Statut initial de la campagne

**Tous les tests de cette matrice : NOT_RUN.** Les observations issues du banc de comparaison historique sont des éléments de motivation, pas des PASS sur cette nouvelle spécification. Le prochain développement consiste à matérialiser les fixtures, convertir ces scénarios en assertions exécutables, puis activer la CI multi-versions.

