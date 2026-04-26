<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Auth\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:admin:grant',
    description: 'Grants ROLE_ADMIN to a user identified by email',
)]
final class GrantAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the user to promote');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('User "%s" not found.', $email));

            return Command::FAILURE;
        }

        $current = $user->getRoles();
        if (in_array('ROLE_ADMIN', $current, true)) {
            $io->note(sprintf('%s already has ROLE_ADMIN.', $email));

            return Command::SUCCESS;
        }

        // ROLE_USER is granted implicitly by getRoles(), no need to store it.
        $next = array_values(array_unique([...array_filter($current, fn (string $r): bool => 'ROLE_USER' !== $r), 'ROLE_ADMIN']));
        $user->setRoles($next);
        $this->em->flush();

        $io->success(sprintf('%s now has ROLE_ADMIN.', $email));

        return Command::SUCCESS;
    }
}
