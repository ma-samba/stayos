<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Security\Voter;

use App\Hotel\Shared\Domain\Service\FeatureChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter qui autorise un endpoint si la feature encodée dans le
 * nom d'attribut est activée pour le tenant courant.
 *
 * Utilisation dans un controller :
 *
 *   #[IsGranted('FEATURE_revenue_management')]
 *   public function createRatePlan(...) { ... }
 *
 * ⚠️ Sur le format de l'attribut : `FEATURE_<name>` au lieu de
 * `('FEATURE', '<name>')`. Pourquoi ?
 * - Symfony interprète le SECOND argument d'`IsGranted` comme
 *   un NOM D'ARGUMENT du contrôleur (à passer comme sujet au
 *   voter), pas comme une chaîne littérale. Pour passer une
 *   chaîne littérale il faudrait `new Expression("'...'")` —
 *   qui requiert `symfony/expression-language` (non installé).
 * - Encoder la feature dans le nom d'attribut évite cette
 *   dépendance et reste tout aussi déclaratif.
 *
 * Le voter délègue à `FeatureChecker::assertEnabled` qui throw
 * une `FeatureNotAvailableException` (HTTP 403 + message custom)
 * si la feature est désactivée. L'exception remonte jusqu'à
 * `ApiExceptionListener` qui la mappe en code `PLAN_LIMIT`.
 *
 * Choix technique : le voter THROW l'exception au lieu de
 * retourner false. Pourquoi ?
 * - Retourner false ferait lever `AccessDeniedException` par
 *   Symfony Security avec un message générique ("Accès
 *   refusé."), pas le message custom contenant le nom de la
 *   feature ("La fonctionnalité 'X' n'est pas disponible dans
 *   votre plan.").
 * - Throw préserve le message UX explicite + le code HTTP 403
 *   + le code métier `PLAN_LIMIT` défini dans
 *   FeatureNotAvailableException.
 *
 * ⚠️ NOTE POUR LES FUTURS DÉVELOPPEURS : tout nouvel endpoint
 * nécessitant une feature doit ajouter l'attribut
 * `#[IsGranted('FEATURE_<feature_name>')]` ET un test
 * correspondant dans
 * `tests/Functional/Security/FeatureGuardTest.php`.
 * Le test global est la barrière qui empêche les régressions
 * de feature-gating (cf. Sprint 14-A.1).
 */
final class FeatureVoter extends Voter
{
    public const ATTRIBUTE_PREFIX = 'FEATURE_';

    public function __construct(
        private readonly FeatureChecker $featureChecker,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::ATTRIBUTE_PREFIX);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
    ): bool {
        $feature = substr($attribute, strlen(self::ATTRIBUTE_PREFIX));

        // Throw FeatureNotAvailableException (HTTP 403 + PLAN_LIMIT)
        // si la feature n'est pas activée pour le tenant courant.
        // Sinon, autoriser explicitement.
        $this->featureChecker->assertEnabled($feature);

        return true;
    }
}
