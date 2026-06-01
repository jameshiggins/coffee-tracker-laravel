<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Ops notifications: the daily ops summary. Wraps the structured array from
 * DailyOpsReport in a plain Markdown email so an operator gets one glance-able
 * pulse of the last 24h — roasters added, import errors, dropped variants, and
 * whether mail is still flowing. Internal-only: sent to the ops address, never
 * to directory users. The subject leads with a status word so an inbox skim
 * separates the "all clear" days from the ones that need a look.
 *
 * @property array $report  the DailyOpsReport::build() payload
 */
class DailyOpsSummary extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $report, public bool $notable)
    {
    }

    public function envelope(): Envelope
    {
        $date = Carbon::parse($this->report['generated_at'])->toFormattedDateString();
        $status = $this->notable ? 'action needed' : 'all clear';

        return new Envelope(
            subject: "Roastmap daily ops ({$status}) — {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-ops-summary', with: [
            'report' => $this->report,
            'notable' => $this->notable,
        ]);
    }
}
