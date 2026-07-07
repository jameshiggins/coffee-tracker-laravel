<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Trust#2: the weekly ops digest. Wraps the structured array from
 * DataQualityReport in a plain Markdown email so an operator gets one
 * glance-able summary of import health, dropped variants, likely
 * duplicates, and address gaps. Internal-only — sent to the ops address,
 * never to directory users.
 *
 * @property array $report  the DataQualityReport::build() payload
 */
class WeeklyDataQualityDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public array $report)
    {
    }

    public function envelope(): Envelope
    {
        $date = Carbon::parse($this->report['generated_at'])->toFormattedDateString();

        return new Envelope(
            subject: "Roastmap data-quality digest — {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-data-quality', with: [
            'report' => $this->report,
        ]);
    }
}
