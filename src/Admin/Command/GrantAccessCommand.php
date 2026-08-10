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
    name: 'app:access:grant',
    description: 'Grants a back-office access (contacts, dossiers, visits, agents, tools, staff or admin) to a user identified by email',
)]
final class GrantAccessCommand extends Command
{
    /** Access key → stored role. Mirrors UserList::SECTIONS plus the two grades. */
    private const ACCESSES = [
        'contacts' => 'ROLE_SECTION_CONTACTS',
        'dossiers' => 'ROLE_SECTION_DOSSIERS',
        'visits' => 'ROLE_SECTION_VISITS',
        'agents' => 'ROLE_SECTION_AGENTS',
        'tools' => 'ROLE_SECTION_TOOLS',
        'staff' => 'ROLE_STAFF',
        'admin' => 'ROLE_ADMIN',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the user to promote');
        $this->addArgument('access', InputArgument::REQUIRED, 'One of: '.implode(', ', array_keys(self::ACCESSES)));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $access = (string) $input->getArgument('access');

        $role = self::ACCESSES[$access] ?? null;
        if (null === $role) {
            $io->error(sprintf('Unknown access "%s". Use one of: %s.', $access, implode(', ', array_keys(self::ACCESSES))));

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('User "%s" not found.', $email));

            return Command::FAILURE;
        }

        $current = $user->getRoles();
        if (in_array($role, $current, true)) {
            $io->note(sprintf('%s already has %s.', $email, $role));

            return Command::SUCCESS;
        }

        // ROLE_USER is granted implicitly by getRoles(), no need to store it.
        $next = array_values(array_unique([...array_filter($current, fn (string $r): bool => 'ROLE_USER' !== $r), $role]));
        $user->setRoles($next);
        $this->em->flush();

        $io->success(sprintf('%s now has %s.', $email, $role));

        return Command::SUCCESS;
    }
}
