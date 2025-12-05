<?php

namespace App\Scheduler;

use App\Message\TriggerDailyReportsMessage;
use App\Message\TriggerWeeklyReportsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class ReportsSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('*/5 * * * *', new TriggerWeeklyReportsMessage()))
            ->add(RecurringMessage::cron('0 0 * * 0', new TriggerWeeklyReportsMessage()))
            ->add(RecurringMessage::cron('*/2 * * * *', new TriggerDailyReportsMessage()))
            ->stateful($this->cache)
        ;
    }
}
