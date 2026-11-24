<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\AuthException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\NotFoundException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Providers\WPPConnect\WPPConnect;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class WPPConnectTest extends TestCase
{
    private const BASE = 'https://wpp.test';
    private const SESSION = 'sikuwa';
    private const API = '/api/sikuwa';

    /** Payload base64 yang isinya teks terbaca, supaya assertion-nya jelas. */
    private const TEXT_B64 = 'aGFsbyBkdW5pYQ==';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): WPPConnect
    {
        return new WPPConnect(
            array_merge(['token' => 'tok-sesi', 'url' => self::BASE, 'session' => self::SESSION], $options),
            $backend->executor()
        );
    }

    /**
     * Balasan sukses WPPConnect: HTTP 201 dengan amplop
     * `{status:'success', response:…, mapper:'return'}`.
     *
     * @param array<string,mixed> $message Satu objek pesan di dalam `response`.
     */
    private static function sent(array $message): HttpResponse
    {
        return MockBackend::json(['status' => 'success', 'response' => [$message], 'mapper' => 'return'], 201);
    }

    // ----------------------------------------------------------------- teks

    public function testSendsSingleMessage(): void
    {
        $backend = new MockBackend([
            self::sent(['id' => ['_serialized' => 'true_6281234567890@c.us_3EB0']]),
        ]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: true_6281234567890@c.us_3EB0', $result);
        self::assertSame(
            self::BASE . self::API . '/send-message',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('Bearer tok-sesi', $backend->lastHeader('Authorization'));
        self::assertSame(['phone' => '6281234567890', 'message' => 'halo'], $backend->lastJson());
    }

    /**
     * Token WPPConnect adalah token **per sesi** hasil
     * `POST /api/{session}/{secretkey}/generate-token`, jadi headernya tetap
     * Bearer biasa — bukan `x-secret-key`.
     */
    public function testAuthHeaderIsBearer(): void
    {
        $backend = new MockBackend([self::sent(['id' => ['_serialized' => 'x']])]);

        $this->provider($backend, ['token' => 'abc'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('Bearer abc', $backend->lastHeader('Authorization'));
        self::assertSame('', $backend->lastHeader('x-secret-key'));
    }

    public function testBaseUrlAlreadyEndingWithApiIsNotDuplicated(): void
    {
        $backend = new MockBackend([self::sent(['id' => ['_serialized' => 'x']])]);

        $this->provider($backend, ['url' => 'https://wpp.test/api'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://wpp.test' . self::API . '/send-message',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    public function testBulkIsSentSequentially(): void
    {
        $backend = new MockBackend([
            self::sent(['id' => ['_serialized' => 'a']]),
            self::sent(['id' => ['_serialized' => 'b']]),
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
            self::sent(['id' => ['_serialized' => 'a']]),
            MockBackend::json(['status' => 'Error', 'message' => 'Erro ao enviar a mensagem.'], 500),
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

    /**
     * JID grup harus ditandai `isGroup`. Tanpa penanda itu, middleware
     * `statusConnection` menanyakan nomor ke grup dan menolak permintaannya
     * sebelum pesannya sempat dikirim.
     */
    public function testGroupDestinationIsFlagged(): void
    {
        $backend = new MockBackend([self::sent(['id' => ['_serialized' => 'x']])]);

        $this->provider($backend)->sendMessage([
            'destination' => '120363000000000000@g.us',
            'message' => 'halo grup',
        ]);

        self::assertSame([
            'phone' => '120363000000000000@g.us',
            'isGroup' => true,
            'message' => 'halo grup',
        ], $backend->lastJson());
    }

    public function testMessageRequiresSession(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        $this->provider($backend, ['session' => ''])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    /**
     * Server memakai `session:token` di URL sebagai cara autentikasi
     * alternatif, jadi nama sesi bertitik dua membuat token di header
     * diabaikan dan jawabannya 401 tanpa sebab yang jelas.
     */
    public function testSessionNameWithColonIsRejectedEarly(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("tidak boleh memuat ':'");

        $this->provider($backend, ['session' => 'notif:rahasia'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    // ---------------------------------------------------------------- media

    public function testImageIsSentAsDataUri(): void
    {
        $backend = new MockBackend([self::sent(['ack' => 1, 'id' => ['_serialized' => 'IMG']])]);

        $result = $this->provider($backend)->sendImage([
            'destination' => '081234567890',
            'image' => 'data:image/png;base64,' . self::TEXT_B64,
            'filename' => 'bukti.png',
            'caption' => 'Bukti transfer',
        ]);

        self::assertSame('Sukses, messageId: IMG', $result);
        self::assertSame(
            self::BASE . self::API . '/send-file-base64',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame([
            'phone' => '6281234567890',
            'filename' => 'bukti.png',
            'base64' => 'data:image/png;base64,' . self::TEXT_B64,
            'caption' => 'Bukti transfer',
        ], $backend->lastJson());
    }

    /**
     * Base64 telanjang **tidak** diterima WPPConnect: `client.sendFile()`
     * hanya memakai isinya langsung bila berawalan `data:`, selain itu ia
     * menganggapnya path lokal. Jadi SDK yang membungkusnya.
     */
    public function testBareBase64IsWrappedIntoDataUri(): void
    {
        $backend = new MockBackend([self::sent(['ack' => 1, 'id' => ['_serialized' => 'DOC']])]);

        $this->provider($backend)->sendFile([
            'destination' => '0811',
            'file' => self::TEXT_B64,
            'filename' => 'invoice-1209.pdf',
        ]);

        self::assertSame([
            'phone' => '62811',
            'filename' => 'invoice-1209.pdf',
            'base64' => 'data:application/pdf;base64,' . self::TEXT_B64,
        ], $backend->lastJson());
    }

    /** Server mengunduh sendiri berkas dari URL, jadi URL diteruskan apa adanya. */
    public function testPublicUrlIsForwardedAsIs(): void
    {
        $backend = new MockBackend([self::sent(['ack' => 1, 'id' => ['_serialized' => 'URL']])]);

        $this->provider($backend)->sendImage([
            'destination' => '0811',
            'image' => 'https://cdn.test/bukti.png',
            'filename' => 'bukti.png',
        ]);

        self::assertSame([
            'phone' => '62811',
            'filename' => 'bukti.png',
            'base64' => 'https://cdn.test/bukti.png',
        ], $backend->lastJson());
    }

    // --------------------------------------------------------------- typing

    public function testTypingSendsValueTrue(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'success', 'response' => null], 200),
        ]);

        $result = $this->provider($backend)->sendTyping([
            'destination' => '081234567890',
            'duration' => 3,
        ]);

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 6281234567890', $result);
        self::assertSame(
            self::BASE . self::API . '/typing',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame(['phone' => '6281234567890', 'value' => true], $backend->lastJson());
    }

    public function testPausedSendsValueFalseThroughTypingEndpoint(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'success', 'response' => null], 200),
        ]);

        $this->provider($backend)->sendTyping([
            'destination' => '0811',
            'state' => 'paused',
        ]);

        self::assertSame(
            self::BASE . self::API . '/typing',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame(['phone' => '62811', 'value' => false], $backend->lastJson());
    }

    public function testRecordingUsesItsOwnEndpoint(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'success', 'response' => null], 200),
        ]);

        $this->provider($backend)->sendTyping([
            'destination' => '0811',
            'state' => 'audio',
            'duration' => 3,
        ]);

        self::assertSame(
            self::BASE . self::API . '/recording',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame(['phone' => '62811', 'value' => true], $backend->lastJson());
    }

    // --------------------------------------------------------------- nomor

    public function testChecksNumbersOneRequestPerNumber(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'success', 'response' => [
                'numberExists' => true,
                'id' => ['_serialized' => '6281234567890@c.us'],
            ]]),
            MockBackend::json(['status' => 'success', 'response' => ['numberExists' => false]]),
        ]);

        $hasil = $this->provider($backend)->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(2, $backend->count());
        self::assertSame(
            self::BASE . self::API . '/check-number-status/6281234567890',
            (string) $backend->history[0]['request']->getUri()
        );
        self::assertSame(
            self::BASE . self::API . '/check-number-status/6289876543210',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
        self::assertSame('6281234567890@c.us', $hasil->whatsappId('081234567890'));
    }

    public function testCheckNumbersRequiresSession(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        $this->provider($backend, ['session' => ''])->checkNumbers('081234567890');
    }

    // -------------------------------------------------------------- session

    public function testCreateSessionStartsSession(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'CLOSED', 'qrcode' => null], 200),
        ]);

        $session = $this->provider($backend)->createSession(['id' => 'notif']);

        self::assertSame(
            self::BASE . '/api/notif/start-session',
            (string) $backend->lastRequest()?->getUri()
        );
        // Tanpa opsi tambahan, body-nya kosong — bukan `[]`.
        self::assertSame('', $backend->lastBody());
        self::assertSame('WPPConnect', $session->provider);
        self::assertSame('notif', $session->id);
        self::assertSame('CLOSED', $session->status);
        self::assertFalse($session->isConnected());
    }

    public function testCreateSessionPassesWebhookAndWaitFlag(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'INITIALIZING', 'qrcode' => null], 200),
        ]);

        $this->provider($backend)->createSession([
            'waitQrCode' => true,
            'webhook' => 'https://hook.test/wa',
        ]);

        self::assertSame(
            ['waitQrCode' => true, 'webhook' => 'https://hook.test/wa'],
            $backend->lastJson()
        );
        self::assertSame(
            self::BASE . self::API . '/start-session',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    public function testCheckSessionReadsConnectedStatusAndQr(): void
    {
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

        $backend = new MockBackend([
            MockBackend::json(['status' => 'CONNECTED', 'qrcode' => $dataUri, 'version' => '2.3.6']),
        ]);

        $session = $this->provider($backend)->checkSession();

        self::assertSame(
            self::BASE . self::API . '/status-session',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('CONNECTED', $session->status);
        self::assertTrue($session->isConnected());
        // Server sudah merender QR-nya menjadi data URI, jadi tidak ada yang
        // perlu dibungkus ulang.
        self::assertSame($dataUri, $session->qrImage());
        self::assertSame('WPPConnect: CONNECTED (sikuwa)', (string) $session);
    }

    public function testClosedSessionIsNotConnected(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'CLOSED', 'qrcode' => null]),
        ]);

        $session = $this->provider($backend)->checkSession();

        self::assertSame('CLOSED', $session->status);
        self::assertFalse($session->isConnected());
        self::assertFalse($session->hasQr());
    }

    // ------------------------------------------------------------------- QR

    public function testShowQrWrapsPngBytesIntoDataUri(): void
    {
        // Endpoint ini mengirim PNG biner, bukan JSON.
        $png = "\x89PNG\r\n\x1a\nbukan-gambar-sungguhan";

        $backend = new MockBackend([MockBackend::raw($png)]);

        $qr = $this->provider($backend)->showQr();

        self::assertSame(
            self::BASE . self::API . '/qrcode-session',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertTrue($qr->hasQr());
        self::assertSame('data:image/png;base64,' . base64_encode($png), $qr->qrImage());
        self::assertFalse($qr->isConnected());
    }

    public function testShowQrOnConnectedSessionIsNotAnException(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'CONNECTED', 'message' => 'QRCode is not available...']),
        ]);

        $qr = $this->provider($backend)->showQr();

        self::assertTrue($qr->isConnected());
        self::assertFalse($qr->hasQr());
        self::assertSame('CONNECTED', $qr->status);
    }

    /**
     * `{status: null}` berarti sesinya belum dimulai — memanggil showQr()
     * berulang kali tidak akan menghasilkan QR, jadi pemanggil perlu tahu
     * langkah mana yang kurang.
     */
    public function testShowQrOnUnstartedSessionExplainsCreateSession(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'status' => null,
                'message' => 'Session not started. Please, use the /start-session route',
            ]),
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('belum dimulai');

        $this->provider($backend)->showQr();
    }

    public function testShowQrWhileInitializingIsAnException(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => 'INITIALIZING', 'message' => 'QRCode is not available...']),
        ]);

        try {
            $this->provider($backend)->showQr();
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('belum menyediakan QR', $e->getMessage());
            self::assertStringContainsString('status INITIALIZING', $e->getMessage());
        }
    }

    public function testShowQrWithEmptyBodyIsNotSilentlyEmpty(): void
    {
        $backend = new MockBackend([MockBackend::raw('')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons WPPConnect tidak valid (HTTP 200)');

        $this->provider($backend)->showQr();
    }

    // ------------------------------------------------------------ daftar sesi

    public function testListSessionsExplainsWhyItNeedsTheServerSecret(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('x-secret-key');

        $this->provider($backend)->listSessions();
    }

    // ---------------------------------------------------------------- error

    public function testUnauthorizedExplainsPerSessionToken(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['error' => 'Check that the Session and Token are correct'], 401),
        ]);

        try {
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
            self::fail('Seharusnya melempar AuthException');
        } catch (AuthException $e) {
            self::assertStringContainsString('bukan SECRET_KEY server', $e->getMessage());
        }
    }

    /**
     * 404 dari middleware `statusConnection` berarti sesinya belum
     * tersambung — bukan rute yang salah — dan pesan aslinya berbahasa
     * Portugis.
     */
    public function testDisconnectedSessionExplainsQr(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'response' => null,
                'status' => 'Disconnected',
                'message' => 'A sessão do WhatsApp não está ativa.',
            ], 404),
        ]);

        try {
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
            self::fail('Seharusnya melempar NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('pindai QR-nya', $e->getMessage());
        }
    }

    public function testMissingNumberIsExplained(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'response' => null,
                'status' => 'Connected',
                'message' => 'O número 62811 não existe.',
            ], 400),
        ]);

        try {
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('nomor tujuan tidak terdaftar di WhatsApp', $e->getMessage());
        }
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
        $this->expectExceptionMessage('Respons WPPConnect tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    // --------------------------------------------------------- configuration

    public function testUrlAndTokenComeFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://env-wpp.test/',
            'WHATSAPP_TOKEN' => 'tok-env',
            'WHATSAPP_SESSION' => self::SESSION,
        ][$key] ?? null);

        $backend = new MockBackend([self::sent(['id' => ['_serialized' => 'x']])]);

        (new WPPConnect(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://env-wpp.test' . self::API . '/send-message',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer tok-env', $backend->lastHeader('Authorization'));
    }

    /** Kunci sesi per-provider menang atas `WHATSAPP_SESSION` bersama. */
    public function testProviderSessionBeatsSharedSessionFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_SESSION' => 'sesi-bersama',
            'WHATSAPP_SESSION_WPPConnect' => 'sesi-wpp',
            'WHATSAPP_TOKEN_WPPConnect' => 'tok-env',
        ][$key] ?? null);

        $backend = new MockBackend([self::sent(['id' => ['_serialized' => 'x']])]);

        (new WPPConnect(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://wppconnect.whatsapp.com/api/sesi-wpp/send-message',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    public function testProviderNameAndDefaultUrl(): void
    {
        self::assertSame('WPPConnect', (new WPPConnect(['token' => 't']))->getProvider());
        self::assertSame('https://wppconnect.whatsapp.com', WPPConnect::DEFAULT_URL);
    }
}
