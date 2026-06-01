<?php

namespace App\Listeners;

use App\Models\SystemHeartbeat;
use Illuminate\Mail\Events\MessageSent;

/**
 * Ops monitoring: bump the mail.sent heartbeat whenever an email is handed
 * to the transport. This is the positive "mail actually works" signal the
 * /up check surfaces — the counter-evidence to the failure-alert blind spot,
 * where an alert email can't itself warn you that email delivery is broken.
 */
class RecordMailSent
{
    public function handle(MessageSent $event): void
    {
        $subject = null;
        try {
            $subject = $event->message->getSubject();
        } catch (\Throwable $e) {
            // The timestamp is what matters; subject is best-effort context.
        }

        SystemHeartbeat::ping('mail.sent', $subject ? ['subject' => $subject] : null);
    }
}
