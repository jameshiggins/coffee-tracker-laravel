<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Q14: daily digest of beans on the recipient's wishlist that just came
 * back in stock. Plain Markdown email.
 *
 * @property array $coffees [{id, name, image_url, roaster_name, frontend_url}]
 */
class RestockDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $coffees, public string $unsubscribeUrl = '#')
    {
    }

    public function envelope(): Envelope
    {
        $count = count($this->coffees);
        return new Envelope(
            subject: $count === 1
                ? "{$this->coffees[0]['name']} is back in stock"
                : "{$count} beans on your wishlist are back in stock",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.restock-digest', with: [
            'coffees' => $this->coffees,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }
}
