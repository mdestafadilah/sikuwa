<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\AuthException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\NotFoundException;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Waxum\Waxum;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Providers\Wwebjs\Wwebjs;
use Sikuwa\Whatsapp\Session;

/**
 * Operasi sesi — `createSession()` dan `checkSession()` — di semua gateway.
 *
 * Yang diperiksa bukan cuma hasil akhirnya, tapi juga method HTTP, URL, dan
 * header autentikasi yang benar-benar dikirim: itulah bagian yang paling mudah
 * rusak saat provider dirapikan.
 */
final class SessionTest extends TestCase
{
    private const BASE = 'https://gw.test';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // OpenWA — istilah gatewaynya "session"
    // ---------------------------------------------------------------------

    public function testOpenWaCreatesSessionUsingConfiguredId(): void
    {
        $backend = new MockBackend([MockBackend::json(['id' => 'sess-1', 'status' => 'INITIALIZING'])]);

        $session = (new OpenWA(
            ['token' => 'api-key', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->executor()
        ))->createSession(['name' => 'Notifikasi']);

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/sessions', (string) $backend->lastRequest()?->getUri());
        self::assertSame('api-key', $backend->lastHeader('X-API-Key'));
        // Id diambil dari konfigurasi, jadi pemanggil tidak perlu mengulangnya.
        self::assertSame(['id' => 'sess-1', 'name' => 'Notifikasi'], $backend->lastJson());

        self::assertSame('OpenWA', $session->provider);
        self::assertSame('sess-1', $session->id);
        self::assertSame('INITIALIZING', $session->status);
        self::assertFalse($session->isConnected());
    }

    public function testOpenWaReportsConnectedSession(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'id' => 'sess-1',
            'status' => 'CONNECTED',
            'phoneNumber' => '628123456789',
            'profileName' => 'Sekolah',
        ])]);

        $session = $this->openWa($backend)->checkSession();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/sessions/sess-1', (string) $backend->lastRequest()?->getUri());
        self::assertTrue($session->isConnected());
        self::assertSame('CONNECTED', $session->status);
        self::assertSame('628123456789', $session->phoneNumber);
        self::assertSame('Sekolah', $session->profileName);
    }

    public function testOpenWaAcceptsWrappedEnvelopeAndLooseStatusCase(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['id' => 's1', 'status' => 'connected']]),
        ]);

        $session = $this->openWa($backend)->checkSession('s1');

        self::assertTrue($session->isConnected());
        self::assertSame('CONNECTED', $session->status);
    }

    public function testOpenWaCheckSessionWithoutSessionIdIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        (new OpenWA(['token' => 'k', 'url' => self::BASE], $backend->executor()))->checkSession();
    }

    // ---------------------------------------------------------------------
    // ApiMe — istilah gatewaynya "instance"
    // ---------------------------------------------------------------------

    public function testApiMeCreatesInstanceFromConfiguredName(): void
    {
        $backend = new MockBackend([MockBackend::json(['id' => 'uuid-9', 'status' => 'created'], 201)]);

        $session = (new ApiMe(
            ['token' => 'inst-token', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        ))->createSession();

        self::assertSame(self::BASE . '/api/instances', (string) $backend->lastRequest()?->getUri());
        self::assertSame('Bearer inst-token', $backend->lastHeader('Authorization'));
        self::assertSame(['name' => 'uuid-9'], $backend->lastJson());

        self::assertSame('uuid-9', $session->id);
        self::assertFalse($session->isConnected());
    }

    public function testApiMeCreateSessionWithoutNameIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ApiMe membutuhkan nama instance');

        (new ApiMe(['token' => 't', 'url' => self::BASE], $backend->executor()))->createSession();
    }

    public function testApiMeReportsConnectedInstance(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'data' => ['id' => 'uuid-9', 'status' => 'connected', 'phone_number' => '62811'],
        ])]);

        $session = (new ApiMe(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        ))->checkSession();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/instances/uuid-9', (string) $backend->lastRequest()?->getUri());
        self::assertTrue($session->isConnected());
        self::assertSame('62811', $session->phoneNumber);
    }

    // ---------------------------------------------------------------------
    // Evolution API
    // ---------------------------------------------------------------------

    public function testEvolutionCreatesInstanceAndReadsQr(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'instance' => ['instanceName' => 'sikuwa', 'status' => 'created'],
            'hash' => ['apikey' => 'abc'],
            'qrcode' => ['base64' => 'data:image/png;base64,AAA'],
        ])]);

        $session = (new EvolutionAPI(
            ['token' => 'global-key', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        ))->createSession();

        self::assertSame(self::BASE . '/instance/create', (string) $backend->lastRequest()?->getUri());
        self::assertSame('global-key', $backend->lastHeader('apikey'));
        self::assertSame(['instanceName' => 'sikuwa', 'qrcode' => true], $backend->lastJson());

        self::assertSame('sikuwa', $session->id);
        self::assertTrue($session->hasQr());
        self::assertFalse($session->isConnected());
        // API key instance diterbitkan di `hash`, dan itulah yang dipakai
        // untuk memanggil endpoint instance ini.
        self::assertSame('abc', $session->token);
    }

    public function testEvolutionRejectsInstanceNameWithSymbols(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('hanya boleh huruf kecil dan angka');

        (new EvolutionAPI(['token' => 't', 'url' => self::BASE], $backend->executor()))
            ->createSession(['instanceName' => 'Sikuwa-1']);
    }

    public function testEvolutionTreatsOpenStateAsConnected(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['instance' => ['instanceName' => 'sikuwa', 'state' => 'open']]),
        ]);

        $session = (new EvolutionAPI(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        ))->checkSession();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(
            self::BASE . '/instance/connectionState/sikuwa',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertTrue($session->isConnected());
        self::assertSame('open', $session->status);
    }

    // ---------------------------------------------------------------------
    // wuzapi
    // ---------------------------------------------------------------------

    public function testWuzapiConnectsSession(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'code' => 200,
            'success' => true,
            'data' => ['details' => 'Connected!', 'jid' => '62811@s.whatsapp.net'],
        ])]);

        $session = (new Wuzapi(['token' => 'user-token', 'url' => self::BASE], $backend->executor()))
            ->createSession();

        self::assertSame(self::BASE . '/session/connect', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));
        self::assertSame(['Subscribe' => ['Message'], 'Immediate' => true], $backend->lastJson());

        self::assertTrue($session->isConnected());
        self::assertSame('62811@s.whatsapp.net', $session->id);
    }

    public function testWuzapiStatusUsesLoggedInFlagNotConnected(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'code' => 200,
            'success' => true,
            'data' => ['Connected' => true, 'LoggedIn' => false],
        ])]);

        $session = (new Wuzapi(['token' => 't', 'url' => self::BASE], $backend->executor()))->checkSession();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/session/status', (string) $backend->lastRequest()?->getUri());
        self::assertFalse($session->isConnected());
        self::assertSame('connecting', $session->status);
    }

    public function testWuzapiFailureEnvelopeIsRejectedEvenOnHttp200(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 401, 'success' => false, 'error' => 'Invalid token']),
        ]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Invalid token');

        (new Wuzapi(['token' => 't', 'url' => self::BASE], $backend->executor()))->checkSession();
    }

    // ---------------------------------------------------------------------
    // Fonnte — istilah gatewaynya "device", dan Device API menuntut
    // account token, bukan token perangkat yang dipakai mengirim pesan
    // ---------------------------------------------------------------------

    public function testFonnteCreatesDeviceWithAccountToken(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'name' => 'Notifikasi',
            'device' => '628123456789',
            'token' => 'device-token-baru',
        ])]);

        $session = (new Fonnte(['account_token' => 'acct-tok'], $backend->executor()))
            ->createSession(['name' => 'Notifikasi', 'device' => '08123456789']);

        self::assertSame('https://api.fonnte.com/add-device', (string) $backend->lastRequest()?->getUri());
        self::assertSame('acct-tok', $backend->lastHeader('Authorization'));
        // Nomor dinormalkan ke format internasional sebelum dikirim.
        self::assertSame(['name' => 'Notifikasi', 'device' => '628123456789'], $backend->lastJson());

        self::assertSame('created', $session->status);
        self::assertSame('device-token-baru', $session->token);
        self::assertFalse($session->isConnected());
    }

    public function testFonnteDeviceApiRequiresAccountToken(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_ACCOUNT_TOKEN');

        (new Fonnte(['token' => 'device-tok'], $backend->executor()))
            ->createSession(['name' => 'Notifikasi', 'device' => '08123456789']);
    }

    public function testFonnteCreateSessionRequiresNameAndDevice(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('nomor perangkat');

        (new Fonnte(['account_token' => 'acct'], $backend->executor()))->createSession(['name' => 'X']);
    }

    public function testFonnteCheckSessionFindsDeviceByActiveToken(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'connected' => 1,
            'devices' => 2,
            'data' => [
                ['device' => '628111', 'name' => 'lain', 'status' => 'disconnect', 'token' => 'tok-lain'],
                ['device' => '628222', 'name' => 'sekolah', 'status' => 'connect', 'token' => 'tok-aktif'],
            ],
        ])]);

        $session = (new Fonnte(
            ['token' => 'tok-aktif', 'account_token' => 'acct'],
            $backend->executor()
        ))->checkSession();

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/get-devices', (string) $backend->lastRequest()?->getUri());
        self::assertSame('acct', $backend->lastHeader('Authorization'));

        self::assertTrue($session->isConnected());
        self::assertSame('connect', $session->status);
        self::assertSame('628222', $session->id);
        self::assertSame('sekolah', $session->profileName);
        self::assertSame('tok-aktif', $session->token);
    }

    public function testFonnteCheckSessionCanTargetDeviceByName(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'data' => [['device' => '628222', 'name' => 'sekolah', 'status' => 'disconnect', 'token' => 'x']],
        ])]);

        $session = (new Fonnte(
            ['token' => 'tok-aktif', 'account_token' => 'acct'],
            $backend->executor()
        ))->checkSession('sekolah');

        self::assertFalse($session->isConnected());
        self::assertSame('disconnect', $session->status);
    }

    public function testFonnteCheckSessionWithoutMatchIsNotFound(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'data' => [['device' => '628111', 'name' => 'lain', 'status' => 'connect', 'token' => 'tok-lain']],
        ])]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('tidak ditemukan di akun Fonnte');

        (new Fonnte(['token' => 'tok-hilang', 'account_token' => 'acct'], $backend->executor()))->checkSession();
    }

    /** Fonnte membalas HTTP 200 walau menolak; amplopnya yang menentukan. */
    public function testFonnteRejectionEnvelopeBecomesApiException(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'unknown user'])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('unknown user');

        (new Fonnte(['account_token' => 'salah'], $backend->executor()))->checkSession('628222');
    }

    /** Override proxy lewat `WHATSAPP_URL_Fonnte` harus ikut berlaku di Device API. */
    public function testFonnteDeviceApiFollowsProxyUrlOverride(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'data' => [['device' => '628222', 'name' => 'x', 'status' => 'connect', 'token' => 'y']],
        ])]);

        (new Fonnte(
            ['token' => 't', 'account_token' => 'a', 'url' => 'https://proxy.test/send'],
            $backend->executor()
        ))->checkSession('628222');

        self::assertSame('https://proxy.test/get-devices', (string) $backend->lastRequest()?->getUri());
    }

    /** Dua kredensial yang berbeda tidak boleh tertukar saat mengirim pesan. */
    public function testFonnteSendingStillUsesDeviceToken(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        (new Fonnte(
            ['token' => 'device-tok', 'account_token' => 'acct-tok'],
            $backend->executor()
        ))->sendMessage(['destination' => '081234567890', 'message' => 'halo']);

        self::assertSame('https://api.fonnte.com/send', (string) $backend->lastRequest()?->getUri());
        self::assertSame('device-tok', $backend->lastHeader('Authorization'));
    }

    // ---------------------------------------------------------------------
    // listSessions() — mendaftar semua sesi/instance per gateway
    // ---------------------------------------------------------------------

    public function testOpenWaListsAllSessions(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'success' => true,
            'data' => [
                ['id' => 'sess-1', 'status' => 'CONNECTED', 'phoneNumber' => '62811'],
                ['id' => 'sess-2', 'status' => 'DISCONNECTED'],
            ],
        ])]);

        $sessions = $this->openWa($backend)->listSessions();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/sessions', (string) $backend->lastRequest()?->getUri());
        self::assertCount(2, $sessions);
        self::assertSame('sess-1', $sessions[0]->id);
        self::assertTrue($sessions[0]->isConnected());
        self::assertSame('sess-2', $sessions[1]->id);
        self::assertFalse($sessions[1]->isConnected());
    }

    public function testApiMeListsAllInstances(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'data' => [
                ['id' => 'uuid-1', 'status' => 'connected', 'phone_number' => '62811'],
                ['id' => 'uuid-2', 'status' => 'disconnected'],
            ],
        ])]);

        $sessions = (new ApiMe(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        ))->listSessions();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/instances', (string) $backend->lastRequest()?->getUri());
        self::assertCount(2, $sessions);
        self::assertSame('uuid-1', $sessions[0]->id);
        self::assertTrue($sessions[0]->isConnected());
        self::assertSame('62811', $sessions[0]->phoneNumber);
        self::assertSame('uuid-2', $sessions[1]->id);
        self::assertFalse($sessions[1]->isConnected());
    }

    public function testEvolutionListsAllInstances(): void
    {
        $backend = new MockBackend([MockBackend::json([
            ['name' => 'inst1', 'connectionStatus' => 'open', 'number' => '62811', 'profileName' => 'Sekolah'],
            ['name' => 'inst2', 'connectionStatus' => 'close'],
        ])]);

        $sessions = (new EvolutionAPI(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        ))->listSessions();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/instance/fetchInstances', (string) $backend->lastRequest()?->getUri());
        self::assertCount(2, $sessions);
        self::assertSame('inst1', $sessions[0]->id);
        self::assertTrue($sessions[0]->isConnected());
        self::assertSame('62811', $sessions[0]->phoneNumber);
        self::assertSame('Sekolah', $sessions[0]->profileName);
        self::assertSame('inst2', $sessions[1]->id);
        self::assertFalse($sessions[1]->isConnected());
    }

    public function testFonnteListsAllDevices(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'devices' => 2,
            'data' => [
                ['device' => '628111', 'name' => 'lain', 'status' => 'disconnect', 'token' => 'tok-lain'],
                ['device' => '628222', 'name' => 'sekolah', 'status' => 'connect', 'token' => 'tok-aktif'],
            ],
        ])]);

        $sessions = (new Fonnte(
            ['token' => 'tok-aktif', 'account_token' => 'acct'],
            $backend->executor()
        ))->listSessions();

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/get-devices', (string) $backend->lastRequest()?->getUri());
        self::assertSame('acct', $backend->lastHeader('Authorization'));
        self::assertCount(2, $sessions);
        self::assertSame('628111', $sessions[0]->id);
        self::assertFalse($sessions[0]->isConnected());
        self::assertSame('628222', $sessions[1]->id);
        self::assertTrue($sessions[1]->isConnected());
        self::assertSame('sekolah', $sessions[1]->profileName);
    }

    public function testWuzapiListSessionsThrowsConfigurationException(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('tidak bisa mendaftar sesi');

        (new Wuzapi(['token' => 't', 'url' => self::BASE], $backend->executor()))->listSessions();
    }

    public function testWwebjsListsSessionIdsOnly(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'success' => true,
            'result' => ['sess-a', 'sess-b'],
        ])]);

        $sessions = (new Wwebjs(
            ['token' => 't', 'url' => self::BASE, 'session' => 'sess-a'],
            $backend->executor()
        ))->listSessions();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/session/getSessions', (string) $backend->lastRequest()?->getUri());
        self::assertCount(2, $sessions);
        self::assertSame('sess-a', $sessions[0]->id);
        // Wwebjs hanya mengembalikan nama session — keadaan dan connectedness kosong.
        self::assertSame('', $sessions[0]->status);
        self::assertFalse($sessions[0]->isConnected());
        self::assertSame('sess-b', $sessions[1]->id);
    }

    public function testWaxumListsAllSessions(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'sessions' => [
                ['id' => 'sess-1', 'status' => 'logged_in', 'is_logged_in' => true, 'phone_number' => '62811', 'push_name' => 'Sekolah'],
                ['id' => 'sess-2', 'status' => 'disconnected', 'is_logged_in' => false],
            ],
            'total' => 2,
        ])]);

        $sessions = (new Waxum(
            ['token' => 't', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->executor()
        ))->listSessions();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/v1/sessions', (string) $backend->lastRequest()?->getUri());
        self::assertCount(2, $sessions);
        self::assertSame('sess-1', $sessions[0]->id);
        self::assertTrue($sessions[0]->isConnected());
        self::assertSame('62811', $sessions[0]->phoneNumber);
        self::assertSame('Sekolah', $sessions[0]->profileName);
        self::assertSame('sess-2', $sessions[1]->id);
        self::assertFalse($sessions[1]->isConnected());
    }

    // ---------------------------------------------------------------------
    // Client dan value object
    // ---------------------------------------------------------------------

    public function testClientDelegatesCheckSessionToProvider(): void
    {
        $backend = new MockBackend([MockBackend::json(['id' => 'sess-1', 'status' => 'CONNECTED'])]);

        $session = $this->client($backend)->checkSession();

        self::assertSame('OpenWA', $session->provider);
        self::assertTrue($session->isConnected());
        self::assertSame(self::BASE . '/api/sessions/sess-1', (string) $backend->lastRequest()?->getUri());
    }

    public function testClientPassesCreateOptionsThrough(): void
    {
        $backend = new MockBackend([MockBackend::json(['id' => 'baru', 'status' => 'INITIALIZING'])]);

        $session = $this->client($backend)->createSession(['id' => 'baru', 'name' => 'Baru']);

        self::assertSame(['id' => 'baru', 'name' => 'Baru'], $backend->lastJson());
        self::assertSame('baru', $session->id);
    }

    public function testClientDelegatesListSessionsToProvider(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'success' => true,
            'data' => [['id' => 'sess-1', 'status' => 'CONNECTED']],
        ])]);

        $sessions = $this->client($backend)->listSessions();

        self::assertCount(1, $sessions);
        self::assertSame('OpenWA', $sessions[0]->provider);
        self::assertTrue($sessions[0]->isConnected());
    }

    public function testSessionSerialisesWithoutRawPayloadOrToken(): void
    {
        $session = new Session(
            provider: 'OpenWA',
            id: 'sess-1',
            status: 'CONNECTED',
            connected: true,
            qr: 'data:image/png;base64,AAA',
            token: 'rahasia',
            phoneNumber: '62811',
            profileName: 'Sekolah',
            raw: ['id' => 'sess-1', 'stats' => ['messagesSent' => 12]],
        );

        self::assertTrue($session->isConnected());
        self::assertTrue($session->hasQr());
        self::assertSame('rahasia', $session->token);
        self::assertSame('OpenWA: CONNECTED (sess-1)', (string) $session);

        $expected = [
            'provider' => 'OpenWA',
            'id' => 'sess-1',
            'status' => 'CONNECTED',
            'connected' => true,
            'qr' => 'data:image/png;base64,AAA',
            'phoneNumber' => '62811',
            'profileName' => 'Sekolah',
        ];

        // Token adalah kredensial dan raw bisa besar: keduanya sengaja tidak
        // ikut, supaya keluaran ini aman dicatat ke log.
        self::assertSame($expected, $session->toArray());
        self::assertSame(json_encode($expected), $session->toJson());
    }

    /** @param array<string,mixed> $options */
    private function openWa(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            array_merge(['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'], $options),
            $backend->executor()
        );
    }

    private function client(MockBackend $backend): Client
    {
        return new Client(
            ['provider' => 'OpenWA', 'token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->client()
        );
    }
}
