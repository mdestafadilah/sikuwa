<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConflictException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\ServiceUnavailableException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\Waxum\Waxum;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class WaxumTest extends TestCase
{
    private const BASE = 'https://waxum.test';
    private const SESSION = 'siku-1';
    private const API = '/api/v1';

    /** Payload base64 yang isinya teks terbaca, supaya assertion-nya jelas. */
    private const TEXT_B64 = 'aGFsbyBkdW5pYQ==';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): Waxum
    {
        return new Waxum(
            array_merge(['token' => 'super-token', 'url' => self::BASE, 'session' => self::SESSION], $options),
            $backend->executor()
        );
    }

    public function testSendsSingleMessage(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['message_id' => '3EB0WAX', 'timestamp' => 1, 'to' => '62811@s.whatsapp.net']),
        ]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: 3EB0WAX', $result);
        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('Bearer super-token', $backend->lastHeader('Authorization'));
        self::assertSame(['to' => '6281234567890', 'text' => 'halo'], $backend->lastJson());
    }

    /**
     * Waxum adalah satu-satunya gateway di SDK ini yang memakai
     * `Authorization: Bearer` dengan token yang diterbitkan server sendiri,
     * jadi header-nya diperiksa terpisah dari body.
     */
    public function testAuthHeaderIsBearer(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $this->provider($backend, ['token' => 'abc'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('Bearer abc', $backend->lastHeader('Authorization'));
    }

    public function testBaseUrlAlreadyEndingWithApiV1IsNotDuplicated(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $this->provider($backend, ['url' => 'https://waxum.test/api/v1'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://waxum.test/api/v1/sessions/' . self::SESSION . '/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    public function testBulkIsSentSequentially(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['message_id' => 'a']),
            MockBackend::json(['message_id' => 'b']),
        ]);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $result);
        self::assertSame(2, $backend->count());
    }

    public function testBulkFailureIsAggregated(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['message_id' => 'a']),
            MockBackend::json(['success' => false, 'error' => ['code' => 500, 'message' => 'nomor tidak valid']], 500),
        ]);

        try {
            $this->provider($backend)->sendMessage([
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
            ]);
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('1/2 pesan terkirim', $e->getMessage());
            self::assertStringContainsString('62822:', $e->getMessage());
        }
    }

    public function testMessageRequiresSession(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        $this->provider($backend, ['session' => ''])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    // ---------------------------------------------------------------- media

    public function testImageIsSentAsBase64WithMimetype(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'IMG'])]);

        $this->provider($backend)->sendImage([
            'destination' => '081234567890',
            'image' => 'data:image/png;base64,' . self::TEXT_B64,
            'filename' => 'bukti.png',
            'caption' => 'Bukti transfer',
        ]);

        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/messages/image',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame([
            'to' => '6281234567890',
            // Data URI diubah ke {data, mimetype}: waxum memisahkan keduanya.
            'image' => ['data' => self::TEXT_B64, 'mimetype' => 'image/png'],
            'caption' => 'Bukti transfer',
        ], $backend->lastJson());
    }

    public function testDocumentKeepsFilenameAndGoesToDocumentEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'DOC'])]);

        $this->provider($backend)->sendFile([
            'destination' => '0811',
            'file' => self::TEXT_B64,
            'filename' => 'invoice-1209.pdf',
        ]);

        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/messages/document',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame([
            'to' => '62811',
            // Jenisnya ditebak dari ekstensi; waxum menuntut mimetype ikut.
            'document' => ['data' => self::TEXT_B64, 'mimetype' => 'application/pdf'],
            'filename' => 'invoice-1209.pdf',
        ], $backend->lastJson());
    }

    /**
     * Berbeda dari ApiMe dan wuzapi, waxum mengunduh berkas dari URL sendiri —
     * jadi URL diteruskan sebagai `{url}`, bukan ditolak.
     */
    public function testPublicUrlIsForwardedAsUrlObject(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'URL'])]);

        $this->provider($backend)->sendImage([
            'destination' => '0811',
            'image' => 'https://cdn.test/bukti.png',
            'filename' => 'bukti.png',
        ]);

        self::assertSame([
            'to' => '62811',
            'image' => ['url' => 'https://cdn.test/bukti.png'],
            // Endpoint gambar tidak menerima `filename`: nama itu hanya dipakai
            // pada dokumen, jadi kehadirannya di sini justru salah.
        ], $backend->lastJson());
    }

    public function testFileSentAsImageUsesImageEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'IMG'])]);

        $this->provider($backend)->sendFile([
            'destination' => '0811',
            'file' => 'data:image/png;base64,' . self::TEXT_B64,
        ]);

        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/messages/image',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    // --------------------------------------------------------------- typing

    public function testTypingSendsChatState(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $result = $this->provider($backend)->sendTyping([
            'destination' => '081234567890',
            'duration' => 3,
        ]);

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 6281234567890', $result);
        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/chatstate/send',
            (string) $backend->lastRequest()?->getUri()
        );
        // Kosakata waxum kebetulan sama dengan kosakata baku SDK ini.
        self::assertSame(['to' => '6281234567890', 'state' => 'composing'], $backend->lastJson());
    }

    public function testRecordingStateIsPassedThrough(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $this->provider($backend)->sendTyping([
            'destination' => '0811',
            'state' => 'audio',
            'duration' => 3,
        ]);

        self::assertSame(['to' => '62811', 'state' => 'recording'], $backend->lastJson());
    }

    public function testPausedNeedsNoDuration(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $this->provider($backend)->sendTyping([
            'destination' => '0811',
            'state' => 'paused',
        ]);

        self::assertSame(['to' => '62811', 'state' => 'paused'], $backend->lastJson());
    }

    // -------------------------------------------------------------- session

    public function testCreateSessionSendsIdFromOptions(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['session' => [
                'id' => 'notif',
                'status' => 'connecting',
                'is_logged_in' => false,
            ]]),
        ]);

        $session = $this->provider($backend)->createSession(['id' => 'notif', 'name' => 'Notifikasi']);

        self::assertSame(self::BASE . self::API . '/sessions', (string) $backend->lastRequest()?->getUri());
        self::assertSame(['id' => 'notif', 'name' => 'Notifikasi'], $backend->lastJson());
        self::assertSame('Waxum', $session->provider);
        self::assertSame('notif', $session->id);
        self::assertSame('connecting', $session->status);
        self::assertFalse($session->isConnected());
    }

    public function testCreateSessionFallsBackToConfiguredSessionId(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['session' => ['id' => self::SESSION, 'status' => 'connecting']]),
        ]);

        $session = $this->provider($backend)->createSession();

        self::assertSame(['id' => self::SESSION], $backend->lastJson());
        self::assertSame(self::SESSION, $session->id);
    }

    public function testCheckSessionReadsLoggedIn(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'status' => 'logged_in',
                'is_logged_in' => true,
                'phone_number' => '628123456789',
                'push_name' => 'SIKUWA',
            ]),
        ]);

        $session = $this->provider($backend)->checkSession();

        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/status',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('logged_in', $session->status);
        self::assertTrue($session->isConnected());
        self::assertSame('628123456789', $session->phoneNumber);
        self::assertSame('SIKUWA', $session->profileName);
        self::assertSame('Waxum: logged_in (siku-1)', (string) $session);
    }

    /**
     * Websocket yang hidup belum berarti login: hanya `is_logged_in` yang
     * menentukan sesi siap mengirim pesan.
     */
    public function testConnectedButNotLoggedInIsNotReady(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'connected', 'is_logged_in' => false]),
        ]);

        $session = $this->provider($backend)->checkSession();

        self::assertSame('connected', $session->status);
        self::assertFalse($session->isConnected());
    }

    public function testShowQrReturnsDataUriWhenPayloadIsBase64Png(): void
    {
        // Data URI PNG yang sudah lengkap: satu-satunya bentuk yang dianggap
        // gambar oleh SDK, karena waxum mengirim string mentah untuk digambar.
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

        $backend = new MockBackend([
            MockBackend::json(['qr_codes' => [$dataUri], 'timeout_seconds' => 60, 'status' => 'waiting_for_qr']),
        ]);

        $qr = $this->provider($backend)->showQr();

        self::assertSame(
            self::BASE . self::API . '/sessions/' . self::SESSION . '/qr',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertTrue($qr->hasQr());
        self::assertSame($dataUri, $qr->qrImage());
        self::assertFalse($qr->isConnected());
    }

    /**
     * Waxum mengirim string mentah yang harus digambar sendiri oleh pemanggil.
     * Memasukkannya ke data URI PNG akan menghasilkan `<img>` yang rusak, jadi
     * SDK membiarkannya kosong dan bentuk mentahnya tetap ada di `$raw`.
     */
    public function testRawQrPayloadIsNotMisreportedAsImage(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['qr_codes' => ['2@AbCdEf123456'], 'status' => 'waiting_for_qr']),
        ]);

        $qr = $this->provider($backend)->showQr();

        self::assertFalse($qr->hasQr());
        self::assertSame('', $qr->qrTag());
        self::assertSame('2@AbCdEf123456', $qr->raw['qr_codes'][0]);
    }

    public function testShowQrOnConnectedSessionIsNotAnException(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['qr_codes' => [], 'timeout_seconds' => 60, 'status' => 'logged_in']),
        ]);

        $qr = $this->provider($backend)->showQr();

        self::assertTrue($qr->isConnected());
        self::assertFalse($qr->hasQr());
        self::assertSame('logged_in', $qr->status);
    }

    // ---------------------------------------------------------------- error

    public function testErrorEnvelopeIsUnwrapped(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => ['code' => 404, 'message' => 'Session not found: nope']], 404),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Waxum menolak pesan (HTTP 404): Session not found: nope [sesi tidak ditemukan, cek WHATSAPP_SESSION]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testUnauthorizedExplainsToken(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => ['code' => 401, 'message' => 'Invalid token format']], 401),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('[token waxum salah atau kosong, cek WHATSAPP_TOKEN]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNotConnectedExplainsThatNothingWasSent(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => ['code' => 503, 'message' => 'Client not connected']], 503),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('[sesi WhatsApp belum tersambung, tidak ada pesan yang terkirim — pindai QR-nya lewat showQr()]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testDuplicateSessionExplainsToCheckFirst(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => ['code' => 409, 'message' => 'Client already connected']], 409),
        ]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('[sesi dengan id itu sudah ada — pakai checkSession(), bukan createSession()]');

        $this->provider($backend)->createSession();
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons Waxum tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    // --------------------------------------------------------- configuration

    public function testUrlAndTokenComeFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://env-waxum.test/',
            'WHATSAPP_TOKEN' => 'tok-env',
            'WHATSAPP_SESSION' => self::SESSION,
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        (new Waxum(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://env-waxum.test' . self::API . '/sessions/' . self::SESSION . '/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer tok-env', $backend->lastHeader('Authorization'));
    }

    public function testProviderNameAndDefaultUrl(): void
    {
        self::assertSame('Waxum', (new Waxum(['token' => 't']))->getProvider());
        self::assertSame('https://waxum.whatsapp.com', Waxum::DEFAULT_URL);
    }

    public function testSessionIdIsNotUrlEncoded(): void
    {
        $backend = new MockBackend([MockBackend::json(['message_id' => 'x'])]);

        $this->provider($backend, ['session' => 'notif-sekolah'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            self::BASE . self::API . '/sessions/notif-sekolah/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
    }
}
