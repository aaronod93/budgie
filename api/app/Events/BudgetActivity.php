<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for every audited mutation so other open devices refresh live.
 * ShouldBroadcastNow: sent inline (no queue worker needed in dev); the
 * recorder swallows transport errors when Reverb isn't running.
 */
class BudgetActivity implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public string $budgetUuid,
        public array $entry,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("budget.$this->budgetUuid");
    }

    public function broadcastAs(): string
    {
        return 'activity';
    }

    public function broadcastWith(): array
    {
        return $this->entry;
    }
}
