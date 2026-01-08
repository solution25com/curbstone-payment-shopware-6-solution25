<?php

declare(strict_types=1);

namespace Curbstone\Command;

use Curbstone\Contract\CurbstoneGateway;
use Curbstone\Dto\AuthorizeRequest;
use Curbstone\Dto\AuthorizeResponse;
use Curbstone\Exception\CurbstonePaymentException;
use Curbstone\Config\CurbstoneConfigProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'curbstone:smoke', description: 'Authorize via Curbstone gateway (stub or sandbox) and dump raw response')]
final class CurbstoneSmokeCommand extends Command
{
    public function __construct(
        private readonly CurbstoneGateway $gateway,
        private readonly CurbstoneConfigProvider $cfg
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('amount', InputArgument::OPTIONAL, 'Amount in minor units (e.g. 1234 = 12.34)', '1234')
            ->addArgument('order', InputArgument::OPTIONAL, 'Order number', 'ORD-SMOKE-1')
            ->addArgument('sc', InputArgument::OPTIONAL, 'Sales channel ID or null', '');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $amountMinor    = (int) $in->getArgument('amount');
        $orderNumber    = (string) $in->getArgument('order');
        $salesChannelId = (string) $in->getArgument('sc') ?: null;

        $cfg  = $this->cfg->forSalesChannel($salesChannelId);
        $mode = $cfg->enabled ? ($cfg->sandbox ? 'SANDBOX (live call)' : 'PRODUCTION (live call)') : 'STUB MODE (no network)';
        $out->writeln("<info>Mode:</info> {$mode}");

        try {
            $req = new AuthorizeRequest(
                amountMinor:    $amountMinor,
                currency:       'EUR',
                orderNumber:    $orderNumber,
                previousToken:  null,
                avs:            null,
                salesChannelId: $salesChannelId,
                stub:           false
            );

            $resp = $this->gateway->authorize($req);

            $out->writeln('Status:      ' . $resp->status);
            $out->writeln('Token:       ' . ($resp->token ?? '-'));
            $out->writeln('Network Ref: ' . ($resp->networkRef ?? '-'));
            $out->writeln('Message:     ' . ($resp->message ?? '-'));

            if (!empty($resp->raw)) {
                $out->writeln('');
                $out->writeln('<info>Raw Curbstone response (MF*):</info>');

                // Make sure writeln() always receives a string (never false)
                try {
                    /** @var non-empty-string $json */
                    $json = json_encode($resp->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $out->writeln($json);
                } catch (\JsonException) {
                    $out->writeln(print_r($resp->raw, true));
                }
            }

            return $resp->status === AuthorizeResponse::STATUS_APPROVED
                ? Command::SUCCESS
                : Command::FAILURE;
        } catch (CurbstonePaymentException $e) {
            $out->writeln('');
            $out->writeln('<error>Curbstone error:</error> ' . $e->getMessage());

            // CurbstonePaymentException guarantees context(); drop method_exists()
            /** @var array<string,mixed> $ctx */
            $ctx = $e->context();

            if ($ctx !== []) {
                try {
                        /** @var non-empty-string $ctxJson */
                    $ctxJson = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $out->writeln('<comment>Context:</comment> ' . $ctxJson);
                } catch (\JsonException) {
                    $out->writeln('<comment>Context:</comment> ' . print_r($ctx, true));
                }
            }

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $out->writeln('');
            $out->writeln('<error>Unexpected error:</error> ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
