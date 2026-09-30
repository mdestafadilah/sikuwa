<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Contracts\Whatsapp;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Exceptions\WhatsappException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;
use Sikuwa\Whatsapp\Support\Presence;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Bagian yang sama pada semua gateway: pemegang konfigurasi, penyunting bentuk
 * pesan, dan penerjemah respons menjadi exception.
 *
 * Setiap provider mengurus tiga hal sendiri — URL endpoint, header autentikasi,
 * dan amplop errornya. Sisanya ada di sini.
 */
abstract class AbstractProvider implements Whatsapp
{
    protected Config $config;
    protected HttpExecutor $http;

    /**
     * Penidur pengganti, dipakai test supaya jeda tidak benar-benar ditunggu.
     *
     * @var (callable(int):void)|null
     */
    private static $sleeper = null;

    /**
     * @param array{
     *     token?:string, url?:string, session?:string, instance?:string,
     *     timeout?:int|float, tokens?:array<string,string>, provider?:string,
     *     headers?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        $this->config = Config::from($options);
        $this->http = $http ?? new HttpExecutor(
            timeout: $this->config->timeout(),
            defaultHeaders: $this->config->headers(),
        );
    }

    public function getToken(): string
    {
        return $this->config->token($this->getProvider());
    }

    /**
     * Header autentikasi gateway ini.
     *
     * Satu-satunya tempat token dipasang, supaya tiap provider cukup
     * menyebutkan *bagaimana* ia mengautentikasi — `Authorization: Bearer`,
     * `X-API-Key`, `apikey`, atau `Token` — tanpa mengulang cara request
     * dikirim dan dibaca.
     *
     * @return array<string,string>
     */
    abstract protected function authHeaders(): array;

    /**
     * Buat sesi/instance baru di gateway.
     *
     * Sengaja abstrak: setiap gateway punya istilah, endpoint, dan kredensial
     * sendiri untuk ini, jadi tidak ada perilaku bawaan yang masuk akal.
     *
     * @param array<string,mixed> $options
     */
    abstract public function createSession(array $options = []): Session;

    /**
     * Baca keadaan sesi yang sudah ada.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     */
    abstract public function checkSession(?string $id = null): Session;

    /**
     * Ambil QR sesi yang sudah ada, untuk dipindai.
     *
     * Sama seperti {@see self::createSession()}: abstrak, karena endpoint dan
     * bentuk balasannya khas tiap gateway — Fonnte mengirim base64 telanjang
     * di `url`, OpenWA dan wuzapi mengirim data URI yang sudah lengkap.
     * Penyeragamannya ada di {@see Support\Qr}.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     */
    abstract public function showQr(?string $id = null): Session;

    /**
     * Kirim satu berkas ke satu tujuan.
     *
     * Sengaja abstrak: tiap gateway punya endpoint, bentuk body, dan cara
     * membawa berkasnya sendiri — Fonnte dan ApiMe menuntut unggahan
     * multipart, OpenWA dan Evolution API menerima base64 di dalam JSON,
     * wuzapi hanya mau data URI. Yang seragam adalah cara pemanggil
     * menyerahkan berkasnya, dan itu dirapikan di {@see Support\File}.
     *
     * @param string $destination Nomor tujuan, sama seperti `sendMessage()`
     * @param File   $file        Berkas yang sudah dinormalkan
     * @param string $caption     Teks yang menyertai berkas; boleh kosong
     */
    abstract protected function sendMedia(string $destination, File $file, string $caption): string;

    /**
     * Tampilkan atau hapus indikator "sedang mengetik" di satu tujuan.
     *
     * Sengaja abstrak: hanya endpoint dan nama kolomnya yang berbeda antar
     * gateway — Fonnte memakai `/typing` dengan `target` dan `stop`, OpenWA
     * memakai `chats/typing` dengan `typing`/`paused`, Evolution API menuntut
     * `delay` dalam milidetik, wuzapi menandai rekaman suara lewat `Media`.
     * Yang seragam adalah cara pemanggil memintanya, dan itu dirapikan di
     * {@see Support\Presence}.
     *
     * @param string   $destination Nomor tujuan, sama seperti `sendMessage()`
     * @param Presence $presence    Keadaan yang diminta, sudah dinormalkan
     */
    abstract protected function sendPresence(string $destination, Presence $presence): string;

    /**
     * Apakah gateway ini sudah menunggu dan membersihkan indikatornya sendiri.
     *
     * Evolution API mengirim `composing`, menunggu `delay` milidetik, lalu
     * mengirim `paused` — ketiganya di dalam satu request, sehingga request itu
     * baru selesai setelah durasinya habis. Menunggu lagi di klien berarti
     * menunggu dua kali. Gateway lain hanya menyimpan status, jadi durasinya
     * harus dihabiskan SDK sendiri lewat {@see self::announceTyping()}.
     */
    protected function presenceBlocks(): bool
    {
        return false;
    }

    /**
     * Kirim satu gambar.
     *
     * Kunci yang dibaca: `destination` dan `image` (alias `media`), lalu
     * opsional `filename` dan `caption`.
     *
     * ```php
     * $client->sendImage([
     *     'destination' => '081234567890',
     *     'image'       => 'data:image/png;base64,iVBORw0KGgo…',
     *     'caption'     => 'Bukti transfer',
     * ]);
     * ```
     *
     * @param array<string,mixed> $message
     *
     * @throws WhatsappException
     */
    public function sendImage(array $message): string
    {
        return $this->dispatchMedia($message, 'image');
    }

    /**
     * Kirim satu berkas/dokumen.
     *
     * Kunci yang dibaca: `destination` dan `file` (alias `media`), lalu
     * opsional `filename` dan `caption`. `filename` menentukan nama yang
     * dilihat penerima sekaligus jenis berkasnya, jadi isilah kalau isinya
     * base64 telanjang tanpa nama yang jelas.
     *
     * @param array<string,mixed> $message
     *
     * @throws WhatsappException
     */
    public function sendFile(array $message): string
    {
        return $this->dispatchMedia($message, 'file');
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik".
     *
     * Kunci yang dibaca: `destination`, lalu opsional `state` (default
     * `composing`) dan `duration` dalam detik. `duration` wajib saat
     * menampilkan indikator — Fonnte dan Evolution API memakainya untuk
     * menentukan berapa lama indikator tampil, dan tanpa angka keduanya tidak
     * menampilkan apa pun.
     *
     * ```php
     * $client->sendTyping([
     *     'destination' => '081234567890',
     *     'state'       => 'composing',
     *     'duration'    => 5,
     * ]);
     * ```
     *
     * @param array<string,mixed> $message
     *
     * @throws WhatsappException
     */
    public function sendTyping(array $message): string
    {
        $destination = $message['destination'] ?? null;

        if (! \is_string($destination) || trim($destination) === '') {
            throw new ConfigurationException("Gagal menyusun indikator ketik: kunci 'destination' belum diisi");
        }

        $state = $message['state'] ?? '';

        return $this->sendPresence(
            trim($destination),
            Presence::from(\is_string($state) ? $state : '', $message['duration'] ?? null)
        );
    }

    /**
     * Normalkan pesan bermedia lalu teruskan ke gateway.
     *
     * `sendImage()` dan `sendFile()` sengaja hanya berbeda pada kunci yang
     * mereka cari: yang menentukan sebuah berkas dikirim sebagai gambar atau
     * dokumen adalah jenis berkasnya, bukan nama method yang dipanggil. Jadi
     * `sendFile()` dengan PNG tetap terkirim sebagai gambar, dan `sendImage()`
     * dengan PDF tetap terkirim sebagai dokumen — persis seperti yang
     * dilakukan gateway sendiri saat memilih endpoint.
     *
     * @param array<string,mixed> $message
     *
     * @throws ConfigurationException
     */
    private function dispatchMedia(array $message, string $key): string
    {
        // Kunci `media` berlaku untuk kedua method, supaya pemanggil yang
        // menyimpan berkasnya secara umum tidak perlu tahu mana yang dipakai.
        $payload = $message[$key] ?? $message['media'] ?? null;

        if (! \is_string($payload) || trim($payload) === '') {
            throw new ConfigurationException(
                "Gagal menyusun berkas: kunci '{$key}' harus berisi data URI, base64, atau URL publik"
            );
        }

        $destination = $message['destination'] ?? null;

        if (! \is_string($destination) || trim($destination) === '') {
            throw new ConfigurationException("Gagal menyusun berkas: kunci 'destination' belum diisi");
        }

        $filename = $message['filename'] ?? '';
        $caption = $message['caption'] ?? '';

        return $this->sendMedia(
            trim($destination),
            File::from($payload, \is_string($filename) ? $filename : ''),
            \is_string($caption) ? $caption : ''
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function executor(): HttpExecutor
    {
        return $this->http;
    }

    /**
     * Kalimat hasil untuk pengiriman indikator ketik.
     *
     * Ditaruh di sini, bukan di tiap provider, supaya kelima gateway
     * melaporkan hal yang sama dengan kata yang sama — pemanggil yang mencatat
     * hasilnya ke log tidak perlu tahu gateway mana yang sedang dipakai.
     *
     * @param string $destination Tujuan dalam bentuk yang dipakai gateway ini
     *                            (nomor, JID, atau WID), untuk memudahkan
     *                            penelusuran di log.
     */
    protected function presenceResult(Presence $presence, string $destination): string
    {
        return "Sukses, indikator {$presence->label()} dikirim ke {$destination}";
    }

    /**
     * Tampilkan indikator "sedang mengetik" untuk satu pesan, lalu habiskan
     * durasinya — dipanggil tepat sebelum pesannya dikirim.
     *
     * Ditaruh sedekat mungkin dengan pesannya, bukan sekali di awal batch:
     * yang membuat indikator ini masuk akal adalah kedekatannya dengan pesan
     * yang menyusul.
     *
     * Kegagalan menampilkan indikator sengaja **tidak** menggagalkan
     * pengiriman, dan tidak menambah jeda apa pun. Mengirim pesan jauh lebih
     * penting daripada hiasannya, dan gateway yang tidak mengenal presence
     * tidak boleh membuat pemanggil kehilangan pesannya. Karena SDK ini tidak
     * punya logger, kegagalan itu senyap — kalau perlu diketahui, panggil
     * {@see self::sendTyping()} sendiri dan tangani exception-nya.
     *
     * @param array<string,mixed>|null $item Satu item dari {@see self::plan()},
     *                                       atau satu item `$prepared` provider
     *                                       yang sudah membawa `destination`
     *                                       dan `typing`.
     */
    protected function announceTyping(?array $item): void
    {
        $seconds = $item['typing'] ?? null;

        if (! \is_int($seconds) || $seconds <= 0) {
            return;
        }

        $destination = $item['destination'] ?? null;

        if (! \is_string($destination) || trim($destination) === '') {
            return;
        }

        try {
            $this->sendPresence(trim($destination), Presence::from(Presence::COMPOSING, $seconds));
        } catch (WhatsappException) {
            return;
        }

        // Gateway yang menunggu dan membersihkan indikatornya sendiri sudah
        // menghabiskan durasi itu di dalam request-nya.
        if (! $this->presenceBlocks()) {
            $this->pause($seconds);
        }
    }

    /**
     * Pasang penidur sendiri. Kirim null untuk kembali ke `sleep()` biasa.
     *
     * Ada supaya jeda antar pesan bisa diuji tanpa benar-benar menunggu —
     * sama seperti {@see Config::useResolver()} yang jadi jalur test untuk
     * environment. Hanya dipakai test.
     *
     * @param (callable(int):void)|null $sleeper
     */
    public static function useSleeper(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    /** Tunggu `$seconds` detik, lewat penidur yang sedang terpasang. */
    protected function pause(int $seconds): void
    {
        if (self::$sleeper !== null) {
            (self::$sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }

    /**
     * Normalisasi bentuk pesan menjadi list yang seragam, sekaligus
     * menyelesaikan jeda tiap pesan.
     *
     * Pacing diselesaikan **di sini**, bukan di jalur kirim: hanya di titik ini
     * isi pesan masih berupa teks, dan panjangnya ikut menentukan jeda — pesan
     * panjang ditunggu lebih lama. Kalau jedanya baru dihitung di
     * `sendSequentially()` atau di DTO tiap gateway, panjang pesan sudah
     * berubah bentuk menjadi objek dan aturannya harus diulang lima kali.
     *
     * `delay` tetap boleh null: itu berarti pacing sedang mati, dan gateway
     * yang memutuskan nilai bawaannya sendiri (Fonnte 2 detik, OpenWA 3 detik,
     * sisanya 0). Mengisinya dengan 0 berarti "tanpa jeda" — dan angka yang
     * disebut pemanggil selalu menang atas pacing, karena pemanggil yang
     * menyebut angka pasti lebih tahu daripada nilai bawaan.
     *
     * Hal yang sama berlaku untuk `typing`: lamanya indikator ketik dihitung
     * dari panjang pesan, jadi ia pun harus diselesaikan selagi isinya masih
     * teks. Null berarti fitur itu sedang mati.
     *
     * Kunci `typing` dibaca di dua tempat, dan keduanya bekerja untuk bentuk
     * pesan apa pun: di tingkat amplop (berlaku untuk seluruh panggilan) dan
     * di dalam tiap item (berlaku untuk pesan itu saja). Yang membuatnya
     * seragam adalah posisinya — `typing` selalu ditulis di sebelah
     * `destination` dan `message` yang hendak dipengaruhinya.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     * @return array<int,array{destination:string,message:string,delay:?int,typing:?int}>
     *
     * @throws ConfigurationException
     */
    protected function plan(array|string $message): array
    {
        if (\is_string($message)) {
            throw new ConfigurationException(
                'Format pesan tidak valid: ' . $this->getProvider() . ' membutuhkan array pesan'
            );
        }

        // Pengaturan per panggilan. Dua bentuk diterima, karena daftar pesan
        // polos tidak punya tempat untuk menaruh kunci pengaturan:
        //   ['messages' => [...], 'pacing' => [...]]   (amplop)
        //   ['pacing' => [...], [...], [...]]          (kunci di samping daftar)
        $override = self::setting($message, 'pacing', "['cycle' => '0,30', 'interval' => '20-30']");
        $typingOverride = self::setting($message, 'typing', "['speed' => 8, 'max' => 30]");
        $throttleOverride = self::setting($message, 'throttle', "['max' => 50, 'window' => 60]");

        if (isset($message['messages']) && \is_array($message['messages'])) {
            $message = $message['messages'];
        }

        // Dibuang supaya tidak ikut terbaca sebagai pesan pada bentuk daftar.
        unset($message['pacing'], $message['typing'], $message['throttle']);

        $pacing = $this->config->pacing()->merge($override);
        $typing = $this->config->typing()->merge($typingOverride);
        $throttle = $this->config->throttle()->merge($throttleOverride);

        // Bulk kalau elemen pertama sendiri berupa array pesan.
        $isBulk = isset($message[0]) && \is_array($message[0]);
        $messages = $isBulk ? $message : [$message];
        $items = [];

        // Jatah laju dijadwalkan untuk seluruh batch sekaligus, karena jumlah
        // pesan hanya diketahui di sini. Hasilnya digabung dengan jeda pacing
        // secara maksimum, bukan penjumlahan: keduanya sama-sama menahan
        // pemanggil, dan menunggu 60 detik penuh untuk tiap pesan saat kedua
        // aturan aktif hanya akan membuat pengirimannya jauh lebih lambat
        // daripada yang diminta pemanggil.
        $launch = $throttle->schedule(\count($messages));

        foreach ($messages as $i => $item) {
            if (! \is_array($item) || ! isset($item['destination'], $item['message'])) {
                throw new ConfigurationException(
                    "Gagal menyusun pesan: Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $text = (string) $item['message'];
            $length = Text::length($text);

            // Pada bentuk daftar, tiap item boleh membawa `typing`-nya sendiri
            // — mis. satu pesan sengaja dibiarkan tanpa indikator. Pada bentuk
            // satu pesan kunci itu sudah dibaca sebagai pengaturan di atas,
            // jadi tidak ada yang terbaca dua kali.
            $typingItem = $typing->merge(self::setting($item, 'typing', "['speed' => 8, 'max' => 30]"));

            $items[] = [
                'destination' => (string) $item['destination'],
                'message' => $text,
                // Urutan siklus mengikuti posisi di daftar, bukan kunci asli
                // pemanggil — daftar bisa datang dengan kunci yang bolong.
                // Angka yang disebut pemanggil selalu menang atas kedua aturan.
                'delay' => isset($item['delay'])
                    ? max(0, (int) $item['delay'])
                    : self::combine($pacing->delayFor(\count($items), $length), $launch[\count($items)] ?? null),
                // Lama indikator ketik juga bergantung pada panjang isi pesan,
                // jadi ia diselesaikan di sini bersama jeda — satu-satunya
                // titik di mana isi pesan masih berupa teks.
                'typing' => $typingItem->durationFor($length),
            ];
        }

        return $items;
    }

    /**
     * Gabungkan jeda dari pacing dan pembatas laju, dengan `null` berarti
     * "aturan ini tidak aktif".
     *
     * Keduanya sama-sama menahan pemanggil, jadi yang diambil adalah yang
     * **lebih panjang** — bukan jumlahnya. Menjumlahkan berarti menunggu dua
     * kali ketika kedua aturan aktif, dan itu lebih lambat daripada yang
     * diminta pemanggil.
     *
     * Null tetap null: itulah penanda "tidak ada aturan", yang membuat gateway
     * memakai jeda bawaannya sendiri. Mengubahnya menjadi 0 akan mengubah
     * perilaku gateway — nol berarti "jangan tunggu", bukan "terserah gateway".
     */
    private static function combine(?int $pacing, ?int $throttle): ?int
    {
        if ($pacing === null) {
            return $throttle;
        }

        if ($throttle === null) {
            return $pacing;
        }

        return max($pacing, $throttle);
    }

    /**
     * Nomor tujuan yang muncul lebih dari sekali dalam satu batch.
     *
     * Mengirim beberapa pesan ke satu nomor dalam waktu singkat adalah pola
     * yang paling cepat memicu pemblokiran — jauh lebih cepat daripada
     * mengirim satu pesan ke banyak nomor. WhatsApp sendiri menandai pengirim
     * yang "menembak" satu tujuan berulang sebagai spam.
     *
     * Yang dikembalikan hanya tujuannya, bukan isi pesannya, supaya aman
     * dicatat ke log. Dihitung sekali per panggilan, bukan per pesan.
     *
     * ```php
     * $gateway = $client->provider();
     * $ulang = $gateway->repeatedTargets($pesan);   // ['081234567890']
     * ```
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *        Bentuk pesan yang sama seperti `sendMessage()`.
     * @return array<int,string> Daftar tujuan yang berulang; kosong bila tidak ada.
     */
    public function repeatedTargets(array|string $message): array
    {
        if (\is_string($message)) {
            return [];
        }

        if (isset($message['messages']) && \is_array($message['messages'])) {
            $message = $message['messages'];
        }

        // Bentuk satu pesan tidak punya arti "berulang".
        if (! isset($message[0]) || ! \is_array($message[0])) {
            return [];
        }

        $counts = [];

        foreach ($message as $item) {
            if (! \is_array($item) || ! isset($item['destination'])) {
                continue;
            }

            $destination = trim((string) $item['destination']);

            if ($destination !== '') {
                $counts[$destination] = ($counts[$destination] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
    }

    /**
     * Peringatkan bila satu batch mengirim ke nomor yang sama lebih dari sekali.
     *
     * Sengaja **tidak** dipanggil dari jalur kirim. Mengirim dua pesan ke satu
     * orang adalah hal yang sah — mis. teks lalu berkas — dan SDK ini tidak
     * punya logger, sehingga peringatan otomatis hanya akan muncul sebagai
     * notice PHP yang tidak bisa ditangkap pemanggil dengan rapi. Pemanggil
     * yang ingin memeriksanya memanggil {@see self::repeatedTargets()} sendiri
     * dan memutuskan apa yang pantas dilakukan.
     *
     * @param array<int,array<string,mixed>> $messages
     */
    protected function warnAboutRepeatedTargets(array $messages): void
    {
        $ulang = $this->repeatedTargets($messages);

        if ($ulang === []) {
            return;
        }

        trigger_error(
            sprintf(
                '%s: nomor berikut menerima lebih dari satu pesan dalam satu panggilan: %s. '
                . 'Pola ini paling cepat memicu pemblokiran WhatsApp.',
                $this->getProvider(),
                implode(', ', $ulang)
            ),
            E_USER_NOTICE
        );
    }

    /**
     * Baca satu kunci pengaturan dari amplop pesan pemanggil.
     *
     * @param array<string,mixed> $message
     * @param string              $contoh  Contoh penulisan yang benar, dipakai
     *                                     di pesan error supaya pemanggil tahu
     *                                     bentuk yang diharapkan.
     * @return array<string,mixed>|null Null bila kuncinya tidak disebut.
     *
     * @throws ConfigurationException
     */
    private static function setting(array $message, string $key, string $contoh): ?array
    {
        if (! array_key_exists($key, $message) || $message[$key] === null) {
            return null;
        }

        if (! \is_array($message[$key])) {
            throw new ConfigurationException(
                "Gagal menyusun pesan: kunci '{$key}' harus berupa array, mis. {$contoh}"
            );
        }

        return $message[$key];
    }

    /**
     * Jalankan penyusun payload, ubah kegagalannya menjadi
     * {@see ConfigurationException} dengan awalan yang seragam.
     */
    protected function compose(callable $factory): mixed
    {
        try {
            return $factory();
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConfigurationException('Gagal menyusun pesan: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Baca body respons, dan lempar exception kalau request-nya tidak sampai.
     *
     * Sengaja tidak melempar untuk status 4xx/5xx: body-nya masih dibutuhkan
     * provider untuk menyusun pesan penolakan yang berguna.
     *
     * @return array<string,mixed>|null
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function read(HttpResponse $response): ?array
    {
        if ($response->timedOut) {
            throw new TimeoutException($this->http->timeout(), $response->error);
        }

        if ($response->error !== '') {
            throw new ApiException(
                'Gagal menghubungi ' . $this->getProvider() . ': ' . $response->error,
                0
            );
        }

        return $response->json();
    }

    /**
     * Lempar exception bertipe untuk respons non-2xx.
     *
     * `Retry-After` ikut diteruskan supaya 429 membawa sendiri berapa lama
     * pemanggil harus menunggu — hanya di sinilah header respons masih
     * terlihat, setelah ini yang beredar hanya exception.
     *
     * @param array<string,mixed>|null $body
     */
    protected function reject(HttpResponse $response, ?array $body): never
    {
        throw ApiException::classify(
            $response->status,
            $this->describe($response->status, $body),
            $body,
            $this->kind($body),
            null,
            $response->retryAfterSeconds()
        );
    }

    /**
     * Status 2xx dengan body yang tak bisa di-decode bukan bukti pesan terkirim,
     * jadi jangan pernah dilaporkan sebagai sukses.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    protected function requireJson(?array $body, int $status): array
    {
        if ($body === null) {
            throw new ApiException(
                'Respons ' . $this->getProvider() . " tidak valid (HTTP {$status})",
                $status
            );
        }

        return $body;
    }

    /**
     * Susun header untuk request berbadan JSON.
     *
     * @param array<string,string> $extra Header khusus request ini, mis.
     *                                     `Idempotency-Key` milik ApiMe.
     * @return array<string,string>
     */
    protected function jsonHeaders(array $extra = []): array
    {
        return array_merge(['Content-Type' => 'application/json'], $this->authHeaders(), $extra);
    }

    /**
     * POST JSON, lalu baca + tolak + wajib-JSON dalam satu langkah.
     *
     * Keempat gateway self-hosted melakukan urutan yang persis sama; hanya URL,
     * payload, dan header tambahannya yang berbeda.
     *
     * @param array<string,mixed>|null $payload Body JSON, atau null untuk
     *                                          endpoint yang hanya butuh header
     *                                          autentikasi (mis. Fonnte
     *                                          `get-devices`).
     * @param array<string,string>     $extraHeaders
     * @return array<string,mixed> Body terdecode; tidak pernah null.
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function postJson(string $url, ?array $payload = null, array $extraHeaders = []): array
    {
        $body = $payload === null ? '' : (string) json_encode($payload);

        return $this->decode($this->http->post($url, $body, $this->jsonHeaders($extraHeaders)));
    }

    /**
     * POST multipart, lalu baca + tolak + wajib-JSON dalam satu langkah.
     *
     * Dipakai gateway yang menuntut berkasnya diunggah sebagai biner —
     * Fonnte dan ApiMe. `Content-Type` sengaja tidak ikut diisi: hanya
     * Guzzle yang tahu `boundary` milik body ini.
     *
     * @param array<int,array{name:string, contents:string, filename?:string,
     *                        headers?:array<string,string>}> $parts
     * @param array<string,string> $extraHeaders
     * @return array<string,mixed> Body terdecode; tidak pernah null.
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function postMultipartJson(string $url, array $parts, array $extraHeaders = []): array
    {
        return $this->decode(
            $this->http->postMultipart($url, $parts, array_merge($this->authHeaders(), $extraHeaders))
        );
    }

    /**
     * GET, lalu baca + tolak + wajib-JSON. Dipakai endpoint status sesi.
     *
     * @return array<string,mixed> Body terdecode; tidak pernah null.
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function getJson(string $url): array
    {
        return $this->decode($this->http->get($url, $this->authHeaders()));
    }

    /**
     * Terjemahkan satu respons menjadi body JSON, atau lempar exception yang
     * sesuai.
     *
     * @return array<string,mixed>
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    private function decode(HttpResponse $response): array
    {
        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        return $this->requireJson($body, $response->status);
    }

    /**
     * Jalankan `$attempt`, dan ulangi bila gateway menolak dengan status yang
     * aman diulang dan menyebutkan `Retry-After`.
     *
     * Dua syarat harus terpenuhi sekaligus:
     *
     * - **Statusnya aman diulang** — 429 (batas laju) atau 503 (sesi belum
     *   siap). 401/403/404/409 tidak akan sembuh kalau diulang: token yang
     *   ditolak tetap ditolak, jadi mengulangnya hanya memperlambat kegagalan
     *   yang sudah pasti.
     * - **Gateway menyebut berapa lama harus menunggu.** Tanpa `Retry-After`,
     *   menebak jeda sendiri berarti menabrak dinding yang sama lagi; yang
     *   seperti itu lebih baik diserahkan ke pemanggil, yang tahu jadwalnya.
     *
     * Dijeda dengan {@see self::pause()} supaya test tidak benar-benar menunggu.
     * Bila percobaan terakhir tetap gagal, exception terakhir dilempar apa
     * adanya — pesannya sudah memuat `Retry-After`, jadi pemanggil tetap bisa
     * mengatur ulang jadwalnya sendiri.
     *
     * @param callable():mixed $attempt
     *
     * @throws WhatsappException
     */
    protected function withRetry(callable $attempt): mixed
    {
        $sisa = $this->config->retries();

        while (true) {
            try {
                return $attempt();
            } catch (ApiException $e) {
                $tunggu = $e->getRetryAfter();

                if ($sisa <= 0 || $tunggu === null || ! self::isRetryableStatus($e->getStatus())) {
                    throw $e;
                }

                $sisa--;
                $this->pause($tunggu);
            }
        }
    }

    /**
     * Status HTTP yang aman diulang: 429 dan 503.
     *
     * Dua ini satu-satunya yang menandakan keadaan **sementara** — batas laju
     * yang akan lewat, dan sesi yang belum tersambung. Status 5xx lain
     * (mis. 500) sengaja tidak ikut: bug di sisi gateway biasanya tidak sembuh
     * dalam hitungan detik, dan mengulanginya berkali-kali justru menambah
     * beban.
     */
    private static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 503;
    }

    /**
     * Kirim beberapa pesan satu per satu, dengan jeda di antara pengiriman.
     *
     * Dipakai gateway tanpa endpoint batch (ApiMe, Evolution API, wuzapi).
     * Kegagalan satu pesan tidak menghentikan sisanya — semuanya dikumpulkan
     * lalu dilempar sebagai satu {@see ApiException} supaya pemanggil melihat
     * gambaran lengkapnya, bukan cuma kegagalan pertama.
     *
     * Jeda dihormati dengan `sleep()`, jadi mengirim banyak pesan akan
     * MEMBLOKIR pemanggil selama total jeda tersebut.
     *
     * Jedanya sudah diselesaikan {@see self::plan()} — termasuk bagian pacing
     * yang bergantung pada panjang isi pesan. Di sini tinggal menjalankannya.
     * Pesan pertama tidak pernah ditunggu: jeda sebelum pengiriman pertama
     * adalah urusan pemanggil, bukan urusan SDK.
     *
     * Indikator "sedang mengetik" dimunculkan di sini juga, tepat sebelum tiap
     * pesan. Berbeda dari jeda, pesan **pertama** tetap dapat indikator: justru
     * pesan pertama itulah yang paling sering dikirim sendirian, dan tanpa
     * indikator di sana fiturnya tidak akan terasa sama sekali.
     *
     * @param array<int,array{message:mixed,delay:?int,destination?:string,typing?:?int}> $items
     *        `destination` dan `typing` opsional supaya pemanggil lama tidak
     *        perlu ikut berubah; bila tidak ada, tidak ada indikator.
     * @param callable(mixed):void                      $send
     * @param callable(mixed):string                    $label Penanda pesan untuk pesan error.
     *
     * @throws ApiException
     */
    protected function sendSequentially(array $items, callable $send, callable $label): string
    {
        $sukses = 0;
        $gagal = [];
        $terakhir = null;

        foreach ($items as $i => $item) {
            $jeda = (int) ($item['delay'] ?? 0);

            if ($i > 0 && $jeda > 0) {
                $this->pause($jeda);
            }

            // Indikator menyusul jeda, bukan mendahuluinya: yang dilihat
            // penerima harus "sedang mengetik" lalu pesannya, bukan "sedang
            // mengetik", diam lama, baru pesannya.
            $this->announceTyping($item);

            try {
                $this->withRetry(fn () => $send($item['message']));
                $sukses++;
            } catch (WhatsappException $e) {
                $terakhir = $e;
                $gagal[] = $label($item['message']) . ': ' . $e->getMessage();
            }
        }

        $total = \count($items);

        if ($gagal !== []) {
            throw new ApiException(
                "{$sukses}/{$total} pesan terkirim. Gagal: " . implode(' | ', $gagal),
                $terakhir instanceof ApiException ? $terakhir->getStatus() : 0,
                null,
                null,
                $terakhir
            );
        }

        return "Sukses, {$sukses}/{$total} pesan terkirim";
    }

    /**
     * Rangkai pesan penolakan dari amplop body milik gateway.
     *
     * @param array<string,mixed>|null $body
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);
        $pesan = $this->getProvider() . " menolak pesan (HTTP {$status})";

        return $detail === '' ? $pesan : "{$pesan}: {$detail}";
    }

    /**
     * Ambil teks detail dari amplop body. Bentuknya berbeda-beda antar gateway
     * — OpenWA memakai amplop NestJS, Evolution API menyelipkan
     * `response.message`, ApiMe dan wuzapi memakai `error`, Fonnte `reason`.
     *
     * @param array<string,mixed>|null $body
     */
    protected function detail(?array $body): string
    {
        $candidates = [
            $body['response']['message'] ?? null,
            $body['message'] ?? null,
            $body['error'] ?? null,
            $body['reason'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (\is_array($candidate)) {
                // Kegagalan validasi per-field dikirim sebagai array.
                $flat = array_filter(
                    array_map(
                        static fn ($part) => \is_scalar($part) ? (string) $part : (json_encode($part) ?: ''),
                        $candidate
                    ),
                    static fn (string $part) => $part !== ''
                );

                if ($flat !== []) {
                    return implode('; ', $flat);
                }
            } elseif (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Penanda jenis error dari amplop body, bila gateway menyediakannya.
     *
     * @param array<string,mixed>|null $body
     */
    protected function kind(?array $body): ?string
    {
        $value = $body['error'] ?? $body['reason'] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Pastikan kunci konfigurasi wajib sudah diisi.
     *
     * Dipakai provider self-hosted yang tidak bisa jalan tanpa id sesi atau
     * instance. Pesan errornya sengaja menyebut nama kunci `.env`-nya, karena
     * itulah satu-satunya hal yang bisa diperbaiki pemanggil.
     *
     * @param string $value Nilai yang diperiksa
     * @param string $key   Nama kunci `.env`, mis. `WHATSAPP_SESSION`
     * @return string Nilai yang sama, supaya bisa langsung dipakai:
     *                `$id = $this->requireConfigured($id, 'WHATSAPP_SESSION');`
     *
     * @throws ConfigurationException
     */
    protected function requireConfigured(string $value, string $key): string
    {
        if ($value === '') {
            throw new ConfigurationException("{$key} belum diisi di .env");
        }

        return $value;
    }

    /**
     * Ubah tujuan menjadi bentuk yang diminta gateway, dan tolak bila kosong.
     *
     * Nomor yang sudah berupa JID (`...@g.us`) diteruskan apa adanya: menormalkannya
     * akan merusak identitas grup. Sisanya dinormalkan menjadi nomor
     * internasional tanpa tanda plus.
     *
     * Gateway yang memakai JID berkode lain mengurus tujuannya sendiri —
     * OpenWA dan Wwebjs menuntut sufiks `@c.us`, sedangkan Fonnte tidak
     * mengenal JID sama sekali.
     *
     * @param string $destination Nomor mentah dari pemanggil
     *
     * @throws ConfigurationException
     */
    protected function target(string $destination): string
    {
        $target = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);

        if ($target === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        return $target;
    }

    /**
     * Jalur kirim gateway tanpa endpoint batch.
     *
     * Satu pesan dikirim langsung; lebih dari satu dikirim berurutan lewat
     * {@see self::sendSequentially()} — yang juga memunculkan indikator ketik
     * dan menghabiskan jeda tiap pesan. Dipakai ApiMe, Evolution API, wuzapi,
     * dan Wwebjs; OpenWA dan Fonnte punya endpoint batch sendiri.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     * @param callable(array):array   $build Menyusun item {@see self::plan()}
     *                                       menjadi pesan siap kirim — biasanya
     *                                       lewat {@see self::buildItems()}
     * @param callable(object):string $send  Mengirim satu pesan
     * @param callable(object):string $label Penanda pesan untuk pesan error
     *
     * @throws WhatsappException
     */
    protected function sendIndividually(array|string $message, callable $build, callable $send, callable $label): string
    {
        $items = $this->plan($message);

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        $prepared = $this->compose(fn (): array => $build($items));

        if (\count($prepared) === 1) {
            $this->announceTyping($prepared[0]);

            // Lewat withRetry() juga: satu pesan adalah kasus paling sering
            // dipakai, jadi justru di situ percobaan ulang paling terasa.
            // sendSequentially() di bawah punya jalurnya sendiri.
            return $this->withRetry(fn (): string => $send($prepared[0]['message']));
        }

        return $this->sendSequentially($prepared, $send, $label);
    }

    /**
     * Susun tiap item hasil {@see self::plan()} menjadi pesan siap kirim.
     *
     * Yang berbeda antar gateway hanyalah bentuk pesannya, bukan cara
     * menyusunnya. Nomor tujuan yang tidak bisa dibaca ditolak di sini, sebelum
     * ada request apa pun: pesan error gateway untuk kasus ini biasanya tidak
     * menjelaskan apa-apa.
     *
     * @param array<int,array{destination:string,message:string,delay:?int,typing:?int}> $items
     * @param callable(array):object  $factory (item) => pesan gateway
     * @param callable(object):string $target  (pesan) => tujuan yang sudah
     *                                         dinormalkan, untuk validasi
     *                                         sekaligus penanda pesan di log
     * @param bool $delayOnServer true bila jeda dititipkan ke payload sehingga
     *                            klien tidak perlu menunggu — lihat
     *                            {@see \Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI}
     * @return array<int,array{message:object,delay:?int,destination:string,typing:?int}>
     *
     * @throws ConfigurationException
     */
    protected function buildItems(array $items, callable $factory, callable $target, bool $delayOnServer = false): array
    {
        $prepared = [];

        foreach ($items as $i => $item) {
            $message = $factory($item);

            if ($target($message) === '') {
                throw new ConfigurationException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $prepared[] = [
                'message' => $message,
                // Jeda yang dititipkan ke payload tidak boleh ditunggu lagi di
                // klien — kalau ditunggu, pemanggil menunggu dua kali.
                'delay' => $delayOnServer ? 0 : $item['delay'],
                'destination' => $item['destination'],
                'typing' => $item['typing'],
            ];
        }

        return $prepared;
    }
}
