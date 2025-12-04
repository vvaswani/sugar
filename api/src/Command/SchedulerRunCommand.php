<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'scheduler:run',
    description: 'Run the scheduler and dispatch messages',
)]
final class SchedulerRunCommand extends Command
{
    public function __construct(
        private MessageBusInterface $bus,
        private iterable $scheduleProviders,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Running scheduler...');

        while (true) {
            $output->writeln(sprintf('[%s] Polling schedules...', date('H:i:s')));

            foreach ($this->scheduleProviders as $provider) {
                $output->writeln(sprintf('  Provider: %s', get_class($provider)));
                $schedule = $provider->getSchedule();
                $output->writeln(sprintf('  Schedule retrieved, checking messages...'));

                $messageCount = 0;
                foreach ($schedule as $message) {
                    $messageCount++;
                    $this->bus->dispatch($message);
                    $output->writeln(sprintf('[%s] Dispatched: %s', date('H:i:s'), get_class($message)));
                }
                $output->writeln(sprintf('  Total messages dispatched: %d', $messageCount));
            }

            $output->writeln(sprintf('[%s] Sleeping for 1 second...', date('H:i:s')));
            sleep(1);
        }
    }
}
