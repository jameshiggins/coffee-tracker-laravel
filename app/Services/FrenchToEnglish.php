<?php

namespace App\Services;

/**
 * Best-effort French→English translation for coffee-domain terms.
 *
 * This isn't a general-purpose translator — it's a dictionary of the
 * vocabulary that shows up on Quebec specialty roaster sites (titles,
 * tasting notes, processing methods). Applied during import so the
 * directory presents a uniformly English UX.
 *
 * Word-boundary matched, case-preserving on initial caps. Missing terms
 * pass through unchanged — this is additive, not destructive.
 */
class FrenchToEnglish
{
    /**
     * Coffee-domain vocabulary. Ordered: longer phrases first so e.g.
     * "café filtre" wins over bare "café".
     */
    private const DICTIONARY = [
        // Multi-word phrases
        'boîte d\'échantillons' => 'sample box',
        'boite d\'echantillons' => 'sample box',
        'coffret découverte' => 'discovery box',
        'coffret decouverte' => 'discovery box',
        'trousse de dégustation' => 'tasting kit',
        'trousse de degustation' => 'tasting kit',
        'adresses commerciales' => 'commercial addresses',
        'pour les commerces' => 'for businesses',
        'uniquement pour les' => 'only for',
        'café filtre' => 'filter coffee',
        'café espresso' => 'espresso',
        'café noir' => 'black coffee',
        'mélange maison' => 'house blend',
        'mélange du jour' => "today's blend",
        'origine unique' => 'single origin',
        'pleine fleur' => 'full bloom',
        'lavé à l\'eau' => 'washed',
        'séchage naturel' => 'natural process',
        'traité au miel' => 'honey processed',
        'traitement par voie sèche' => 'natural process',
        'traitement par voie humide' => 'washed',
        'note de dégustation' => 'tasting note',
        'notes de dégustation' => 'tasting notes',
        'sucre brun' => 'brown sugar',
        'sucre roux' => 'brown sugar',
        'fève de cacao' => 'cocoa bean',
        'chocolat noir' => 'dark chocolate',
        'chocolat au lait' => 'milk chocolate',
        'chocolat blanc' => 'white chocolate',
        'fruit rouge' => 'red fruit',
        'fruits rouges' => 'red fruits',
        'fruit tropical' => 'tropical fruit',
        'fruits tropicaux' => 'tropical fruits',
        'fruit à noyau' => 'stone fruit',
        'fruits à noyau' => 'stone fruits',
        'agrumes' => 'citrus',
        'fleur d\'oranger' => 'orange blossom',
        'pamplemousse rose' => 'pink grapefruit',
        'thé noir' => 'black tea',
        'thé vert' => 'green tea',

        // Single words — coffee-specific
        'café' => 'coffee',
        'mélange' => 'blend',
        'brûlerie' => 'roastery',
        'torréfaction' => 'roasting',
        'torréfié' => 'roasted',
        'torréfiée' => 'roasted',
        'filtre' => 'filter',
        'lavé' => 'washed',
        'lavée' => 'washed',
        'naturel' => 'natural',
        'naturelle' => 'natural',
        'miel' => 'honey',
        'rôti' => 'roasted',
        'rôtie' => 'roasted',
        'décaféiné' => 'decaf',
        'décaféinée' => 'decaf',
        'décaf' => 'decaf',
        'biologique' => 'organic',
        'bio' => 'organic',
        'équitable' => 'fair trade',
        'producteur' => 'producer',
        'productrice' => 'producer',
        'récolte' => 'harvest',
        'altitude' => 'altitude',
        'région' => 'region',
        'pays' => 'country',
        'origine' => 'origin',
        'échantillon' => 'sample',
        'échantillons' => 'samples',
        'echantillon' => 'sample',
        'echantillons' => 'samples',
        'boîte' => 'box',
        'boite' => 'box',
        'sac' => 'bag',
        'sacs' => 'bags',
        'commerciale' => 'commercial',
        'commerciales' => 'commercial',
        'curieux' => 'curious',
        'goûter' => 'taste',
        'gouter' => 'taste',
        'avant' => 'before',
        'engager' => 'commit',
        'comprend' => 'understand',
        'permettre' => 'allow',
        'découvrir' => 'discover',
        'decouvrir' => 'discover',
        'nouveautés' => 'new arrivals',
        'nouveautes' => 'new arrivals',
        'tarif' => 'price',
        'fixe' => 'fixed',
        'offert' => 'offered',
        'offerte' => 'offered',
        'uniquement' => 'only',

        // Tasting notes — fruits
        'cerise' => 'cherry',
        'cerises' => 'cherries',
        'bleuet' => 'blueberry',
        'bleuets' => 'blueberries',
        'framboise' => 'raspberry',
        'framboises' => 'raspberries',
        'fraise' => 'strawberry',
        'fraises' => 'strawberries',
        'cassis' => 'blackcurrant',
        'mûre' => 'blackberry',
        'mûres' => 'blackberries',
        'pomme' => 'apple',
        'pommes' => 'apples',
        'poire' => 'pear',
        'poires' => 'pears',
        'banane' => 'banana',
        'pêche' => 'peach',
        'pêches' => 'peaches',
        'abricot' => 'apricot',
        'prune' => 'plum',
        'mangue' => 'mango',
        'ananas' => 'pineapple',
        'fruit' => 'fruit',
        'fruits' => 'fruits',
        'citron' => 'lemon',
        'lime' => 'lime',
        'pamplemousse' => 'grapefruit',
        'mandarine' => 'mandarin',
        'tangerine' => 'tangerine',
        'figue' => 'fig',
        'figues' => 'figs',
        'datte' => 'date',
        'dattes' => 'dates',
        'raisin' => 'grape',
        'raisins' => 'grapes',

        // Tasting notes — sweet / nuts / chocolate
        'chocolat' => 'chocolate',
        'cacao' => 'cocoa',
        'caramel' => 'caramel', // same
        'vanille' => 'vanilla',
        'noix' => 'nuts',
        'noisette' => 'hazelnut',
        'noisettes' => 'hazelnuts',
        'amande' => 'almond',
        'amandes' => 'almonds',
        'cacahuète' => 'peanut',
        'arachide' => 'peanut',
        'pacane' => 'pecan',
        'sucre' => 'sugar',
        'mélasse' => 'molasses',
        'sirop d\'érable' => 'maple syrup',
        'érable' => 'maple',
        'beurre' => 'butter',
        'crème' => 'cream',
        'crémeux' => 'creamy',
        'crémeuse' => 'creamy',

        // Tasting notes — flowers / herbs / spices
        'fleur' => 'flower',
        'fleurs' => 'flowers',
        'floral' => 'floral',
        'florale' => 'floral',
        'jasmin' => 'jasmine',
        'rose' => 'rose',
        'lavande' => 'lavender',
        'cannelle' => 'cinnamon',
        'épice' => 'spice',
        'épices' => 'spices',
        'épicé' => 'spiced',
        'poivre' => 'pepper',
        'menthe' => 'mint',

        // Mouthfeel / acidity
        'sucré' => 'sweet',
        'sucrée' => 'sweet',
        'acide' => 'acidic',
        'acidité' => 'acidity',
        'amer' => 'bitter',
        'amère' => 'bitter',
        'doux' => 'smooth',
        'douce' => 'smooth',
        'lourd' => 'heavy',
        'léger' => 'light',
        'légère' => 'light',
        'corsé' => 'full-bodied',
        'corsée' => 'full-bodied',
        'équilibré' => 'balanced',
        'équilibrée' => 'balanced',
        'rond' => 'round',
        'ronde' => 'round',
        'puissant' => 'strong',
        'puissante' => 'strong',
    ];

    /**
     * Translate French phrases inside a string to English.
     * Word-boundary matched (won't munge sub-strings of other words).
     * Preserves the original case of the FIRST letter where possible.
     */
    public static function translate(?string $text): ?string
    {
        if ($text === null || $text === '') return $text;

        $out = $text;
        foreach (self::DICTIONARY as $fr => $en) {
            // Use unicode-aware word boundaries (\b doesn't match across
            // accented chars properly in PHP without /u flag).
            $pattern = '/(?<![\p{L}\-])' . preg_quote($fr, '/') . '(?![\p{L}\-])/iu';
            $out = preg_replace_callback($pattern, function ($m) use ($en) {
                $hit = $m[0];
                // Preserve initial-caps when source was capitalized.
                if (mb_strlen($hit) > 0 && mb_strtoupper(mb_substr($hit, 0, 1)) === mb_substr($hit, 0, 1)) {
                    return mb_strtoupper(mb_substr($en, 0, 1)) . mb_substr($en, 1);
                }
                return $en;
            }, $out) ?? $out;
        }
        return $out;
    }
}
