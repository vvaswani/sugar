<?php

namespace App\Message;

/**
 * Triggers report generation for all users.
 */
class TriggerScheduledReportsMessage
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_WEEKLY = 'weekly';

    public function __construct(
        private string $triggerType
    ) {}

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }
}
