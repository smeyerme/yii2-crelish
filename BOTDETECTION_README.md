# Bot Detection System

Retrospective, batch-based bot detection for Crelish Analytics with confidence scoring. Analyzes existing analytics data across 11 detection phases, assigns confidence scores, and removes high-confidence bot traffic.

## Quick Start

```bash
# Dry run (no changes, just shows what would happen)
php yii crelish/bot-detection --dryRun

# Full detection + cleanup
php yii crelish/bot-detection

# View statistics
php yii crelish/bot-detection/stats
```

## How It Works

### Confidence Scoring

Every session is scored across multiple detection phases. Scores accumulate and determine the action taken:

| Level | Score | Action |
|-------|-------|--------|
| **HIGH** | >= 70 | Marked as bot, deleted automatically |
| **MEDIUM** | 30-69 | Flagged for manual review |
| **LOW** | < 30 | Kept as legitimate traffic |

### Detection Phases (11 total)

| # | Phase | Points | What it detects |
|---|-------|--------|-----------------|
| 1 | Orphan sessions | 100 | Sessions with zero page views |
| 2 | Spam referrers | 50 | Known spam referrer domains (Matomo list) |
| 3 | User agent analysis | 50 | Known bots via DeviceDetector library |
| 3 | Dead browsers | 50 | IE, Opera Mini, Konqueror, PaleMoon, dead OS |
| 3 | Outdated browsers | 20-50 | Age-scored based on how outdated the browser/OS is |
| 3 | Custom bot patterns | 50 | Email in UA, headless browsers, monitoring agents, etc. |
| 4 | Volume anomalies | 30-35 | Excessive requests per hour/day, IP volume |
| 5 | Timing patterns | 40 | Robotic timing (consistent intervals) |
| 6 | Crawling patterns | 40 | Sequential pagination crawling |
| 7 | Single-page sessions | 20 | Sessions with only 1 page view (weak signal) |
| 8 | Datacenter IPs | 35 | Cloud provider IPs via ipcat + optional ASN lookup |
| 9 | Combination bonuses | 15-40 | Multiple signals reinforce each other |

### Combination Bonuses

When multiple signals fire on the same session, combo bonuses are applied (highest match wins):

- Outdated + Datacenter + Behavior: **+40**
- Known bot + Datacenter: **+25**
- Spam referrer + Datacenter: **+25**
- Outdated + Datacenter: **+25**
- Spam referrer + Outdated: **+20**
- Outdated + Behavior: **+20**
- Datacenter + Behavior: **+15**
- Custom bot + Outdated: **+15**
- Outdated + Single-page: **+15**

## Configuration

### Config File

Create `@app/config/bot-detection-thresholds.php` to override any default thresholds:

```php
<?php
return [
    // --- ASN-based datacenter detection (optional) ---
    // Set this path to enable. See "ASN Detection Setup" below.
    'asn_database_path' => '/path/to/GeoLite2-ASN.mmdb',

    // --- Volume thresholds ---
    'session_requests_per_hour' => 500,
    'session_requests_per_day' => 2000,
    'ip_requests_per_hour' => 1000,
    'ip_requests_per_day' => 5000,

    // --- Timing thresholds ---
    'consistent_interval_threshold' => 2,  // Stddev in seconds

    // --- Pattern thresholds ---
    'url_diversity_threshold' => 0.95,     // 95% unique URLs = bot
    'sequential_page_threshold' => 5,
    'min_requests_for_pattern_detection' => 50,

    // --- Feature toggles ---
    'enable_datacenter_ip_detection' => true,
];
```

Only include the keys you want to override. Everything has sensible defaults.

### ASN Detection Setup (Optional)

ASN (Autonomous System Number) detection supplements the ipcat IP range list by looking up the organization that owns an IP address. This catches hosting providers that ipcat misses.

**Requirements:**
1. The `geoip2/geoip2` PHP package
2. A GeoLite2-ASN database file (`.mmdb` format) from MaxMind

**Step-by-step:**

1. Install the package:
   ```bash
   composer require geoip2/geoip2
   ```

2. Create a free MaxMind account at https://www.maxmind.com/en/geolite2/signup

3. Log in and download **GeoLite2-ASN** in `.mmdb` format from the "Download Databases" page.

4. Place the file on your server, e.g.:
   ```
   /path/to/your/app/data/GeoLite2-ASN.mmdb
   ```

5. Add the path to your config file (`@app/config/bot-detection-thresholds.php`):
   ```php
   <?php
   return [
       'asn_database_path' => '/path/to/your/app/data/GeoLite2-ASN.mmdb',
   ];
   ```

That's it. The feature activates automatically when:
- The `asn_database_path` config value is set
- The file actually exists at that path
- The `geoip2/geoip2` package is installed

If any of these conditions aren't met, ASN detection is silently skipped. The output during a run will tell you:
```
ASN-based detection: enabled          (green = working)
ASN-based detection: disabled (...)   (yellow = not configured)
```

**Keeping the database current:** MaxMind updates GeoLite2 weekly. You can automate updates with their [`geoipupdate`](https://dev.maxmind.com/geoip/updating-databases) tool, or just re-download periodically.

**Detected providers:** Amazon/AWS, Google Cloud, Microsoft/Azure, DigitalOcean, Linode, Vultr, OVH, Hetzner, Alibaba Cloud, Tencent Cloud, Oracle Cloud, Rackspace, Scaleway, Cloudflare, Fastly, Leaseweb, and more.

## CLI Commands

### `php yii crelish/bot-detection`

Runs the full detection pipeline. Options:

| Flag | Alias | Description |
|------|-------|-------------|
| `--dryRun` | `-d` | Show what would happen without making changes |
| `--batchSize=N` | `-b N` | Records per batch (default: 1000) |

Examples:
```bash
php yii crelish/bot-detection                    # Full run
php yii crelish/bot-detection --dryRun           # Preview only
php yii crelish/bot-detection --batchSize=5000   # Larger batches
```

### `php yii crelish/bot-detection/stats`

Displays current traffic statistics and confidence breakdown. No changes are made.

### `php yii crelish/bot-detection/review`

Lists the top 50 medium-confidence sessions for manual review, showing score, IP, user agent, and detection reasons.

### `php yii crelish/bot-detection/promote <session_id>`

Promotes a session to HIGH confidence (will be deleted on next run). Use after reviewing a suspicious session.

```bash
php yii crelish/bot-detection/promote abc123def456
```

### `php yii crelish/bot-detection/demote <session_id>`

Clears the bot score for a session, marking it as legitimate. Use when a session was incorrectly flagged.

```bash
php yii crelish/bot-detection/demote abc123def456
```

Session IDs support prefix matching, so you don't need to type the full ID.

## Real-Time Detection

The `CrelishAnalyticsComponent` provides real-time bot detection on every page view using the same DeviceDetector library plus custom heuristic rules. This runs automatically and marks `is_bot` at tracking time.

The batch system (this CLI tool) is a second pass that catches what real-time detection missed, using signals that require historical data (timing patterns, volume, referrers, IP lookups).

## Services

### ReferrerSpamService

Fetches the [Matomo referrer spam list](https://github.com/matomo-org/referrer-spam-list) (~5,000+ domains). Cached for 24 hours. Falls back to ~30 hardcoded domains if the fetch fails. No configuration needed.

### DatacenterIpService

Detects cloud/hosting provider IPs using:
1. **ipcat** (primary) - CSV of known datacenter IP ranges, fetched and cached for 24 hours
2. **ASN lookup** (optional fallback) - MaxMind GeoLite2-ASN database, see setup above

## Database Requirements

The system expects these columns on `analytics_sessions`:

| Column | Type | Notes |
|--------|------|-------|
| `is_bot` | tinyint | 0 or 1 |
| `bot_score` | int | 0-100 confidence score |
| `bot_reason` | varchar(255) | Optional - comma-separated detection reasons |

If `bot_score` or `bot_reason` columns don't exist, the system gracefully degrades (scores still work, reasons just aren't stored).

## Recommended Cron Schedule

```
# Run bot detection daily at 3 AM
0 3 * * * cd /path/to/app && php yii crelish/bot-detection >> /var/log/bot-detection.log 2>&1
```
