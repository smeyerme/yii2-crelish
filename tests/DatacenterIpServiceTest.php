<?php

/**
 * Datacenter IP detection: the ranges the public lists miss are always checked,
 * and the ASN lookup says whether it can run at all.
 *
 * Run with:  php tests/DatacenterIpServiceTest.php
 * With a GeoLite2-ASN database the ASN lookup is checked too:
 *   ASN_DB=/path/to/GeoLite2-ASN.mmdb php tests/DatacenterIpServiceTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\DatacenterIpService;

/** No network: ipcat answers with one line of its own */
class OfflineDatacenterIpService extends DatacenterIpService
{
    public ?string $ipcat = "1.2.3.0,1.2.3.255,Example Hosting,https://example.com/\n";

    protected function fetchUrl(string $url): ?string
    {
        return $this->ipcat;
    }
}

$provider = static function (DatacenterIpService $service, string $ip): ?string {
    $hit = $service->isDatacenterIp($ip);
    return $hit === false ? null : $hit['provider'];
};

echo "Ranges next to ipcat\n";
$service = new OfflineDatacenterIpService();
check('ipcat range is kept', 'Example Hosting', $provider($service, '1.2.3.4'));
check('Alibaba Cloud 47.82.x (November 2026 scraper)', 'Alibaba Cloud', $provider($service, '47.82.8.1'));
check('Alibaba Cloud 47.79.x', 'Alibaba Cloud', $provider($service, '47.79.10.10'));
check('Alibaba Cloud lower end 47.74.0.0', 'Alibaba Cloud', $provider($service, '47.74.0.0'));
check('Alibaba Cloud upper end 47.87.255.255', 'Alibaba Cloud', $provider($service, '47.87.255.255'));
check('47.88.0.0 is outside the added ranges', null, $provider($service, '47.88.0.0'));
check('47.73.255.255 is outside the added ranges', null, $provider($service, '47.73.255.255'));
check('a residential address is no datacenter', null, $provider($service, '84.226.1.1'));

echo "Ranges next to the fallback list (ipcat unreachable)\n";
$service = new OfflineDatacenterIpService();
$service->ipcat = null;
check('Alibaba Cloud also without ipcat', 'Alibaba Cloud', $provider($service, '47.82.8.1'));
check('fallback list still used', 'Tencent Cloud', $provider($service, '43.130.1.1'));

echo "ASN lookup\n";
$service = new OfflineDatacenterIpService();
check('no database configured: not available', false, $service->asnAvailable());
$service->asnDatabasePath = '/nonexistent/GeoLite2-ASN.mmdb';
check('missing database file: not available', false, $service->asnAvailable());
check('geoip2/geoip2 is installed', true, class_exists('GeoIp2\\Database\\Reader'));

$asnDb = getenv('ASN_DB') ?: '';
if ($asnDb !== '' && file_exists($asnDb)) {
    $service = new OfflineDatacenterIpService();
    $service->asnDatabasePath = $asnDb;
    check('database and reader present: available', true, $service->asnAvailable());
    $hit = $service->isHostingAsn('47.82.8.1');
    check('ASN lookup finds Alibaba for 47.82.8.1', true, $hit !== false && stripos($hit['provider'], 'Alibaba') !== false);
    check('ASN lookup leaves a Swiss residential address alone', false, $service->isHostingAsn('84.226.1.1'));
} else {
    echo "  skip ASN lookup against a database (set ASN_DB)\n";
}

analyticsDone();
