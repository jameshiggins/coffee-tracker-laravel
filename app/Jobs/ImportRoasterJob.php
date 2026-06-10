<?php

namespace App\Jobs;

use App\Services\RoasterImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * H7: a roaster import is a multi-fetch scrape (catalog + about + shipping +
 * favicon + up to ~40 product pages) that can take minutes. Running it inline
 * on the admin web request (POST /admin/import, /admin/roasters/{r}/refresh)
 * blocks and can time out the request. This job moves it onto the queue
 * worker. RoasterImporter already persists last_import_status, so failures are
 * visible on the admin index without the job needing to report back.
 */
class ImportRoasterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One attempt: don't re-hammer a flaky/blocked roaster site on failure. */
    public int $tries = 1;

    /** A full scrape can legitimately run for a few minutes. */
    public int $timeout = 300;

    public function __construct(
        public string $url,
        public ?string $name = null,
        public ?string $city = null,
        public ?string $region = null,
    ) {
    }

    public function handle(RoasterImporter $importer): void
    {
        $importer->import($this->url, name: $this->name, city: $this->city, region: $this->region);
    }
}
