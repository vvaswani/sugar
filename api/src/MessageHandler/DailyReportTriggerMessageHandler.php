<?php

namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use App\Message\DailyReportTriggerMessage;
use App\Message\ReportMessage;

#[AsMessageHandler]
class DailyReportTriggerMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(DailyReportTriggerMessage $message): void
    {
        $this->logger->info('Daily report scheduler triggered');

        try {
            $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
            $users = $this->em->getRepository(User::class)->findAll();

            foreach ($users as $user) {
                $timezone = new \DateTimeZone($user->getTimezone());

                // Calculate user's current local time
                $nowInUserTz = (clone $nowUtc)->setTimezone($timezone);

                // Compute "yesterday" in user's local timezone
                $localStart = new \DateTime('yesterday', $timezone);
                $localStart->setTime(0, 0);
                $localEnd = (clone $localStart)->modify('+1 day');

                // If the user's current local time is before the end of yesterday (i.e., today hasn't started yet), skip
                if ($nowInUserTz < $localEnd) {
                    continue; // too early to generate report for this user
                }

                // Convert local start/end to UTC for DB querying
                $utcStart = (clone $localStart)->setTimezone(new \DateTimeZone('UTC'));
                $utcEnd = (clone $localEnd)->setTimezone(new \DateTimeZone('UTC'));

                // Dispatch message with the UTC date range
                $this->bus->dispatch(new ReportMessage(
                    $user->getId(),
                    $utcStart,
                    $utcEnd,
                    ReportMessage::TYPE_DAILY
                ));

                $this->logger->info(
                    'Daily report message dispatched',
                    [
                        'userId' => $user->getId(),
                        'timezone' => $user->getTimezone(),
                        'startDate' => $utcStart->format('Y-m-d H:i:s'),
                        'endDate' => $utcEnd->format('Y-m-d H:i:s'),
                    ]
                );
            }

            $this->logger->info('Daily report scheduler completed');
        } catch (\Throwable $e) {
            $this->logger->error('Daily report scheduler error', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
