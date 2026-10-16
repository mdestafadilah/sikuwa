<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Sikuwa\Whatsapp\Contracts\Whatsapp;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\UnknownProviderException;
use Sikuwa\Whatsapp\Exceptions\WhatsappException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Waxum\Waxum;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Providers\Wwebjs\Wwebjs;
use Sikuwa\Whatsapp\Support\Log;

/**
 * Titik masuk SDK — pemegang konfigurasi, transport, dan pemilihan gateway.
 *
 * ```php
 * use Sikuwa\Whatsapp\Client;
 *
 * $client = new Client([
 *     'provider' => 'OpenWA',
 *     'token'    => 'owa_k1_…',
 *     'url'      => 'http://localhost:2785',
 *     'session'  => 'my-session',
 * ]);
 *
 * echo $client->send([
 *     'destination' => '081234567890',
 *     'message'     => 'Halo dari SIKUWA',
 * ]);
 * // Sukses, messageId: 3EB0...
 * ```
 *
 * Opsi yang tidak diisi akan dicari di environment (`WHATSAPP_*`), jadi di
 * aplikasi CodeIgniter cukup `new Client()` tanpa argumen apa pun.
 *
 * Untuk pengujian, suntikkan klien Guzzle ber-handler `MockHandler`:
 *
 * ```php
 * $client = new Client(['provider' => 'Fonnte'], $mockGuzzleClient);
 * ```
 */
final class Client
{
    /** Nilai `provider` yang berarti "undi di antara gateway yang punya token". */
    public const AUTO = 'auto';

    /** @var array<string,class-string<Whatsapp>> */
    public const PROVIDERS = [
        'Fonnte' => Fonnte::class,
        'OpenWA' => OpenWA::class,
        'ApiMe' => ApiMe::class,
        'EvolutionAPI' => EvolutionAPI::class,
        'Wuzapi' => Wuzapi::class,
        'Wwebjs' => Wwebjs::class,
        'Waxum' => Waxum::class,
    ];

    private Config $config;
    private HttpExecutor $http;

    /**
     * @param array{
     *     provider?:string, token?:string, url?:string, session?:string,
     *     instance?:string, timeout?:int|float, tokens?:array<string,string>,
     *     urls?:array<string,string>, headers?:array<string,string>,
     *     httpClient?:ClientInterface, account_token?:string,
     *     sessions?:array<string,array<int,string>>,
     *     instances?:array<string,array<int,string>>,
     *     pacing?:array<string,mixed>, typing?:array<string,mixed>,
     *     throttle?:array<string,mixed>, retries?:string|int,
     *     logger?:LoggerInterface, rotate?:mixed
     * }|Config $options
     */
    public function __construct(array|Config $options = [], ?ClientInterface $httpClient = null)
    {
        $this->config = Config::from($options);

        $injected = $httpClient
            ?? (\is_array($options) ? ($options['httpClient'] ?? null) : null);

        $this->http = new HttpExecutor(
            $injected,
            $this->config->timeout(),
            $this->config->headers()
        );
    }

    /** @return array<string,class-string<Whatsapp>> */
    public static function providers(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Nama gateway yang tokennya benar-benar tersedia.
     *
     * Hanya menghitung `WHATSAPP_TOKEN_<Provider>` / opsi `tokens`; token umum
     * `WHATSAPP_TOKEN` tidak dihitung, karena token itu tidak menunjukkan
     * gateway mana yang siap dipakai.
     *
     * @return array<int,string>
     */
    public static function configured(array|Config|null $config = null): array
    {
        $config = Config::from($config);
        $names = [];

        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($config->providerToken($name) !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Nama gateway yang akan dipakai, tanpa membangun instance-nya.
     *
     * Bedanya dengan {@see self::provider()} hanya pada hasilnya: yang ini
     * mengembalikan **nama**, bukan objeknya, sehingga aman dipanggil untuk
     * sekadar mencatat ke log.
     *
     * Perlu diingat bahwa `provider = auto` mengundi **setiap kali** dipanggil,
     * jadi nama yang dikembalikan method ini tidak selalu sama dengan gateway
     * yang dipakai `send()` berikutnya. Untuk mengunci pilihan, bangun gateway
     * sekali lalu simpan:
     *
     * ```php
     * $gateway = $client->provider();          // undian terjadi di sini
     * $nama    = $gateway->getProvider();      // "OpenWA"
     * $gateway->sendMessage($pesan);           // pasti lewat OpenWA
     * ```
     *
     * @param string|null $name Nama gateway, atau `auto`. Default dari konfigurasi.
     *
     * @throws UnknownProviderException
     * @throws ConfigurationException
     */
    public function providerName(?string $name = null): string
    {
        $name ??= $this->config->provider();

        if ($name === null || $name === '') {
            throw new ConfigurationException(
                'WHATSAPP_PROVIDER belum diisi, dan tidak ada nama provider yang diberikan'
            );
        }

        if (strcasecmp($name, self::AUTO) === 0) {
            return $this->pickAuto();
        }

        // Lewat resolve() supaya nama yang tidak dikenal tetap ditolak dengan
        // pesan yang sama seperti provider() — bukan diteruskan apa adanya.
        self::resolve($name);

        return self::canonical($name);
    }

    /**
     * Ejaan resmi sebuah nama gateway, apa pun huruf besar-kecilnya.
     *
     * Dipakai supaya `provider_name()` mengembalikan `OpenWA`, bukan `openwa`
     * yang kebetulan diketik pemanggil — nama itu biasanya langsung dicatat ke
     * log atau ditampilkan, jadi ejaannya harus seragam.
     */
    private static function canonical(string $name): string
    {
        foreach (array_keys(self::PROVIDERS) as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return $candidate;
            }
        }

        // Tidak mungkin tercapai: resolve() sudah menolak nama asing sebelumnya.
        return $name;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function http(): HttpExecutor
    {
        return $this->http;
    }

    /**
     * Bangun gateway yang akan dipakai.
     *
     * Memanggil method ini berulang kali menghasilkan instance baru; untuk
     * `provider = auto` itu berarti undiannya diulang setiap kali. Panggil
     * sekali lalu simpan hasilnya kalau beberapa pesan harus lewat gateway
     * yang sama.
     *
     * @param string|null $name Nama gateway, atau `auto`. Default dari konfigurasi.
     *
     * @throws UnknownProviderException
     * @throws ConfigurationException
     */
    public function provider(?string $name = null): Whatsapp
    {
        $name ??= $this->config->provider();

        if ($name === null || $name === '') {
            throw new ConfigurationException(
                'WHATSAPP_PROVIDER belum diisi, dan tidak ada nama provider yang diberikan'
            );
        }

        if (strcasecmp($name, self::AUTO) === 0) {
            $name = $this->pickAuto();
        }

        $class = self::resolve($name);

        return new $class($this->config, $this->http);
    }

    /**
     * Kirim pesan dan lempar exception bila gagal.
     *
     * Jeda antar pesan pada pengiriman massal diatur lewat `WHATSAPP_PACING_*`
     * (siklus, jitter acak, dan pengali untuk pesan panjang); untuk menimpanya
     * pada satu panggilan saja, bungkus list-nya bersama kunci `pacing`:
     *
     * ```php
     * $client->send([
     *     'messages' => [
     *         ['destination' => '0811111111', 'message' => 'Pesan pertama'],
     *         ['destination' => '0822222222', 'message' => 'Pesan kedua'],
     *     ],
     *     'pacing' => ['cycle' => '0,45', 'interval' => '10-20'],
     * ]);
     * ```
     *
     * Indikator "sedang mengetik" yang dimunculkan sendiri sebelum mengirim
     * diatur lewat `WHATSAPP_TYPING`; kunci `typing` menimpanya untuk satu
     * panggilan — dan menulis kunci itu sudah cukup menyalakannya, tanpa perlu
     * `WHATSAPP_TYPING` di `.env`:
     *
     * ```php
     * $client->send([
     *     'destination' => '081234567890',
     *     'message'     => 'Laporan harian sudah siap',
     *     'typing'      => ['speed' => 8, 'max' => 30],
     * ]);
     * ```
     *
     * Satu batch bisa juga dibagi ke beberapa gateway sekaligus lewat
     * `WHATSAPP_ROTATE` — hanya berguna bersama `provider = auto`, karena satu
     * gateway yang dipilih tetap tidak punya apa pun untuk dibagi:
     *
     * ```php
     * // .env: WHATSAPP_PROVIDER=auto, WHATSAPP_ROTATE=1, tiga token terisi
     * $client->send(['messages' => $seratusPesan]);
     * // Sukses, 100/100 pesan terkirim lewat 3 gateway (Fonnte 34, OpenWA 33, Wuzapi 33)
     * ```
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws WhatsappException
     */
    public function send(array|string $message): string
    {
        // Rotasi hanya menyentuh bentuk daftar: satu pesan tidak punya apa pun
        // untuk dibagi, dan jalur lamanya sudah benar. Saklarnya diperiksa
        // lebih dulu supaya selama ia mati tidak ada satu pun pekerjaan
        // tambahan di jalur kirim.
        if ($this->config->rotate()->isEnabled() && \is_array($message)) {
            $batch = self::batchOf($message);

            if ($batch !== null && \count($batch) > 1) {
                return $this->sendAcrossGateways($message, $batch);
            }
        }

        return $this->provider()->sendMessage($message);
    }

    /**
     * Kirim pesan untuk dicatat ke log — tidak pernah melempar exception.
     *
     * Kegagalan dikembalikan sebagai string, dengan teks yang sama seperti
     * kalau exception-nya dibaca. Cocok untuk notifikasi yang tidak boleh
     * menggagalkan request pemanggil.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     */
    public function notify(array|string $message): string
    {
        try {
            return $this->send($message);
        } catch (WhatsappException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Kirim satu gambar.
     *
     * Bentuk pesannya seragam di semua gateway; daftar lengkap kuncinya ada di
     * {@see Contracts\Whatsapp::sendImage()}:
     *
     * ```php
     * $client->sendImage([
     *     'destination' => '081234567890',
     *     'image'       => 'data:image/png;base64,iVBORw0KGgo…',
     *     'caption'     => 'Bukti transfer',
     * ]);
     * ```
     *
     * Isi `image` boleh berupa data URI, base64 telanjang, atau URL publik —
     * kecuali di ApiMe dan wuzapi, yang menuntut isi berkasnya ikut dikirim.
     *
     * @param array<string,mixed> $message
     *
     * @throws WhatsappException
     */
    public function sendImage(array $message): string
    {
        return $this->provider()->sendImage($message);
    }

    /**
     * Kirim satu berkas/dokumen.
     *
     * Sama seperti {@see self::sendImage()}, hanya kuncinya `file`. Isilah
     * `filename`, karena itulah yang menentukan nama dan jenis berkas yang
     * dilihat penerima:
     *
     * ```php
     * $client->sendFile([
     *     'destination' => '081234567890',
     *     'file'        => $base64Pdf,
     *     'filename'    => 'invoice-1209.pdf',
     * ]);
     * ```
     *
     * @param array<string,mixed> $message
     *
     * @throws WhatsappException
     */
    public function sendFile(array $message): string
    {
        return $this->provider()->sendFile($message);
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik".
     *
     * `duration` wajib diisi saat menampilkan indikator — Fonnte dan
     * Evolution API memakainya untuk menentukan berapa lama indikator tampil.
     * Di Evolution API panggilan ini ikut menunggu selama durasi tersebut,
     * karena servernya yang menidurkan permintaan.
     *
     * ```php
     * $client->sendTyping(['destination' => '081234567890', 'duration' => 5]);
     * $client->send(['destination' => '081234567890', 'message' => 'Halo']);
     * $client->sendTyping(['destination' => '081234567890', 'state' => 'paused']);
     * ```
     *
     * @param array<string,mixed> $message Kunci `destination`, `state`, dan
     *                                     `duration`. Lihat
     *                                     {@see Contracts\Whatsapp::sendTyping()}.
     *
     * @throws WhatsappException
     */
    public function sendTyping(array $message): string
    {
        return $this->provider()->sendTyping($message);
    }

    /**
     * Periksa nomor mana yang terdaftar di WhatsApp.
     *
     * Berguna untuk menyaring daftar tujuan sebelum mengirim: WhatsApp tidak
     * memberi tahu apakah sebuah nomor bisa dihubungi sebelum pesannya benar-
     * benar dikirim, dan menembak nomor yang tidak pernah ada adalah pemicu
     * pemblokiran yang paling sering.
     *
     * ```php
     * $hasil = $client->checkNumbers(['081234567890', '089876543210']);
     *
     * foreach ($hasil->registered() as $nomor) {
     *     $client->send(['destination' => $nomor, 'message' => 'Halo']);
     * }
     * ```
     *
     * Seperti {@see self::provider()}, gateway dipilih ulang pada tiap
     * pemanggilan — untuk `provider = auto` itu berarti undiannya diulang.
     * Simpan instance gateway kalau pemeriksaan dan pengiriman harus mengenai
     * gateway yang sama.
     *
     * @param array<int,string>|string $numbers Satu nomor, atau daftar nomor.
     *
     * @throws WhatsappException
     */
    public function checkNumbers(array|string $numbers): NumberCheck
    {
        return $this->provider()->checkNumbers($numbers);
    }

    /**
     * Apakah notifikasi diaktifkan (`WA_NOTIFICATION`).
     *
     * SDK tidak menegakkannya sendiri — ini hanya pembacaan environment yang
     * disediakan supaya pemanggil tidak perlu mengurainya sendiri:
     *
     * ```php
     * if (! $client->enabled()) {
     *     return;
     * }
     * ```
     */
    public function enabled(): bool
    {
        return Config::notificationEnabled();
    }

    /**
     * Buat sesi/instance baru di gateway yang sedang dipilih.
     *
     * Sama seperti {@see self::provider()}, gateway dipilih ulang pada tiap
     * pemanggilan — untuk `provider = auto` itu berarti undiannya diulang.
     * Simpan instance gateway kalau pembuatan dan pemeriksaan sesi harus
     * mengenai gateway yang sama.
     *
     * @param array<string,mixed> $options Kunci spesifik gateway, mis. `name`,
     *                                     `id`, `config`. Lihat
     *                                     {@see Contracts\Whatsapp::createSession()}.
     *
     * @throws WhatsappException
     */
    public function createSession(array $options = []): Session
    {
        return $this->provider()->createSession($options);
    }

    /**
     * Baca keadaan sesi yang sudah ada.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi
     *                        (`WHATSAPP_SESSION_<Provider>` /
     *                        `WHATSAPP_INSTANCE_<Provider>`, lalu kunci
     *                        bersamanya).
     *
     * @throws WhatsappException
     */
    public function checkSession(?string $id = null): Session
    {
        return $this->provider()->checkSession($id);
    }

    /**
     * Tampilkan semua sesi/instance yang dikenal gateway.
     *
     * Setiap gateway yang mendukungnya mendaftar seluruh sesi yang ada di
     * server — pemanggil tidak perlu tahu nama atau id sesi lebih dulu.
     * Wuzapi tidak mendukungnya (tokennya sendiri yang menentukan sesi),
     * dan di sana method ini melempar `ConfigurationException`.
     *
     * @return array<int,Session>
     *
     * @throws WhatsappException
     */
    public function listSessions(): array
    {
        return $this->provider()->listSessions();
    }

    /**
     * Ambil QR sesi yang sudah ada, untuk dipindai.
     *
     * Dipakai setelah {@see self::createSession()} atau
     *
     * ```php
     * $qr = $client->showQr();
     *
     * if ($qr->hasQr()) {
     *     echo '<img src="' . $qr->qrImage() . '">';   // atau $qr->qrBase64()
     * }
     * ```
     *
     * Sesi yang sudah tersambung tidak punya QR, dan itu dilaporkan sebagai
     * `isConnected() === true` dengan `hasQr() === false` — bukan exception.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi
     *                        (`WHATSAPP_SESSION_<Provider>` /
     *                        `WHATSAPP_INSTANCE_<Provider>`, lalu kunci
     *                        bersamanya).
     *
     * @throws WhatsappException
     */
    public function showQr(?string $id = null): Session
    {
        return $this->provider()->showQr($id);
    }

    /** @return class-string<Whatsapp> */
    private static function resolve(string $name): string
    {
        foreach (self::PROVIDERS as $candidate => $class) {
            if (strcasecmp($candidate, $name) === 0) {
                return $class;
            }
        }

        throw UnknownProviderException::forName($name, array_keys(self::PROVIDERS));
    }

    /**
     * Pilih gateway acak di antara yang tokennya terisi.
     *
     * Pengundian tanpa penyaringan ini akan bisa jatuh ke gateway yang tidak
     * dikonfigurasi, dan notifikasinya gagal terkirim tanpa sebab yang jelas.
     */
    private function pickAuto(): string
    {
        $candidates = self::configured($this->config);

        if ($candidates === []) {
            throw new ConfigurationException(
                "WHATSAPP_PROVIDER 'auto' tidak punya kandidat: "
                . 'tidak ada WHATSAPP_TOKEN_<Provider> yang diisi di .env'
            );
        }

        shuffle($candidates);

        return $candidates[0];
    }

    /**
     * Daftar pesan bila panggilan ini berbentuk batch, atau null bila satu pesan.
     *
     * Bentuknya dikenali persis seperti `plan()` di tiap gateway: elemen
     * pertama yang berupa array pesan. Elemen yang bukan pesan — kunci
     * pengaturan seperti `pacing` yang ikut berdiri di daftar yang sama — tidak
     * dihitung, supaya jumlah pesannya tidak meleset satu.
     *
     * Daftar yang isinya tidak lengkap dikembalikan sependek yang bisa
     * dikenali, bukan ditolak di sini: yang berhak mengeluh soal pesan cacat
     * adalah gateway-nya, dengan pesan yang menyebut posisinya.
     *
     * @param array<string,mixed>|array<int,mixed> $message
     *
     * @return array<int,array<string,mixed>>|null
     */
    private static function batchOf(array $message): ?array
    {
        $list = isset($message['messages']) && \is_array($message['messages'])
            ? $message['messages']
            : $message;

        if (! isset($list[0]) || ! \is_array($list[0])) {
            return null;
        }

        return array_values(array_filter(
            $list,
            static fn (mixed $item): bool => \is_array($item) && isset($item['destination'], $item['message'])
        ));
    }

    /**
     * Kunci pengaturan tingkat panggilan yang harus ikut ke tiap potongan.
     *
     * Pemanggil menulisnya di amplop, jadi ia berlaku untuk seluruh batch —
     * dan batch itu sekarang dipecah. Tanpa dipindahkan, pengaturan yang
     * disebut pemanggil akan hilang diam-diam di potongan kedua dan
     * seterusnya, yang justru bagian yang paling butuh dijeda.
     *
     * @param array<string,mixed> $message
     *
     * @return array<string,mixed>
     */
    private static function envelopeSettings(array $message): array
    {
        $settings = [];

        foreach (['pacing', 'typing', 'throttle'] as $key) {
            if (isset($message[$key])) {
                $settings[$key] = $message[$key];
            }
        }

        return $settings;
    }

    /**
     * Kirim satu batch pesan teks lewat beberapa gateway sekaligus.
     *
     * Batch dibagi bergiliran setelah urutan gatewaynya diundi, lalu tiap
     * potongan dikirim oleh gateway-nya sendiri — jadi gateway yang punya
     * endpoint batch tetap mengirim potongannya dalam satu request.
     *
     * Tiga akibat yang perlu diketahui pemanggil, dan disebut apa adanya
     * daripada disembunyikan:
     *
     * - **Urutan pesan tidak lagi menaik.** Potongan pertama dikirim habis
     *   sebelum potongan kedua, jadi pesan ke-6 bisa tiba sebelum pesan ke-2.
     *   Untuk pengiriman ke banyak tujuan ini tidak terasa; untuk rangkaian
     *   yang urutannya berarti, jangan nyalakan rotasi.
     * - **Jeda dan pagar laju dihitung per gateway.** Tiap gateway menerima
     *   daftarnya sendiri, dan jedanya dihitung dari posisi di dalam daftar
     *   itu. "Dua puluh pesan per menit" berarti dua puluh per gateway, bukan
     *   dua puluh untuk seluruh batch.
     * - **Kegagalan satu gateway tidak membatalkan yang lain.** Potongan yang
     *   gagal dilaporkan bersama nama gatewaynya, dan exception-nya baru
     *   dilempar setelah semua potongan dicoba.
     *
     * @param array<string,mixed>|array<int,mixed> $message Bentuk asli dari pemanggil.
     * @param array<int,array<string,mixed>>       $batch   Hasil {@see self::batchOf()}.
     *
     * @throws WhatsappException
     */
    private function sendAcrossGateways(array $message, array $batch): string
    {
        $dipilih = $this->config->provider();

        if ($dipilih === null || $dipilih === '') {
            throw new ConfigurationException(
                'WHATSAPP_PROVIDER belum diisi, dan tidak ada nama provider yang diberikan'
            );
        }

        if (strcasecmp($dipilih, self::AUTO) !== 0) {
            throw new ConfigurationException(
                "WHATSAPP_ROTATE menyala, tetapi WHATSAPP_PROVIDER berisi '{$dipilih}' — "
                . 'satu gateway yang dipilih tetap tidak punya apa pun untuk dibagi. '
                . 'Isi WHATSAPP_PROVIDER=auto, atau matikan WHATSAPP_ROTATE.'
            );
        }

        $kandidat = self::configured($this->config);

        if (\count($kandidat) < 2) {
            // Bukan kegagalan: mode auto tetap jalan dengan satu gateway, dan
            // rotasi cuma tidak punya pilihan. Yang tidak boleh terjadi adalah
            // pemanggil mengira batchnya sudah dibagi padahal tidak.
            Log::write(
                $this->config->logger(),
                'warning',
                'WHATSAPP_ROTATE menyala, tetapi hanya satu gateway yang punya token — '
                . 'seluruh batch dikirim lewat gateway itu saja',
                ['provider' => $kandidat[0] ?? null]
            );

            return $this->provider()->sendMessage($message);
        }

        $potongan = $this->config->rotate()->assign(\count($batch), $kandidat);
        $pengaturan = self::envelopeSettings($message);
        $total = \count($batch);
        $terkirim = 0;
        $dipakai = [];
        $gagal = [];
        $terakhir = null;

        foreach ($potongan as $nama => $posisi) {
            $isi = [];

            foreach ($posisi as $i) {
                $isi[] = $batch[$i];
            }

            try {
                $this->provider($nama)->sendMessage($pengaturan + ['messages' => $isi]);
                $terkirim += \count($isi);
                $dipakai[] = $nama . ' ' . \count($isi);
            } catch (WhatsappException $e) {
                $terakhir = $e;
                $gagal[] = $nama . ': ' . $e->getMessage();
            }
        }

        if ($gagal !== []) {
            // Angka di depan adalah yang **dipastikan** terkirim: potongan yang
            // gagal sebagian tidak menambah hitungannya, jadi laporannya
            // meremehkan, bukan membesar-besarkan. Rincian tiap gateway ada di
            // pesan kegagalannya sendiri.
            $lewat = $dipakai === [] ? '' : ' lewat ' . \count($dipakai) . ' gateway';

            throw new ApiException(
                "{$terkirim}/{$total} pesan terkirim{$lewat}. Gagal: " . implode(' | ', $gagal),
                $terakhir instanceof ApiException ? $terakhir->getStatus() : 0,
                null,
                null,
                $terakhir
            );
        }

        return "Sukses, {$terkirim}/{$total} pesan terkirim lewat " . \count($dipakai)
            . ' gateway (' . implode(', ', $dipakai) . ')';
    }
}
