# ΩGuardian Profiler Shield — Real Test

Dépôt public et indépendant consacré à la **spécification, aux tests différentiels et à la validation de sécurité** du bundle Symfony [omegaguardian/profiler-shield](https://github.com/PsiGuardian/profiler-shield).

> **Important :** ce dépôt est un banc d'essai et non une version du bundle. Il ne doit contenir ni identifiants réels, ni secrets, ni données de production. Un test « vert » ne prouve pas à lui seul l'absence de vulnérabilités.

## Références du projet

- [Cahier des charges — Safe Native, sécurité et compatibilité API](docs/cahier-des-charges.md)
- [Matrice de tests détaillée et critères d'acceptation](docs/matrice-de-tests.md)

## Méthode

Comparer une application Symfony **clean** avec une application équivalente **avec Profiler Shield**, à configuration métier et données identiques. Vérifier la conservation du fonctionnement applicatif, les contrôles d'accès au profiler, le masquage des diagnostics et le respect des contrats API. Les écarts autorisés sont explicitement distingués des régressions.

## Politique de réalisation

Les scénarios sont **à exécuter** tant qu'un rapport de CI daté et vérifiable n'est pas publié. Les assertions critiques doivent faire échouer les jobs en cas de violation. Les actions actives et tests de résistance sont limités à des environnements isolés appartenant aux responsables du projet. Aucun test destructif ou de charge n'est exécuté sur des services tiers.

**Projet en phase de spécification.** Les résultats historiques d'un banc de comparaison précédent ne constituent pas la validation de la présente matrice.
