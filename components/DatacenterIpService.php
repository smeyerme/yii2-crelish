<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;

/**
 * Service to detect datacenter/cloud provider IPs
 *
 * Fetches and caches known datacenter IP ranges from public sources.
 * Real users rarely browse from datacenters - these are typically bots/scrapers.
 */
class DatacenterIpService extends Component
{
  /**
   * @var int Cache duration in seconds (default: 24 hours)
   */
  public int $cacheDuration = 86400;

  /**
   * @var string Cache key for storing IP ranges
   */
  public string $cacheKey = 'datacenter_ip_ranges';

  /**
   * @var array URLs to fetch datacenter IP lists from
   */
  public array $sources = [
    'ipcat' => 'https://raw.githubusercontent.com/client9/ipcat/master/datacenters.csv',
  ];

  /**
   * @var array Known cloud provider CIDR ranges (fallback if fetch fails)
   */
  protected array $fallbackRanges = [
    // Tencent Cloud (the botnet hitting your site)
    '43.128.0.0/10',
    '43.152.0.0/13',
    '43.160.0.0/11',
    '124.156.0.0/16',
    '124.157.0.0/16',
    // AWS
    '3.0.0.0/8',
    '13.0.0.0/8',
    '18.0.0.0/8',
    '52.0.0.0/8',
    '54.0.0.0/8',
    // Google Cloud
    '34.0.0.0/8',
    '35.0.0.0/8',
    // Azure
    '13.64.0.0/11',
    '40.64.0.0/10',
    // DigitalOcean
    '64.225.0.0/16',
    '134.122.0.0/15',
    '137.184.0.0/14',
    '138.68.0.0/15',
    '142.93.0.0/16',
    '157.230.0.0/15',
    '159.65.0.0/16',
    '159.89.0.0/16',
    '161.35.0.0/16',
    '164.90.0.0/15',
    '164.92.0.0/14',
    '165.22.0.0/15',
    '165.227.0.0/16',
    '167.71.0.0/16',
    '167.172.0.0/14',
    '174.138.0.0/15',
    '178.62.0.0/15',
    '178.128.0.0/13',
    '188.166.0.0/15',
    '192.241.128.0/17',
    '206.189.0.0/16',
    // Vultr
    '45.32.0.0/14',
    '45.63.0.0/16',
    '45.76.0.0/15',
    '45.77.0.0/16',
    '64.156.0.0/14',
    '66.42.0.0/16',
    '104.156.224.0/19',
    '104.207.128.0/17',
    '108.61.0.0/16',
    '136.244.0.0/14',
    '140.82.0.0/15',
    '149.28.0.0/15',
    '155.138.0.0/16',
    '207.246.0.0/16',
    '209.250.224.0/19',
    // Linode
    '45.33.0.0/17',
    '45.56.64.0/18',
    '45.79.0.0/16',
    '50.116.0.0/18',
    '66.175.208.0/20',
    '69.164.192.0/19',
    '72.14.176.0/20',
    '74.207.224.0/19',
    '96.126.96.0/19',
    '139.144.0.0/14',
    '139.162.0.0/16',
    '170.187.128.0/17',
    '172.104.0.0/15',
    '172.232.0.0/14',
    '173.255.192.0/18',
    '178.79.128.0/17',
    '192.155.80.0/20',
    '194.195.208.0/20',
    '198.58.96.0/19',
    // OVH
    '51.38.0.0/16',
    '51.68.0.0/16',
    '51.75.0.0/16',
    '51.77.0.0/16',
    '51.79.0.0/16',
    '51.81.0.0/16',
    '51.83.0.0/16',
    '51.89.0.0/16',
    '51.91.0.0/16',
    '51.161.0.0/16',
    '51.178.0.0/16',
    '51.195.0.0/16',
    '51.210.0.0/16',
    '51.254.0.0/15',
    '54.36.0.0/14',
    '54.37.0.0/16',
    '54.38.0.0/16',
    '54.39.0.0/16',
    '135.125.0.0/16',
    '137.74.0.0/16',
    '139.99.0.0/17',
    '141.94.0.0/15',
    '142.44.128.0/17',
    '144.217.0.0/16',
    '145.239.0.0/16',
    '147.135.0.0/17',
    '149.56.0.0/16',
    '151.80.0.0/16',
    '158.69.0.0/16',
    '162.19.0.0/16',
    '164.132.0.0/16',
    '167.114.0.0/16',
    '176.31.0.0/16',
    '178.32.0.0/15',
    '185.228.64.0/18',
    '188.165.0.0/16',
    '192.95.0.0/18',
    '192.99.0.0/16',
    '193.70.0.0/17',
    '198.27.64.0/18',
    '198.50.128.0/17',
    '198.100.144.0/20',
    '198.245.48.0/20',
    // Hetzner
    '5.9.0.0/16',
    '23.88.0.0/14',
    '46.4.0.0/16',
    '49.12.0.0/14',
    '49.13.0.0/16',
    '65.21.0.0/16',
    '78.46.0.0/15',
    '85.10.192.0/18',
    '88.198.0.0/16',
    '88.99.0.0/16',
    '91.107.128.0/17',
    '94.130.0.0/16',
    '95.216.0.0/15',
    '95.217.0.0/16',
    '116.202.0.0/15',
    '116.203.0.0/16',
    '128.140.0.0/17',
    '135.181.0.0/16',
    '136.243.0.0/16',
    '138.201.0.0/16',
    '142.132.128.0/17',
    '144.76.0.0/16',
    '148.251.0.0/16',
    '157.90.0.0/16',
    '159.69.0.0/16',
    '162.55.0.0/16',
    '168.119.0.0/16',
    '176.9.0.0/16',
    '178.63.0.0/16',
    '185.12.64.0/22',
    '188.40.0.0/16',
    '195.201.0.0/16',
    '213.133.96.0/19',
    '213.239.192.0/18',
  ];

  /**
   * @var string|null Path to GeoLite2-ASN.mmdb database file for ASN-based detection
   */
  public ?string $asnDatabasePath = null;

  /**
   * @var object|null Lazy-loaded GeoIp2 ASN reader
   */
  private ?object $_asnReader = null;

  /**
   * @var array Known hosting provider ASN org name patterns
   */
  protected array $hostingAsnPatterns = [
    'Amazon',
    'AWS',
    'Google Cloud',
    'Google LLC',
    'Microsoft Azure',
    'Microsoft Corporation',
    'DigitalOcean',
    'Linode',
    'Akamai Connected Cloud',
    'Vultr',
    'OVH',
    'OVHcloud',
    'Hetzner',
    'Alibaba Cloud',
    'Alibaba US',
    'Tencent Cloud',
    'Oracle Cloud',
    'Oracle Corporation',
    'Rackspace',
    'Scaleway',
    'UpCloud',
    'Cloudflare',
    'Fastly',
    'Leaseweb',
    'ColoCrossing',
    'QuadraNet',
    'Choopa',
  ];

  /**
   * @var array|null Cached parsed IP ranges
   */
  private ?array $_parsedRanges = null;

  /**
   * Check if an IP belongs to a known datacenter
   *
   * @param string $ip IP address to check
   * @return array|false Returns provider info array if datacenter IP, false otherwise
   */
  public function isDatacenterIp(string $ip): array|false
  {
    $ranges = $this->getIpRanges();
    $ipLong = ip2long($ip);

    if ($ipLong === false) {
      return false;
    }

    foreach ($ranges as $range) {
      if ($this->ipInRange($ipLong, $range['start'], $range['end'])) {
        return [
          'provider' => $range['provider'] ?? 'Unknown Datacenter',
          'cidr' => $range['cidr'] ?? 'Unknown',
        ];
      }
    }

    // Fallback: try ASN-based lookup if configured
    $asnResult = $this->isHostingAsn($ip);
    if ($asnResult !== false) {
      return $asnResult;
    }

    return false;
  }

  /**
   * Get all IP ranges (from cache or fetch fresh)
   *
   * @return array Array of parsed IP ranges
   */
  public function getIpRanges(): array
  {
    if ($this->_parsedRanges !== null) {
      return $this->_parsedRanges;
    }

    // Try to get from cache
    $cache = Yii::$app->cache ?? null;
    if ($cache) {
      $cached = $cache->get($this->cacheKey);
      if ($cached !== false) {
        $this->_parsedRanges = $cached;
        return $cached;
      }
    }

    // Fetch fresh data
    $ranges = $this->fetchAndParseRanges();

    // Cache the results
    if ($cache && !empty($ranges)) {
      $cache->set($this->cacheKey, $ranges, $this->cacheDuration);
    }

    $this->_parsedRanges = $ranges;
    return $ranges;
  }

  /**
   * Force refresh the IP ranges cache
   *
   * @return int Number of ranges loaded
   */
  public function refreshCache(): int
  {
    $this->_parsedRanges = null;

    $cache = Yii::$app->cache ?? null;
    if ($cache) {
      $cache->delete($this->cacheKey);
    }

    $ranges = $this->getIpRanges();
    return count($ranges);
  }

  /**
   * Fetch IP ranges from sources and parse them
   *
   * @return array Parsed IP ranges
   */
  protected function fetchAndParseRanges(): array
  {
    $ranges = [];

    // Try to fetch from ipcat
    try {
      $content = $this->fetchUrl($this->sources['ipcat']);
      if ($content) {
        $ranges = array_merge($ranges, $this->parseIpcatCsv($content));
      }
    } catch (\Exception $e) {
      Yii::warning('Failed to fetch ipcat data: ' . $e->getMessage(), 'datacenter-ip');
    }

    // If we got no data, use fallback ranges
    if (empty($ranges)) {
      Yii::info('Using fallback datacenter IP ranges', 'datacenter-ip');
      $ranges = $this->parseFallbackRanges();
    }

    return $ranges;
  }

  /**
   * Fetch URL content with timeout
   *
   * @param string $url URL to fetch
   * @return string|null Content or null on failure
   */
  protected function fetchUrl(string $url): ?string
  {
    $context = stream_context_create([
      'http' => [
        'timeout' => 30,
        'user_agent' => 'CrelishBotDetection/1.0',
      ],
    ]);

    $content = @file_get_contents($url, false, $context);
    return $content !== false ? $content : null;
  }

  /**
   * Parse ipcat CSV format
   *
   * @param string $content CSV content
   * @return array Parsed ranges
   */
  protected function parseIpcatCsv(string $content): array
  {
    $ranges = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
      $line = trim($line);
      if (empty($line) || $line[0] === '#') {
        continue;
      }

      $parts = str_getcsv($line);
      if (count($parts) >= 3) {
        $startIp = $parts[0];
        $endIp = $parts[1];
        $provider = $parts[2];

        $startLong = ip2long($startIp);
        $endLong = ip2long($endIp);

        if ($startLong !== false && $endLong !== false) {
          $ranges[] = [
            'start' => $startLong,
            'end' => $endLong,
            'provider' => $provider,
            'cidr' => $startIp . '-' . $endIp,
          ];
        }
      }
    }

    return $ranges;
  }

  /**
   * Parse fallback CIDR ranges
   *
   * @return array Parsed ranges
   */
  protected function parseFallbackRanges(): array
  {
    $ranges = [];

    foreach ($this->fallbackRanges as $cidr) {
      $parsed = $this->parseCidr($cidr);
      if ($parsed) {
        $ranges[] = $parsed;
      }
    }

    return $ranges;
  }

  /**
   * Parse CIDR notation to start/end range
   *
   * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
   * @return array|null Parsed range or null on failure
   */
  protected function parseCidr(string $cidr): ?array
  {
    if (strpos($cidr, '/') === false) {
      return null;
    }

    [$ip, $prefix] = explode('/', $cidr);
    $ipLong = ip2long($ip);

    if ($ipLong === false) {
      return null;
    }

    $prefix = (int)$prefix;
    $mask = -1 << (32 - $prefix);
    $start = $ipLong & $mask;
    $end = $start + (~$mask & 0xFFFFFFFF);

    return [
      'start' => $start,
      'end' => $end,
      'provider' => $this->guessProviderFromCidr($cidr),
      'cidr' => $cidr,
    ];
  }

  /**
   * Guess provider name from CIDR based on known ranges
   *
   * @param string $cidr CIDR notation
   * @return string Provider name
   */
  protected function guessProviderFromCidr(string $cidr): string
  {
    $ip = explode('/', $cidr)[0];
    $firstOctet = (int)explode('.', $ip)[0];
    $secondOctet = (int)explode('.', $ip)[1];

    // Tencent Cloud
    if ($firstOctet === 43 || ($firstOctet === 124 && $secondOctet >= 156)) {
      return 'Tencent Cloud';
    }

    // AWS
    if (in_array($firstOctet, [3, 13, 18, 52, 54])) {
      return 'Amazon AWS';
    }

    // Google Cloud
    if (in_array($firstOctet, [34, 35])) {
      return 'Google Cloud';
    }

    // Azure
    if ($firstOctet === 13 || $firstOctet === 40) {
      return 'Microsoft Azure';
    }

    // DigitalOcean
    if (in_array($firstOctet, [64, 134, 137, 138, 142, 157, 159, 161, 164, 165, 167, 174, 178, 188, 192, 206])) {
      return 'DigitalOcean';
    }

    // OVH
    if (in_array($firstOctet, [51, 54, 135, 137, 139, 141, 142, 144, 145, 147, 149, 151, 158, 162, 164, 167, 176, 178, 185, 188, 192, 193, 198])) {
      return 'OVH';
    }

    // Hetzner
    if (in_array($firstOctet, [5, 23, 46, 49, 65, 78, 85, 88, 91, 94, 95, 116, 128, 135, 136, 138, 142, 144, 148, 157, 159, 162, 168, 176, 178, 185, 188, 195, 213])) {
      return 'Hetzner';
    }

    // Vultr
    if (in_array($firstOctet, [45, 64, 66, 104, 108, 136, 140, 149, 155, 207, 209])) {
      return 'Vultr';
    }

    // Linode
    if (in_array($firstOctet, [45, 50, 66, 69, 72, 74, 96, 139, 170, 172, 173, 178, 192, 194, 198])) {
      return 'Linode';
    }

    return 'Unknown Datacenter';
  }

  /**
   * Check if IP (as long) is within range
   *
   * @param int $ipLong IP as unsigned long
   * @param int $start Range start
   * @param int $end Range end
   * @return bool
   */
  protected function ipInRange(int $ipLong, int $start, int $end): bool
  {
    // Handle unsigned comparison for IPs > 127.x.x.x
    $ipLong = sprintf('%u', $ipLong);
    $start = sprintf('%u', $start);
    $end = sprintf('%u', $end);

    return bccomp($ipLong, $start) >= 0 && bccomp($ipLong, $end) <= 0;
  }

  /**
   * Check if an IP belongs to a known hosting provider via ASN lookup
   *
   * Requires geoip2/geoip2 package and a GeoLite2-ASN.mmdb database file.
   * Silently returns false if dependencies are not available.
   *
   * @param string $ip IP address to check
   * @return array|false Returns provider info array if hosting ASN, false otherwise
   */
  public function isHostingAsn(string $ip): array|false
  {
    if ($this->asnDatabasePath === null || !file_exists($this->asnDatabasePath)) {
      return false;
    }

    if (!class_exists('GeoIp2\Database\Reader')) {
      return false;
    }

    try {
      if ($this->_asnReader === null) {
        $this->_asnReader = new \GeoIp2\Database\Reader($this->asnDatabasePath);
      }

      $record = $this->_asnReader->asn($ip);
      $orgName = $record->autonomousSystemOrganization ?? '';

      if (empty($orgName)) {
        return false;
      }

      foreach ($this->hostingAsnPatterns as $pattern) {
        if (stripos($orgName, $pattern) !== false) {
          return [
            'provider' => $orgName,
            'cidr' => 'ASN:' . ($record->autonomousSystemNumber ?? 'unknown'),
          ];
        }
      }
    } catch (\Exception $e) {
      // Silently skip on any GeoIP2 error (invalid IP, DB error, etc.)
    }

    return false;
  }
}