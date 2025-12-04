<?php

namespace App\Scheduler;

use App\Message\WeeklyReportTriggerMessage;
use App\Message\DailyReportTriggerMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class ReportTriggerSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('0 0 * * 0', new WeeklyReportTriggerMessage()))
            ->add(RecurringMessage::cron('*/2 * * * *', new DailyReportTriggerMessage()))
            ->stateful($this->cache)
        ;
    }
}
