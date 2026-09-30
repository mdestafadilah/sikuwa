<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Support\Throttle;

/**
 * Pembatas laju dan pencegahan tujuan berulang — dua kontrol yang menjaga
 * pengiriman agar tidak menembak terlalu cepat, yang keduanya adalah pola
 * paling cepat memicu pemblokiran WhatsApp.
 */
final class ThrottleTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::useResolver(null);
        AbstractProvider::useSleeper(null);
    }

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    // ------------------------------------------------------------- Throttle

    /** Bawaannya mati: null berarti "tidak mengubah apa pun". */
    public function testThrottleIsOffByDefault(): void
    {
        $throttle = Throttle::fromConfig(null);

        self::assertFalse($throttle->isEnabled());
        self::assertNull($throttle->delayFor(0));
        self::assertNull($throttle->delayFor(500));
    }

    /**
     * Jatah jendela dibuka per kelompok: dengan max 3, tiga pesan pertama
     * langsung keluar, pesan ke-4 menunggu satu jendela penuh.
     */
    public function testThrottleOpensOneWindowPerGroup(): void
    {
        $throttle = Throttle::fromConfig('3', '60');

        self::assertSame(0, $throttle->delayFor(0));
        self::assertSame(0, $throttle->delayFor(1));
        self::assertSame(0, $throttle->delayFor(2));
        self::assertSame(60, $throttle->delayFor(3));
        self::assertSame(60, $throttle->delayFor(5));
        self::assertSame(120, $throttle->delayFor(6));
        self::assertSame(120, $throttle->delayFor(8));
    }

    #[DataProvider('throttleValues')]
    public function testThrottleReadsFromEnvironment(?string $max, ?string $window, bool $expectedEnabled): void
    {
        $values = array_filter([
            'WHATSAPP_THROTTLE_MAX' => $max,
            'WHATSAPP_THROTTLE_WINDOW' => $window,
        ], static fn (?string $v): bool => $v !== null);

        self::fakeEnv($values);

        self::assertSame($expectedEnabled, Config::fromEnvironment()->throttle()->isEnabled());
    }

    /** @return array<string,array{0:?string,1:?string,2:bool}> */
    public static function throttleValues(): array
    {
        return [
            'kosong berarti mati' => [null, null, false],
            'max saja' => ['50', null, true],
            'max dan window' => ['50', '60', true],
            'max nol berarti mati' => ['0', '60', false],
            'max bukan angka berarti mati' => ['abc', null, false],
        ];
    }

    /** Salah tulis tidak boleh menahan pemanggil berjam-jam. */
    public function testThrottleWaitIsClamped(): void
    {
        $throttle = Throttle::fromConfig('1', '99999');

        self::assertSame(Throttle::MAX_WAIT, $throttle->delayFor(2));
    }

    public function testThrottleWindowFallsBackToSixtyWhenInvalid(): void
    {
        self::assertSame(60, Throttle::fromConfig('5', '0')->window());
        self::assertSame(60, Throttle::fromConfig('5', 'abc')->window());
    }

    public function testThrottleOptionBeatsEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_THROTTLE_MAX' => '10']);

        $throttle = Config::from(['throttle' => ['max' => 99, 'window' => 30]])->throttle();

        self::assertSame(99, $throttle->max());
        self::assertSame(30, $throttle->window());
    }

    /**
     * Inti dari menggabungkan dua aturan: yang dipakai adalah jeda terpanjang,
     * bukan jumlahnya — kalau dijumlah, pemanggil menunggu dua kali.
     *
     * Diuji lewat ApiMe, bukan OpenWA: OpenWA mengirim satu batch dalam satu
     * request sehingga jedanya dititipkan ke server dan tidak pernah ditunggu
     * klien. ApiMe mengirim satu per satu, jadi sleeper benar-benar terpakai.
     */
    public function testThrottleAndPacingTakeTheLongerDelayNotTheSum(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);
        $jeda = [];
        AbstractProvider::useSleeper(static function (int $seconds) use (&$jeda): void {
            $jeda[] = $seconds;
        });

        // Throttle: 1 pesan per 60 detik. Pacing: siklus tetap 5 detik.
        // Pesan ke-2 seharusnya menunggu 60, bukan 65.
        (new ApiMe([
            'token' => 't',
            'url' => 'https://api.test',
            'instance' => 'inst-1',
            'throttle' => ['max' => 1, 'window' => 60],
            'pacing' => ['cycle' => '5,5', 'interval' => null],
        ], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame([60], $jeda);
    }

    /** Angka yang disebut pemanggil selalu menang atas kedua aturan. */
    public function testExplicitDelayBeatsThrottleAndPacing(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);
        $jeda = [];
        AbstractProvider::useSleeper(static function (int $seconds) use (&$jeda): void {
            $jeda[] = $seconds;
        });

        (new ApiMe([
            'token' => 't',
            'url' => 'https://api.test',
            'instance' => 'inst-1',
            'throttle' => ['max' => 1, 'window' => 600],
        ], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b', 'delay' => 3],
        ]);

        self::assertSame([3], $jeda);
    }

    /** Dengan throttle mati, tidak ada jeda tambahan sama sekali. */
    public function testDisabledThrottleAddsNoDelay(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
        ]);
        $jeda = [];
        AbstractProvider::useSleeper(static function (int $seconds) use (&$jeda): void {
            $jeda[] = $seconds;
        });

        (new ApiMe([
            'token' => 't',
            'url' => 'https://api.test',
            'instance' => 'inst-1',
        ], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame([], $jeda);
    }

    /**
     * Throttle aktif sendirian harus benar-benar menahan pemanggil — kalau
     * tidak, fiturnya cuma angka yang tidak dipakai siapa pun.
     */
    public function testEnabledThrottleHoldstheCaller(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['data' => ['whatsappId' => 'a']]),
            MockBackend::json(['data' => ['whatsappId' => 'b']]),
            MockBackend::json(['data' => ['whatsappId' => 'c']]),
        ]);
        $jeda = [];
        AbstractProvider::useSleeper(static function (int $seconds) use (&$jeda): void {
            $jeda[] = $seconds;
        });

        (new ApiMe([
            'token' => 't',
            'url' => 'https://api.test',
            'instance' => 'inst-1',
            'throttle' => ['max' => 2, 'window' => 30],
        ], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0833', 'message' => 'c'],
        ]);

        // Dua pesan pertama masih jatah jendela pertama; pesan ketiga menunggu.
        self::assertSame([30], $jeda);
    }

    // ----------------------------------------------------- Tujuan berulang

    public function testRepeatedTargetsAreReported(): void
    {
        $backend = new MockBackend([]);

        $ulang = (new OpenWA(
            ['token' => 'k', 'url' => 'https://gw.test', 'session' => 's1'],
            $backend->executor()
        ))->repeatedTargets([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0811', 'message' => 'c'],
            ['destination' => '0811', 'message' => 'd'],
        ]);

        self::assertSame(['0811'], $ulang);
    }

    public function testUniqueTargetsProduceNoWarning(): void
    {
        $backend = new MockBackend([]);

        $ulang = (new OpenWA(
            ['token' => 'k', 'url' => 'https://gw.test', 'session' => 's1'],
            $backend->executor()
        ))->repeatedTargets([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame([], $ulang);
    }

    /** Bentuk satu pesan tidak punya arti "berulang". */
    public function testSingleMessageHasNoRepeatedTargets(): void
    {
        $backend = new MockBackend([]);

        $ulang = (new OpenWA(
            ['token' => 'k', 'url' => 'https://gw.test', 'session' => 's1'],
            $backend->executor()
        ))->repeatedTargets(['destination' => '0811', 'message' => 'a']);

        self::assertSame([], $ulang);
    }

    /** Bentuk amplop dengan kunci `messages` juga harus terbaca. */
    public function testRepeatedTargetsAreReadFromEnvelopeShape(): void
    {
        $backend = new MockBackend([]);

        $ulang = (new OpenWA(
            ['token' => 'k', 'url' => 'https://gw.test', 'session' => 's1'],
            $backend->executor()
        ))->repeatedTargets([
            'messages' => [
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0811', 'message' => 'b'],
            ],
        ]);

        self::assertSame(['0811'], $ulang);
    }
}
