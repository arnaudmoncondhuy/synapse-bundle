<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\UpdateVersionCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateVersionCommandTest extends TestCase
{
    private string $versionFile;

    protected function setUp(): void
    {
        // Utilise un tmpfile unique par test pour ne dépendre ni des permissions
        // du filesystem partagé Docker, ni de l'état préalable de packages/VERSION.
        // Le test passe le chemin via --file, la commande écrit où on lui dit.
        $this->versionFile = sys_get_temp_dir().'/synapse_version_test_'.uniqid().'.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->versionFile)) {
            unlink($this->versionFile);
        }
    }

    public function testVersionFileIsWritten(): void
    {
        $command = new UpdateVersionCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--file' => $this->versionFile]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Version updated to', $tester->getDisplay());

        $this->assertFileExists($this->versionFile);
        $content = trim((string) file_get_contents($this->versionFile));
        $this->assertStringStartsWith('dev 0.', $content);
    }

    public function testVersionContainsCurrentDate(): void
    {
        $command = new UpdateVersionCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--file' => $this->versionFile]);

        $expectedDate = (new \DateTime())->format('ymd');
        $content = trim((string) file_get_contents($this->versionFile));
        $this->assertSame("dev 0.{$expectedDate}", $content);
    }

    public function testFailsOnUnwritablePath(): void
    {
        $command = new UpdateVersionCommand();
        $tester = new CommandTester($command);
        // Chemin inexistant et non-créable → file_put_contents échoue.
        $tester->execute(['--file' => '/nonexistent-dir-'.uniqid().'/VERSION']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Could not write', $tester->getDisplay());
    }
}
