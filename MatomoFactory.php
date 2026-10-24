<?php

namespace Omnireach\Matomo;

use Omnireach\Config;
use Omnireach\Exception\InvalidConfigException;
use Omnireach\ProviderFactory;
use Omnireach\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Matomo, from its options:
 *
 *   url    the Matomo installation: https://stats.example.org, https://example.matomo.cloud
 *   site   the site's id (idSite)
 *   token  a token_auth with view access (Administration, Personal, Security); "anonymous" (the
 *          default) for a site whose reports anyone may view
 */
final class MatomoFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireach.factory_name' => 'matomo',
            'omnireach.factory_title' => 'Matomo',
            'omnireach.required_options' => ['url', 'site'],
            'token' => 'anonymous',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new MatomoProvider(
            $this->http ?? throw new InvalidConfigException('The "matomo" provider needs an HTTP client: give one to MatomoFactory.'),
            rtrim((string) $c->string('url'), '/'),
            (string) $c->string('site'),
            $c->string('token') ?? 'anonymous',
        );
    }
}
