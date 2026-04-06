<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Tests\Unit\Controller\Systeme;

use ArnaudMoncondhuy\SynapseAdmin\Controller\Systeme\HealthController;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    public function testControllerIsInstantiable(): void
    {
        $controller = new HealthController(
            $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createStub(\ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository::class),
            $this->createStub(\ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository::class),
            $this->createStub(\ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        $this->assertInstanceOf(HealthController::class, $controller);
    }
}
