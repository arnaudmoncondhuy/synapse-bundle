<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'synapse:install',
    description: 'Installs and configures the Synapse Bundle (routes, config, etc.)',
)]
class InstallCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private Filesystem $filesystem
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🧠 Synapse Bundle Installation');

        // 1. Install Default Config
        $this->installConfig($io);

        // 2. Setup Routes
        $this->setupRoutes($io);

        // 3. Reminder for Env
        $io->section('Final Steps');
        $io->text([
            'Please ensure your .env or .env.local file contains your Google Gemini API key:',
            'GEMINI_API_KEY=your_api_key_here'
        ]);

        $io->success('Synapse Bundle has been successfully configured! 🚀');

        return Command::SUCCESS;
    }

    private function installConfig(SymfonyStyle $io): void
    {
        $configDest = $this->projectDir . '/config/packages/synapse.yaml';

        if ($this->filesystem->exists($configDest)) {
            $io->note('Config file already exists. Skipping.');
            return;
        }

        $configContent = <<<YAML
synapse:
    # Your Google Gemini API Key
    # It's recommended to use an environment variable
    api_key: '%env(GEMINI_API_KEY)%'
    
    # Model configuration
    model: 'gemini-pro'
YAML;

        $this->filesystem->dumpFile($configDest, $configContent);
        $io->success('Created config/packages/synapse.yaml');
    }

    private function setupRoutes(SymfonyStyle $io): void
    {
        $routesPath = $this->projectDir . '/config/routes.yaml';

        if (!$this->filesystem->exists($routesPath)) {
            $io->warning('Could not find config/routes.yaml. Please import the routes manually.');
            return;
        }

        $currentContent = file_get_contents($routesPath);

        if (str_contains($currentContent, 'synapse_bundle:')) {
            $io->note('Routes already configured. Skipping.');
            return;
        }

        $newContent = $currentContent . "\n" . <<<YAML
synapse_bundle:
    resource: '@SynapseBundle/config/routes.yaml'
YAML;

        $this->filesystem->dumpFile($routesPath, $newContent);
        $io->success('Imported routes in config/routes.yaml');
    }
}
