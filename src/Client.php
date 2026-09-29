<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp;

use GuzzleHttp\ClientInterface;
use Sikuwa\Whatsapp\Contracts\Whatsapp;
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
     *     headers?:array<string,string>, httpClient?:ClientInterface,
     *     pacing?:array<string,mixed>, typing?:array<string,mixed>
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
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws WhatsappException
     */
    public function send(array|string $message): string
    {
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
     * Ambil QR sesi yang sudah ada, untuk dipindai.
     *
     * Dipakai setelah {@see self::createSession()} atau
     * {@see self::checkSession()} menunjukkan sesi belum tersambung:
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
}
