<?php

namespace App\Events;

use App\Enums\Urgency;
use App\Models\Reminder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by `reminders:check` when a reminder reaches a more urgent level.
 * Nothing listens yet; a notification channel (e.g. a Discord webhook) can
 * subscribe to this event later.
 */
class ReminderBecameDue
{
    use Dispatchable;

    public function __construct(public Reminder $reminder, public Urgency $urgency) {}
}
