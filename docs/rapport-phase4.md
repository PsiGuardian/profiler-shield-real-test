# Rapport phase 4 — Cycle de vie, authentification et injections de pannes

**Date :** 29 octobre 2026  
**Statut :** 5/5 matrices conformes sur les **nouvelles suites ciblées**, pas de conformité globale des 84 scénarios du cahier des charges.  
**Périmètre d'écriture :** **exclusivement** `PsiGuardian/profiler-shield-real-test`, branche `feature/fault-auth-lifecycle-v4`.  
**Logiciel testé :** package **inchangé** `omegaguardian/profiler-shield v1.0.0`, installé sans plugins ni scripts Composer.

**Preuve :** [GitHub Actions — exécution n° 40804297731](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40804297731).

## 1. Résultats consolidés

| PHP | Symfony | Installation/retrait | Authentification complète | Fault injection | Total |
|---|---|---:|---:|---:|---:|
| 8.3 | 5.4 | 6/6 | 10/10 | 7/7 | **23/23** |
| 8.3 | 6.4 | 6/6 | 10/10 | 7/7 | **23/23** |
| 8.3 | 7.4 | 6/6 | 10/10 | 7/7 | **23/23** |
| 8.4 | 7.4 | 6/6 | 10/10 | 7/7 | **23/23** |
| 8.4 | 8.1 | 6/6 | 10/10 | 7/7 | **23/23** |
| **Total** | | **30/30** | **50/50** | **35/35** | **115/115** |

Les 23 scénarios sont **distincts** et exécutés cinq fois avec des versions Symfony/PHP différentes ; les 115 résultats ne constituent pas 115 vulnérabilités testées indépendamment. Chaque exécution rapporte un objet JSON conservé dans les artefacts de CI (`lifecycle.json`, `auth_e2e.json`, `fault_injection.json`).

## 2. Cycle de vie réel du bundle (`bin/lifecycle.php`)

| Identifiant | Fonction testée | Résultat |
|---|---|---|
| LIFE-01 | `SetupCommand` réel sur projet fictif, manifest de réception, `Uninstaller` sans purge ; préservation de clé, coffre chiffré, fichiers métier et réplication de désinstallation | PASS |
| LIFE-02 | Les routes et fichiers profiler modifiés manuellement **restent** présents lors du retrait | PASS |
| LIFE-03 | Purge **explicitement demandée** et suppression du coffre et des clés *gérés*, sans effacer le fichier métier ou les paramètres propres à l'hôte | PASS |
| LIFE-04 | Fichier inconnu dans le coffre → refus de la purge destructive ; conservation de la clé et du fichier tiers | PASS |
| LIFE-05 | Reçu d'installation pointant vers un lien symbolique → rejet avant suppression ; fichier cible intact | PASS |
| LIFE-06 | `APP_ENV=prod` → `guardian:uninstall` refuse l'exécution | PASS |

**Limite :** le scénario exécute les véritables classes de setup et d'uninstall dans un projet Symfony jetable, mais **n'exécute pas `composer remove`** ; il ne certifie pas l'ensemble des effets Composer/Flex ni le comportement sur un projet tiers déjà configuré.

## 3. Authentification HTTP réelle (`bin/auth_e2e.php`)

| Identifiant | Assertion de sécurité | Résultat |
|---|---|---|
| AUTH4-01 | GET du formulaire, POST login, cookie serveur, session persistante, tableau de bord après connexion | PASS |
| AUTH4-02 | Mauvais CSRF et utilisateur absent refusés, authentification valide toujours possible | PASS |
| AUTH4-03 | Déconnexion : session révoquée côté coffre, ancien cookie inutilisable | PASS |
| AUTH4-04 | POST administratif avec CSRF invalide : aucun paramètre modifié | PASS |
| AUTH4-05 | Compte désactivé refusé ; rotation de mot de passe invalide l'ancienne session et l'ancien mot de passe | PASS |
| AUTH4-06 | Enrôlement TOTP synthétique : connexion valide acceptée, code absent et rejeu du même code rejetés | PASS |
| AUTH4-07 | Activation MFA : **session courante maintenue**, conformément au choix de conception | PASS |
| AUTH4-08 | Cookie « appareil reconnu » **ne permet pas seul de se connecter ni d'ouvrir le profiler** | PASS |
| AUTH4-09 | Cookies `__Host-`, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, sans `Domain` | PASS |
| AUTH4-10 | Coffre de sessions corrompu : portail indisponible de façon contrôlée, route métier toujours utilisable, aucun `X-Debug-Token` public | PASS |

**Limite :** l'activation MFA via formulaire utilisateur complet, les codes de secours, les fonctions JIT et le changement de rôle via interface n'ont pas tous été réexécutés dans cette campagne ; l'injection TOTP s'effectue dans le coffre de test pour isoler le contrôle d'authentification réel.

## 4. Résilience et injection de pannes (`bin/fault_injection.php`)

| Identifiant | Faute introduite dans le laboratoire | Résultat |
|---|---|---|
| FAULT4-01 | Sessions chiffrées corrompues pendant requête authentifiée | PASS |
| FAULT4-02 | Clé AES remplacée alors que la session existe déjà | PASS |
| FAULT4-03 | Noms de collection malformés et tag AES-GCM altéré | PASS |
| FAULT4-04 | Symlink de collection et symlink du répertoire de coffre | PASS |
| FAULT4-05 | Renderer Twig volontairement défaillant pendant l'erreur 404 | PASS |
| FAULT4-06 | Verrou de journal d'audit impossible à ouvrir ; API métier et fallback d'exception restent disponibles | PASS |
| FAULT4-07 | Collection des comptes corrompue pendant une exception HTTP publique et tentative d'accès profiler | PASS |

**Limites importantes :**

- Les tests ont lieu avec des comptes, clés, chemins et secrets factices dans `/tmp`.
- La simulation d'audit défaillant vérifie la résistance de l'application **pendant une erreur d'écriture du journal existant**. Elle ne démontre **pas** la présence du futur journal spécifique `guardian.http_intervention`, qui reste à développer.
- Le test Twig vérifie le repli sûr du `PublicErrorRenderer` réel. Il ne remplace pas une campagne exhaustive contre toutes les exceptions secondaires possibles.
- Les erreurs du reverse proxy, de PHP-FPM avant Symfony et les pertes matérielles d'E/S restent hors périmètre.

## 5. Mise en relation avec les 84 scénarios initiaux

Cette campagne fournit des **preuves complémentaires indépendantes** à la matrice initiale :

- `FAULT-09` : la préservation des fichiers d'hôte, les purges volontaires et les scénarios de rollback sont désormais abordés à travers `LIFE-01..06` ; la partie `composer remove` reste ouverte.
- `FAULT-04` : le repli du renderer lorsque Twig lève une exception est vérifié par `FAULT4-05`, mais un défaut de l'orchestrateur des exceptions lui-même reste non testé.
- `FAULT-05` : panne d'audit vérifiée pour l'audit existant, mais le futur journal d'interventions HTTP n'existe pas encore dans le package.
- `AUTH-01..10` : renforcés par dix parcours HTTP ciblés, mais sans présumer la couverture totale de tous les écrans et options de sécurité.
- `FAULT-07` : une campagne distincte de concurrence a déjà été validée précédemment ([run 40803121051](https://github.com/PsiGuardian/profiler-shield-real-test/actions/runs/40803121051)).

**Les `NOT_RUN` historiques ne deviennent pas automatiquement des `PASS` dans le rapport de référence.** Chaque dossier de preuve est conservé distinctement, avec son périmètre exact.

## 6. Décision et prochaines lacunes

**Validation de la phase 4 : positive dans le périmètre testé.** Aucun échec des 115 évaluations et aucun changement du bundle publié.

Restent à réaliser pour l'ensemble du cahier des charges : tests d'installation/retrait Composer de bout en bout sur un vrai squelette Symfony, audit de cohérence des 28 traductions et du générateur de documentation, scénario d'exception secondaire de l'orchestrateur, validation du nouveau journal d'intervention (lorsqu'il existera), extension des scénarios MFA/JIT/rôles et matrice de configuration `APP_ENV`/reverse proxy.

**Aucune fusion vers le dépôt du bundle d'origine ne doit être effectuée dans ce chantier.**
