<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DebugDetailController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseDebugLoggerInterface $debugLogger,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('/synapse/_debug/{id}', name: 'synapse_debug_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $debug = $this->debugLogger->findByDebugId($id);

        if (!$debug) {
            return new Response('Debug data expired or not found. It might have been cleared or the cache expired.', 404);
        }

        return $this->render('@Synapse/admin/systeme/debug_detail.html.twig', [
            'id'    => $id,
            'debug' => $debug,
        ]);
    }
}
