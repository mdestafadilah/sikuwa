<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Support\Rotation;

/**
 * Pembagian batch pesan ke beberapa gateway.
 *
 * Yang diuji di sini murni aritmetikanya — siapa dapat berapa, dan apakah
 * undiannya benar-benar berubah antar panggilan. Jalan ceritanya (batch
 * benar-benar keluar lewat beberapa gateway) ada di ProviderRotationTest.
 */
final class RotationTest extends TestCase
{
    private const TIGA = ['OpenWA', 'Wuzapi', 'Wwebjs'];

    public function testRotationIsOffUnlessTheKeySaysOtherwise(): void
    {
        self::assertFalse(Rotation::fromConfig(null)->isEnabled());
        self::assertFalse(Rotation::fromConfig('')->isEnabled());
        self::assertFalse(Rotation::fromConfig('0')->isEnabled());
        self::assertFalse(Rotation::fromConfig('salah tulis')->isEnabled());
        self::assertFalse(Rotation::off()->isEnabled());

        self::assertTrue(Rotation::fromConfig('1')->isEnabled());
        self::assertTrue(Rotation::fromConfig('true')->isEnabled());
        self::assertTrue(Rotation::fromConfig(true)->isEnabled());
    }

    /**
     * Menyebut kunci `enabled` saja sudah dianggap permintaan untuk menyalakan
     * — sama seperti `Typing::fromArray()`, supaya dua pengaturan bersebelahan
     * tidak menuntut dua cara penulisan.
     */
    public function testOptionArrayEnablesUnlessExplicitlyDisabled(): void
    {
        self::assertTrue(Rotation::fromArray([])->isEnabled());
        self::assertTrue(Rotation::fromArray(['enabled' => true])->isEnabled());
        self::assertFalse(Rotation::fromArray(['enabled' => false])->isEnabled());
    }

    public function testOffNeverAssignsAnything(): void
    {
        self::assertSame([], Rotation::off()->assign(10, self::TIGA));
    }

    public function testNothingIsAssignedWithoutGatewaysOrMessages(): void
    {
        $rotation = Rotation::fromConfig('1');

        self::assertSame([], $rotation->assign(0, self::TIGA));
        self::assertSame([], $rotation->assign(5, []));
    }

    /**
     * Pembagiannya bergiliran, jadi selisih jumlah antar gateway paling banyak
     * satu — tidak ada gateway yang kebagian nol selama pesannya lebih banyak
     * daripada gatewaynya.
     */
    public function testTheSplitIsBalancedAndCoversEveryMessageExactlyOnce(): void
    {
        $slices = Rotation::fromConfig('1')->assign(7, self::TIGA);

        self::assertCount(3, $slices, 'setiap gateway yang dikonfigurasi ikut kebagian');

        $jumlah = array_map('count', $slices);
        sort($jumlah);

        self::assertSame([2, 2, 3], $jumlah);

        $semua = array_merge(...array_values($slices));
        sort($semua);

        self::assertSame(range(0, 6), $semua, 'tiap posisi pesan dipakai tepat sekali');

        foreach ($slices as $nama => $posisi) {
            self::assertSame(
                $posisi,
                array_values(array_unique($posisi)),
                "posisi pesan di {$nama} tidak berulang"
            );
        }
    }

    public function testOneMessageGoesToExactlyOneGateway(): void
    {
        $slices = Rotation::fromConfig('1')->assign(1, self::TIGA);

        self::assertCount(1, $slices);
        self::assertSame([[0]], array_values($slices));
    }

    public function testGatewayNamesAreNotDuplicated(): void
    {
        $slices = Rotation::fromConfig('1')->assign(2, ['OpenWA', 'OpenWA', 'OpenWA']);

        self::assertCount(1, $slices);
        self::assertSame([[0, 1]], array_values($slices));
    }

    /**
     * Urutannya diundi tiap panggilan.
     *
     * Rotasi yang urutannya selalu sama lebih mudah dikenali daripada tidak
     * berotasi sama sekali — gateway pertama selalu kebagian pesan pertama.
     * Yang diperiksa di sini bukan undian tertentu, melainkan bahwa hasilnya
     * memang tidak tetap: dengan lima gateway ada 120 urutan, jadi tiga puluh
     * panggilan yang selalu sama mustahil terjadi kalau undiannya benar.
     */
    public function testGatewayOrderIsDrawnAgainOnEveryCall(): void
    {
        $rotation = Rotation::fromConfig('1');
        $lima = ['Fonnte', 'OpenWA', 'ApiMe', 'EvolutionAPI', 'Wuzapi'];
        $terlihat = [];

        for ($i = 0; $i < 30; $i++) {
            $terlihat[implode(',', array_keys($rotation->assign(5, $lima)))] = true;
        }

        self::assertGreaterThan(1, \count($terlihat), 'urutan gateway tidak pernah berubah');
    }
}
