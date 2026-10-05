<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /**
     * Pasang environment palsu untuk satu test.
     *
     * @param array<string,string> $values
     */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    public function testReadsEveryValueFromEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_TOKEN' => 'tok',
            'WHATSAPP_URL' => 'https://gw.test/',
            'WHATSAPP_SESSION' => 'sess-1',
            'WHATSAPP_INSTANCE' => 'inst-1',
            'WHATSAPP_TIMEOUT' => '25',
            'WHATSAPP_PROVIDER' => 'OpenWA',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('tok', $config->token());
        self::assertSame('https://gw.test', $config->url());
        self::assertSame('sess-1', $config->session());
        self::assertSame('inst-1', $config->instance());
        self::assertSame(25.0, $config->timeout());
        self::assertSame('OpenWA', $config->provider());
    }

    public function testEmptyEnvironmentValuesAreTreatedAsAbsent(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => '', 'WHATSAPP_URL' => '']);

        $config = Config::fromEnvironment();

        self::assertSame('', $config->token());
        self::assertSame('', $config->url());
    }

    public function testExplicitValuesWinOverEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'dari-env', 'WHATSAPP_URL' => 'https://env.test']);

        $config = Config::from(['token' => 'eksplisit', 'url' => 'https://eksplisit.test']);

        self::assertSame('eksplisit', $config->token());
        self::assertSame('https://eksplisit.test', $config->url());
    }

    public function testProviderTokenBeatsGlobalToken(): void
    {
        self::fakeEnv([
            'WHATSAPP_TOKEN' => 'umum',
            'WHATSAPP_TOKEN_OpenWA' => 'khusus-openwa',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('khusus-openwa', $config->token('OpenWA'));
        self::assertSame('umum', $config->token('Fonnte'));
    }

    public function testTokenOptionBeatsProviderToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN_Fonnte' => 'dari-env']);

        $config = Config::from(['tokens' => ['Fonnte' => 'dari-opsi']]);

        self::assertSame('dari-opsi', $config->token('Fonnte'));
    }

    /**
     * Inti dari penyaringan mode `auto`: token umum tidak boleh membuat semua
     * gateway dianggap siap.
     */
    public function testProviderTokenDoesNotFallBackToGlobalToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'umum']);

        self::assertNull(Config::fromEnvironment()->providerToken('Fonnte'));
    }

    public function testUrlFallsBackToProviderDefault(): void
    {
        self::fakeEnv([]);

        self::assertSame('https://default.test', Config::fromEnvironment()->url('https://default.test'));
    }

    #[DataProvider('timeouts')]
    public function testTimeoutIsClampedToSaneRange(?string $configured, float $expected): void
    {
        self::fakeEnv($configured === null ? [] : ['WHATSAPP_TIMEOUT' => $configured]);

        self::assertSame($expected, Config::fromEnvironment()->timeout());
    }

    /** @return array<string,array{0:?string,1:float}> */
    public static function timeouts(): array
    {
        return [
            'tidak diisi' => [null, 10.0],
            'nilai wajar' => ['25', 25.0],
            'nol ditolak' => ['0', 10.0],
            'negatif ditolak' => ['-5', 10.0],
            'terlalu besar ditolak' => ['999', 10.0],
            'bukan angka ditolak' => ['abc', 10.0],
        ];
    }

    /**
     * Fonnte memakai ini supaya `WHATSAPP_URL` — yang biasanya ditujukan untuk
     * gateway self-hosted — tidak mengalihkan pengirimannya ke host lain.
     */
    public function testExplicitUrlIgnoresEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_URL' => 'https://env.test']);

        // Config tanpa opsi `url` tidak menarik WHATSAPP_URL sama sekali...
        self::assertNull(Config::from(['token' => 'tok'])->explicitUrl());

        // ...sedangkan URL yang benar-benar diberikan tetap terbaca.
        self::assertSame('https://fonnte.test', Config::from(['url' => 'https://fonnte.test'])->explicitUrl());

        // Jalan lain (url()) tetap membaca environment seperti biasa.
        self::assertSame('https://env.test', Config::from(['token' => 'tok'])->url());
    }

    public function testWithUrlReturnsACopy(): void
    {
        $config = Config::from(['url' => 'https://awal.test']);
        $copy = $config->withUrl('https://baru.test');

        self::assertNotSame($config, $copy);
        self::assertSame('https://awal.test', $config->url());
        self::assertSame('https://baru.test', $copy->url());
    }

    public function testWithUrlIgnoresEmptyValue(): void
    {
        $config = Config::from(['url' => 'https://awal.test']);

        self::assertSame($config, $config->withUrl(null));
        self::assertSame($config, $config->withUrl(''));
    }

    /**
     * Inti dari fitur ini: dua gateway self-hosted bisa dikonfigurasi
     * bersamaan. Dengan satu `WHATSAPP_URL` bersama, mengisi URL OpenWA akan
     * ikut terpakai Wuzapi.
     */
    public function testProviderUrlBeatsSharedUrl(): void
    {
        self::fakeEnv([
            'WHATSAPP_URL' => 'https://bersama.test',
            'WHATSAPP_URL_OpenWA' => 'https://openwa.test',
            'WHATSAPP_URL_Wuzapi' => 'https://wuzapi.test',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('https://openwa.test', $config->url('', 'OpenWA'));
        self::assertSame('https://wuzapi.test', $config->url('', 'Wuzapi'));

        // Provider yang tidak punya kunci sendiri tetap memakai yang bersama.
        self::assertSame('https://bersama.test', $config->url('', 'Fonnte'));
    }

    /** Sama seperti token: kunci per-provider tidak jatuh ke kunci umum. */
    public function testProviderUrlDoesNotFallBackToSharedUrl(): void
    {
        self::fakeEnv(['WHATSAPP_URL' => 'https://bersama.test']);

        self::assertNull(Config::fromEnvironment()->providerUrl('Wuzapi'));
    }

    public function testProviderUrlFallsBackToProviderDefault(): void
    {
        self::fakeEnv([]);

        self::assertSame(
            'https://default.test',
            Config::fromEnvironment()->url('https://default.test', 'OpenWA')
        );
    }

    public function testUrlsOptionBeatsProviderUrlEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_URL_OpenWA' => 'https://dari-env.test']);

        $config = Config::from(['urls' => ['OpenWA' => 'https://dari-opsi.test']]);

        self::assertSame('https://dari-opsi.test', $config->url('', 'OpenWA'));
    }

    /**
     * Tanpa nama provider, kunci per-provider tidak boleh ikut terbaca —
     * kalau ikut, `url()` polos jadi tidak bisa diprediksi.
     */
    public function testUrlWithoutProviderIgnoresProviderKeys(): void
    {
        self::fakeEnv([
            'WHATSAPP_URL' => 'https://bersama.test',
            'WHATSAPP_URL_OpenWA' => 'https://openwa.test',
        ]);

        self::assertSame('https://bersama.test', Config::fromEnvironment()->url());
    }

    public function testProviderUrlIsTrimmedOfTrailingSlash(): void
    {
        self::fakeEnv(['WHATSAPP_URL_OpenWA' => 'https://openwa.test/']);

        self::assertSame('https://openwa.test', Config::fromEnvironment()->url('', 'OpenWA'));
    }

    /**
     * Inti fitur session per-provider: tiga gateway bisa memakai nama session
     * yang berbeda-beda bersamaan. Dengan satu `WHATSAPP_SESSION` bersama, nama
     * yang ditujukan untuk OpenWA ikut terpakai Wwebjs dan Waxum.
     */
    public function testProviderSessionBeatsSessionOptionAndEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_SESSION' => 'bersama',
            'WHATSAPP_SESSION_OpenWA' => 'sesi-openwa',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('sesi-openwa', $config->session('OpenWA'));
        // Provider yang tidak punya kunci sendiri tetap memakai yang bersama.
        self::assertSame('bersama', $config->session('Wwebjs'));

        // Kunci per-provider juga menang atas opsi `session` eksplisit.
        self::assertSame('sesi-openwa', Config::from(['session' => 'eksplisit'])->session('OpenWA'));
        self::assertSame('eksplisit', Config::from(['session' => 'eksplisit'])->session('Wwebjs'));
    }

    /** Sama seperti token dan URL: kunci per-provider tidak jatuh ke kunci umum. */
    public function testProviderSessionDoesNotFallBackToSharedSession(): void
    {
        self::fakeEnv(['WHATSAPP_SESSION' => 'bersama']);

        self::assertNull(Config::fromEnvironment()->providerSession('OpenWA'));
    }

    public function testProviderInstanceBeatsInstanceOptionAndEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_INSTANCE' => 'bersama',
            'WHATSAPP_INSTANCE_EvolutionAPI' => 'instance-evo',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('instance-evo', $config->instance('EvolutionAPI'));
        self::assertSame('bersama', $config->instance('ApiMe'));
    }

    /** Kunci per-provider tidak pernah jatuh ke kunci umum. */
    public function testProviderInstanceDoesNotFallBackToSharedInstance(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCE' => 'bersama']);

        self::assertNull(Config::fromEnvironment()->providerInstance('ApiMe'));
    }

    /**
     * Tanpa nama provider, `session()` dan `instance()` harus berperilaku
     * persis seperti sebelum fitur ini ada — kalau tidak, pemanggil lama yang
     * tidak menyebut provider ikut berubah diam-diam.
     */
    public function testSessionAndInstanceWithoutProviderBehaveAsBefore(): void
    {
        self::fakeEnv([
            'WHATSAPP_SESSION' => 'sesi-lama',
            'WHATSAPP_INSTANCE' => 'inst-lama',
            'WHATSAPP_SESSION_OpenWA' => 'diabaikan',
            'WHATSAPP_INSTANCE_ApiMe' => 'diabaikan',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('sesi-lama', $config->session());
        self::assertSame('inst-lama', $config->instance());
    }

    public function testSessionAndInstanceOptionsWinOverEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_SESSION' => 'dari-env', 'WHATSAPP_INSTANCE' => 'dari-env']);

        $config = Config::from(['session' => 'sesi-opsi', 'instance' => 'inst-opsi']);

        self::assertSame('sesi-opsi', $config->session());
        self::assertSame('inst-opsi', $config->instance());
    }

    public function testEmptyEnvironmentSessionAndInstanceAreTreatedAsAbsent(): void
    {
        self::fakeEnv(['WHATSAPP_SESSION' => '', 'WHATSAPP_INSTANCE' => '']);

        $config = Config::fromEnvironment();

        self::assertSame('', $config->session());
        self::assertSame('', $config->instance());
        self::assertSame('', $config->session('OpenWA'));
        self::assertSame('', $config->instance('ApiMe'));
    }

    public function testFromAcceptsConfigInstance(): void
    {
        $config = Config::from(['token' => 'tok']);

        self::assertSame($config, Config::from($config));
    }

    #[DataProvider('notificationFlags')]
    public function testNotificationFlagIsRead(?string $value, bool $expected): void
    {
        self::fakeEnv($value === null ? [] : ['WA_NOTIFICATION' => $value]);

        self::assertSame($expected, Config::notificationEnabled());
    }

    /** @return array<string,array{0:?string,1:bool}> */
    public static function notificationFlags(): array
    {
        return [
            'tidak diisi berarti aktif' => [null, true],
            'true' => ['true', true],
            'false' => ['false', false],
            'satu' => ['1', true],
            'nol' => ['0', false],
        ];
    }

    // ---------------------------------------------------------------------
    // Kunci jamak: WHATSAPP_SESSIONS_<Provider> / WHATSAPP_INSTANCES_<Provider>
    //
    // Kunci tunggal (WHATSAPP_SESSION_<Provider>) hanya menampung satu sesi
    // aktif. Kunci jamak menampung DAFTAR sesi yang dikenal aplikasi — mis.
    // satu server OpenWA dengan sesi "sales", "support", "billing". Inilah
    // inti fitur "multiple session config di .env": beberapa sesi untuk satu
    // gateway didaftarkan dalam satu kunci yang dipisah koma.
    // ---------------------------------------------------------------------

    /**
     * Kunci jamak WHATSAPP_SESSIONS_<Provider> berisi daftar id sesi yang
     * dipisah koma. Dibaca apa adanya sebagai array bersih.
     */
    public function testProviderSessionsReadsCommaSeparatedListFromEnv(): void
    {
        self::fakeEnv(['WHATSAPP_SESSIONS_OpenWA' => 'sales,support,billing']);

        self::assertSame(
            ['sales', 'support', 'billing'],
            Config::fromEnvironment()->providerSessions('OpenWA')
        );
    }

    /**
     * Spasi di sekitar elemen dipangkas, dan elemen kosong dibuang —
     * pemanggil yang menulis "sales, support, , billing" tidak mendapat
     * sesi bernama spasi-kosong yang diam-diam lolos ke URL.
     */
    public function testProviderSessionsTrimsWhitespaceAndDropsEmptyElements(): void
    {
        self::fakeEnv(['WHATSAPP_SESSIONS_OpenWA' => ' sales , support , , billing ']);

        self::assertSame(
            ['sales', 'support', 'billing'],
            Config::fromEnvironment()->providerSessions('OpenWA')
        );
    }

    /** Tanpa kunci jamak, daftar sesi kosong — bukan null atau error. */
    public function testProviderSessionsReturnsEmptyArrayWhenKeyAbsent(): void
    {
        self::fakeEnv([]);

        self::assertSame([], Config::fromEnvironment()->providerSessions('OpenWA'));
    }

    /** Kunci jamak tidak pernah jatuh ke kunci umum WHATSAPP_SESSION. */
    public function testProviderSessionsDoesNotFallBackToSharedSession(): void
    {
        self::fakeEnv(['WHATSAPP_SESSION' => 'bersama']);

        self::assertSame([], Config::fromEnvironment()->providerSessions('OpenWA'));
    }

    /**
     * Opsi `sessions` eksplisit menang atas environment — sama seperti
     * `tokens` dan `urls`. Dipakai aplikasi yang menyimpan daftar sesi di
     * tempat lain (mis. database) dan hanya memakai .env untuk kredensial.
     */
    public function testSessionsOptionBeatsProviderSessionsEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_SESSIONS_OpenWA' => 'dari-env-1,dari-env-2']);

        $config = Config::from(['sessions' => ['OpenWA' => ['dari-opsi-1', 'dari-opsi-2']]]);

        self::assertSame(['dari-opsi-1', 'dari-opsi-2'], $config->providerSessions('OpenWA'));
    }

    /**
     * Daftar sesi efektif: kunci jamak dipakai bila ada, selain itu sesi
     * tunggal dibungkus jadi array satu elemen — sehingga pemanggil yang
     * hanya punya satu sesi tidak perlu mengubah penulisannya.
     */
    public function testSessionsReturnsPluralListOrSingularWrapped(): void
    {
        // Jamak dipakai apa adanya.
        self::fakeEnv(['WHATSAPP_SESSIONS_OpenWA' => 's1,s2']);

        self::assertSame(['s1', 's2'], Config::fromEnvironment()->sessions('OpenWA'));

        // Tanpa jamak, sesi tunggal menjadi satu-satunya elemen.
        self::fakeEnv(['WHATSAPP_SESSION_OpenWA' => 's1']);

        self::assertSame(['s1'], Config::fromEnvironment()->sessions('OpenWA'));
    }

    /** Daftar sesi kosong bila tidak ada kunci tunggal maupun jamak. */
    public function testSessionsReturnsEmptyArrayWhenNothingConfigured(): void
    {
        self::fakeEnv([]);

        self::assertSame([], Config::fromEnvironment()->sessions('OpenWA'));
    }

    /**
     * Inti dari kunci jamak: bila kunci tunggal tidak diisi, elemen pertama
     * daftar jamak menjadi sesi aktif bawaan. Kunci tunggal tetap menang
     * bila keduanya diisi, karena sesi aktif adalah pilihan eksplisit.
     */
    public function testSessionPicksFirstFromPluralListWhenSingularUnset(): void
    {
        self::fakeEnv(['WHATSAPP_SESSIONS_OpenWA' => 'sales,support,billing']);

        self::assertSame('sales', Config::fromEnvironment()->session('OpenWA'));
    }

    /** Kunci tunggal menang atas elemen pertama daftar jamak. */
    public function testSingularSessionBeatsFirstOfPluralList(): void
    {
        self::fakeEnv([
            'WHATSAPP_SESSION_OpenWA' => 'support',
            'WHATSAPP_SESSIONS_OpenWA' => 'sales,support,billing',
        ]);

        self::assertSame('support', Config::fromEnvironment()->session('OpenWA'));
    }

    /**
     * Kunci jamak WHATSAPP_INSTANCES_<Provider> untuk ApiMe dan EvolutionAPI —
     * bentuk dan aturannya sama persis dengan WHATSAPP_SESSIONS_<Provider>.
     */
    public function testProviderInstancesReadsCommaSeparatedListFromEnv(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCES_ApiMe' => 'uuid-1,uuid-2,uuid-3']);

        self::assertSame(
            ['uuid-1', 'uuid-2', 'uuid-3'],
            Config::fromEnvironment()->providerInstances('ApiMe')
        );
    }

    public function testProviderInstancesTrimsAndDropsEmpties(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCES_EvolutionAPI' => ' e1 , , e2 ']);

        self::assertSame(['e1', 'e2'], Config::fromEnvironment()->providerInstances('EvolutionAPI'));
    }

    public function testInstancesOptionBeatsProviderInstancesEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCES_ApiMe' => 'env-1,env-2']);

        $config = Config::from(['instances' => ['ApiMe' => ['opsi-1', 'opsi-2']]]);

        self::assertSame(['opsi-1', 'opsi-2'], $config->providerInstances('ApiMe'));
    }

    public function testInstancesReturnsPluralListOrSingularWrapped(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCES_EvolutionAPI' => 'e1,e2']);

        self::assertSame(['e1', 'e2'], Config::fromEnvironment()->instances('EvolutionAPI'));

        self::fakeEnv(['WHATSAPP_INSTANCE_EvolutionAPI' => 'e1']);

        self::assertSame(['e1'], Config::fromEnvironment()->instances('EvolutionAPI'));
    }

    public function testInstancePicksFirstFromPluralListWhenSingularUnset(): void
    {
        self::fakeEnv(['WHATSAPP_INSTANCES_ApiMe' => 'uuid-1,uuid-2']);

        self::assertSame('uuid-1', Config::fromEnvironment()->instance('ApiMe'));
    }

    public function testSingularInstanceBeatsFirstOfPluralList(): void
    {
        self::fakeEnv([
            'WHATSAPP_INSTANCE_ApiMe' => 'uuid-2',
            'WHATSAPP_INSTANCES_ApiMe' => 'uuid-1,uuid-2',
        ]);

        self::assertSame('uuid-2', Config::fromEnvironment()->instance('ApiMe'));
    }
}
