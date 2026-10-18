<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ForbiddenException;
use Sikuwa\Whatsapp\Exceptions\RateLimitException;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Waxum\Waxum;

/**
 * Percobaan ulang pada `checkNumbers()`, bukan pada pengiriman pesan.
 *
 * Sampai sini pemeriksaan nomor adalah satu-satunya jalur yang tidak menghormati
 * `Retry-After`: pengiriman pesan sudah mengulang sejak 0.7.1, sedangkan
 * pemeriksaan langsung melempar 429. Akibatnya paling terasa justru di gateway
 * yang memeriksa satu nomor per request — daftar sepuluh nomor berhenti di nomor
 * ketiga, dan pemanggil tidak pernah tahu nomor mana yang sudah dijawab.
 *
 * Yang paling penting diuji di sini bukan "apakah ia mengulang", melainkan
 * **di mana** ia mengulang. Percobaan ulang diletakkan per request, bukan
 * membungkus seluruh daftar: mengulang seluruh daftar berarti menanyakan ulang
 * nomor yang sudah dijawab, dan pada daftar panjang mengulang dari awal hampir
 * pasti menabrak batas laju yang sama lagi sehingga tidak pernah selesai.
 */
final class ProbeRetryTest extends TestCase
{
    private const BASE = 'https://gw.test';

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

    private static function rateLimited(int $retryAfter = 5): HttpResponse
    {
        return MockBackend::response(
            429,
            ['Content-Type' => 'application/json', 'Retry-After' => (string) $retryAfter],
            '{"message":"rate limited"}'
        );
    }

    /** @param array<string,mixed> $options */
    private function openWa(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'] + $options,
            $backend->executor()
        );
    }

    /** @param array<string,mixed> $options */
    private function waxum(MockBackend $backend, array $options = []): Waxum
    {
        return new Waxum(
            ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'] + $options,
            $backend->executor()
        );
    }

    /** Path pemeriksaan yang diminta pada request ke-`$i`. */
    private static function probePath(MockBackend $backend, int $i): string
    {
        return (string) $backend->history[$i]['request']->getUri();
    }

    // ---------------------------------------------------------------------
    // Bawaannya tetap mati
    // ---------------------------------------------------------------------

    public function testRetryIsOffByDefaultSoTheFirst429IsThrown(): void
    {
        $backend = new MockBackend([self::rateLimited(5)]);

        $this->expectException(RateLimitException::class);

        try {
            $this->openWa($backend)->checkNumbers('081234567890');
        } finally {
            self::assertSame(1, $backend->count(), 'Bawaannya mati: jangan kirim request kedua');
            self::assertSame([], $this->slept);
        }
    }

    // ---------------------------------------------------------------------
    // Yang mengulang
    // ---------------------------------------------------------------------

    public function testCheckNumbersRetriesAfterRateLimit(): void
    {
        $backend = new MockBackend([
            self::rateLimited(7),
            MockBackend::json(['exists' => true, 'whatsappId' => '6281234567890@c.us']),
        ]);

        $hasil = $this->openWa($backend, ['retries' => 1])->checkNumbers('081234567890');

        self::assertSame(2, $backend->count(), 'Satu percobaan awal + satu percobaan ulang');
        self::assertSame([7], $this->slept, 'Tunggu sesuai Retry-After, bukan angka karangan');
        self::assertTrue($hasil->isRegistered('081234567890'));
    }

    /**
     * Inti perubahan ini: percobaan ulang **per request**, bukan per daftar.
     *
     * Nomor pertama sudah dijawab sebelum nomor kedua ditolak, jadi mengulang
     * seluruh daftar akan menanyakannya lagi. Yang boleh terlihat di backend
     * hanyalah nomor kedua yang diminta ulang.
     */
    public function testRetryPerNumberDoesNotReaskAnsweredNumbers(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['exists' => true]),   // 6281234567890 — langsung dijawab
            self::rateLimited(2),                    // 6289876543210 — kena batas
            MockBackend::json(['exists' => false]),  // 6289876543210 — diulang, berhasil
        ]);

        $hasil = $this->openWa($backend, ['retries' => 1])
            ->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(3, $backend->count());
        self::assertStringContainsString('/contacts/check/6281234567890', self::probePath($backend, 0));
        self::assertStringContainsString('/contacts/check/6289876543210', self::probePath($backend, 1));
        self::assertStringContainsString(
            '/contacts/check/6289876543210',
            self::probePath($backend, 2),
            'Yang diulang hanya nomor yang ditolak, bukan nomor pertama'
        );

        self::assertSame([2], $this->slept);
        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
    }

    /** Gateway yang memeriksa seluruh daftar sekali panggil mengulang request itu. */
    public function testBatchProviderRetriesItsSingleRequest(): void
    {
        $backend = new MockBackend([
            self::rateLimited(3),
            MockBackend::json(['results' => [['phone' => '6281234567890', 'exists' => true]]]),
        ]);

        $hasil = $this->waxum($backend, ['retries' => 1])->checkNumbers('081234567890');

        self::assertSame(2, $backend->count());
        self::assertSame([3], $this->slept);
        self::assertTrue($hasil->isRegistered('081234567890'));
    }

    /** Fonnte memotong daftar panjang menjadi beberapa request; tiap potongan diulang sendiri. */
    public function testChunkedProviderRetriesPerChunk(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => true, 'registered' => ['6281234567890'], 'not_registered' => []]),
            self::rateLimited(4),
            MockBackend::json(['status' => true, 'registered' => [], 'not_registered' => ['6289876543210']]),
        ]);

        $numbers = [];

        for ($i = 1; $i <= 501; $i++) {
            $numbers[] = sprintf('0812%07d', $i);
        }

        (new \Sikuwa\Whatsapp\Providers\Fonnte\Fonnte(
            ['token' => 't', 'retries' => 1],
            $backend->executor()
        ))->checkNumbers($numbers);

        // Potongan pertama (500 nomor) langsung dijawab; potongan kedua (1 nomor)
        // ditolak lalu diulang — bukan mengulang potongan pertama.
        self::assertSame(3, $backend->count());
        self::assertSame([4], $this->slept);

        // Fonnte mengirim form-encoded, jadi body-nya diurai dengan parse_str —
        // bukan json_decode, yang akan gagal diam-diam dan membuat pemeriksaan
        // ini lolos tanpa membuktikan apa pun.
        $form = [];
        parse_str((string) $backend->history[2]['request']->getBody(), $form);

        $target = $form['target'] ?? '';
        self::assertNotSame('', $target, 'Potongan kedua benar-benar diminta ulang');
        self::assertStringNotContainsString(',', $target, 'Yang diulang potongan kedua saja, bukan 500 nomor lagi');
        self::assertSame('628120000501', $target, 'Nomor terakhir — yang ada di potongan kedua');
    }

    // ---------------------------------------------------------------------
    // Yang tetap tidak diulang
    // ---------------------------------------------------------------------

    public function testNonRetryableStatusIsNotRetried(): void
    {
        // 403 tidak akan sembuh kalau diulang — kunci baca tetap kunci baca.
        $backend = new MockBackend([
            MockBackend::response(403, ['Retry-After' => '10'], '{"message":"butuh kunci operator"}'),
        ]);

        $this->expectException(ForbiddenException::class);

        try {
            $this->openWa($backend, ['retries' => 5])->checkNumbers('081234567890');
        } finally {
            self::assertSame(1, $backend->count());
            self::assertSame([], $this->slept);
        }
    }

    public function testNoRetryWhenGatewayDoesNotSendRetryAfter(): void
    {
        $backend = new MockBackend([
            MockBackend::response(429, ['Content-Type' => 'application/json'], '{}'),
        ]);

        $this->expectException(RateLimitException::class);

        try {
            $this->openWa($backend, ['retries' => 3])->checkNumbers('081234567890');
        } finally {
            self::assertSame(1, $backend->count(), 'Tanpa Retry-After, menebak jeda hanya menabrak dinding yang sama');
            self::assertSame([], $this->slept);
        }
    }

    public function testRetryStopsAfterTheConfiguredBudget(): void
    {
        $backend = new MockBackend([
            self::rateLimited(3),
            self::rateLimited(3),
            self::rateLimited(3),
        ]);

        try {
            $this->openWa($backend, ['retries' => 1])->checkNumbers('081234567890');
            self::fail('Seharusnya melempar setelah jatah percobaan habis');
        } catch (RateLimitException $e) {
            self::assertSame(2, $backend->count());
            self::assertSame([3], $this->slept);
            self::assertSame(3, $e->getRetryAfter(), 'Exception terakhir tetap membawa Retry-After');
        }
    }

    /** Evolution API juga satu request untuk seluruh daftar — jalur yang sama. */
    public function testEvolutionApiProbeRetries(): void
    {
        $backend = new MockBackend([
            self::rateLimited(2),
            MockBackend::json([['jid' => '6281234567890@s.whatsapp.net', 'exists' => true, 'number' => '6281234567890']]),
        ]);

        $hasil = (new EvolutionAPI(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'sikuwa', 'retries' => 1],
            $backend->executor()
        ))->checkNumbers('081234567890');

        self::assertSame(2, $backend->count());
        self::assertSame([2], $this->slept);
        self::assertTrue($hasil->isRegistered('081234567890'));
    }
}
