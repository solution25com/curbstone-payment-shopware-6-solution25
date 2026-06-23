<?php declare(strict_types=1);

namespace Curbstone\Command;

use Curbstone\Service\CurbstoneVaultService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'curbstone:backfill-saved-card-metadata',
    description: 'Backfill and normalize saved card metadata for all customers.',
)]
final class BackfillSavedCardMetadataCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly CurbstoneVaultService $vaultService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist any changes.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size for customer batches.', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(1, (int) $input->getOption('limit'));

        $context = Context::createDefaultContext();

        $io->title('Curbstone saved-card metadata backfill');
        $io->writeln(sprintf('Mode: %s', $dryRun ? 'dry-run' : 'write'));
        $io->writeln(sprintf('Batch size: %d', $limit));

        $offset = 0;
        $totalCustomers = 0;
        $customersWithSavedCards = 0;
        $customersUpdated = 0;
        $cardsNormalized = 0;

        do {
            $criteria = (new Criteria())->setLimit($limit)->setOffset($offset);
            $result = $this->customerRepository->search($criteria, $context);
            $customers = $result->getEntities();

            if ($customers->count() === 0) {
                break;
            }

            foreach ($customers as $customer) {
                ++$totalCustomers;

                $customFields = $customer->getCustomFields() ?? [];
                $savedCards = $customFields['curbstone_saved_cards'] ?? [];

                if (!\is_array($savedCards) || $savedCards === []) {
                    continue;
                }

                ++$customersWithSavedCards;

                $normalizedCards = $this->vaultService->normalizeSavedCards($savedCards, $customer);

                if ($normalizedCards === $savedCards) {
                    continue;
                }

                $changedCards = 0;

                foreach ($normalizedCards as $index => $card) {
                    $original = $savedCards[$index] ?? null;
                    if (\is_array($original) && $original !== $card) {
                        ++$changedCards;
                    }
                }

                $cardsNormalized += $changedCards;

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '[dry-run] customer %s would be updated (%d cards normalized)',
                        $customer->getId(),
                        $changedCards
                    ));
                    continue;
                }

                $customFields['curbstone_saved_cards'] = $normalizedCards;
                $this->customerRepository->update([[
                    'id' => $customer->getId(),
                    'customFields' => $customFields,
                ]], $context);

                ++$customersUpdated;
                $io->writeln(sprintf(
                    'updated customer %s (%d cards normalized)',
                    $customer->getId(),
                    $changedCards
                ));
            }

            $offset += $limit;
        } while (true);

        $summary = sprintf(
            'Done. Scanned %d customers, found %d with saved cards, updated %d customers, normalized %d card records%s.',
            $totalCustomers,
            $customersWithSavedCards,
            $customersUpdated,
            $cardsNormalized,
            $dryRun ? ' (dry-run)' : ''
        );

        $this->logger->info('[Curbstone] Saved-card backfill completed', [
            'dryRun' => $dryRun,
            'limit' => $limit,
            'customersScanned' => $totalCustomers,
            'customersWithSavedCards' => $customersWithSavedCards,
            'customersUpdated' => $customersUpdated,
            'cardsNormalized' => $cardsNormalized,
        ]);

        $io->success($summary);

        return Command::SUCCESS;
    }
}
