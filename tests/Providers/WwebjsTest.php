<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\ForbiddenException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\Wwebjs\Wwebjs;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class WwebjsTest extends TestCase
{
    private const BASE = 'https://wwebjs.test';
    private const SESSION = 'siku-1';

    /** PNG satu piksel — cukup untuk memastikan byte-nya tidak diubah. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01";

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): Wwebjs
    {
        return new Wwebjs(
            array_merge(['token' => 'api-key', 'url' => self::BASE, 'session' => self::SESSION], $options),
            $backend->executor()
        );
    }

    private static function png(): Response
    {
        return new Response(200, ['Content-Type' => 'image/png'], self::PNG);
    }

    public function testSendsSingleMessage(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'message' => ['id' => ['_serialized' => 'true_62811@c.us_3EB0']]]),
        ]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: true_62811@c.us_3EB0', $result);
        self::assertSame(
            self::BASE . '/client/sendMessage/' . self::SESSION,
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('api-key', $backend->lastHeader('x-api-key'));
        self::assertSame([
            'chatId' => '6281234567890@c.us',
            'contentType' => 'string',
            'content' => 'halo',
        ], $backend->lastJson());
    }

    /** Sebagian versi hanya mengisi `message.id.id`, tanpa `_serialized`. */
    public function testMessageIdFallsBackToIdId(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'FALLBACK']]]),
        ]);

        self::assertSame(
            'Sukses, messageId: FALLBACK',
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a'])
        );
    }

    public function testMediaIsSentAsBase64WithCaption(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'x']]])]);

        $this->provider($backend)->sendImage([
            'destination' => '081234567890',
            'image' => 'data:image/png;base64,' . base64_encode(self::PNG),
            'filename' => 'bukti.png',
            'caption' => 'Bukti transfer',
        ]);

        $payload = $backend->lastJson();

        self::assertSame('MessageMedia', $payload['contentType']);
        self::assertSame([
            'mimetype' => 'image/png',
            // whatsapp-web.js menerima base64 mentah, bukan data URI.
            'data' => base64_encode(self::PNG),
            'filename' => 'bukti.png',
        ], $payload['content']);
        self::assertSame(['caption' => 'Bukti transfer'], $payload['options']);
    }

    public function testMediaWithoutCaptionOmitsOptions(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'x']]])]);

        $this->provider($backend)->sendFile([
            'destination' => '0811',
            'file' => base64_encode('isi'),
            'filename' => 'laporan.pdf',
        ]);

        $payload = $backend->lastJson();

        self::assertSame('MessageMedia', $payload['contentType']);
        self::assertArrayNotHasKey('options', $payload);
    }

    public function testPublicUrlIsSentAsMessageMediaFromUrl(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'x']]])]);

        $this->provider($backend)->sendImage([
            'destination' => '0811',
            'image' => 'https://cdn.test/bukti.png',
            'caption' => 'Lihat ini',
        ]);

        $payload = $backend->lastJson();

        self::assertSame('MessageMediaFromURL', $payload['contentType']);
        self::assertSame('https://cdn.test/bukti.png', $payload['content']);
        self::assertSame(['caption' => 'Lihat ini'], $payload['options']);
    }

    public function testGroupJidIsPassedThroughUntouched(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'x']]])]);

        $this->provider($backend)->sendMessage([
            'destination' => '1234567890-123456@g.us',
            'message' => 'a',
        ]);

        self::assertSame('1234567890-123456@g.us', $backend->lastJson()['chatId']);
    }

    public function testInvalidDestinationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('tidak punya nomor tujuan yang valid');

        $this->provider(new MockBackend())
            ->sendMessage(['destination' => 'abc', 'message' => 'a']);
    }

    public function testMissingSessionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        $this->provider(new MockBackend(), ['session' => ''])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    /**
     * Pengiriman ditolak middleware `sessionValidation` selama session belum
     * tersambung; "Not Found" tanpa konteks mudah disalahartikan.
     */
    public function testNotFoundExplainsDisconnectedSession(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => 'session_not_connected'], 404),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('[session belum tersambung, pindai QR-nya lebih dulu]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testForbiddenExplainsApiKey(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'error' => 'Invalid API key'], 403),
        ]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('[API key salah, cek WHATSAPP_TOKEN]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons Wwebjs tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testSuccessfulBulkIsReported(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'a']]]),
            MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'b']]]),
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
            MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'a']]]),
            MockBackend::json(['success' => false, 'error' => 'Chat not Found'], 404),
        ]);

        try {
            $this->provider($backend)->sendMessage([
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
            ]);
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('1/2 pesan terkirim', $e->getMessage());
            self::assertStringContainsString('62822@c.us:', $e->getMessage());
        }
    }

    /**
     * wwebjs memakai endpoint berbeda per keadaan — bukan satu endpoint dengan
     * kolom status seperti gateway lain.
     */
    public function testTypingUsesItsOwnEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'result' => true])]);

        $result = $this->provider($backend)->sendTyping([
            'destination' => '081234567890',
            'state' => 'composing',
            'duration' => 5,
        ]);

        self::assertSame(self::BASE . '/chat/sendStateTyping/' . self::SESSION, (string) $backend->lastRequest()?->getUri());
        self::assertSame(['chatId' => '6281234567890@c.us'], $backend->lastJson());
        self::assertStringStartsWith('Sukses', $result);
    }

    public function testRecordingUsesItsOwnEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'result' => true])]);

        $this->provider($backend)->sendTyping([
            'destination' => '0811',
            'state' => 'recording',
            'duration' => 3,
        ]);

        self::assertSame(self::BASE . '/chat/sendStateRecording/' . self::SESSION, (string) $backend->lastRequest()?->getUri());
    }

    public function testPausedClearsState(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'result' => true])]);

        $this->provider($backend)->sendTyping(['destination' => '0811', 'state' => 'paused']);

        self::assertSame(self::BASE . '/chat/clearState/' . self::SESSION, (string) $backend->lastRequest()?->getUri());
    }

    /**
     * Endpoint QR mengirim PNG biner; SDK yang membungkusnya menjadi data URI
     * supaya bisa langsung dipasang di atribut `src`.
     */
    public function testShowQrWrapsPngAsDataUri(): void
    {
        $backend = new MockBackend([self::png()]);

        $session = $this->provider($backend)->showQr();

        self::assertSame('data:image/png;base64,' . base64_encode(self::PNG), $session->qrImage());
        self::assertTrue($session->hasQr());
        self::assertFalse($session->isConnected());
        self::assertSame(
            self::BASE . '/session/qr/' . self::SESSION . '/image',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    /**
     * QR yang sudah dipindai dilaporkan sebagai sesi tersambung — memang tidak
     * ada lagi yang perlu dipindai — bukan sebagai kegagalan.
     */
    public function testShowQrAlreadyScannedReportsConnected(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'message' => 'qr code not ready or already scanned']),
            MockBackend::json(['success' => true, 'state' => 'CONNECTED', 'message' => 'session_connected']),
        ]);

        $session = $this->provider($backend)->showQr();

        self::assertTrue($session->isConnected());
        self::assertFalse($session->hasQr());
        self::assertSame(2, $backend->count());
    }

    /** Session yang masih memuat Chromium memang belum menerbitkan QR. */
    public function testShowQrNotReadyIsRejected(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => false, 'message' => 'qr code not ready or already scanned']),
            MockBackend::json(['success' => false, 'state' => null, 'message' => 'session_not_connected']),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Wwebjs menolak pesan (HTTP 200): qr code not ready or already scanned');

        $this->provider($backend)->showQr();
    }

    public function testCheckSessionMapsConnectedState(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'state' => 'CONNECTED', 'message' => 'session_connected']),
            MockBackend::json(['success' => false, 'state' => null, 'message' => 'session_not_found']),
        ]);

        $provider = $this->provider($backend);

        $connected = $provider->checkSession();
        self::assertTrue($connected->isConnected());
        self::assertSame('CONNECTED', $connected->status);
        self::assertSame(self::SESSION, $connected->id);

        // `state` kosong, jadi `message` yang menjelaskan sebabnya.
        $missing = $provider->checkSession();
        self::assertFalse($missing->isConnected());
        self::assertSame('session_not_found', $missing->status);
    }

    public function testCreateSessionSendsWebhookAndUsesConfiguredName(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => 'Session initiated successfully'])]);

        $session = $this->provider($backend)->createSession(['webhookUrl' => 'https://hook.test/wa']);

        self::assertSame(
            self::BASE . '/session/start/' . self::SESSION,
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame(['webhookUrl' => 'https://hook.test/wa'], $backend->lastJson());
        self::assertSame(self::SESSION, $session->id);
        self::assertFalse($session->isConnected());
    }

    public function testCreateSessionWithoutWebhookSendsEmptyBody(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => 'ok'])]);

        $this->provider($backend)->createSession(['id' => 'siku-2']);

        self::assertSame(self::BASE . '/session/start/siku-2', (string) $backend->lastRequest()?->getUri());
        self::assertSame('', $backend->lastBody());
    }

    /**
     * Middleware wwebjs-api menolak nama di luar `[A-Za-z0-9_-]` dengan HTTP 422;
     * SDK menolaknya lebih awal dengan pesan yang menyebut aturannya.
     */
    public function testCreateSessionRejectsInvalidName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Nama session Wwebjs hanya boleh huruf, angka, garis bawah, dan tanda minus');

        $this->provider(new MockBackend())->createSession(['id' => 'siku satu']);
    }

    public function testCreateSessionWithoutAnyNameIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Wwebjs membutuhkan nama session');

        $this->provider(new MockBackend(), ['session' => ''])->createSession();
    }

    public function testUrlAndTokenComeFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://env-wwebjs.test/',
            'WHATSAPP_TOKEN' => 'tok-env',
            'WHATSAPP_SESSION' => 'env-session',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'a']]])]);

        (new Wwebjs(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://env-wwebjs.test/client/sendMessage/env-session',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('tok-env', $backend->lastHeader('x-api-key'));
    }

    /** Kunci session per-provider menang atas `WHATSAPP_SESSION` bersama. */
    public function testProviderSessionBeatsSharedSessionFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_SESSION' => 'sesi-bersama',
            'WHATSAPP_SESSION_Wwebjs' => 'sesi-wwebjs',
            'WHATSAPP_TOKEN_Wwebjs' => 'tok-env',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['success' => true, 'message' => ['id' => ['id' => 'a']]])]);

        (new Wwebjs(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertStringContainsString(
            '/client/sendMessage/sesi-wwebjs',
            (string) $backend->lastRequest()?->getUri()
        );
    }
}
