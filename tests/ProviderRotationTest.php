<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Rotasi gateway saat mengirim banyak pesan.
 *
 * Mode `auto` hanya mengundi **sekali per panggilan**, jadi satu `send()` berisi
 * seratus pesan tetap keluar lewat satu gateway saja. Dengan `WHATSAPP_ROTATE`
 * menyala, batch itu dibagi ke semua gateway yang punya token.
 *
 * Yang paling penting dibuktikan di sini bukan pembagiannya, melainkan bahwa
 * **tanpa saklarnya jalur kirimnya tidak berubah sama sekali** — batch tetap
 * utuh di satu gateway. Karena itu tiap perilaku diuji berpasangan.
 *
 * Dua gateway dipakai karena bentuknya berbeda: OpenWA menerima seluruh
 * potongan dalam satu request, wuzapi mengirim satu pesan per request. Yang
 * diperiksa karena itu bukan jumlah request, melainkan **nomor tujuan mana
 * mendarat di gateway mana** — dibaca dari body request, sehingga tetap sah
 * untuk kedua bentuk.
 */
final class ProviderRotationTest extends TestCase
{
    private const OWA = 'https://openwa.test';
    private const WUZ = 'https://wuzapi.test';

    /** @var array<int,int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->slept = [];

        AbstractProvider::useSleeper(function (int $seconds): void {
            $this->slept[] = $seconds;
        });

        // Environment dikosongkan supaya kunci asli di mesin ini tidak ikut
        // terbaca: seluruh pengaturan di berkas ini lewat opsi, bukan .env.
        self::fakeEnv([]);
    }

    protected function tearDown(): void
    {
        AbstractProvider::useSleeper(null);
        Config::useResolver(null);
    }

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private static function options(array $extra = []): array
    {
        return array_merge([
            'provider' => 'auto',
            'tokens' => ['OpenWA' => 'owa-key', 'Wuzapi' => 'wuz-token'],
            'urls' => ['OpenWA' => self::OWA, 'Wuzapi' => self::WUZ],
            'sessions' => ['OpenWA' => ['owa-session']],
        ], $extra);
    }

    /**
     * Balasan yang diterima OpenWA maupun wuzapi sekaligus.
     *
     * Keduanya memakai amplop yang berbeda — OpenWA membaca `messageId` atau
     * `totalMessages`, wuzapi mewajibkan `success` — jadi badannya
     * digabungkan daripada membuat dua antrean yang harus ditebak urutannya.
     *
     * @return array<int,\GuzzleHttp\Psr7\Response>
     */
    private static function responses(int $jumlah = 8): array
    {
        $satu = MockBackend::json([
            'success' => true,
            'messageId' => '3EB0',
            'totalMessages' => 1,
            'batchId' => 'batch-1',
            'data' => ['Id' => '3EB0'],
        ]);

        return array_fill(0, $jumlah, $satu);
    }

    /** @return array<int,string> Nomor tujuan yang tidak saling mengandung. */
    private static function nomor(int $jumlah): array
    {
        $daftar = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $daftar[] = '6281200000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        return $daftar;
    }

    /**
     * @param array<int,string> $nomor
     *
     * @return array<int,array{destination:string,message:string}>
     */
    private static function batch(array $nomor): array
    {
        return array_map(
            static fn (string $n): array => ['destination' => $n, 'message' => 'halo'],
            $nomor
        );
    }

    /**
     * Nomor tujuan mana mendarat di gateway mana, dibaca dari body request.
     *
     * @param array<int,string> $nomor
     *
     * @return array<string,array<int,string>> host => nomor tujuan
     */
    private function rute(MockBackend $backend, array $nomor): array
    {
        $rute = [];

        foreach ($backend->history as $entry) {
            $host = $entry['request']->getUri()->getHost();
            $body = (string) $entry['request']->getBody();

            foreach ($nomor as $n) {
                if (str_contains($body, $n)) {
                    $rute[$host][] = $n;
                }
            }
        }

        return $rute;
    }

    /**
     * `delayBetweenMessages` yang benar-benar dikirim ke OpenWA, dalam
     * milidetik — bukti bahwa pengaturan amplop sampai ke potongannya.
     */
    private function delayOpenWA(MockBackend $backend): ?int
    {
        foreach ($backend->history as $entry) {
            if ($entry['request']->getUri()->getHost() !== 'openwa.test') {
                continue;
            }

            $body = json_decode((string) $entry['request']->getBody(), true);

            return \is_array($body) ? ($body['options']['delayBetweenMessages'] ?? null) : null;
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Tanpa rotasi: perilakunya harus sama persis seperti sebelumnya
    // ---------------------------------------------------------------------

    public function testWithoutRotationTheWholeBatchStillGoesThroughOneGateway(): void
    {
        $backend = new MockBackend(self::responses());
        $nomor = self::nomor(4);

        $hasil = (new Client(self::options(), $backend->client()))->send(self::batch($nomor));

        $rute = $this->rute($backend, $nomor);

        self::assertCount(1, $rute, 'tanpa rotasi seluruh batch tetap satu gateway');
        self::assertSame($nomor, $rute[array_key_first($rute)]);
        self::assertStringNotContainsString('gateway', $hasil);
    }

    public function testSingleMessageIsNeverRotated(): void
    {
        $backend = new MockBackend(self::responses());
        $client = new Client(self::options(['rotate' => true]), $backend->client());

        $hasil = $client->send(['destination' => '6281200000001', 'message' => 'halo']);

        self::assertSame(1, $backend->count());
        self::assertStringNotContainsString('gateway', $hasil);
    }

    /**
     * Daftar berisi satu pesan tidak punya apa pun untuk dibagi, jadi ia lewat
     * jalur lama — dan tidak boleh menuntut `provider = auto`.
     */
    public function testABatchOfOneMessageIsNotRotated(): void
    {
        $backend = new MockBackend(self::responses());
        $client = new Client(self::options(['provider' => 'OpenWA', 'rotate' => true]), $backend->client());

        $hasil = $client->send([['destination' => '6281200000001', 'message' => 'halo']]);

        self::assertSame(1, $backend->count());
        self::assertStringNotContainsString('gateway', $hasil);
    }

    // ---------------------------------------------------------------------
    // Dengan rotasi: batch benar-benar dibagi
    // ---------------------------------------------------------------------

    public function testRotationSplitsTheBatchAcrossEveryConfiguredGateway(): void
    {
        $backend = new MockBackend(self::responses());
        $nomor = self::nomor(4);

        $hasil = (new Client(self::options(['rotate' => true]), $backend->client()))
            ->send(self::batch($nomor));

        $rute = $this->rute($backend, $nomor);

        self::assertCount(2, $rute, 'batch dibagi ke dua gateway');
        self::assertSame([2, 2], array_map('count', array_values($rute)));

        $semua = array_merge(...array_values($rute));
        sort($semua);

        self::assertSame($nomor, $semua, 'tiap pesan tetap terkirim, lewat salah satu gateway');

        self::assertStringContainsString('4/4 pesan terkirim lewat 2 gateway', $hasil);
        self::assertStringContainsString('OpenWA 2', $hasil);
        self::assertStringContainsString('Wuzapi 2', $hasil);
    }

    public function testTheSplitStaysBalancedWhenTheBatchDoesNotDivideEvenly(): void
    {
        $backend = new MockBackend(self::responses(12));
        $nomor = self::nomor(7);

        (new Client(self::options(['rotate' => true]), $backend->client()))->send(self::batch($nomor));

        $jumlah = array_map('count', array_values($this->rute($backend, $nomor)));
        sort($jumlah);

        self::assertSame([3, 4], $jumlah, 'selisih antar gateway paling banyak satu pesan');
    }

    public function testTheEnvironmentKeyTurnsRotationOn(): void
    {
        self::fakeEnv([
            'WHATSAPP_PROVIDER' => 'auto',
            'WHATSAPP_ROTATE' => '1',
            'WHATSAPP_TOKEN_OpenWA' => 'owa-key',
            'WHATSAPP_TOKEN_Wuzapi' => 'wuz-token',
            'WHATSAPP_URL_OpenWA' => self::OWA,
            'WHATSAPP_URL_Wuzapi' => self::WUZ,
            'WHATSAPP_SESSION_OpenWA' => 'owa-session',
        ]);

        $backend = new MockBackend(self::responses());
        $nomor = self::nomor(4);

        (new Client([], $backend->client()))->send(self::batch($nomor));

        self::assertCount(2, $this->rute($backend, $nomor), 'kunci .env menyalakan rotasi');
    }

    // ---------------------------------------------------------------------
    // Pengaturan tingkat panggilan ikut ke tiap potongan
    // ---------------------------------------------------------------------

    /**
     * `pacing` ditulis pemanggil di amplop, jadi ia berlaku untuk seluruh
     * batch — dan batch itu sekarang dipecah. Kalau pengaturannya tidak ikut
     * dipindahkan, potongan kedua dan seterusnya kembali ke jeda bawaan
     * gateway: tepat di bagian yang paling butuh dijeda.
     */
    public function testEnvelopePacingReachesEverySlice(): void
    {
        $backend = new MockBackend(self::responses());
        $client = new Client(self::options(['rotate' => true]), $backend->client());

        $client->send([
            'messages' => self::batch(self::nomor(4)),
            'pacing' => ['cycle' => '0,30'],
        ]);

        // Tiap potongan berisi dua pesan, jadi tiap potongan punya satu jeda 30
        // detik di posisi kedua — dan hanya wuzapi, yang mengirim satu per
        // satu, benar-benar menunggunya.
        self::assertSame([30], $this->slept);

        // OpenWA hanya menerima satu angka jeda untuk seluruh batch, dan yang
        // dipakai adalah jeda pesan kedua: 30 detik. Tanpa pengaturan amplopnya,
        // angka ini kembali ke bawaan 3 detik.
        self::assertSame(30000, $this->delayOpenWA($backend));
    }

    /**
     * Bentuk daftar yang menaruh kunci pengaturan di sebelah pesannya —
     * `['pacing' => [...], [...], [...]]` — harus diperlakukan sama seperti
     * bentuk amplop, karena itulah bentuk yang tidak punya tempat lain.
     */
    public function testSettingsBesideTheListReachEverySliceToo(): void
    {
        $backend = new MockBackend(self::responses());
        $client = new Client(self::options(['rotate' => true]), $backend->client());

        $pesan = self::batch(self::nomor(4));
        $pesan['pacing'] = ['cycle' => '0,30'];

        $client->send($pesan);

        self::assertSame(30000, $this->delayOpenWA($backend));
    }

    // ---------------------------------------------------------------------
    // Batas dan kegagalannya
    // ---------------------------------------------------------------------

    public function testRotationRejectsAFixedProvider(): void
    {
        $backend = new MockBackend(self::responses());
        $client = new Client(self::options(['provider' => 'OpenWA', 'rotate' => true]), $backend->client());

        try {
            $client->send(self::batch(self::nomor(2)));
            self::fail('rotasi dengan provider tetap seharusnya ditolak');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('WHATSAPP_ROTATE', $e->getMessage());
            self::assertStringContainsString('WHATSAPP_PROVIDER=auto', $e->getMessage());
        }

        self::assertSame(0, $backend->count(), 'tidak ada satu pun request yang berangkat');
    }

    public function testRotationWithoutAProviderIsRejected(): void
    {
        $client = new Client(['rotate' => true], (new MockBackend(self::responses()))->client());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_PROVIDER belum diisi');

        $client->send(self::batch(self::nomor(2)));
    }

    /**
     * Satu gateway bertoken bukan kegagalan — mode `auto` tetap jalan, rotasi
     * cuma tidak punya pilihan. Yang tidak boleh terjadi adalah pemanggil
     * mengira batchnya sudah dibagi padahal tidak, jadi kejadian ini dicatat
     * ke logger.
     */
    public function testRotationFallsBackToASingleGatewayAndSaysSoInTheLog(): void
    {
        $backend = new MockBackend(self::responses());
        $logger = new RecordingLogger();
        $nomor = self::nomor(3);

        $hasil = (new Client(
            self::options(['rotate' => true, 'tokens' => ['OpenWA' => 'owa-key'], 'logger' => $logger]),
            $backend->client()
        ))->send(self::batch($nomor));

        self::assertCount(1, $this->rute($backend, $nomor));
        self::assertSame($nomor, $this->rute($backend, $nomor)['openwa.test']);
        self::assertStringNotContainsString('gateway', $hasil);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('hanya satu gateway', $logger->records[0]['message']);
    }

    /**
     * Potongan yang gagal dilaporkan bersama nama gatewaynya, dan potongan
     * lain tetap dicoba — satu gateway yang sedang bermasalah tidak boleh
     * menahan bagian yang sudah dibagi kepadanya.
     */
    public function testAFailingSliceIsReportedWithItsGatewayAndDoesNotStopTheOthers(): void
    {
        $backend = new MockBackend(array_fill(0, 8, MockBackend::json(['error' => 'boom'], 500)));
        $client = new Client(self::options(['rotate' => true]), $backend->client());

        try {
            $client->send(self::batch(self::nomor(4)));
            self::fail('kegagalan kedua gateway seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('0/4 pesan terkirim', $e->getMessage());
            self::assertStringContainsString('OpenWA: ', $e->getMessage());
            self::assertStringContainsString('Wuzapi: ', $e->getMessage());
        }

        self::assertGreaterThan(1, $backend->count(), 'kedua potongan tetap dicoba');
    }
}
