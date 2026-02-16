<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;

/**
 * Service to detect spam referrer domains
 *
 * Fetches and caches known spam referrer domains from Matomo's public list.
 * Spam referrers pollute analytics with fake traffic from SEO spam sites.
 */
class ReferrerSpamService extends Component
{
  /**
   * @var int Cache duration in seconds (default: 24 hours)
   */
  public int $cacheDuration = 86400;

  /**
   * @var string Cache key for storing spam domains
   */
  public string $cacheKey = 'referrer_spam_domains';

  /**
   * @var string URL to fetch spam referrer list from
   */
  public string $source = 'https://raw.githubusercontent.com/matomo-org/referrer-spam-list/master/spammers.txt';

  /**
   * @var array Hardcoded fallback spam domains if fetch fails
   */
  protected array $fallbackDomains = [
    'semalt.com',
    'buttons-for-website.com',
    'buttons-for-your-website.com',
    'ilovevitaly.com',
    'priceg.com',
    'hulfingtonpost.com',
    'darodar.com',
    'cenoval.ru',
    'lomb.co',
    'econom.co',
    'edakgfvyah.ga',
    'seoanalyses.com',
    'get-free-traffic-now.com',
    'free-social-buttons.com',
    'traffic2money.com',
    'best-seo-offer.com',
    'best-seo-solution.com',
    'buy-cheap-online.info',
    'googlsucks.com',
    'theguardlan.com',
    'webmonetizer.net',
    'ranksonic.info',
    'social-buttons.com',
    'screentoolkit.com',
    'o-o-6-o-o.com',
    'qualitymarketzone.com',
    'floating-share-buttons.com',
    'event-tracking.com',
    'guardlink.org',
    'trafficgenius.xyz',
  ];

  /**
   * @var array|null In-memory cache of parsed domains
   */
  private ?array $_parsedDomains = null;

  /**
   * Check if a hostname is a known spam referrer
   *
   * @param string $hostname Hostname to check
   * @return bool True if spam referrer
   */
  public function isSpammer(string $hostname): bool
  {
    $hostname = strtolower(trim($hostname));
    if (empty($hostname)) {
      return false;
    }

    // Strip www. prefix
    $hostname = preg_replace('/^www\./', '', $hostname);

    $domains = $this->getDomains();

    // Exact match
    if (in_array($hostname, $domains, true)) {
      return true;
    }

    // Subdomain check: is hostname a subdomain of a spam domain?
    foreach ($domains as $spamDomain) {
      if (str_ends_with($hostname, '.' . $spamDomain)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Force refresh the spam domains cache
   *
   * @return int Number of domains loaded
   */
  public function refreshCache(): int
  {
    $this->_parsedDomains = null;

    $cache = Yii::$app->cache ?? null;
    if ($cache) {
      $cache->delete($this->cacheKey);
    }

    $domains = $this->getDomains();
    return count($domains);
  }

  /**
   * Get all spam domains (from memory -> Yii cache -> fetch -> fallback)
   *
   * @return array Array of spam domain strings
   */
  public function getDomains(): array
  {
    // 1. In-memory cache
    if ($this->_parsedDomains !== null) {
      return $this->_parsedDomains;
    }

    // 2. Yii cache
    $cache = Yii::$app->cache ?? null;
    if ($cache) {
      $cached = $cache->get($this->cacheKey);
      if ($cached !== false) {
        $this->_parsedDomains = $cached;
        return $cached;
      }
    }

    // 3. Fetch fresh data
    $domains = $this->fetchDomains();

    // 4. Fallback if fetch fails
    if (empty($domains)) {
      Yii::info('Using fallback spam referrer domains', 'referrer-spam');
      $domains = $this->fallbackDomains;
    }

    // Cache the results
    if ($cache && !empty($domains)) {
      $cache->set($this->cacheKey, $domains, $this->cacheDuration);
    }

    $this->_parsedDomains = $domains;
    return $domains;
  }

  /**
   * Fetch spam domains from remote source
   *
   * @return array Parsed domain list
   */
  protected function fetchDomains(): array
  {
    try {
      $context = stream_context_create([
        'http' => [
          'timeout' => 30,
          'user_agent' => 'CrelishBotDetection/1.0',
        ],
      ]);

      $content = @file_get_contents($this->source, false, $context);
      if ($content === false) {
        Yii::warning('Failed to fetch spam referrer list', 'referrer-spam');
        return [];
      }

      return $this->parseSpammersList($content);
    } catch (\Exception $e) {
      Yii::warning('Failed to fetch spam referrer list: ' . $e->getMessage(), 'referrer-spam');
      return [];
    }
  }

  /**
   * Parse spammers.txt format (one domain per line)
   *
   * @param string $content Raw text content
   * @return array Array of domain strings
   */
  protected function parseSpammersList(string $content): array
  {
    $domains = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
      $line = strtolower(trim($line));
      if (empty($line) || $line[0] === '#') {
        continue;
      }
      $domains[] = $line;
    }

    return $domains;
  }
}
