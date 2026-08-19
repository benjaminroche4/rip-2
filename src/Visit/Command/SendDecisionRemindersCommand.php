<?php

declare(strict_types=1);

namespace App\Visit\Command;

use App\Visit\Service\DecisionReminderSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point for the daily visit follow-up staff reminders: overdue
 * client-decision deadlines and done visits still missing their report.
 * Schedule it once a day on o2switch:
 *
 *   php /path/to/bin/console app:visits:send-decision-reminders
 */
#[AsCommand(
    name: 'app:visits:send-decision-reminders',
    description: 'Send the daily staff reminder emails on visit follow-up: overdue client-decision deadlines and missing visit reports.',
)]
final class SendDecisionRemindersCommand extends Command
{
    public function __construct(
        private readonly DecisionReminderSender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of reminders per run (bounded cron on shared hosting).', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Limite partagée entre les deux volets : chaque run reste borné
        // (process mutualisé), le run suivant rattrape le reliquat.
        $limit = max(1, (int) $input->getOption('limit'));
        $sent = $this->sender->send(limit: $limit);
        $reportSent = $this->sender->sendReportReminders(limit: $limit);

        (new SymfonyStyle($input, $output))->success(\sprintf('%d decision reminder(s) sent, %d report reminder(s) sent.', $sent, $reportSent));

        return Command::SUCCESS;
    }
}
