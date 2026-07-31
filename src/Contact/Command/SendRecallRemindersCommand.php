<?php

declare(strict_types=1);

namespace App\Contact\Command;

use App\Contact\Service\RecallReminderSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point for the recall reminder emails. Schedule it every
 * 5 minutes on o2switch:
 *
 *   php /path/to/bin/console app:contacts:send-recall-reminders
 */
#[AsCommand(
    name: 'app:contacts:send-recall-reminders',
    description: 'Send the planned-recall reminder emails (day before, 1 hour and 5 minutes before).',
)]
final class SendRecallRemindersCommand extends Command
{
    public function __construct(
        private readonly RecallReminderSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->sender->send();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d recall reminder(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
