<?php

declare(strict_types=1);

namespace App\Dossier\Command;

use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierRecapGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point for the AI dossier recaps. Schedule it once a day on
 * o2switch, outside business hours:
 *
 *   php /path/to/bin/console app:dossiers:refresh-recaps
 *
 * Only refreshes recaps that already exist and went stale (a note or an
 * audit event landed after the last generation), so an untouched dossier
 * never costs a model call. The admin can still regenerate any recap on
 * demand from the dossier page; this command just keeps them warm.
 *
 * The run is capped (--limit) because each generation is a synchronous
 * call of a few seconds and shared hosting kills long-running processes.
 */
#[AsCommand(
    name: 'app:dossiers:refresh-recaps',
    description: 'Regenerate the AI recaps of dossiers that changed since their last generation.',
)]
final class RefreshDossierRecapsCommand extends Command
{
    private const DEFAULT_LIMIT = 25;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierRecapGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of recaps regenerated in one run.', (string) self::DEFAULT_LIMIT)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate every open dossier that has a recap, even an up-to-date one.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        if ($limit < 1) {
            $io->error('The --limit option must be a positive integer.');

            return Command::INVALID;
        }

        $dossiers = $this->dossiers->findWithStaleRecap($limit, (bool) $input->getOption('force'));
        if ([] === $dossiers) {
            $io->success('No recap to refresh.');

            return Command::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;
        foreach ($dossiers as $dossier) {
            // A model hiccup on one dossier must not abort the whole run:
            // the generator already fails softly and logs.
            if (null !== $this->generator->generate($dossier)) {
                ++$refreshed;
                continue;
            }

            ++$failed;
            $io->warning(\sprintf('Recap generation failed for dossier %s.', $dossier->getReference()));
        }

        $io->success(\sprintf('%d recap(s) refreshed, %d failure(s).', $refreshed, $failed));

        // A failed generation is expected noise (model unreachable), not a
        // cron failure: only report success so o2switch stops emailing.
        return Command::SUCCESS;
    }
}
