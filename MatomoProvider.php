<?php

namespace Omnireach\Matomo;

use Omnireach\AudienceInterface;
use Omnireach\Capabilities;
use Omnireach\Exception\InvalidKeyException;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Exception\ProviderException;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Report;
use Omnireach\Model\Row;
use Omnireach\Support\Data;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Matomo's Reporting API (https://developer.matomo.org/api-reference/reporting-api):
 * POST index.php?module=API with the token in the body, one report a call -
 * API.get for the whole site, Actions.getEntryPageUrls, Actions.getPageUrls,
 * Referrers.getAll, UserCountry.getCountry, DevicesDetection.getType for a
 * breakdown; period=range over the query's days, or period=day for a
 * history (Dimension::Date).
 */
final class MatomoProvider implements AudienceInterface
{
    /** @var array<string, array{0: string, 1: string}> the report of each dimension, and the prefix of its metrics */
    private const REPORTS = [
        'entry_page' => ['Actions.getEntryPageUrls', 'entry_'],
        'page' => ['Actions.getPageUrls', ''],
        'source' => ['Referrers.getAll', ''],
        'country' => ['UserCountry.getCountry', ''],
        'device' => ['DevicesDetection.getType', ''],
    ];

    /** @var array<string, string> the segment of each dimension a filter can name */
    private const SEGMENTS = ['source' => 'referrerName', 'country' => 'countryCode', 'device' => 'deviceType'];

    private readonly JsonApi $api;

    public function __construct(
        HttpClientInterface $http,
        private readonly string $url,
        private readonly string $site,
        #[\SensitiveParameter] private readonly string $token = 'anonymous',
    ) {
        $this->api = new JsonApi($http, 'Matomo');
    }

    public function getName(): string
    {
        return 'matomo';
    }

    public function getTitle(): string
    {
        return 'Matomo';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            audience: true,
            dimensions: [Dimension::EntryPage, Dimension::Page, Dimension::Source, Dimension::Country, Dimension::Device, Dimension::Date],
            metrics: [Metric::Visitors, Metric::Visits, Metric::Pageviews, Metric::BounceRate, Metric::VisitDuration],
            combined: false,
            terms: ['https://developer.matomo.org/api-reference/reporting-api'],
            limits: ['the installation\'s own: a self-hosted Matomo is as fast as its archiving (reports are archived, browser-triggered or by cron)', 'the token is sent by POST, never in the URL'],
        );
    }

    public function audience(ReachQuery $query): Report
    {
        $metrics = $query->metricsOr([Metric::Visitors, Metric::Visits, Metric::Pageviews]);
        $this->capabilities()->check($query, $metrics, 'Matomo');
        $dimension = $query->breakdown()[0] ?? null;
        [$method, $prefix] = null !== $dimension ? (self::REPORTS[$dimension->value] ?? throw new NotSupportedException('Matomo has no report by '.$dimension->value.'.')) : ['API.get', ''];
        $segments = [];
        foreach ($query->filters as $name => $value) {
            if (isset(self::SEGMENTS[$name])) {
                $segments[] = self::SEGMENTS[$name].'=='.rawurlencode('country' === $name ? strtolower($value) : $value);
            } elseif ($name !== $dimension?->value) {
                throw new NotSupportedException(\sprintf('Matomo filters on the dimension "%s" only in a report broken down by it.', $name));
            }
        }
        $daily = $query->has(Dimension::Date);
        $answer = $this->call($method, [
            'period' => $daily ? 'day' : 'range',
            'date' => $query->start->format('Y-m-d').','.$query->end->format('Y-m-d'),
            'filter_limit' => (string) $query->limit,
        ] + (null !== $dimension ? ['flat' => '1'] : []) + ([] !== $segments ? ['segment' => implode(';', $segments)] : []), $query->site ?? $this->site);

        $rows = [];
        foreach ($daily ? $answer->values : ['' => $answer->values] as $date => $report) {
            $lines = null === $dimension ? [Data::of($report)] : Data::of($report)->items();
            foreach ($lines as $line) {
                $dimensions = ($daily ? ['date' => (string) $date] : []) + (null !== $dimension ? [$dimension->value => self::label($dimension, $line)] : []);
                if (null === $dimension && [] === $line->values) {
                    continue;
                }
                $rows[] = new Row($dimensions, self::metrics($line, $metrics, $prefix));
            }
        }

        return new Report('matomo', $query->site ?? $this->site, $query, $query->apply($rows), \in_array(Metric::Visitors, $metrics, true) && !$daily ? 'Matomo gives distinct visitors over a range only when its installation processes them; otherwise the sum of each day\'s distinct visitors.' : null);
    }

    /** @param array<string, string> $parameters */
    private function call(string $method, array $parameters, string $site): Data
    {
        $answer = $this->api->request('POST', $this->url.'/index.php', ['body' => ['module' => 'API', 'method' => $method, 'idSite' => $site, 'format' => 'JSON', 'token_auth' => $this->token] + $parameters], $method);
        if ('error' === $answer->string('result')) {
            $message = (string) $answer->string('message');

            throw str_contains(strtolower($message), 'access') || str_contains(strtolower($message), 'token') ? InvalidKeyException::refused('Matomo', $method, 200, $message) : ProviderException::answered('Matomo', $method, 200, $message);
        }

        return $answer;
    }

    private static function label(Dimension $dimension, Data $line): string
    {
        $label = (string) $line->string('label');

        return match ($dimension) {
            Dimension::Country => strtoupper((string) ($line->string('code') ?? $label)),
            Dimension::Device => match (strtolower($label)) {
                'smartphone', 'phablet', 'feature phone' => 'mobile',
                default => strtolower($label),
            },
            Dimension::EntryPage, Dimension::Page => str_starts_with($label, '/') ? $label : '/'.$label,
            default => $label,
        };
    }

    /**
     * @param list<Metric> $metrics
     *
     * @return array<string, int|float>
     */
    private static function metrics(Data $line, array $metrics, string $prefix): array
    {
        $visits = $line->int($prefix.'nb_visits');
        $values = [];
        foreach ($metrics as $metric) {
            $value = match ($metric) {
                Metric::Visitors => $line->int($prefix.'nb_uniq_visitors') ?? $line->int('sum_daily_'.$prefix.'nb_uniq_visitors'),
                Metric::Visits => $visits,
                Metric::Pageviews => '' !== $prefix ? $line->int($prefix.'nb_actions') : $line->int('nb_pageviews') ?? $line->int('nb_hits') ?? $line->int('nb_actions'),
                Metric::BounceRate => null !== ($bounces = $line->int($prefix.'bounce_count')) && $visits ? round($bounces / $visits, 4) : self::percent($line->string('bounce_rate')),
                Metric::VisitDuration => null !== ($length = $line->int($prefix.'sum_visit_length')) && $visits ? round($length / $visits, 1) : $line->float('avg_time_on_site'),
                default => null,
            };
            if (null !== $value) {
                $values[$metric->value] = $value;
            }
        }

        return $values;
    }

    /** "67%" as 0.67. */
    private static function percent(?string $value): ?float
    {
        return null !== $value && is_numeric($number = rtrim($value, '% ')) ? round((float) $number / 100, 4) : null;
    }
}
