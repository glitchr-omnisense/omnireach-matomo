# omnireach/matomo

[omniscient/omnireach](https://github.com/glitchr-studio/omnireach)'s Matomo, under any PHP host:
visits, visitors, pageviews, bounce rate and visit length of a site, by entry page, page,
referrer, country, device or day, through Matomo's Reporting API - a self-hosted Matomo or Matomo
Cloud.

```php
use Omnireach\Matomo\MatomoFactory;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new MatomoFactory(HttpClient::create())], [
    'stats' => ['factory' => 'matomo', 'options' => ['url' => 'https://stats.example.org', 'site' => '1', 'token' => '...']],
]);
```

Options: `url`, `site` (idSite), `token` (a token_auth with view access; `anonymous`, the
default, for reports anyone may view).

## The API

The Reporting API (<https://developer.matomo.org/api-reference/reporting-api>), always by POST
with the token in the body (never in a URL, where logs keep it): `API.get` for the whole site,
`Actions.getEntryPageUrls`, `Actions.getPageUrls`, `Referrers.getAll`, `UserCountry.getCountry`,
`DevicesDetection.getType` for a breakdown (`flat=1`), `period=range` over the query's days or
`period=day` for a history. One dimension at a time besides the date (`Capabilities::$combined`
false). Filters on the source, the country and the device become a Matomo segment; a filter on a
page or an entry page works in a report broken down by it.

Units: entry-page metrics are those of the visits that started there (`entry_nb_visits`,
`entry_nb_actions`, `entry_bounce_count`); a bounce rate is bounces over visits; a visit length is
the visit seconds over visits. Distinct visitors over a range are Matomo's own only when the
installation processes them, otherwise the sum of each day's (the report's note says so).

**Terms**: a self-hosted installation's limits are its own (archiving by browser or cron); Matomo
Cloud's are its plan's. Reuse: no PHP client of the Reporting API was found to reuse (Matomo's
own PHP package, matomo/matomo-php-tracker, sends tracking hits, which is not this).

## Verified, and not

- Verified (the core's harness, PHP 8.4): the whole site, entry pages, a daily history by country
  with a device segment, the token by POST, what Matomo cannot answer, a refused token - against
  answers recorded from Matomo's public demo on 2026-10-24 (trimmed of unread fields); every class
  loading with the package's requirements alone (`BareTest`).
- Verified live (`docker compose run --rm omnireach live`, 2026-10-24): demo.matomo.cloud, site 1,
  anonymous - the whole site over the last seven days, then entry pages over two days.
- Not verified: a self-hosted installation with a token.

## Licence

MIT. An unofficial client, affiliated with Matomo (InnoCraft) in no way.
