# omnireach/matomo

Matomo for [omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach): visits,
visitors, pageviews, bounce rate and visit length of a site, by entry page, page, referrer,
country, device or day, through Matomo's Reporting API
(<https://developer.matomo.org/api-reference/reporting-api>) - a self-hosted Matomo or Matomo
Cloud. Every call is `POST {url}/index.php` with `module=API` and one report method:

| Report | Matomo method | Metrics read |
|---|---|---|
| the whole site | `API.get` | `nb_uniq_visitors`, `nb_visits`, `nb_pageviews`, `bounce_count`, `sum_visit_length`... |
| by entry page | `Actions.getEntryPageUrls` | the `entry_` metrics: visits that started there |
| by page | `Actions.getPageUrls` | `nb_visits`, `nb_hits`... |
| by source | `Referrers.getAll` | |
| by country | `UserCountry.getCountry` | |
| by device | `DevicesDetection.getType` | |

`{url}` is the installation's; Matomo has no sandbox, but its public demo (`https://demo.matomo.cloud`,
site 1) answers without a token. No PHP client of the Reporting API was found to reuse
(matomo/matomo-php-tracker sends tracking hits, which is not this).

## Installation

```sh
composer require omnireach/matomo symfony/http-client
```

Requires PHP 8.2, `omnisense/omnireach` (1.x) and `symfony/http-client-contracts` (3).

## Credentials

- A **`token_auth`** with view access to the site: Administration, Personal, Security.
- Or none: `token` defaults to `anonymous`, which reads the reports of a site whose reports anyone
  may view (the demo's).

The token is sent in the POST body, never in the URL, where logs keep it.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `url` | string | - | yes | the installation: `https://stats.example.org`, `https://example.matomo.cloud` (trailing slash removed) |
| `site` | string | - | yes | the site's id, `idSite`: `1` |
| `token` | string | `anonymous` | no | a `token_auth` with view access |

Per query: `ReachQuery::$site` replaces `site` (another `idSite`) for one report.

## Capabilities

| | |
|---|---|
| contract | `AudienceInterface` |
| dimensions | entry page, page, source, country, device, date - one at a time besides the date (`combined: false`) |
| metrics | visitors, visits, pageviews, bounce rate, visit duration (visitors, visits, pageviews by default) |
| filters | source, country, device: a Matomo segment; page, entry page: only in a report broken down by it |
| `SearchPerformanceInterface` | no |

## Behaviour

**The call.** One `POST` per report, with `module=API`, `method`, `idSite`, `format=JSON`,
`token_auth` and:

| Parameter | Value |
|---|---|
| `period` | `range` over the query's days; `day` when the date is asked (a history) |
| `date` | `<start>,<end>` (`Y-m-d`) |
| `filter_limit` | the query's `limit` (per day in a history: the rows are cut to `limit` afterwards) |
| `flat` | `1` for a breakdown |
| `segment` | the filters on source (`referrerName==`), country (`countryCode==`, lower-cased) and device (`deviceType==`), joined by `;` (and), each value URL-encoded |

```
POST https://demo.matomo.cloud/index.php
  body: {"module":"API","method":"API.get","idSite":"1","format":"JSON","token_auth":"anonymous","period":"range","date":"2026-10-01,2026-10-07","filter_limit":"1000"}
```

(the package's test asserts this body for the whole site over a week)

A filter on page or entry page is not sent: it is applied to the rows of a report broken down by
that same dimension, and refused in any other report (`Matomo filters on the dimension "page" only
in a report broken down by it.`).

**Errors in a 200.** Matomo answers some errors with status 200 and `{"result":"error","message":
"..."}`: a message speaking of "access" or "token" becomes an `InvalidKeyException`, any other a
`ProviderException` (both of code 200).

**Rows.** With the date, Matomo answers one report per day (keyed by the date), each row dated;
an empty day of the whole site's report is skipped. Labels become the family's values:

- country: Matomo's `code` upper-cased (`FR`), else its label;
- device: `smartphone`, `phablet` and `feature phone` become `mobile`, every other type is
  Matomo's label lower-cased (`desktop`, `tablet`, `console`, `tv`, `portable media player`,
  `unknown`...); types with no visit are kept, at 0;
- pages and entry pages: the label with a leading `/` added when it has none;
- source: Matomo's label as it is.

Metrics, for the whole site and by page (prefix none) or by entry page (prefix `entry_`):

| Metric | Read |
|---|---|
| visitors | `<prefix>nb_uniq_visitors`, else `sum_daily_<prefix>nb_uniq_visitors` |
| visits | `<prefix>nb_visits` |
| pageviews | entry pages: `entry_nb_actions` (the actions of the visits that started there); else `nb_pageviews`, `nb_hits`, then `nb_actions` |
| bounce rate | `<prefix>bounce_count` over the visits, rounded to 4 decimals; else Matomo's `bounce_rate` (`67%` read as 0.67) |
| visit duration | `<prefix>sum_visit_length` over the visits, in seconds rounded to 1; else `avg_time_on_site` |

A metric Matomo does not give for a row is left out of it. Rows keep Matomo's order (the demo's
entry pages did not come by visits: [the core's installation page](https://github.com/glitchr-omnisense/omnireach/blob/1.x/docs/installation.md#a-providers-api));
the query's filters and limit are then applied.

**Note.** When visitors are counted over a range (not by day), `Report::$note` is `Matomo gives
distinct visitors over a range only when its installation processes them; otherwise the sum of
each day's distinct visitors.` The provider cannot tell which of the two it read.

**Limits**: the installation's own - a self-hosted Matomo answers as fast as its archiving (reports
archived by the browser or by cron); Matomo Cloud's are its plan's. A segment the installation has
not archived may answer 0: the public demo answered 0 visits for every segment asked on
2026-10-26.

> [!WARNING]
> Known defect: smartphones, phablets and feature phones each give a row labelled `mobile` (seen
> live on the demo, 2026-10-26: `mobile` rows of 25867, 976 and 10 visits in one report), where one
> `mobile` row summing them is expected. Sum the rows by device in the application.

> [!WARNING]
> Known defect: a `device` filter is sent as it is as Matomo's `deviceType` segment, so the
> family's `mobile` is refused by Matomo (`ProviderException`: `Matomo answered 200 for API.get:
> Invalid 'log_visit.config_device_type' segment value mobile`, seen live on 2026-10-26), where a
> filter in the family's units is expected. Matomo's own names (`smartphone`, `desktop`) are
> accepted; but in a report broken down by device, filtering on `smartphone` then drops every row,
> since the rows say `mobile`.

## Example

Run live against Matomo's public demo on 2026-10-26 (14:49 UTC), in the core's harness:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireach\Matomo\MatomoFactory;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new MatomoFactory(HttpClient::create(['timeout' => 30]))], [
    'demo' => ['factory' => 'matomo', 'options' => ['url' => 'https://demo.matomo.cloud', 'site' => '1']],
]);
$matomo = $registry->audience('demo');
$week = ReachQuery::lastDays(7);

$devices = $matomo->audience(new ReachQuery($week->start, $week->end, [Dimension::Device], [Metric::Visits]));
foreach ($devices->rows as $row) {
    printf("%-20s %6d visits\n", $row->dimension(Dimension::Device), $row->metric(Metric::Visits));
}
```

```
desktop               56962 visits
mobile                25867 visits
unknown               10087 visits
tablet                 4687 visits
mobile                  976 visits
console                  17 visits
mobile                   10 visits
portable media player      8 visits
tv                        2 visits
camera                    0 visits
car browser               0 visits
peripheral                0 visits
smart display             0 visits
smart speaker             0 visits
wearable                  0 visits
```

(2026-10-19 to 2026-10-25; the whole site and its entry pages over the same week are in the core's
[installation page](https://github.com/glitchr-omnisense/omnireach/blob/1.x/docs/installation.md#a-providers-api))

With a token, in Symfony:

```yaml
services:
    Omnireach\Matomo\MatomoFactory:
        arguments: ['@http_client']
    Omnireach\Registry:
        arguments:
            $factories: ['@Omnireach\Matomo\MatomoFactory']
            $config:
                stats: { factory: matomo, options: { url: 'https://stats.example.org', site: '1', token: '%env(MATOMO_TOKEN)%' } }
```

## Errors

The provider's calls are named `Matomo` in the messages, the method called being the "what":

- a 200 carrying `result: error`: `InvalidKeyException` when the message speaks of access or a
  token (`Matomo refused the credentials (200) for API.get: You can't access this resource as it
  requires 'view' access for the website id = 1.`), `ProviderException` otherwise;
- 401, 403: `InvalidKeyException`; 404: `NotFoundException`; another status of 400 or more, or a
  body that is not JSON: `ProviderException`; a network error: `UnreachableException`;
- a missing `url` or `site`: `InvalidConfigException` when the provider is built;
- a search dimension or metric, two dimensions besides the date, a page filter outside a page
  report: `NotSupportedException` before any call.

## Verified, and not

Tested offline (`Tests/MatomoProviderTest.php`, in the core's harness, PHP 8.4), against answers
recorded from Matomo's public demo on 2026-10-24 (trimmed of unread fields): the whole site and
the request's body, the token by POST; entry pages with the entry metrics and the note; a daily
history by country with a device segment (`deviceType==desktop`); what Matomo cannot answer; a
refused token; the factory through a `Registry`. `Tests/BareTest.php` loads every class with the
package's requirements alone.

Verified live: `docker compose run --rm omnireach live` (`Tests/LiveTest.php`) on 2026-10-24 -
the demo's whole site over a week, then its entry pages day by day; and on 2026-10-26 the reports
above, which showed the two known defects.

Not verified: a self-hosted installation with a token; segments on an installation that archives
them (the demo answers 0 for any segment).

## Responsibility

An independent, unofficial client, affiliated with Matomo (InnoCraft) in no way. Whoever runs it
complies with the installation's terms - a self-hosted Matomo's owner's, Matomo Cloud's plan - and
the Reporting API's (<https://developer.matomo.org/api-reference/reporting-api>): see the README.
