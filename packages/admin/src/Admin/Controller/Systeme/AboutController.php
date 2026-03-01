<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * À propos de Synapse Bundle — Administration Synapse
 *
 * Affiche la version, les dépendances principales et les informations du projet.
 */
#[Route('/synapse/admin/systeme/a-propos', name: 'synapse_admin_')]
class AboutController extends AbstractController
{
    use AdminSecurityTrait;

    /** @var array<string, string> Principales dépendances du bundle */
    private const DEPENDENCIES = [
        'symfony/framework-bundle'  => '^7.0',
        'doctrine/orm'              => '^3.0',
        'symfony/asset-mapper'      => '^7.0',
        'psr/log'                   => '^3.0',
    ];

    public function __construct(
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: 'about', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        // Version du bundle via composer.json si disponible
        $version = $this->getBundleVersion();

        return $this->render('@Synapse/admin/systeme/about.html.twig', [
            'version'      => $version,
            'dependencies' => self::DEPENDENCIES,
            'php_version'  => PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
        ]);
    }

    private function getBundleVersion(): string
    {
        // Cherche le composer.json du bundle
        $candidates = [
            __DIR__ . '/../../../../../../composer.json',
            __DIR__ . '/../../../../composer.json',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                try {
                    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                    return $data['version'] ?? $data['extra']['branch-alias']['dev-main'] ?? 'dev-main';
                } catch (\Exception) {
                }
            }
        }
        return 'dev-main';
    }
}
