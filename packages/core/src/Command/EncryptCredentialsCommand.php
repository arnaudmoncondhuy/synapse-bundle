<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Chiffre les credentials LLM stockés en clair dans `synapse_provider`.
 *
 * Utilité : après activation de `synapse.encryption.enabled: true` sur un hôte
 * où des providers avaient déjà des credentials en clair (migration historique
 * ou insertion directe SQL), cette commande parcourt chaque provider et chiffre
 * les champs sensibles via {@see EncryptionServiceInterface::encrypt()}.
 *
 * **Sécurité opérationnelle** :
 * - Cette commande **ne logue jamais** les valeurs des credentials, ni en clair
 *   ni chiffrées. Elle affiche uniquement des compteurs par provider.
 * - `isEncrypted()` est utilisé pour éviter les doubles chiffrements : si une
 *   valeur est déjà reconnue comme chiffrée, elle est laissée telle quelle.
 * - Aucun `echo`, `dump`, `var_dump` ou équivalent sur les valeurs.
 *
 * Les champs chiffrés (alignés sur
 * {@see \ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence\ProviderController}) :
 * - `api_key`
 * - `service_account_json`
 * - `private_key`
 * - `token`
 *
 * Utilisation :
 * ```
 * bin/console synapse:credentials:encrypt-all           # exécute la migration
 * bin/console synapse:credentials:encrypt-all --dry-run # simule, ne modifie rien
 * ```
 */
#[AsCommand(
    name: 'synapse:credentials:encrypt-all',
    description: 'Chiffre les credentials LLM existants en clair dans synapse_provider (migration post-activation encryption).',
)]
class EncryptCredentialsCommand extends Command
{
    /** @var list<string> Les 4 champs considérés comme sensibles, alignés sur ProviderController. */
    private const SENSITIVE_FIELDS = ['api_key', 'service_account_json', 'private_key', 'token'];

    public function __construct(
        private readonly SynapseProviderRepository $providerRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionServiceInterface $encryptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule la migration sans écrire en base.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $providers = $this->providerRepository->findBy([], ['id' => 'ASC']);

        if ([] === $providers) {
            $io->info('Aucun provider trouvé.');

            return Command::SUCCESS;
        }

        $io->title('Chiffrement des credentials providers');
        if ($dryRun) {
            $io->note('Mode dry-run : rien ne sera écrit en base.');
        }

        $totalProviders = 0;
        $totalFieldsEncrypted = 0;
        $totalFieldsAlreadyEncrypted = 0;
        $totalFieldsSkipped = 0;

        foreach ($providers as $provider) {
            $credentials = $provider->getCredentials();
            if (!is_array($credentials) || [] === $credentials) {
                $io->writeln(sprintf('  • Provider "%s" (#%d) : pas de credentials, ignoré.', $provider->getName(), $provider->getId()));
                continue;
            }

            $encryptedCount = 0;
            $alreadyCount = 0;
            $skippedCount = 0;
            $changed = false;

            foreach (self::SENSITIVE_FIELDS as $field) {
                $value = $credentials[$field] ?? null;
                if (!is_string($value) || '' === $value) {
                    // Champ absent ou vide : rien à faire.
                    continue;
                }

                // IMPORTANT : on n'affiche JAMAIS la valeur. On travaille
                // uniquement en mémoire, isEncrypted décide du sort.
                if ($this->encryptionService->isEncrypted($value)) {
                    ++$alreadyCount;
                    continue;
                }

                try {
                    $credentials[$field] = $this->encryptionService->encrypt($value);
                    ++$encryptedCount;
                    $changed = true;
                } catch (\Throwable $e) {
                    // Erreur inattendue : on saute ce champ en le loggant sans
                    // afficher la valeur pour ne rien fuiter.
                    ++$skippedCount;
                    $io->warning(sprintf(
                        'Provider "%s" (#%d) : impossible de chiffrer le champ "%s" — %s',
                        $provider->getName(),
                        $provider->getId(),
                        $field,
                        $e->getMessage(),
                    ));
                }
            }

            // Résumé par provider, sans valeur sensible.
            $summary = sprintf(
                '  • Provider "%s" (#%d) : %d chiffré(s), %d déjà chiffré(s), %d ignoré(s)',
                $provider->getName(),
                $provider->getId(),
                $encryptedCount,
                $alreadyCount,
                $skippedCount,
            );
            $io->writeln($summary);

            if ($changed && !$dryRun) {
                // Re-setter le tableau complet pour que Doctrine détecte le
                // changement (JSON column → comparaison par valeur).
                $provider->setCredentials($credentials);
                $this->entityManager->persist($provider);
            }

            ++$totalProviders;
            $totalFieldsEncrypted += $encryptedCount;
            $totalFieldsAlreadyEncrypted += $alreadyCount;
            $totalFieldsSkipped += $skippedCount;
        }

        if (!$dryRun && $totalFieldsEncrypted > 0) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d provider(s) traité(s), %d champ(s) nouvellement chiffré(s), %d déjà chiffré(s)%s.',
            $totalProviders,
            $totalFieldsEncrypted,
            $totalFieldsAlreadyEncrypted,
            $totalFieldsSkipped > 0 ? sprintf(', %d erreur(s)', $totalFieldsSkipped) : '',
        ));

        if ($dryRun) {
            $io->note('Aucun changement persisté (dry-run). Relancer sans --dry-run pour appliquer.');
        }

        return Command::SUCCESS;
    }
}
