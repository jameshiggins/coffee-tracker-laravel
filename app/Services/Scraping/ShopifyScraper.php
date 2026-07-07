<?php

namespace App\Services\Scraping;

use App\Services\CoffeeFieldExtractor;
use App\Services\Http\SafeHttp;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyScraper implements RoasterScraper
{
    /**
     * Upper bound on per-product page fetches for metafield enrichment in a
     * single import. Shopify's /products.json never includes metafields, so
     * roasters who keep roast/notes/process there (Agro Roasters is the
     * canonical case) need one extra HTML fetch per affected product. The cap
     * protects the daily cron from a pathologically large catalog; the
     * thin-body gate in enrichFromMetafields() already keeps most roasters
     * from triggering any fetches at all.
     */
    private const MAX_ENRICH_FETCHES = 40;

    /**
     * Below this body_html length we treat the description as "thin" — a strong
     * signal the roaster parked the real detail (notes/roast/process) in
     * metafields rather than the product body. Agro's bodies run ~95 chars
     * ("This coffee is part of our seasonal lineup…").
     */
    private const THIN_BODY_CHARS = 240;

    /**
     * Shopify's /products.json returns at most 250 products PER PAGE (coffee
     * and gear interleaved — looksLikeCoffee() filtering happens after the
     * fetch). A single un-paginated request silently drops the tail of any
     * larger catalog, and the importer then soft-removes the dropped coffees,
     * flipping in-stock beans to "removed". Rogue Wave (~730 products) is the
     * canonical case. We paginate with ?page=N up to this cap.
     */
    private const MAX_PAGES = 10;

    public function platformKey(): string
    {
        return 'shopify';
    }

    public function canHandle(string $url): bool
    {
        try {
            $endpoint = Shared::origin($url) . '/products.json?limit=1';
            $response = SafeHttp::client(10)->acceptJson()->get($endpoint);
            if (!$response->ok()) return false;
            $body = $response->json();
            // Shopify returns {"products":[...]} on success even when empty.
            return is_array($body) && array_key_exists('products', $body);
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetch(string $url): array
    {
        $origin = Shared::origin($url);
        $rawProducts = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $endpoint = $origin . '/products.json?limit=250&page=' . $page;
            $response = SafeHttp::client(15)->acceptJson()->get($endpoint);
            if (!$response->ok()) {
                // A first-page failure is a hard error (the store is down /
                // not really Shopify); a later-page failure just ends
                // pagination with whatever we already collected.
                if ($page === 1) {
                    throw new RuntimeException("Shopify fetch failed: {$response->status()} for {$endpoint}");
                }
                break;
            }
            $batch = $response->json()['products'] ?? [];
            if (!is_array($batch) || $batch === []) {
                break; // empty page = past the end
            }
            $rawProducts = array_merge($rawProducts, $batch);
            if (count($batch) < 250) {
                break; // short page = last page
            }
        }

        $coffees = $this->normalize($url, ['products' => $rawProducts]);
        return $this->enrichFromMetafields($coffees);
    }

    /**
     * Best-effort recovery of detail fields that live in Shopify metafields
     * (absent from /products.json). For each coffee whose body_html is thin AND
     * carries no extractable tasting notes — the "details are in metafields"
     * signal — fetch the product page once and parse the rendered
     * "<strong>Label: </strong><span class="metafield-…">value</span>" rows,
     * then feed those through the same CoffeeFieldExtractor the importer uses so
     * normalization (roast canonicalization, bullet-note splitting, process
     * mapping) stays in one place.
     *
     * Failures are swallowed per-product: enrichment must never turn a working
     * import into a failed one. Bounded by MAX_ENRICH_FETCHES.
     *
     * @param  array<int, array<string, mixed>>  $coffees
     * @return array<int, array<string, mixed>>
     */
    private function enrichFromMetafields(array $coffees): array
    {
        $fetched = 0;

        foreach ($coffees as $i => $coffee) {
            if ($fetched >= self::MAX_ENRICH_FETCHES) break;

            $productUrl = $coffee['product_url'] ?? null;
            if (!$productUrl) continue;

            $description = (string) ($coffee['description'] ?? '');
            // Gate: only spend a request when the body is thin AND gave us no
            // notes. A rich body_html almost always carries the rest inline, so
            // this keeps non-metafield roasters from triggering any fetches.
            if (mb_strlen(trim($description)) >= self::THIN_BODY_CHARS) continue;
            if (CoffeeFieldExtractor::extractTastingNotes($description) !== null) continue;

            try {
                $resp = SafeHttp::client(12)->get($productUrl);
                if (!$resp->ok()) continue;
            } catch (\Throwable) {
                continue;
            }
            $fetched++;

            $pairs = self::extractMetafieldPairs($resp->body());
            if (empty($pairs)) continue;

            $coffees[$i] = self::applyMetafieldPairs($coffee, $pairs);
        }

        return $coffees;
    }

    /**
     * Pull "Label: value" detail rows out of a rendered Shopify product page.
     * Targets the common metafield rendering where the label is bolded and the
     * value follows (optionally wrapped in a metafield span):
     *   <strong>Roast: </strong><span class="metafield-…">Light</span>
     * Returns a label => value map (first occurrence wins). Pure / no HTTP, so
     * it's unit-tested directly against captured HTML.
     *
     * @return array<string, string>
     */
    public static function extractMetafieldPairs(string $html): array
    {
        $pairs = [];
        if (preg_match_all(
            '/<strong>\s*([A-Za-z][A-Za-z \/]{1,24}?)\s*:\s*<\/strong>\s*(?:<span[^>]*>)?\s*([^<]{1,160})/u',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $label = trim($m[1]);
                $value = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));
                if ($label !== '' && $value !== '' && !isset($pairs[$label])) {
                    $pairs[$label] = $value;
                }
            }
        }
        return $pairs;
    }

    /**
     * Fold parsed metafield pairs onto a coffee row, filling ONLY fields that
     * are still empty (the importer treats explicit row keys as authoritative
     * over its own description-based extraction). Each value is routed through
     * the matching CoffeeFieldExtractor so roast/process/notes come out in the
     * same canonical shape as the body_html path.
     *
     * @param  array<string, mixed>  $coffee
     * @param  array<string, string>  $pairs
     * @return array<string, mixed>
     */
    public static function applyMetafieldPairs(array $coffee, array $pairs): array
    {
        // Case-insensitive label lookup.
        $byLabel = [];
        foreach ($pairs as $label => $value) {
            $byLabel[strtolower($label)] = $value;
        }
        $pick = function (array $keys) use ($byLabel): string {
            foreach ($keys as $k) {
                if (!empty($byLabel[$k])) return $byLabel[$k];
            }
            return '';
        };

        if (empty($coffee['tasting_notes'])) {
            $raw = $pick(['tasting notes', 'notes', 'flavour notes', 'flavor notes', 'cup notes']);
            // Re-attach a "Notes:" label so extractTastingNotes runs its full
            // gate (looksLikeTastingNoteList) + bullet-separator normalization.
            $notes = $raw !== '' ? CoffeeFieldExtractor::extractTastingNotes('Notes: ' . $raw) : null;
            if ($notes) $coffee['tasting_notes'] = $notes;
        }

        if (empty($coffee['roast_level'])) {
            $raw = $pick(['roast', 'roast level', 'roast profile']);
            $roast = $raw !== '' ? CoffeeFieldExtractor::extractRoastLevel('Roast: ' . $raw) : null;
            if ($roast) $coffee['roast_level'] = $roast;
        }

        if (empty($coffee['process'])) {
            $raw = $pick(['process', 'processing', 'process method']);
            $process = $raw !== '' ? CoffeeFieldExtractor::extractProcess($raw) : null;
            if ($process) $coffee['process'] = $process;
        }

        if (empty($coffee['varietal'])) {
            $raw = $pick(['varietal', 'variety', 'varieties', 'cultivar']);
            $varietal = $raw !== '' ? CoffeeFieldExtractor::extractVarietal($raw) : null;
            if ($varietal) $coffee['varietal'] = $varietal;
        }

        return $coffee;
    }

    /**
     * Find a bag-weight signal inside a product description. Delegates to the
     * shared standard-size-whitelist parser (Shared::parseBodyGrams) so the
     * Shopify body_html path and the Squarespace excerpt path stay in lockstep.
     */
    private function parseBodyGrams(string $body): ?int
    {
        return Shared::parseBodyGrams($body);
    }

    /** Filter Shopify products to coffee bags and normalize to the RoasterScraper output shape. */
    public function normalize(string $url, ?array $payload): array
    {
        $origin = Shared::origin($url);
        $out = [];

        foreach ($payload['products'] ?? [] as $p) {
            $title = (string) ($p['title'] ?? '');
            $productType = (string) ($p['product_type'] ?? '');
            $tags = is_array($p['tags'] ?? null) ? $p['tags'] : explode(',', (string) ($p['tags'] ?? ''));

            if (!Shared::looksLikeCoffee($title, $productType, $tags)) continue;

            $productUrl = !empty($p['handle'])
                ? $origin . '/products/' . $p['handle']
                : null;

            // Pre-compute a body_html-derived gram weight for the product —
            // used as a last-resort fallback when neither variant title nor
            // product title carry a parseable bag size. Botany Rd is the
            // canonical case: variants are all "Default Title", product
            // titles read "DORSIA | MILK BAR" / "ZOQUIÁPAM WASHED | MEXICO"
            // with no grams, but the body_html includes "250G". Whitespace
            // is collapsed first because some templates render the size as
            // "2  50G" or "250  G" via formatting artifacts.
            // Replace tags with a space BEFORE stripping, so "</p><p>250G</p>"
            // becomes " 250G " not "Bag250G" (which would have no word boundary
            // before the digit and parseGrams would miss it entirely).
            $bodyForWeight = preg_replace('/\s+/', ' ', strip_tags(
                preg_replace('/<[^>]+>/', ' ', (string) ($p['body_html'] ?? ''))
            ));
            $bodyGrams = $this->parseBodyGrams($bodyForWeight);

            $rawVariants = [];
            foreach ($p['variants'] ?? [] as $v) {
                $varTitle = (string) ($v['title'] ?? '');
                // Skip multipack / portion-pack / pod / sample-flight variants —
                // they corrupt per-gram pricing because the size we'd record
                // is for one packet, not the bundle's total weight.
                if (Shared::isBadVariantTitle($varTitle)) continue;
                // Try the variant title first; fall back to the product title
                // for shops that put the bag size in the product name and use
                // "Default Title" as the variant (Thom Bargen, Sam James pattern).
                // Last resort: the body_html — covers Default-Title-only sites
                // that put the bag size only in the product description (Botany
                // Rd pattern).
                $grams = Shared::parseGrams($varTitle)
                    ?? Shared::parseGrams($title)
                    ?? $bodyGrams;
                if ($grams === null) continue;
                $variantId = isset($v['id']) ? (string) $v['id'] : null;
                $price = (float) ($v['price'] ?? 0);
                if ($price <= 0) continue;  // free/missing price = bad data
                // Per-variant deep link: Shopify product pages preselect the
                // size dropdown when ?variant=<id> is in the URL.
                $variantPurchaseLink = ($productUrl && $variantId)
                    ? $productUrl . '?variant=' . $variantId
                    : $productUrl;
                $rawVariants[] = [
                    'grams' => $grams,
                    'price' => $price,
                    'available' => (bool) ($v['available'] ?? true),
                    'source_id' => $variantId,
                    'purchase_link' => $variantPurchaseLink,
                    'source_size_label' => Shared::extractSourceSizeLabel($varTitle),
                ];
            }
            $variants = Shared::dedupeVariantsByGrams($rawVariants);
            if (empty($variants)) continue;

            $imageUrl = null;
            if (!empty($p['images']) && is_array($p['images'])) {
                $first = $p['images'][0] ?? null;
                $imageUrl = is_array($first) ? ($first['src'] ?? null) : null;
            }

            $out[] = [
                'name' => $title,
                'source_id' => isset($p['id']) ? (string) $p['id'] : '',
                // Replace block tags with a space BEFORE stripping so adjacent
                // <p>Origin</p><p>Process</p> doesn't merge into "OriginProcess".
                // RoasterImporter::cleanDescription then collapses the
                // whitespace and trims; the intermediate space-padding is
                // what saves the word boundary.
                'description' => strip_tags(preg_replace('/<[^>]+>/', ' ', (string) ($p['body_html'] ?? ''))),
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
                'is_blend' => Shared::isBlend($title, $productType, $tags),
                'variants' => $variants,
            ];
        }

        return $out;
    }
}
