<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\AuthException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class OpenWATest extends TestCase
{
    private const BASE = 'https://gw.test';
    private const SESSION = 'sess-1';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            array_merge(['token' => 'api-key', 'url' => self::BASE, 'session' => self::SESSION], $options),
            $backend->executor()
        );
    }

    public function testSendsSingleMessageToSendText(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => '3EB0ABC'])]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: 3EB0ABC', $result);
        self::assertSame(
            self::BASE . '/api/sessions/' . self::SESSION . '/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('api-key', $backend->lastHeader('X-API-Key'));
        self::assertSame(
            ['chatId' => '6281234567890@c.us', 'text' => 'halo'],
            $backend->lastJson()
        );
    }

    public function testSendsBulkToSendBulk(): void
    {
        $backend = new MockBackend([MockBackend::json(['totalMessages' => 2, 'batchId' => 'batch-9'])]);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame('Batch diterima (2 pesan), batchId: batch-9', $result);
        self::assertSame(
            self::BASE . '/api/sessions/' . self::SESSION . '/messages/send-bulk',
            (string) $backend->lastRequest()?->getUri()
        );

        $payload = $backend->lastJson();

        self::assertSame([
            ['chatId' => '62811@c.us', 'type' => 'text', 'content' => ['text' => 'a']],
            ['chatId' => '62822@c.us', 'type' => 'text', 'content' => ['text' => 'b']],
        ], $payload['messages']);

        // Tanpa delay eksplisit, jeda bawaan 3 detik dipakai.
        self::assertSame(3000, $payload['options']['delayBetweenMessages']);
        self::assertTrue($payload['options']['randomizeDelay']);
        self::assertFalse($payload['options']['stopOnError']);
    }

    public function testBulkDelayIsClampedToGatewayRange(): void
    {
        $backend = new MockBackend([MockBackend::json(['totalMessages' => 2, 'batchId' => 'b'])]);

        $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a', 'delay' => 0],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        // delayBetweenMessages dibatasi 1000-60000 milidetik oleh OpenWA.
        self::assertSame(1000, $backend->lastJson()['options']['delayBetweenMessages']);
    }

    public function testMissingSessionIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        $this->provider($backend, ['session' => ''])->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testInvalidDestinationIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('tidak punya nomor tujuan yang valid');

        $this->provider($backend)->sendMessage(['destination' => 'abc', 'message' => 'a']);
    }

    public function testTooManyMessagesAreRejected(): void
    {
        $backend = new MockBackend([]);
        $messages = [];

        for ($i = 0; $i < 101; $i++) {
            $messages[] = ['destination' => '0811', 'message' => 'a'];
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('OpenWA membatasi 100 pesan per batch');

        $this->provider($backend)->sendMessage($messages);
    }

    public function testNestJsErrorMessageIsUsed(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['statusCode' => 400, 'message' => 'chatId tidak valid'], 400),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('OpenWA menolak pesan (HTTP 400): chatId tidak valid');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testValidationMessageArrayIsFlattened(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['statusCode' => 400, 'message' => ['chatId harus diisi', 'text terlalu panjang']], 400),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('chatId harus diisi; text terlalu panjang');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testUnauthorizedMapsToAuthException(): void
    {
        $backend = new MockBackend([MockBackend::json(['statusCode' => 401, 'message' => 'nope'], 401)]);

        $this->expectException(AuthException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testConnectionFailureIsReported(): void
    {
        $backend = new MockBackend([MockBackend::connectionFailure()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Gagal menghubungi OpenWA');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testUrlAndSessionComeFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'http://env.test/',
            'WHATSAPP_SESSION' => 'dari-env',
            'WHATSAPP_TOKEN' => 'tok-env',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['messageId' => 'x'])]);

        (new OpenWA(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'http://env.test/api/sessions/dari-env/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('tok-env', $backend->lastHeader('X-API-Key'));
    }

    /**
     * `WHATSAPP_SESSION_OpenWA` harus menang atas `WHATSAPP_SESSION` bersama,
     * supaya OpenWA, Wwebjs, dan Waxum bisa memakai nama session masing-masing
     * dalam satu aplikasi.
     */
    public function testProviderSessionBeatsSharedSessionFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_SESSION' => 'sesi-bersama',
            'WHATSAPP_SESSION_OpenWA' => 'sesi-openwa',
            'WHATSAPP_TOKEN_OpenWA' => 'key-env',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['messageId' => 'x'])]);

        (new OpenWA(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertStringContainsString(
            '/api/sessions/sesi-openwa/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    public function testSessionIdIsUrlEncoded(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'x'])]);

        $this->provider($backend, ['session' => 'a/b c'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertStringContainsString('/messages/send-text', (string) $backend->lastRequest()?->getUri());
        self::assertStringContainsString('a%2Fb%20c', (string) $backend->lastRequest()?->getUri());
    }

    /**
     * `WHATSAPP_URL_OpenWA` harus menang atas `WHATSAPP_URL` bersama, supaya
     * OpenWA dan Wuzapi bisa dikonfigurasi berdampingan.
     */
    public function testProviderUrlBeatsSharedUrlFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://bersama.test',
            'WHATSAPP_URL_OpenWA' => 'https://openwa.test',
            'WHATSAPP_SESSION' => 'sess-env',
            'WHATSAPP_TOKEN_OpenWA' => 'key-env',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['messageId' => 'x'])]);

        (new OpenWA(null, $backend->executor()))
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            'https://openwa.test/api/sessions/sess-env/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
    }
}
