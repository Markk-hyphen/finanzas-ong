<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationDeliverer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// Retry sweep for the mail/Telegram outbox (docs/fase3-mail-telegram.md §2.3).
// The fast path already tried each delivery once; this picks up whatever failed.
#[AsCommand(name: 'app:notifications:deliver', description: 'Retries pending mail/Telegram notification deliveries')]
class DeliverNotificationsCommand extends Command
{
    public function __construct(private readonly NotificationDeliverer $deliverer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d notification delivery(ies) attempted.', $this->deliverer->deliverDue()));

        return Command::SUCCESS;
    }
}
