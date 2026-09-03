<?php

namespace Xstrm\Xstrm;

/**
 * Compact bot filter. Deliberately a single regex over the substrings that
 * appear in essentially every crawler UA, not a UA database — the goal is to
 * keep obvious robots out of the pageview count, not to classify traffic.
 */
final class Bots
{
    private const PATTERN = '/(bot|crawl|spider|slurp|search|fetch|monitor|scrape|curl|wget|python-requests|okhttp|headless|phantom|lighthouse|pingdom|uptime|preview|facebookexternalhit|whatsapp|telegram|slack|discord|embedly|feed|rss|validator|archiver|semrush|ahrefs|mj12|dotbot|petal|bytespider|gptbot|claudebot|ccbot|perplexity)/i';

    public static function matches(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            // No UA at all is not a browser rendering a page.
            return true;
        }

        return (bool) preg_match(self::PATTERN, $userAgent);
    }
}
