<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\AuthException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\ForbiddenException;
use Sikuwa\Whatsapp\Exceptions\NotFoundException;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class EvolutionAPITest extends TestCase
{
    private const BASE = 'https://v7.test';
    private const INSTANCE = 'siku';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): EvolutionAPI
    {
        return new EvolutionAPI(
            array_merge(['token' => 'api-key', 'url' => self::BASE, 'instance' => self::INSTANCE], $options),
            $backend->executor()
        );
    }

    public function testSendsSingleMessage(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => '3EB0XYZ']])]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: 3EB0XYZ', $result);
        self::assertSame(
            self::BASE . '/message/sendText/' . self::INSTANCE,
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('api-key', $backend->lastHeader('apikey'));
        self::assertSame(['number' => '6281234567890', 'text' => 'halo'], $backend->lastJson());
    }

    public function testDelayIsConvertedToMilliseconds(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'x']])]);

        $this->provider($backend)->sendMessage([
            'destination' => '0811',
            'message' => 'a',
            'delay' => 4,
        ]);

        // Antarmuka SDK memakai detik; Evolution menghitung milidetik.
        self::assertSame(4000, $backend->lastJson()['delay']);
    }

    public function testDelayIsOmittedWhenZero(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'x']])]);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertArrayNotHasKey('delay', $backend->lastJson());
    }

    public function testMissingInstanceIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_INSTANCE belum diisi di .env');

        $this->provider(new MockBackend(), ['instance' => ''])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testUnauthorizedExplainsApiKey(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'Unauthorized'], 401)]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[apikey salah, cek WHATSAPP_TOKEN]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testForbiddenExplainsDisconnectedInstance(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'Forbidden'], 403)]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('[instance belum tersambung ke WhatsApp]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNotFoundExplainsInstanceName(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'Not Found'], 404)]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('[instance tidak ditemukan, cek WHATSAPP_INSTANCE]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    /**
     * Evolution menyelipkan detail aslinya di response.message, bukan di
     * message tingkat atas.
     */
    public function testNestedErrorMessageIsUsed(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'status' => 400,
                'error' => 'Bad Request',
                'response' => ['message' => ['number is required']],
            ], 400),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('EvolutionAPI menolak pesan (HTTP 400): number is required');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons EvolutionAPI tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    /**
     * Jeda dititipkan ke server, jadi klien tidak menunggu di antara request —
     * dua pesan harus terkirim tanpa `sleep()`.
     */
    public function testBulkSendsWithoutBlocking(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['key' => ['id' => 'a']]),
            MockBackend::json(['key' => ['id' => 'b']]),
        ]);

        $mulai = microtime(true);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a', 'delay' => 5],
            ['destination' => '0822', 'message' => 'b', 'delay' => 5],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $result);
        self::assertSame(2, $backend->count());
        self::assertLessThan(1.0, microtime(true) - $mulai, 'Pengiriman bulk tidak boleh memblokir pemanggil');
    }

    /**
     * Kunci instance per-provider menang atas `WHATSAPP_INSTANCE` bersama,
     * supaya Evolution API dan ApiMe bisa memakai instance yang berbeda.
     */
    public function testProviderInstanceBeatsSharedInstanceFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://v7.test',
            'WHATSAPP_INSTANCE' => 'inst-bersama',
            'WHATSAPP_INSTANCE_EvolutionAPI' => 'inst-evo',
            'WHATSAPP_TOKEN_EvolutionAPI' => 'api-key',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'a']])]);

        (new EvolutionAPI(null, $backend->executor()))->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            self::BASE . '/message/sendText/inst-evo',
            (string) $backend->lastRequest()?->getUri()
        );
    }
}
