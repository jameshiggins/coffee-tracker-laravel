<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Walks every active roaster + its non-removed coffees and HEAD-probes
 * each outbound URL (roaster.website, roaster.instagram, coffee.product_url,
 * variant.purchase_link). Reports an OK / REDIRECT / BROKEN breakdown plus
 * the first N broken URLs so we can spot link rot before users do.
 *
 *   php artisan roasters:check-links
 *   php artisan roasters:check-links --only=oso-negro-coffee
 *
 * Categorization:
 *   2xx                  → OK
 *   3xx (Location: …)    → REDIRECT (visible target — usually still works)
 *   4xx / 5xx / network  → BROKEN
 *
 * Soft-removed coffees, inactive roasters, and null URLs are skipped.
 */
class CheckLinks extends Command
{
    protected $signature = 'roasters:check-links
                            {--only= : Limit to a single roaster by slug}
                            {--max-broken=20 : Maximum broken URLs to print in detail}';

    protected $description = 'HEAD-probe every roaster + coffee + variant URL and report link health.';

    /** Per-request timeout — generous because some shops are slow but eventually respond. */
    private const TIMEOUT = 8;

    public function handle(): int
    {
        $query = Roaster::query()->where('is_active', true);
        if ($only = $this->option('only')) {
            $query->where('slug', $only);
        }
        $roasters = $query->with(['coffees' => fn ($q) => $q->whereNull('removed_at'), 'coffees.variants'])
            ->orderBy('name')->get();

        $maxBroken = (int) $this->option('max-broken');
        $ok = 0; $redirect = 0; $broken = 0;
        $brokenSamples = [];

        foreach ($roasters as $r) {
            $links = $this->collectLinks($r);
            foreach ($links as $label => $url) {
                if ($url === null || $url === '') continue;
                $result = $this->probe($url);
                switch ($result['kind']) {
                    case 'ok':
                        $ok++;
                        break;
                    case 'redirect':
                        $redirect++;
                        break;
                    default:
                        $broken++;
                        if (count($brokenSamples) < $maxBroken) {
                            $statusSuffix = isset($result['status'])
                                ? sprintf(' [HTTP %d]', $result['status'])
                                : (isset($result['error']) ? sprintf(' [%s]', $result['error']) : '');
                            $brokenSamples[] = sprintf('  %s | %s | %s%s', $r->name, $label, $url, $statusSuffix);
                        }
                        break;
                }
            }
        }

        // One line per kind so that each shows up cleanly in CI logs (and so
        // test expectations can match each independently — Laravel's
        // expectsOutputToContain consumes output as it matches, so multiple
        // expectations on a single concatenated line can race).
        $this->info(sprintf('OK: %d', $ok));
        $this->info(sprintf('REDIRECT: %d', $redirect));
        $this->info(sprintf('BROKEN: %d', $broken));
        if (!empty($brokenSamples)) {
            $this->newLine();
            $this->warn('Broken links:');
            foreach ($brokenSamples as $line) $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Collect every outbound URL on a roaster, labelled for the broken-list
     * report. Variants get labels like "Yirgacheffe variant 250g" so a
     * broken purchase_link can be traced back to the specific bag size.
     *
     * @return array<string, string|null>
     */
    private function collectLinks(Roaster $r): array
    {
        $links = [
            'website'   => $r->website,
            'instagram' => $r->instagram,
        ];
        foreach ($r->coffees as $c) {
            $cKey = $c->name;
            if (!empty($c->product_url)) {
                $links["coffee:{$cKey}:product_url"] = $c->product_url;
            }
            foreach ($c->variants as $v) {
                if (!empty($v->purchase_link)) {
                    $links["variant:{$cKey}:{$v->bag_weight_grams}g"] = $v->purchase_link;
                }
            }
        }
        return $links;
    }

    /**
     * Probe a single URL and classify the result. Uses a Range-limited
     * GET rather than HEAD because Shopify (and Cloudflare in front of
     * many roaster sites) rejects HEAD with 403 from data-center IPs,
     * which would false-flag every Shopify-hosted variant link as
     * broken when those links work fine for real users. Range: bytes=0-1023
     * keeps the bandwidth low — the server returns either 200 with a
     * truncated body or 206 (Partial Content); both count as OK.
     *
     * Browser-shaped Accept / Accept-Language headers further reduce
     * the chance of bot-detection 403s. Status 206 is a valid 2xx for
     * our purposes.
     *
     * @return array{kind: 'ok'|'redirect'|'broken'|'error', status?: int, error?: string}
     */
    private function probe(string $url): array
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15 roastmap-link-checker/1.0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-CA,en;q=0.9',
            'Range' => 'bytes=0-1023',
        ];
        try {
            $r = Http::timeout(self::TIMEOUT)
                ->withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException $e) {
            return ['kind' => 'broken', 'error' => 'connection: ' . substr($e->getMessage(), 0, 80)];
        } catch (\Throwable $e) {
            return ['kind' => 'broken', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 80)];
        }

        $code = $r->status();
        // 200 = honored full response (server ignored Range)
        // 206 = honored Range — Partial Content, still success
        if ($code >= 200 && $code < 300) return ['kind' => 'ok', 'status' => $code];
        if ($code >= 300 && $code < 400) return ['kind' => 'redirect', 'status' => $code];
        return ['kind' => 'broken', 'status' => $code];
    }
}
