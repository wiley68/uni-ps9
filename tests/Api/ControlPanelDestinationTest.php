<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api {
    // Exercise the real transport's options without opening sockets.
    function curl_init(string $url): object { $GLOBALS['cpCurlCalls']++; return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { $GLOBALS['cpCurlOptions'] = $options; return $GLOBALS['cpCurlSetoptSuccess'] ?? true; }
    function curl_version(): array { return ['version_number' => $GLOBALS['cpCurlVersion'] ?? 0x080b00]; }
    function curl_exec(object $handle): string { $GLOBALS['cpCurlExecs'] = ($GLOBALS['cpCurlExecs'] ?? 0) + 1; return '{}'; }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/Support/ControlPanelDoubles.php';
    use PrestaShop\Module\Unipayment\Api\ControlPanelDestinationPolicy;
    use PrestaShop\Module\Unipayment\Api\ControlPanelDnsResolver;
    use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
    use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
    use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
    use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;
    use PrestaShop\Module\Unipayment\Security\TokenRepository;
    use PrestaShop\Module\Unipayment\Tests\Support\DeploymentEnvironmentFixture;

    DeploymentEnvironmentFixture::activate();
    $GLOBALS['cpCurlCalls'] = 0;
    foreach (['https://uni.avalonbg.com', 'https://cptest.ucfinonline.bg', 'https://cp.ucfinonline.bg', 'https://future-public.example'] as $origin) {
        DeploymentEnvironmentFixture::configure($origin . '/');
        $env = new ModuleDeploymentEnvironment();
        cpAssert($env->controlPanelUrl() === $origin && $env->controlPanelApiBaseUrl() === $origin . '/api/v1', 'One-file origin/base switch');
        $dns = new ControlPanelDnsResolver(static fn (string $host): array => [
            ['type' => 'A', 'ip' => '93.184.215.14'], ['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
        ]);
        (new CurlHttpTransport(5, 15, $dns))->request('GET', $env->controlPanelApiBaseUrl() . '/shop', [], null);
        $options = $GLOBALS['cpCurlOptions'];
        cpAssert($options[CURLOPT_RESOLVE] === [substr($origin, 8) . ':443:93.184.215.14'], 'Validated DNS pinned');
        cpAssert($options[CURLOPT_PROXY] === '' && $options[CURLOPT_NOPROXY] === '*', 'Proxy bypass prevented');
        cpAssert($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_FOLLOWLOCATION] === false, 'HTTPS/no redirects');
        cpAssert($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS identity verification');
        cpAssert($options[CURLOPT_CONNECTTIMEOUT] === 5 && $options[CURLOPT_TIMEOUT] === 15, 'Existing timeouts');
    }
    cpAssert(ControlPanelDestinationPolicy::canonicalOrigin('HTTPS://PUBLIC.EXAMPLE:443/') === 'https://public.example', 'Effective port normalization');
    $before = $GLOBALS['cpCurlCalls'];
    foreach (['http://public.example', '', 'bad', '//public.example', 'https:///bad',
        'https://user:pass@public.example', 'https://public.example?', 'https://public.example#',
        'https://public.example/api/v1', 'https://public.example//', 'https://public.example:444',
        'https://127.0.0.1', 'https://[::1]', 'https://localhost', 'https://host.local',
        'https://host.internal', 'https://host.home.arpa', 'https://bad host', 'https://host.invalid',
        'https://2130706433', 'https://0x7f000001', 'https://bad-.example', 'https://host.example/%2f', null] as $unsafe) {
        DeploymentEnvironmentFixture::configure($unsafe);
        $fake = new CpCaptureTransport();
        cpRejects(static fn (): ControlPanelClient => new ControlPanelClient(new ConfigurationRepository(), new TokenRepository(), $fake, 'https://shop.example'), 'Unsafe URL accepted');
        cpAssert($fake->requests === [], 'Rejected URL caused CP HTTP');
    }
    cpAssert($GLOBALS['cpCurlCalls'] === $before, 'Unsafe URLs reached cURL');
    DeploymentEnvironmentFixture::configure('https://public.example');
    $url = (new ModuleDeploymentEnvironment())->controlPanelApiBaseUrl() . '/shop';
    foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.169.254', '192.0.2.1', '198.18.0.1',
        '192.31.196.1', '192.52.193.1', '192.175.48.1', '224.0.0.1', '255.255.255.255', '::1', '::ffff:8.8.8.8', 'fc00::1', 'fe80::1',
        '2001:db8::1', '2001::1', '2002::1', '2620:4f:8000::1', '3fff::1', 'not-an-address'] as $ip) {
        $dns = new ControlPanelDnsResolver(static fn (string $host): array => [
            ['type' => 'A', 'ip' => '93.184.215.14'],
            ['type' => str_contains($ip, ':') ? 'AAAA' : 'A', str_contains($ip, ':') ? 'ipv6' : 'ip' => $ip],
        ]);
        cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, ['Authorization' => 'synthetic'], null), 'Unsafe DNS accepted');
    }
    foreach ([false, [], [['type' => 'CNAME', 'target' => 'localhost']], [['type' => 'CNAME', 'target' => 'public.example']]] as $records) {
        $dns = new ControlPanelDnsResolver(static fn (string $host): array|false => $records);
        cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, [], null), 'Unsafe alias/no DNS accepted');
    }
    cpAssert($GLOBALS['cpCurlCalls'] === $before, 'Rejected DNS caused HTTP');
    $dns = new ControlPanelDnsResolver(static fn (string $host): array => $host === 'public.example'
        ? [['type' => 'CNAME', 'target' => 'edge.example.']]
        : [['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111']]);
    (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, [], null);
    cpAssert($GLOBALS['cpCurlOptions'][CURLOPT_RESOLVE] === ['public.example:443:[2606:4700:4700::1111]'], 'Safe CNAME/IPv6 pin');
    $GLOBALS['cpCurlVersion'] = 0x071502;
    cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, [], null), 'Old cURL failed open');
    cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', 'https://other.example/api/v1/shop', [], null), 'Independent destination accepted');
    $GLOBALS['cpCurlVersion'] = 0x080b00;
    $beforeExec = $GLOBALS['cpCurlExecs'];
    $GLOBALS['cpCurlSetoptSuccess'] = false;
    cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, [], null), 'failed security options accepted');
    cpAssert($GLOBALS['cpCurlExecs'] === $beforeExec, 'option failure must never execute HTTP');
    $GLOBALS['cpCurlSetoptSuccess'] = true;
    $GLOBALS['cpCurlVersion'] = 0x073800;
    cpRejects(static fn () => (new CurlHttpTransport(5, 15, $dns))->request('GET', $url, [], null), 'unsupported IPv6 pin accepted');
    cpAssert($GLOBALS['cpCurlExecs'] === $beforeExec, 'old IPv6 cURL must not execute HTTP');
    $params = (new ReflectionMethod(ControlPanelClient::class, '__construct'))->getParameters();
    cpAssert(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $params) === ['configuration', 'tokens', 'transport', 'shopName', 'clock'], 'API-base override remains');
    fwrite(STDOUT, "OK (CP destination, DNS pinning, zero HTTP on rejection, sole authority)\n");
}
