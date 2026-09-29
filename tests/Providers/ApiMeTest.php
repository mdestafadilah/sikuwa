<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\ForbiddenException;
use Sikuwa\Whatsapp\Exceptions\ServiceUnavailableException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class ApiMeTest extends TestCase
{
    private const BASE = 'https://v14.test';
    private const INSTANCE = 'inst-uuid';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): ApiMe
    {
        return new ApiMe(
            array_merge(['token' => 'instance-token', 'url' => self::BASE, 'instance' => self::INSTANCE], $options),
            $backend->executor()
        );
    }

    public function testSendsSingleMessage(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['whatsappId' => '3EB0']])]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: 3EB0', $result);
        self::assertSame(
            self::BASE . '/api/instances/' . self::INSTANCE . '/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer instance-token', $backend->lastHeader('Authorization'));
        self::assertSame(
            ['to' => '6281234567890', 'text' => 'halo'],
            $backend->lastJson()
        );
    }

    public function testFallsBackToIdWhenWhatsappIdIsMissing(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['id' => 'fallback-1']])]);

        self::assertSame(
            'Sukses, messageId: fallback-1',
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a'])
        );
    }

    /**
     * Kunci idempotensi harus stabil supaya kartu yang ter-scan dua kali
     * beruntun tidak menghasilkan dua pesan WhatsApp.
     */
    public function testIdempotencyKeyIsDeterministic(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);

        $provider = $this->provider($backend);
        $message = ['destination' => '0811', 'message' => 'a'];

        $provider->sendMessage($message);
        $first = $backend->lastHeader('Idempotency-Key');

        $provider->sendMessage($message);
        $second = $backend->lastHeader('Idempotency-Key');

        self::assertNotSame('', $first);
        self::assertSame($first, $second);
        self::assertStringStartsWith('siku-', $first);
    }

    public function testDifferentContentProducesDifferentKey(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);

        $provider = $this->provider($backend);

        $provider->sendMessage(['destination' => '0811', 'message' => 'a']);
        $first = $backend->lastHeader('Idempotency-Key');

        $provider->sendMessage(['destination' => '0811', 'message' => 'b']);

        self::assertNotSame($first, $backend->lastHeader('Idempotency-Key'));
    }

    public function testGroupJidIsPassedThroughUntouched(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['whatsappId' => 'a']])]);

        $this->provider($backend)->sendMessage([
            'destination' => '1234567890-123456@g.us',
            'message' => 'a',
        ]);

        self::assertSame('1234567890-123456@g.us', $backend->lastJson()['to']);
    }

    public function testMissingInstanceIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_INSTANCE belum diisi di .env');

        $this->provider(new MockBackend(), ['instance' => ''])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testInvalidDestinationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('tidak punya nomor tujuan yang valid');

        $this->provider(new MockBackend())
            ->sendMessage(['destination' => 'abc', 'message' => 'a']);
    }

    /**
     * ApiMe menuntut instance token; JWT user ditolak. Pesan errornya harus
     * menjelaskan itu, karena 403 tanpa konteks mudah disalahartikan.
     */
    public function testForbiddenExplainsInstanceTokenRequirement(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'forbidden'], 403)]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('[token harus instance token, bukan JWT user atau API token global]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testServiceUnavailableExplainsSessionIsNotReady(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'unavailable'], 503)]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('[sesi WhatsApp belum siap, tidak ada pesan yang terkirim]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testSuccessfulBulkIsReported(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $result);
        self::assertSame(2, $backend->count());
    }

    /**
     * Satu nomor bermasalah tidak boleh membatalkan sisanya, tapi pemanggil
     * tetap harus tahu ada yang gagal.
     */
    public function testBulkFailureIsAggregatedAndThrown(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'ok']]),
            MockBackend::json(['error' => 'nomor tidak terdaftar'], 400),
        ]);

        try {
            $this->provider($backend)->sendMessage([
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
            ]);
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('1/2 pesan terkirim', $e->getMessage());
            self::assertStringContainsString(
                '62822: ApiMe menolak pesan (HTTP 400): nomor tidak terdaftar',
                $e->getMessage()
            );
        }
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons ApiMe tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    /** URL yang sudah berakhiran /api tidak boleh menjadi /api/api. */
    public function testApiSuffixInBaseUrlIsNotDoubled(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['whatsappId' => 'a']])]);

        $this->provider($backend, ['url' => self::BASE . '/api'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            self::BASE . '/api/instances/' . self::INSTANCE . '/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    /**
     * Kunci instance per-provider menang atas `WHATSAPP_INSTANCE` bersama,
     * supaya ApiMe dan Evolution API bisa memakai instance yang berbeda.
     */
    public function testProviderInstanceBeatsSharedInstanceFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://v14.test',
            'WHATSAPP_INSTANCE' => 'inst-bersama',
            'WHATSAPP_INSTANCE_ApiMe' => 'inst-apime',
            'WHATSAPP_TOKEN_ApiMe' => 'instance-token',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['data' => ['whatsappId' => 'a']])]);

        (new ApiMe(null, $backend->executor()))->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame(
            self::BASE . '/api/instances/inst-apime/messages/text',
            (string) $backend->lastRequest()?->getUri()
        );
    }
}
