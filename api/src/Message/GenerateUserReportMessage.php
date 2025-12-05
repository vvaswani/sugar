<?php
namespace App\Message;

class GenerateUserReportMessage
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_WEEKLY = 'weekly';

    public function __construct(
        private int $userId,
        private \DateTimeInterface $startDate,
        private \DateTimeInterface $endDate,
        private string $reportType = self::TYPE_WEEKLY
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeInterface
    {
        return $this->endDate;
    }

    public function getReportType(): string
    {
        return $this->reportType;
    }
}
