<?php

namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use App\Message\WeeklyReportTriggerMessage;
use App\Message\GenerateUserReportMessage;

#[AsMessageHandler]
class WeeklyReportTriggerMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(WeeklyReportTriggerMessage $message): void
    {
        $this->logger->info('Weekly report scheduler triggered');

        try {
            $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
            $users = $this->em->getRepository(User::class)->findAll();

            foreach ($users as $user) {
                $timezone = new \DateTimeZone($user->getTimezone());
                $nowInUserTz = (clone $nowUtc)->setTimezone($timezone);

                // Determine user's last week's range in local time (Monday to Sunday)
                $localStart = new \DateTime('last monday', $timezone);
                $localStart->modify('-7 days')->setTime(0, 0); // start of last week
                $localEnd = (clone $localStart)->modify('+7 days'); // end of last week

                // Convert to UTC for querying readings
                $utcStart = (clone $localStart)->setTimezone(new \DateTimeZone('UTC'));
                $utcEnd = (clone $localEnd)->setTimezone(new \DateTimeZone('UTC'));

                $this->bus->dispatch(new GenerateUserReportMessage(
                    $user->getId(),
                    $utcStart,
                    $utcEnd,
                    GenerateUserReportMessage::TYPE_WEEKLY
                ));

                $this->logger->info(
                    'Weekly report message dispatched',
                    [
                        'userId' => $user->getId(),
                        'timezone' => $user->getTimezone(),
                        'startDate' => $utcStart->format('Y-m-d H:i:s'),
                        'endDate' => $utcEnd->format('Y-m-d H:i:s'),
                    ]
                );
            }

            $this->logger->info('Weekly report scheduler completed');
        } catch (\Throwable $e) {
            $this->logger->error('Weekly report scheduler error', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
