<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\RateLimitException;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;

/**
 * Percobaan ulang otomatis untuk 429/503 yang menyebutkan `Retry-After`.
 *
 * Semua test di sini memakai gateway yang mengirim satu pesan per request
 * (ApiMe), karena hanya di sana percobaan ulang per pesan terlihat sebagai
 * request tambahan. Fonnte dan OpenWA mengirim seluruh batch dalam satu
 * request, jadi percobaan ulangnya pun satu kali untuk seluruh batch.
 */
final class RetryTest extends TestCase
{
    /** @var array<int,int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->slept = [];

        AbstractProvider::useSleeper(function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    protected function tearDown(): void
    {
        AbstractProvider::useSleeper(null);
        Config::useResolver(null);
    }

    /** @param array<string,string|null> $env */
    private function config(array $env): void
    {
        Config::useResolver(fn (string $key): ?string => $env[$key] ?? null);
    }

    /** @return array<string,string|null> */
    private function baseEnv(): array
    {
        return [
            'WHATSAPP_URL' => 'https://v14.test',
            'WHATSAPP_INSTANCE' => 'inst',
            'WHATSAPP_TOKEN' => 'tok',
        ];
    }

    /**
     * Opsi konfigurasi siap pakai untuk konstruktor provider.
     *
     * Sengaja tidak memakai kunci environment: `Config::from()` membaca kunci
     * pendek (`instance`, `token`), bukan nama variabel `.env`. Menulisnya
     * sebagai `instance` di sini membuat provider tidak bergantung pada
     * resolver environment sama sekali.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function options(array $extra = []): array
    {
        return [
            'url' => 'https://v14.test',
            'instance' => 'inst',
            'token' => 'tok',
        ] + $extra;
    }

    private static function rateLimited(int $retryAfter = 5): Response
    {
        return new Response(
            429,
            ['Content-Type' => 'application/json', 'Retry-After' => (string) $retryAfter],
            '{"message":"rate limited"}'
        );
    }

    // -- Retry-After dibaca dari header --------------------------------

    public function testRetryAfterInSecondsIsExposedOnTheException(): void
    {
        $backend = new MockBackend([self::rateLimited(30)]);
        $provider = new ApiMe($this->options(), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getStatus());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function testRetryAfterAsHttpDateIsConvertedToSeconds(): void
    {
        // Satu menit dari sekarang, dibulatkan ke detik supaya selisihnya stabil.
        $moment = time() + 60;
        $header = gmdate('D, d M Y H:i:s', $moment) . ' GMT';

        $backend = new MockBackend([new Response(429, ['Retry-After' => $header], '{}')]);
        $provider = new ApiMe($this->options(), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar RateLimitException');
        } catch (RateLimitException $e) {
            // Jangan paku angka persis: detik bisa bergeser satu selama test
            // berjalan. Yang penting nilainya masuk akal dan bukan nol.
            $this->assertNotNull($e->getRetryAfter());
            $this->assertGreaterThanOrEqual(58, $e->getRetryAfter());
            $this->assertLessThanOrEqual(61, $e->getRetryAfter());
        }
    }

    public function testRetryAfterIsNullWhenGatewayDoesNotSayIt(): void
    {
        $backend = new MockBackend([new Response(429, ['Content-Type' => 'application/json'], '{}')]);
        $provider = new ApiMe($this->options(), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfter());
        }
    }

    public function testZeroAndGarbageRetryAfterAreTreatedAsMissing(): void
    {
        $this->config($this->baseEnv());
        $response = new HttpResponse(429, '{}', '', false, '0');
        $this->assertNull($response->retryAfterSeconds(), 'Nol detik sama saja dengan tidak menunggu');

        $garbage = new HttpResponse(429, '{}', '', false, 'segera');
        $this->assertNull($garbage->retryAfterSeconds());

        $past = new HttpResponse(429, '{}', '', false, 'Wed, 21 Oct 2020 07:28:00 GMT');
        $this->assertNull($past->retryAfterSeconds(), 'Tanggal yang sudah lewat bukan ajakan menunggu');
    }

    // -- Percobaan ulang -------------------------------------------------

    public function testRetryIsOffByDefaultSoTheFirst429IsThrown(): void
    {
        $backend = new MockBackend([self::rateLimited(5)]);
        $provider = new ApiMe($this->options(), $backend->executor());

        $this->expectException(RateLimitException::class);

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
        } finally {
            $this->assertSame(1, $backend->count(), 'Bawaannya mati: jangan kirim request kedua');
            $this->assertSame([], $this->slept);
        }
    }

    public function testRetrySucceedsAfterOneRateLimit(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '2']);

        $backend = new MockBackend([
            self::rateLimited(7),
            MockBackend::json(['success' => true, 'id' => 'msg-1']),
        ]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        $result = $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);

        $this->assertStringContainsString('Sukses', $result);
        $this->assertSame(2, $backend->count());
        $this->assertSame([7], $this->slept, 'Tunggu sesuai Retry-After, bukan angka karangan');
    }

    public function testRetryStopsAfterTheConfiguredBudget(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '1']);

        $backend = new MockBackend([
            self::rateLimited(3),
            self::rateLimited(3),
            self::rateLimited(3),
        ]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar setelah jatah percobaan habis');
        } catch (RateLimitException $e) {
            $this->assertSame(2, $backend->count(), 'Satu percobaan awal + satu percobaan ulang');
            $this->assertSame([3], $this->slept);
            $this->assertSame(3, $e->getRetryAfter(), 'Exception terakhir tetap membawa Retry-After');
        }
    }

    public function testNoRetryWhenGatewayDoesNotSendRetryAfter(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '3']);

        $backend = new MockBackend([new Response(429, ['Content-Type' => 'application/json'], '{}')]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar tanpa mencoba ulang');
        } catch (RateLimitException $e) {
            $this->assertSame(1, $backend->count(), 'Tanpa Retry-After, menebak jeda hanya menabrak dinding yang sama');
            $this->assertSame([], $this->slept);
        }
    }

    public function testOtherStatusesAreNeverRetried(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '5']);

        // 401 tidak akan sembuh kalau diulang, walau Retry-After-nya ada.
        $backend = new MockBackend([
            new Response(401, ['Retry-After' => '10'], '{"message":"token ditolak"}'),
        ]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        try {
            $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
            $this->fail('Seharusnya melempar AuthException');
        } catch (\Sikuwa\Whatsapp\Exceptions\AuthException) {
            $this->assertSame(1, $backend->count());
            $this->assertSame([], $this->slept);
        }
    }

    public function testServiceUnavailableIsAlsoRetried(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '1']);

        $backend = new MockBackend([
            new Response(503, ['Retry-After' => '4'], '{"message":"session belum siap"}'),
            MockBackend::json(['success' => true]),
        ]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        $result = $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);

        $this->assertStringContainsString('Sukses', $result);
        $this->assertSame([4], $this->slept);
    }

    public function testRetryPerMessageInsideABatchDoesNotLoseTheOthers(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '1']);

        $backend = new MockBackend([
            MockBackend::json(['success' => true]),        // pesan 1 langsung sukses
            self::rateLimited(2),                          // pesan 2 kena batas
            MockBackend::json(['success' => true]),        // pesan 2 berhasil diulang
            MockBackend::json(['success' => true]),        // pesan 3 sukses
        ]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        $result = $provider->sendMessage([
            ['destination' => '0811111111', 'message' => 'satu'],
            ['destination' => '0822222222', 'message' => 'dua'],
            ['destination' => '0833333333', 'message' => 'tiga'],
        ]);

        $this->assertStringContainsString('3/3', $result);
        $this->assertSame(4, $backend->count(), 'Tiga pesan, satu di antaranya diulang sekali');
        $this->assertSame([2], $this->slept);
    }

    public function testRetriesOptionOverridesEnvironmentEntirely(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '9']);

        // Nilai eksplisit 0 harus menang utuh, bukan digabung dengan env.
        $config = Config::from($this->baseEnv() + ['retries' => 0]);
        $this->assertSame(0, $config->retries());

        $config = Config::from(['retries' => 3]);
        $this->assertSame(3, $config->retries());
    }

    public function testNegativeRetriesAreClampedToZero(): void
    {
        $config = Config::from(['retries' => -5]);

        $this->assertSame(0, $config->retries());
    }

    public function testSleeperIsUsedSoTestsNeverReallyWait(): void
    {
        $this->config($this->baseEnv() + ['WHATSAPP_RETRIES' => '1']);

        $backend = new MockBackend([self::rateLimited(600), MockBackend::json(['success' => true])]);
        $provider = new ApiMe($this->options(['retries' => 1]), $backend->executor());

        $start = microtime(true);
        $provider->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);
        $elapsed = microtime(true) - $start;

        $this->assertSame([600], $this->slept);
        $this->assertLessThan(1.0, $elapsed, 'Penidur pengganti harus mencegah tidur sungguhan');
    }
}
