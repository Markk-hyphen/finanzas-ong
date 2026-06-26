<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:member:create', description: 'Create a member (ROLE_MEMBER by default, --admin for ROLE_ADMIN)')]
class CreateMemberCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Full name')
            ->addArgument('email', InputArgument::REQUIRED, 'Email (used as login)')
            ->addArgument('password', InputArgument::REQUIRED, 'Password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getRepository(Member::class)->findOneBy(['email' => $input->getArgument('email')]);
        if ($existing) {
            $io->error(sprintf('A member with email "%s" already exists.', $input->getArgument('email')));
            return Command::FAILURE;
        }

        $member = new Member($input->getArgument('name'), $input->getArgument('email'));
        $member->setPassword($this->hasher->hashPassword($member, $input->getArgument('password')));

        if ($input->getOption('admin')) {
            $member->setRoles(['ROLE_ADMIN']);
        }

        $this->em->persist($member);
        $this->em->flush();

        $io->success(sprintf(
            'Member created: [%d] %s <%s> — roles: %s',
            $member->getId(),
            $member->getName(),
            $member->getEmail(),
            implode(', ', $member->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
