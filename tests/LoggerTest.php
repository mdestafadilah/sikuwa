<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;

/**
 * Logger PSR-3 opsional.
 *
 * Yang dicatat hanya kejadian yang tadinya senyap sama sekali: indikator
 * "sedang mengetik" yang gagal ditampilkan, dan percobaan ulang setelah
 * gateway menolak sementara. Keduanya sengaja tidak menggagalkan pengiriman,
 * jadi yang perlu dibuktikan di sini ada dua hal sekaligus — bahwa logger
 * benar-benar menerima catatannya, dan bahwa tanpa logger tidak ada satu pun
 * perilaku yang berubah.
 */
final class LoggerTest extends TestCase
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

    // ---------------------------------------------------------------------
    // Indikator "sedang mengetik" yang gagal
    // ---------------------------------------------------------------------

    public function testFailedTypingIndicatorIsLoggedButMessageStillGoesOut(): void
    {
        $logger = new RecordingLogger;
        $backend = new MockBackend([
            MockBackend::json(['message' => 'presence tidak didukung'], 500),
            MockBackend::json(['messageId' => 'ABC']),
        ]);

        $hasil = $this->openWa($backend, ['logger' => $logger])
            ->sendMessage($this->withTyping('081234567890'));

        self::assertSame(2, $backend->count());
        self::assertSame('Sukses, messageId: ABC', $hasil);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('sedang mengetik', $logger->records[0]['message']);
        self::assertSame('OpenWA', $logger->records[0]['context']['provider']);
        // Tujuan dicatat seperti yang ditulis pemanggil, bukan bentuk yang
        // sudah dinormalkan gateway — itulah yang bisa ia kenali kembali.
        self::assertSame('081234567890', $logger->records[0]['context']['destination']);
        self::assertStringContainsString(
            'presence tidak didukung',
            $logger->records[0]['context']['error']
        );
    }

    /** Indikator yang berhasil bukan kejadian yang perlu dicatat. */
    public function testSuccessfulTypingIndicatorIsNotLogged(): void
    {
        $logger = new RecordingLogger;
        $backend = new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['messageId' => 'ABC']),
        ]);

        $this->openWa($backend, ['logger' => $logger])
            ->sendMessage($this->withTyping('081234567890'));

        self::assertSame([], $logger->records);
        // Durasinya tetap dihabiskan SDK sendiri, bukan oleh gateway.
        self::assertSame([2], $this->slept);
    }

    /** Tanpa logger, jalur kirimnya berjalan persis seperti sebelumnya. */
    public function testWithoutLoggerFailedIndicatorStaysSilent(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['message' => 'presence tidak didukung'], 500),
            MockBackend::json(['messageId' => 'ABC']),
        ]);

        $hasil = $this->openWa($backend)->sendMessage($this->withTyping('081234567890'));

        self::assertSame('Sukses, messageId: ABC', $hasil);
        // Indikator yang gagal tidak menambah jeda apa pun.
        self::assertSame([], $this->slept);
    }

    // ---------------------------------------------------------------------
    // Percobaan ulang
    // ---------------------------------------------------------------------

    public function testRetryAttemptIsLogged(): void
    {
        $logger = new RecordingLogger;
        $backend = new MockBackend([
            self::rateLimited(5),
            MockBackend::json(['data' => ['id' => 'm1']]),
        ]);

        $hasil = (new ApiMe([
            'token' => 'tok',
            'url' => 'https://v14.test',
            'instance' => 'inst',
            'retries' => 1,
            'logger' => $logger,
        ], $backend->executor()))->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);

        self::assertSame(2, $backend->count());
        self::assertSame('Sukses, messageId: m1', $hasil);
        self::assertSame([5], $this->slept);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('ApiMe', $logger->records[0]['context']['provider']);
        self::assertSame(429, $logger->records[0]['context']['status']);
        self::assertSame(5, $logger->records[0]['context']['retry_after']);
        // Sisa jatah setelah percobaan ini dihabiskan.
        self::assertSame(0, $logger->records[0]['context']['remaining']);
    }

    public function testWithoutLoggerRetryStillHappensSilently(): void
    {
        $backend = new MockBackend([
            self::rateLimited(5),
            MockBackend::json(['data' => ['id' => 'm1']]),
        ]);

        $hasil = (new ApiMe([
            'token' => 'tok',
            'url' => 'https://v14.test',
            'instance' => 'inst',
            'retries' => 1,
        ], $backend->executor()))->sendMessage(['destination' => '081234567890', 'message' => 'Halo']);

        self::assertSame('Sukses, messageId: m1', $hasil);
        self::assertSame(2, $backend->count());
    }

    // ---------------------------------------------------------------------
    // Logger tidak boleh bisa menggagalkan pengiriman
    // ---------------------------------------------------------------------

    public function testThrowingLoggerDoesNotBreakSending(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['message' => 'presence tidak didukung'], 500),
            MockBackend::json(['messageId' => 'ABC']),
        ]);

        $hasil = $this->openWa($backend, ['logger' => new ThrowingLogger])
            ->sendMessage($this->withTyping('081234567890'));

        self::assertSame('Sukses, messageId: ABC', $hasil);
    }

    // ---------------------------------------------------------------------
    // Dari Client
    // ---------------------------------------------------------------------

    public function testClientPassesLoggerThroughToProvider(): void
    {
        $logger = new RecordingLogger;
        $backend = new MockBackend([
            MockBackend::json(['message' => 'presence tidak didukung'], 500),
            MockBackend::json(['messageId' => 'ABC']),
        ]);

        (new Client([
            'provider' => 'OpenWA',
            'token' => 'k',
            'url' => self::BASE,
            'session' => 'sess-1',
            'logger' => $logger,
        ], $backend->client()))->send($this->withTyping('081234567890'));

        self::assertCount(1, $logger->records);
        self::assertSame('OpenWA', $logger->records[0]['context']['provider']);
    }

    /** @param array<string,mixed> $extra */
    private function openWa(MockBackend $backend, array $extra = []): OpenWA
    {
        return new OpenWA([
            'token' => 'k',
            'url' => self::BASE,
            'session' => 'sess-1',
        ] + $extra, $backend->executor());
    }

    /**
     * Satu pesan dengan indikator ketik menyala.
     *
     * `speed => 8` membuat pesan empat karakter meminta satu detik, lalu
     * dijepit ke batas bawah dua detik — jadi durasinya pasti lebih dari nol
     * dan indikatornya benar-benar dikirim.
     *
     * @return array<string,mixed>
     */
    private function withTyping(string $destination): array
    {
        return [
            'destination' => $destination,
            'message' => 'Halo',
            'typing' => ['speed' => 8, 'max' => 30],
        ];
    }

    private static function rateLimited(int $retryAfter): HttpResponse
    {
        return MockBackend::response(
            429,
            ['Content-Type' => 'application/json', 'Retry-After' => (string) $retryAfter],
            '{"message":"rate limited"}'
        );
    }
}

/**
 * Logger yang merekam semuanya, untuk diperiksa test.
 *
 * Ditulis dengan tanda tangan PSR-3 versi 3 — `psr/log` 2.0 dan 3.0 memakai
 * antarmuka yang sama persis, jadi satu kelas ini cocok untuk keduanya.
 */
class RecordingLogger implements LoggerInterface
{
    /** @var array<int,array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @param array<string,mixed> $context */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}

/** Logger yang selalu gagal — pengiriman tetap harus jalan. */
final class ThrowingLogger extends RecordingLogger
{
    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger sedang rusak');
    }
}
