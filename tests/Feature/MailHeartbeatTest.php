<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ops monitoring: sending any email bumps the mail.sent heartbeat (the
 * positive "mail actually works" signal the /up check surfaces). Uses the
 * array transport which, unlike Mail::fake(), still fires the MessageSent
 * event the RecordMailSent listener hangs off.
 */
class MailHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_mail_records_the_heartbeat(): void
    {
        config(['mail.default' => 'array']);

        $this->assertNull(SystemHeartbeat::lastSeen('mail.sent'));

        Mail::raw('hello', fn ($m) => $m->to('ops@example.com')->subject('Ping'));

        $this->assertNotNull(SystemHeartbeat::lastSeen('mail.sent'));
    }
}
