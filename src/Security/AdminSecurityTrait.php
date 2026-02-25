<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Security;

use ArnaudMoncondhuy\SynapseBundle\Contract\PermissionCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Trait pour centraliser la sécurité dans les contrôleurs d'administration.
 */
trait AdminSecurityTrait
{
    /**
     * Vérifie si l'utilisateur a accès à l'administration.
     */
    protected function denyAccessUnlessAdmin(PermissionCheckerInterface $permissionChecker): void
    {
        if (!$permissionChecker->canAccessAdmin()) {
            throw new AccessDeniedHttpException('Access Denied.');
        }
    }

    /**
     * Vérifie la validité du jeton CSRF si le manager est disponible.
     */
    protected function validateCsrfToken(Request $request, ?CsrfTokenManagerInterface $csrfTokenManager): void
    {
        if ($csrfTokenManager) {
            $token = $request->request->get('_csrf_token') ?? $request->headers->get('X-CSRF-Token');
            if (!$this->isCsrfTokenValid('synapse_admin', (string) $token)) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
        }
    }
}
