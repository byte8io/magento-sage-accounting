<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Console\Command;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually (re)fire invoice.created / invoice.paid webhooks for one or
 * more existing Magento invoices.
 *
 * Same code path as `sales_order_invoice_save_after` observers — reuses
 * ByteClient::publishEvent — so historical invoices, invoices raised
 * before the pair was complete, or invoices that failed to sync the
 * first time all go through the exact same ledger flow.
 *
 * Dedup: relies on ledger-side entity_xref (provider_entity_id cached
 * per Magento invoice id). Re-running this command for an
 * already-synced invoice is a no-op on the Sage side — ledger returns
 * the cached provider id without making a second POST. If you want to
 * force a fresh Sage POST, delete the corresponding entity_xref row on
 * the ledger DB first; see `apps/ledger/__docs/LOCAL_TESTING.md`.
 *
 * Usage:
 *   bin/magento byte8:sage:invoice:sync --id=6
 *   bin/magento byte8:sage:invoice:sync --ids=1,2,3
 *   bin/magento byte8:sage:invoice:sync --all
 *   bin/magento byte8:sage:invoice:sync --id=6 --event=paid
 *   bin/magento byte8:sage:invoice:sync --all --state=open
 */
class SyncInvoiceCommand extends Command
{
    private const COMMAND_NAME = 'byte8:sage:invoice:sync';

    private const OPT_ID = 'id';
    private const OPT_IDS = 'ids';
    private const OPT_ALL = 'all';
    private const OPT_EVENT = 'event';
    private const OPT_STATE = 'state';

    private const EVENT_CREATED = 'created';
    private const EVENT_PAID = 'paid';
    private const EVENT_BOTH = 'both';

    private const STATE_OPEN = 'open';
    private const STATE_PAID = 'paid';
    private const STATE_ANY = 'any';

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ByteClientInterface $byteClient,
        private readonly SageConfigInterface $sageConfig,
        private readonly AppState $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Fire invoice.created / invoice.paid webhooks for one or more invoices.')
            ->addOption(self::OPT_ID, null, InputOption::VALUE_REQUIRED, 'A single invoice entity_id.')
            ->addOption(self::OPT_IDS, null, InputOption::VALUE_REQUIRED, 'Comma-separated invoice entity_ids.')
            ->addOption(self::OPT_ALL, null, InputOption::VALUE_NONE, 'Every invoice (filtered by --state).')
            ->addOption(
                self::OPT_EVENT,
                null,
                InputOption::VALUE_REQUIRED,
                'Which webhook(s) to fire: created | paid | both.',
                self::EVENT_BOTH
            )
            ->addOption(
                self::OPT_STATE,
                null,
                InputOption::VALUE_REQUIRED,
                'Filter with --all: open | paid | any.',
                self::STATE_ANY
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Webapi/event dispatch expects an area; the `crontab` area is
        // inert — won't auto-load admin / frontend layouts, won't trip
        // store scope lookups, matches what cron drains already do.
        try {
            $this->appState->setAreaCode('crontab');
        } catch (\Throwable) {
            // Area already set (nested command) — safe to ignore.
        }

        if (!$this->sageConfig->isConnected()) {
            $output->writeln(
                '<error>Byte8: not paired with ledger — run Connect Magento at ledger.byte8.io first.</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        $event = $this->validateEvent($input->getOption(self::OPT_EVENT), $output);
        if ($event === null) {
            return Cli::RETURN_FAILURE;
        }

        $invoices = $this->resolveTargets($input, $output);
        if ($invoices === null) {
            return Cli::RETURN_FAILURE;
        }
        if ($invoices === []) {
            $output->writeln('<comment>No invoices matched; nothing to do.</comment>');
            return Cli::RETURN_SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Syncing %d invoice%s (event=%s)...</info>',
            count($invoices),
            count($invoices) === 1 ? '' : 's',
            $event
        ));

        $sent = 0;
        $failed = 0;
        foreach ($invoices as $invoice) {
            if ($this->publishFor($invoice, $event, $output)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $output->writeln(sprintf(
            '<info>Done. %d published, %d failed.</info>',
            $sent,
            $failed
        ));

        return $failed === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }

    private function validateEvent(?string $event, OutputInterface $output): ?string
    {
        $event = $event ?: self::EVENT_BOTH;
        if (!in_array($event, [self::EVENT_CREATED, self::EVENT_PAID, self::EVENT_BOTH], true)) {
            $output->writeln("<error>--event must be one of: created, paid, both. Got: {$event}</error>");
            return null;
        }
        return $event;
    }

    /**
     * @return InvoiceInterface[]|null  null on argument error, [] on empty match.
     */
    private function resolveTargets(InputInterface $input, OutputInterface $output): ?array
    {
        $id = $input->getOption(self::OPT_ID);
        $ids = $input->getOption(self::OPT_IDS);
        $all = (bool) $input->getOption(self::OPT_ALL);

        $selectors = array_filter([$id !== null, $ids !== null, $all], static fn ($v) => (bool) $v);
        if (count($selectors) !== 1) {
            $output->writeln('<error>Exactly one of --id, --ids, --all is required.</error>');
            return null;
        }

        if ($id !== null) {
            return $this->loadById((int) $id, $output);
        }
        if ($ids !== null) {
            return $this->loadByIds((string) $ids, $output);
        }
        return $this->loadAll($input->getOption(self::OPT_STATE), $output);
    }

    /**
     * @return InvoiceInterface[]|null
     */
    private function loadById(int $id, OutputInterface $output): ?array
    {
        if ($id <= 0) {
            $output->writeln("<error>--id must be a positive integer. Got: {$id}</error>");
            return null;
        }
        try {
            return [$this->invoiceRepository->get($id)];
        } catch (NoSuchEntityException) {
            $output->writeln("<error>Invoice {$id} not found.</error>");
            return [];
        }
    }

    /**
     * @return InvoiceInterface[]|null
     */
    private function loadByIds(string $csv, OutputInterface $output): ?array
    {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        if ($ids === []) {
            $output->writeln('<error>--ids is empty.</error>');
            return null;
        }
        $out = [];
        foreach ($ids as $id) {
            try {
                $out[] = $this->invoiceRepository->get($id);
            } catch (NoSuchEntityException) {
                $output->writeln("<comment>Skipping: invoice {$id} not found.</comment>");
            }
        }
        return $out;
    }

    /**
     * @return InvoiceInterface[]|null
     */
    private function loadAll(?string $state, OutputInterface $output): ?array
    {
        $state = $state ?: self::STATE_ANY;
        if (!in_array($state, [self::STATE_OPEN, self::STATE_PAID, self::STATE_ANY], true)) {
            $output->writeln("<error>--state must be one of: open, paid, any. Got: {$state}</error>");
            return null;
        }

        $builder = $this->searchCriteriaBuilder;
        if ($state === self::STATE_OPEN) {
            $builder->addFilter('state', Invoice::STATE_OPEN);
        } elseif ($state === self::STATE_PAID) {
            $builder->addFilter('state', Invoice::STATE_PAID);
        } else {
            // any == OPEN or PAID (never CANCELED — those shouldn't reach Sage)
            $builder->addFilter('state', [Invoice::STATE_OPEN, Invoice::STATE_PAID], 'in');
        }

        $result = $this->invoiceRepository->getList($builder->create());
        return $result->getItems();
    }

    private function publishFor(InvoiceInterface $invoice, string $which, OutputInterface $output): bool
    {
        $entityId = (int) $invoice->getEntityId();
        $state = (int) $invoice->getState();

        if ($state !== Invoice::STATE_OPEN && $state !== Invoice::STATE_PAID) {
            $output->writeln("<comment>  invoice {$entityId}: state={$state} (not OPEN/PAID) — skipped.</comment>");
            return true;
        }

        $ok = true;
        if ($which === self::EVENT_CREATED || $which === self::EVENT_BOTH) {
            $ok = $this->fire(
                'invoice.created',
                'invoice.created:' . $entityId,
                $invoice,
                ['paid' => $state === Invoice::STATE_PAID],
                $output
            ) && $ok;
        }
        if ($which === self::EVENT_PAID || $which === self::EVENT_BOTH) {
            if ($state !== Invoice::STATE_PAID) {
                $output->writeln(
                    "<comment>  invoice {$entityId}: state=OPEN — skipping invoice.paid (nothing to pay).</comment>"
                );
            } else {
                $ok = $this->fire(
                    'invoice.paid',
                    'invoice.paid:' . $entityId,
                    $invoice,
                    [],
                    $output
                ) && $ok;
            }
        }
        return $ok;
    }

    /**
     * @param array<string, mixed> $extraPayload
     */
    private function fire(
        string $eventName,
        string $idempotencyKey,
        InvoiceInterface $invoice,
        array $extraPayload,
        OutputInterface $output
    ): bool {
        $entityId = (int) $invoice->getEntityId();
        try {
            $syncRunId = $this->byteClient->publishEvent(
                $eventName,
                [
                    'magento_entity_id' => $entityId,
                    'website_id'        => $this->resolveWebsiteId($invoice),
                    'store_id'          => (int) $invoice->getStoreId(),
                    'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                    'payload'           => array_merge(
                        ['increment_id' => (string) $invoice->getIncrementId()],
                        $extraPayload
                    ),
                ],
                $idempotencyKey
            );
            $tag = $syncRunId !== '' ? "sync_run_id={$syncRunId}" : 'queued in outbox (ledger unreachable)';
            $output->writeln("<info>  invoice {$entityId}: {$eventName} → {$tag}</info>");
            return true;
        } catch (\Throwable $e) {
            $output->writeln("<error>  invoice {$entityId}: {$eventName} FAILED: {$e->getMessage()}</error>");
            return false;
        }
    }

    private function resolveWebsiteId(InvoiceInterface $invoice): int
    {
        if (method_exists($invoice, 'getOrder')) {
            $order = $invoice->getOrder();
            if ($order && method_exists($order, 'getStore')) {
                $store = $order->getStore();
                if ($store && method_exists($store, 'getWebsiteId')) {
                    return (int) $store->getWebsiteId();
                }
            }
        }
        return 0;
    }
}
