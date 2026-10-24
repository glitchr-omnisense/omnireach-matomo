<?php

namespace Omnireach\Matomo\Tests;

use Omnireach\Matomo\MatomoProvider;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Matomo's public demo (https://demo.matomo.cloud, site 1, anonymous
 * access): the whole site over a week, then its entry pages day by day -
 * two calls. `docker compose run --rm omnireach live`; never in the
 * default run.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testTheDemo(): void
    {
        $matomo = new MatomoProvider(HttpClient::create(['timeout' => 30]), 'https://demo.matomo.cloud', '1');
        $week = ReachQuery::lastDays(7);

        $site = $matomo->audience(new ReachQuery($week->start, $week->end, [], [Metric::Visits, Metric::Pageviews, Metric::BounceRate]));
        self::assertGreaterThan(0, $site->rows[0]->metric(Metric::Visits));
        self::assertGreaterThan(0, $site->rows[0]->metric(Metric::Pageviews));

        $entries = $matomo->audience(new ReachQuery($week->end->modify('-1 day'), $week->end, [Dimension::EntryPage, Dimension::Date], [Metric::Visits], limit: 6));
        self::assertNotEmpty($entries->rows);
        self::assertStringStartsWith('/', (string) $entries->rows[0]->dimension(Dimension::EntryPage));
        self::assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2}$~', (string) $entries->rows[0]->dimension(Dimension::Date));
    }
}
