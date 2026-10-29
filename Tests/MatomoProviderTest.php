<?php

namespace Omnireach\Matomo\Tests;

use Omnireach\Exception\InvalidKeyException;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Matomo\MatomoFactory;
use Omnireach\Matomo\MatomoProvider;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Row;
use Omnireach\Registry;
use Omnireach\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Matomo's answers were recorded from its public demo
 * (https://demo.matomo.cloud, site 1, anonymous access) on 2026-10-24, for
 * 2026-10-01 to 2026-10-07 - trimmed of the fields this package does not
 * read (goals, page performance...).
 */
final class MatomoProviderTest extends TestCase
{
    public function testTheWholeSite(): void
    {
        $http = self::http();
        $report = self::provider($http)->audience(self::week([], [Metric::Visits, Metric::Pageviews, Metric::BounceRate, Metric::VisitDuration]));

        self::assertSame([['visits' => 100103, 'pageviews' => 195851, 'bounce_rate' => 0.6663, 'visit_duration' => 181.2]], array_map(static fn (Row $r) => $r->metrics, $report->rows));
        $body = $http->requests[0]['options']['body'];
        self::assertIsArray($body);
        self::assertSame(['module' => 'API', 'method' => 'API.get', 'idSite' => '1', 'format' => 'JSON', 'token_auth' => 'anonymous', 'period' => 'range', 'date' => '2026-10-01,2026-10-07', 'filter_limit' => '1000'], $body);
        self::assertSame('POST', $http->requests[0]['method'], 'the token in the body, never in the URL');
    }

    public function testEntryPages(): void
    {
        $report = self::provider(self::http())->audience(self::week([Dimension::EntryPage]));

        self::assertSame('/cart/', $report->rows[0]->dimension(Dimension::EntryPage));
        self::assertSame(['visitors' => 2462, 'visits' => 2583, 'pageviews' => 9148], $report->rows[0]->metrics, 'the entry metrics: visits that started there');
        self::assertCount(3, $report->rows);
        self::assertNotNull($report->note);
    }

    public function testADailyHistoryByCountryFilteredBySegment(): void
    {
        $http = self::http();
        $report = self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'), [Dimension::Country, Dimension::Date], [Metric::Visits], ['device' => 'desktop']));

        self::assertSame(['date' => '2026-10-01', 'country' => 'US'], $report->rows[0]->dimensions);
        self::assertSame(['2026-10-01', '2026-10-01', '2026-10-02', '2026-10-02'], array_map(static fn (Row $r) => $r->dimension(Dimension::Date), $report->rows));
        $body = $http->requests[0]['options']['body'];
        self::assertIsArray($body);
        self::assertSame(['UserCountry.getCountry', 'day', 'deviceType==desktop', '1'], [$body['method'], $body['period'], $body['segment'], $body['flat']]);
    }

    /**
     * Matomo's smartphone, phablet and feature phone are the family's one "mobile": one row,
     * the counts summed, the rates weighted by visits. The rows are composed in the shape of
     * DevicesDetection.getType's flat answer, the three visit counts those seen on
     * demo.matomo.cloud on 2026-10-26.
     */
    public function testTheMobileDevicesAreOneRow(): void
    {
        $report = self::provider(self::http())->audience(self::week([Dimension::Device], [Metric::Visits, Metric::Pageviews, Metric::BounceRate]));

        self::assertSame([
            ['device' => 'desktop', 'visits' => 70120, 'pageviews' => 140300, 'bounce_rate' => 0.656],
            ['device' => 'mobile', 'visits' => 26853, 'pageviews' => 52912, 'bounce_rate' => 0.6762],
            ['device' => 'tablet', 'visits' => 3130, 'pageviews' => 6100, 'bounce_rate' => 0.6709],
        ], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows));
    }

    /**
     * A device filter in the family's units: "mobile" is Matomo's three mobile types, ORed
     * (Matomo's segment syntax: "," is OR, https://developer.matomo.org/api-reference/reporting-api-segmentation);
     * the rows of a report broken down by device then say "mobile" and are kept.
     */
    public function testAMobileFilterIsMatomosThreeTypes(): void
    {
        $http = self::http();
        $report = self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), [Dimension::Device], [Metric::Visits], ['device' => 'mobile']));

        $body = $http->requests[0]['options']['body'];
        self::assertIsArray($body);
        self::assertSame('deviceType==smartphone,deviceType==phablet,deviceType==feature%20phone', $body['segment']);
        self::assertSame([['device' => 'mobile', 'visits' => 26853]], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows));
    }

    public function testWhatMatomoCannotAnswer(): void
    {
        foreach ([self::week([Dimension::Query]), self::week([Dimension::EntryPage, Dimension::Country]), self::week([], [Metric::Clicks]), new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), [Dimension::Country], filters: ['page' => '/'])] as $query) {
            try {
                self::provider(self::http())->audience($query);
                self::fail('Not supported.');
            } catch (NotSupportedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testARefusedToken(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage("Matomo refused the credentials (200) for API.get: You can't access this resource as it requires 'view' access for the website id = 1.");
        self::provider(RecordedHttpClient::routes(['POST /index.php' => '{"result":"error","message":"You can\'t access this resource as it requires \'view\' access for the website id = 1."}']))->audience(self::week([]));
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new MatomoFactory(self::http())], ['stats' => ['factory' => 'matomo', 'options' => ['url' => 'https://demo.matomo.cloud/', 'site' => '1']]]);

        self::assertSame(['audience'], $registry->get('stats')->capabilities()->contracts());
        self::assertSame(100103, $registry->audience('stats')->audience(self::week([], [Metric::Visits]))->rows[0]->metric(Metric::Visits));
    }

    /**
     * @param list<Dimension> $dimensions
     * @param list<Metric>    $metrics
     */
    private static function week(array $dimensions, array $metrics = []): ReachQuery
    {
        return new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), $dimensions, $metrics);
    }

    private static function provider(RecordedHttpClient $http): MatomoProvider
    {
        return new MatomoProvider($http, 'https://demo.matomo.cloud', '1');
    }

    private static function http(): RecordedHttpClient
    {
        return new RecordedHttpClient(static function (string $method, string $url, array $options): array {
            $body = \is_array($options['body'] ?? null) ? $options['body'] : [];

            return [200, (string) file_get_contents(__DIR__.'/Fixtures/'.match ($body['method'] ?? null) {
                'API.get' => 'api-get',
                'Actions.getEntryPageUrls' => 'entry-pages',
                'DevicesDetection.getType' => 'devices',
                default => 'countries-daily',
            }.'.json')];
        });
    }
}
