# ΩGuardian Profiler Shield — Real Test

Dépôt public et indépendant consacré à la **spécification, aux tests différentiels et à la validation de sécurité** du bundle Symfony [omegaguardian/profiler-shield](https://github.com/PsiGuardian/profiler-shield).

> **Important :** ce dépôt est un banc d'essai et non une version du bundle. Il ne doit contenir ni identifiants réels, ni secrets, ni données de production. Un test « vert » ne prouve pas à lui seul l'absence de vulnérabilités.

## Références du projet

- [Cahier des charges — Safe Native, sécurité et compatibilité API](docs/cahier-des-charges.md)
- [Matrice de tests détaillée et critères d'acceptation](docs/matrice-de-tests.md)
- [Rapport initial : 630 vérifications / 3 scénarios incompatibles](docs/rapport-initial.md)
- [Audit approfondi : 84 scénarios, 2520 évaluations, transport réel et fuzzing](docs/rapport-84-approfondi.md)
- [Phase 3 — Résultats du prototype Safe Native et de la concurrence](docs/rapport-phase3.md)
- [Plan d'intégration sécurisée (sans modifier le bundle original)](docs/plan-integration-safe-native.md)

## Méthode

Comparer une application Symfony **clean** avec une application équivalente **avec Profiler Shield**, à configuration métier et données identiques. Vérifier la conservation du fonctionnement applicatif, les contrôles d'accès au profiler, le masquage des diagnostics et le respect des contrats API. Les écarts autorisés sont explicitement distingués des régressions.

## Politique de réalisation

Les scénarios sont **à exécuter** tant qu'un rapport de CI daté et vérifiable n'est pas publié. Les assertions critiques doivent faire échouer les jobs en cas de violation. Les actions actives et tests de résistance sont limités à des environnements isolés appartenant aux responsables du projet. Aucun test destructif ou de charge n'est exécuté sur des services tiers.

**Phase de laboratoire en cours.** 21 scénarios sont désormais exécutables sur cinq configurations PHP/Symfony ; la CI échoue volontairement lorsqu'une incompatibilité est reproduite. Les autres scénarios de la matrice restent non exécutés. Voir le rapport initial.
