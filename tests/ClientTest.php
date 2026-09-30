<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\UnknownProviderException;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    public function testResolvesProviderIgnoringCase(): void
    {
        $client = new Client(['provider' => 'fonnte']);

        self::assertInstanceOf(Fonnte::class, $client->provider());
        self::assertSame('Fonnte', $client->provider()->getProvider());
    }

    /**
     * Inti fitur ini: nama gateway bisa diketahui tanpa membangun objeknya,
     * dan ejaannya mengikuti `PROVIDERS` — bukan apa yang diketik pemanggil.
     */
    public function testProviderNameReturnsCanonicalNameWithoutBuildingGateway(): void
    {
        $client = new Client(['provider' => 'openwa']);

        self::assertSame('OpenWA', $client->providerName());
    }

    public function testProviderNameFallsBackToEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_PROVIDER' => 'Wuzapi']);

        self::assertSame('Wuzapi', (new Client())->providerName());
    }

    public function testProviderNameOverridesEnvironmentAndAuto(): void
    {
        self::fakeEnv([
            'WHATSAPP_PROVIDER' => 'Auto',
            'WHATSAPP_TOKEN_Wuzapi' => 'k',
        ]);

        self::assertSame('Fonnte', (new Client())->providerName('fonnte'));
    }

    /**
     * Nama yang dikembalikan harus salah satu kandidat yang punya token —
     * bukan gateway yang tidak dikonfigurasi.
     */
    public function testProviderNameWithAutoPicksFromConfiguredGateways(): void
    {
        self::fakeEnv([
            'WHATSAPP_PROVIDER' => 'Auto',
            'WHATSAPP_TOKEN_Wuzapi' => 'k1',
            'WHATSAPP_TOKEN_OpenWA' => 'k2',
        ]);

        $client = new Client();
        $nama = $client->providerName();

        self::assertContains($nama, ['Wuzapi', 'OpenWA']);
    }

    public function testProviderNameRejectsUnknownGateway(): void
    {
        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('NopeApi');

        (new Client(['provider' => 'NopeApi']))->providerName();
    }

    public function testProviderNameRejectsUnsetProvider(): void
    {
        self::fakeEnv([]);

        $this->expectException(ConfigurationException::class);

        (new Client())->providerName();
    }

    public function testProviderNameWithAutoWithoutCandidatesIsRejected(): void
    {
        self::fakeEnv(['WHATSAPP_PROVIDER' => 'Auto']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_PROVIDER \'auto\' tidak punya kandidat');

        (new Client())->providerName();
    }

    public function testResolvesProviderFromEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_PROVIDER' => 'Wuzapi']);

        $client = new Client();

        self::assertInstanceOf(Wuzapi::class, $client->provider());
    }

    public function testExplicitProviderBeatsEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_PROVIDER' => 'Wuzapi']);

        $client = new Client(['provider' => 'OpenWA']);

        self::assertInstanceOf(OpenWA::class, $client->provider());
    }

    public function testUnknownProviderIsRejected(): void
    {
        $client = new Client(['provider' => 'NopeApi']);

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('NopeApi');

        $client->provider();
    }

    public function testMissingProviderConfigurationIsRejected(): void
    {
        self::fakeEnv([]);

        $this->expectException(ConfigurationException::class);

        (new Client())->provider();
    }

    public function testConfiguredListsOnlyProvidersWithTheirOwnToken(): void
    {
        self::fakeEnv([
            'WHATSAPP_TOKEN' => 'umum',
            'WHATSAPP_TOKEN_OpenWA' => 'owa',
            'WHATSAPP_TOKEN_Wuzapi' => 'wuz',
        ]);

        self::assertSame(['OpenWA', 'Wuzapi'], Client::configured());
    }

    public function testConfiguredIgnoresGlobalToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'umum']);

        self::assertSame([], Client::configured());
    }

    public function testAutoPicksAmongConfiguredProvidersOnly(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN_Fonnte' => 'tok']);

        $provider = (new Client(['provider' => 'auto']))->provider();

        self::assertInstanceOf(Fonnte::class, $provider);
    }

    public function testAutoWithoutCandidatesIsRejected(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'umum']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('auto');

        (new Client(['provider' => 'auto']))->provider();
    }

    public function testSendReturnsProviderResult(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'detail' => '1/1'])]);
        $client = new Client(['provider' => 'Fonnte', 'token' => 'tok'], $backend->client());

        $result = $client->send(['destination' => '081234567890', 'message' => 'halo']);

        self::assertSame('Sukses: 1/1', $result);
    }

    public function testNotifyReturnsErrorTextInsteadOfThrowing(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'token tidak valid'])]);
        $client = new Client(['provider' => 'Fonnte', 'token' => 'tok'], $backend->client());

        self::assertSame(
            'token tidak valid',
            $client->notify(['destination' => '081234567890', 'message' => 'halo'])
        );
    }

    public function testSendPropagatesFailure(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'token tidak valid'])]);
        $client = new Client(['provider' => 'Fonnte', 'token' => 'tok'], $backend->client());

        $this->expectException(\Sikuwa\Whatsapp\Exceptions\ApiException::class);
        $this->expectExceptionMessage('token tidak valid');

        $client->send(['destination' => '081234567890', 'message' => 'halo']);
    }

    public function testHttpClientCanBeInjectedThroughOptions(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);
        $client = new Client(['provider' => 'Fonnte', 'token' => 'tok', 'httpClient' => $backend->client()]);

        $client->send(['destination' => '081234567890', 'message' => 'halo']);

        self::assertSame(1, $backend->count());
    }

    public function testProvidersMapExposesEveryGateway(): void
    {
        self::assertSame(
            ['Fonnte', 'OpenWA', 'ApiMe', 'EvolutionAPI', 'Wuzapi', 'Wwebjs', 'Waxum'],
            array_keys(Client::providers())
        );
    }

    public function testTimeoutFromConfigReachesTheExecutor(): void
    {
        $client = new Client(['provider' => 'Fonnte', 'timeout' => 30]);

        self::assertSame(30.0, $client->http()->timeout());
    }
}
