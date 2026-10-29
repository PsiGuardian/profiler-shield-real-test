# Plan d'intégration Safe Native — architecture et garanties de sécurité

**Référence :** GPS-RT-DESIGN-3.0, 29 octobre 2026  
**Statut :** proposition expérimentée dans un laboratoire indépendant. **Ne pas intégrer automatiquement au bundle original.**  
**Code de preuve :** `tests/Prototype/` et `bin/prototype.php` dans ce dépôt.  
**Contrainte absolue :** aucune modification du dépôt `PsiGuardian/profiler-shield` n'est effectuée dans cette phase.

## 1. Résumé de la décision

La solution expérimentale réussit les contrôles de sécurité et compatibilité en plaçant un **listener de test** sur `kernel.exception` à priorité `8192`, avant le `PrivacySubscriber` publié (priorité `4096`). Elle n'est active **que dans les noyaux éphémères du laboratoire avec opt-in** et ne fait pas partie du package `omegaguardian/profiler-shield`.

Cette architecture est une **preuve de possibilité**, pas la conception finale à publier : la version de production devrait intégrer la logique de **classification sûre** *dans* la chaîne de décision de Guardian, sans contraindre les applications clientes à installer un listener précoce pouvant détourner la passerelle de confidentialité.

## 2. Trois frontières de confiance

| Niveau | Informations admissibles | Informations interdites |
|---|---|---|
| Exception PHP non approuvée | Code HTTP et en-têtes protocolaire validés | `getMessage()`, trace, `getPrevious()`, SQL, chemin, secrets |
| Mapper public explicitement déclaré par l'hôte | Code métier allowlist, message public fixe, champs contrôlés | Message brut d'exception ou headers arbitraires |
| Session Guardian autorisée | Diagnostic selon rôle, session, MFA, réseau, horaire, JIT et environnement | Élévation de privilège par seul en-tête, cookie d'appareil ou client non authentifié |

Le mot **publique** ne signifie pas que toute exception de type `HttpExceptionInterface` est sûre. Un `404` peut contenir une chaîne sensible.

## 3. Pipeline proposé à implémenter un jour dans Guardian

```
Symfony kernel.exception
  -> Appartient-il aux routes techniques du profiler ?
       -> oui : garde Guardian prioritaire (refus si non autorisé)
       -> non :
          -> Session technique autorisée et diagnostic explicitement activé ?
               -> oui : laisser Symfony poursuivre les outils de debug
               -> non :
                  -> PublicErrorRegistry: mapper de confiance explicitement enregistré ?
                       -> oui : valider PublicErrorDescriptor
                               -> rendre le format approuvé (HTTP+headers)
                       -> non : rendu neutre du statut HTTP et de la représentation négociée
          -> En cas d'exception secondaire de mapper ou sérialiseur :
               -> ne jamais publier la cause ; fallback neutre de dernier recours
Symfony kernel.response
  -> Masquer X-Debug*, X-Profiler* si l'accès n'est pas autorisé
  -> Respecter les réponses métier préconstruites en mode native
  -> Appliquer generic/custom exclusivement aux représentations HTML visées
```

**Attention :** les erreurs de diagnostic doivent être vérifiées avant tout mapper métier. L'application hôte ne peut pas reclassifier une route de profiler en « route métier approuvée ».

## 4. Interopérabilité avec les contrôleurs et les API Symfony

- Une réponse déjà construite reste native en `error_mode=native`.
- Un mapper API public est un service déclaré explicitement par code, pas un callback de formulaire pouvant renvoyer `Throwable::getMessage()`.
- Les codes `401`, `405`, `429` doivent garder `WWW-Authenticate`, `Allow`, `Retry-After` si ces headers respectent une allowlist stricte.
- Le nouveau `exception_policy` doit être **indépendant** de `error_mode`.
- Les échecs inconnus utilisent la négociation JSON, XML, Problem Details RFC 9457, HTML/texte **sans** reprendre un message interne.
- En production, pas de `WebProfilerBundle` ni collecte, même si l'application déclare un mapper public.
- Le comportement doit être le même sur toutes les branches Symfony supportées, et avec ou sans Twig.
- Pour CORS, seuls les en-têtes publics autorisés issus d'une configuration fiable doivent être conservés ; jamais tous les headers de l'exception sans contrôle.

## 5. Contrat recommandé pour les mappers

Proposition d'API publique (à discuter avant tout portage dans le bundle) :

```php
interface PublicErrorMapperInterface {
    public function supports(\Throwable $exception, Request $request): bool;
    public function map(\Throwable $exception, Request $request): ?PublicErrorDescriptor;
}
```

Descripteur immuable et validé :

```php
final readonly class PublicErrorDescriptor {
    public function __construct(
        public int $status,
        public string $publicCode,
        public string $publicMessage,
        public array $publicHeaders = [],
        public array $safeFields = [],
    ) {}
}
```

Prévoir validation centralisée des types MIME, status 400–599, tailles, champs, headers, CRLF, Unicode et politique de cache ; ne **jamais** exposer les propriétés non déclarées, la trace ou le précédent `Throwable`. Lorsqu'un mapper échoue, la neutralisation est le résultat attendu, pas une 500 secondaire qui divulgue le mapper.

## 6. Migration sans régression

- Les installations `v1.0.0` sans mapper conservent le masquage des exceptions et les réglages existants.
- Les nouvelles fonctionnalités opt-in n'impliquent ni changement de cookies, ni suppression de coffre, ni déconnexion après enrôlement TOTP.
- Une évolution des schémas d'erreurs publics doit être **opt-in** ou accompagnée d'une version majeure documentée.
- Le nouveau composant ne doit dépendre d'aucune extension HTTP tierce nécessairement absente des installations de base.
- Toute différence de comportement doit avoir un test avant/après de la matrice du laboratoire et un exemple de requête HTTP.
- Conserver `guardian:setup` et `guardian:uninstall` réversibles ; rien ne doit installer un plugin Composer implicitement.

## 7. Risques résiduels de cette preuve de concept

**Risque R1 — concurrence entre listeners :** un listener expérimental à priorité `8192` peut court-circuiter le `PrivacySubscriber`. Le prototype se limite volontairement à `/api/` et ne rend que des données explicitement sûres ; ce choix n'est **pas** une autorisation générale d'intercepter les exceptions en amont de Guardian.

**Risque R2 — limites d'un allowlist statique :** le prototype ne publie que `API_DOMAIN_ERROR` pour `GET /api/throw/validation` avec statut 422. Une application réelle doit enregistrer ses propres mappers par des services versionnés et testés, et non écrire les messages dynamiques d'exception dans la réponse.

**Risque R3 — format HTML :** le prototype retourne un fallback texte pour une exception API acceptant HTML, tandis que les pages HTML non API restent gérées par Guardian. Une future intégration doit coordonner le `PublicErrorRenderer` existant pour éviter deux rendus incohérents.

**Risque R4 — négociation HTTP :** l'analyse simplifiée des headers `Accept` doit être complétée avec les paramètres MIME, la priorité du format de route, la spécificité des ranges, les jokers suffixés, la gestion de `q=0`, `Vary` et les encodages avant toute livraison.

**Risque R5 — contrat RFC 9457 :** conformité cible avec formats Problem Details, y compris sémantique des champs `type`/`title`/`status`/`detail`, XML, caractères spéciaux et cache. Le prototype teste la structure minimale, pas une preuve exhaustive de conformité RFC.

**Risque R6 — traces et erreurs hors Symfony :** les réponses d'un reverse proxy, d'un composant PHP avant Kernel ou d'un contrôleur publiant volontairement des secrets restent hors protection.

## 8. Preuves automatiques demandées avant toute correction du bundle

| Famille | Attendu |
|---|---|
| Prototype ciblé | 144 PASS par combinaison (6 modes/debug × 24 invariants) |
| Full matrix différentielle | Aucune transition PASS→FAIL ou PASS→NOT_RUN de l'original vers le prototype |
| Contrats réparés | BASE-04/05 et HTTP-10/11/12/13/20 doivent devenir PASS en prototype |
| Routes métier intactes | Parité statut/corps/MIME pour les réponses déjà construites |
| Profiler | Aucun accès anonyme supplémentaire, normalisation encodage documentée |
| Intégrité du coffre | Compteurs chiffrés concurrents sans perte et rejets de données corrompues |
| Throttle | Requêtes concurrentes sous seuil, refus des dépassements |
| Transport réel | HEAD, OPTIONS, SSE, flux et cookies surveillés séparément |

Les tests qui ne peuvent être exercés par les fixtures existantes restent `NOT_RUN`, avec justification visible. Les fonctionnalités du cahier des charges non livrées sont des **écarts de fonctionnalité**, pas automatiquement des CVE.

## 9. Portage futur — prérequis bloquants

**Ne pas ouvrir de PR modifiant `PsiGuardian/profiler-shield` sans demande explicite.** Préparer une patchset conceptuelle et un guide de migration dans ce laboratoire ; exiger revue de sécurité, CI, modèle de menace et consentement avant de toucher au produit. Un prototype expérimental externe qui passe 720 vérifications ne prouve pas que la même modification est sans danger une fois intégrée au cycle Symfony du bundle.

**Conclusion :** le mécanisme « Safe Native = priorité à la confidentialité, contrat métier explicitement approuvé, neutralisation sinon » est techniquement réalisable et testable sans compromettre la protection actuelle. La décision de portage est séparée.
