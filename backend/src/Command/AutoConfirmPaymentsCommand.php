<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\SchedulerStatus;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Run by the scheduler (profile `cron`, see backend/scheduler/crontab). Every
// run also writes the heartbeat that tells the API this instance can offer
// auto_timeout — even when there is nothing to confirm.
#[AsCommand(name: 'app:payments:auto-confirm', description: 'Confirm auto_timeout payments whose objection window expired, and record the scheduler heartbeat')]
class AutoConfirmPaymentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SchedulerStatus $scheduler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $this->scheduler->beat($now);

        $projectIds = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(m.project) FROM App\Entity\Movement m
             WHERE m.type = :payment AND m.status = :pending
             AND m.autoConfirmAt IS NOT NULL AND m.autoConfirmAt <= :now
             AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)'
        )
        ->setParameter('payment', Movement::TYPE_PAYMENT)
        ->setParameter('pending', Movement::STATUS_PENDING)
        ->setParameter('now', $now)
        ->getSingleColumnResult();

        $confirmed = 0;
        foreach ($projectIds as $projectId) {
            // Per project, under the same lock as every other ledger write: a
            // reject arriving at the deadline either wins or sees it confirmed.
            $confirmed += $this->em->wrapInTransaction(function () use ($projectId, $now) {
                $this->em->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);

                $due = $this->em->createQuery(
                    'SELECT m FROM App\Entity\Movement m
                     WHERE m.project = :project AND m.type = :payment AND m.status = :pending
                     AND m.autoConfirmAt IS NOT NULL AND m.autoConfirmAt <= :now
                     AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)'
                )
                ->setParameter('project', $projectId)
                ->setParameter('payment', Movement::TYPE_PAYMENT)
                ->setParameter('pending', Movement::STATUS_PENDING)
                ->setParameter('now', $now)
                ->getResult();

                foreach ($due as $payment) {
                    $payment->confirm(null, 'timeout', $now);
                }

                return count($due);
            });
        }

        $io->writeln(sprintf('Heartbeat recorded; %d payment share(s) auto-confirmed.', $confirmed));

        return Command::SUCCESS;
    }
}
