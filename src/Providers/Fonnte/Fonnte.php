<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\NotFoundException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;
use Sikuwa\Whatsapp\Support\Presence;

/**
 * Gateway Fonnte (https://fonnte.com), layanan WhatsApp berbayar berbasis cloud.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = Fonnte
 *   WHATSAPP_TOKEN    = token perangkat Fonnte, dikirim sebagai header `Authorization`
 *   WHATSAPP_ACCOUNT_TOKEN = token akun, khusus Device API (add/get/update/delete
 *                            device). Token perangkat DITOLAK di sana.
 *   WHATSAPP_URL_Fonnte = opsional; hanya kalau perlu lewat proxy/endpoint lain
 *
 * Berbeda dari gateway self-hosted lain di SDK ini, Fonnte adalah layanan
 * pihak ketiga dengan satu endpoint tetap. Karena itu `WHATSAPP_URL` — kunci
 * bersama yang biasanya ditujukan untuk gateway self-hosted — **tidak** dibaca
 * di sini: kalau dibaca, satu nilai `WHATSAPP_URL` akan mengalihkan pengiriman
 * Fonnte ke host yang salah.
 *
 * Yang diterima hanya URL yang jelas milik Fonnte: opsi `url` yang eksplisit,
 * atau `WHATSAPP_URL_Fonnte`. Keduanya tidak mungkin tertukar dengan gateway
 * lain, jadi aman — dan berguna kalau pengiriman perlu lewat proxy.
 */
final class Fonnte extends AbstractProvider
{
    public const NAME = 'Fonnte';

    public const DEFAULT_URL = 'https://api.fonnte.com/send';

    private string $urlApi;

    /** Host Device API — sama dengan host pengiriman, tanpa sufiks `/send`. */
    private string $deviceBase;

    /**
     * @param array{
     *     token?:string, url?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>,
     *     urls?:array<string,string>, account_token?:string
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $this->urlApi = $this->config->explicitUrl()
            ?? $this->config->providerUrl(self::NAME)
            ?? self::DEFAULT_URL;

        // Diturunkan dari urlApi, bukan ditulis ulang, supaya override lewat
        // `WHATSAPP_URL_Fonnte` (mis. proxy) ikut berlaku untuk Device API.
        $this->deviceBase = preg_replace('#/send$#', '', $this->urlApi) ?? $this->urlApi;
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => $this->getToken()];
    }

    /**
     * Tambah perangkat baru: `POST /add-device`.
     *
     * Menuntut **account token** (`WHATSAPP_ACCOUNT_TOKEN`), bukan token
     * perangkat yang dipakai mengirim pesan — keduanya kredensial yang
     * berbeda, dan Device API menolak token perangkat dengan `"unknown user"`.
     *
     * Perangkat yang berhasil dibuat menerbitkan tokennya di
     * {@see Session::$token}. Simpan nilainya ke `WHATSAPP_TOKEN` supaya pesan
     * bisa dikirim lewat perangkat itu.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `name` (wajib),
     *                                     `device` (wajib), lalu opsional
     *                                     `autoread`, `personal`, `group`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $body = $this->postJson(
            "{$this->deviceBase}/add-device",
            FonnteSession::payload($options),
            $this->accountHeaders()
        );

        return FonnteSession::fromCreated($this->requireStatus($body));
    }

    /**
     * Baca keadaan perangkat: `POST /get-devices`.
     *
     * Perangkat dicari dengan `$id` bila diberikan (nomor atau nama); kalau
     * tidak, dengan token perangkat yang sedang dipakai SDK ini. Jadi
     * `checkSession()` tanpa argumen menjawab pertanyaan yang sebenarnya ingin
     * diketahui pemanggil: "apakah perangkat yang saya pakai sudah siap?".
     *
     * @param string|null $id Nomor atau nama perangkat; default token aktif.
     *
     * @throws ApiException
     * @throws ConfigurationException
     * @throws NotFoundException
     */
    public function checkSession(?string $id = null): Session
    {
        $needle = ($id !== null && $id !== '') ? $id : $this->getToken();

        if ($needle === '') {
            throw new ConfigurationException(
                'Tentukan perangkat yang diperiksa: kirim nomor/nama perangkat, atau isi WHATSAPP_TOKEN'
            );
        }

        $body = $this->requireStatus(
            $this->postJson("{$this->deviceBase}/get-devices", null, $this->accountHeaders())
        );

        $device = FonnteSession::findDevice($body, $needle);

        if ($device === null) {
            throw new NotFoundException("Perangkat '{$needle}' tidak ditemukan di akun Fonnte", 404, $body);
        }

        return FonnteSession::fromDevice($device, $body);
    }

    /**
     * Tampilkan semua perangkat: `POST /get-devices`.
     *
     * Memakai **account token** seperti `checkSession()` — bukan token
     * perangkat. Seluruh perangkat di akun dikembalikan, bukan hanya yang
     * tokennya sedang aktif.
     *
     * @return array<int,Session>
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function listSessions(): array
    {
        $body = $this->requireStatus(
            $this->postJson("{$this->deviceBase}/get-devices", null, $this->accountHeaders())
        );

        $devices = $body['data'] ?? null;

        if (!\is_array($devices)) {
            return [];
        }

        $sessions = [];

        foreach ($devices as $device) {
            if (\is_array($device)) {
                $sessions[] = FonnteSession::fromDevice($device, $body);
            }
        }

        return $sessions;
    }

    /**
     * Ambil QR perangkat: `POST /qr`.
     *
     * Memakai **token perangkat** (`WHATSAPP_TOKEN`) — berbeda dari
     * `createSession()`/`checkSession()` yang memakai account token — karena
     * yang ditanyakan di sini adalah perangkat yang tokennya sedang dipakai.
     *
     * Fonnte mengirim base64 PNG telanjang di `url`, tanpa awalan `data:`.
     * Perangkat yang sudah tersambung tidak menerima QR melainkan
     * `{"status":false,"reason":"device already connect"}`, dan itu dilaporkan
     * sebagai sesi `connected` tanpa QR, bukan sebagai kegagalan.
     *
     * @param string|null $id Nomor perangkat; dinormalkan lalu dikirim sebagai
     *                        `whatsapp`. Boleh dikosongkan — token yang dipakai
     *                        sudah menentukan perangkatnya.
     *
     * @throws ApiException
     */
    public function showQr(?string $id = null): Session
    {
        // `type` selalu "qr": yang diminta pemanggil adalah gambar QR-nya.
        // Mode "code" (kode pairing) punya alur sendiri dan belum didukung.
        $payload = ['type' => 'qr'];

        $device = PhoneNumber::normalize((string) $id);

        if ($device !== '') {
            $payload['whatsapp'] = $device;
        }

        $body = $this->postJson("{$this->deviceBase}/qr", $payload, $this->authHeaders());

        if (FonnteShowQr::isAlreadyConnected($body)) {
            return FonnteShowQr::alreadyConnected($body);
        }

        return FonnteShowQr::fromResponse($this->requireStatus($body));
    }

    /**
     * Header Device API Fonnte, yang memakai account token.
     *
     * @return array<string,string>
     *
     * @throws ConfigurationException
     */
    private function accountHeaders(): array
    {
        $token = $this->config->accountToken();

        if ($token === '') {
            throw new ConfigurationException(
                'Fonnte Device API membutuhkan account token: isi WHATSAPP_ACCOUNT_TOKEN '
                . '(token perangkat ditolak di sini dengan "unknown user")'
            );
        }

        return ['Authorization' => $token];
    }

    /**
     * Fonnte membalas HTTP 200 bahkan saat menolak, jadi keberhasilan
     * sebenarnya dibaca dari field `status` — status HTTP tidak pernah cukup.
     * Kegagalannya tetap dilaporkan sebagai HTTP 200, sesuai amplop yang
     * benar-benar diterima.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function requireStatus(array $body): array
    {
        if (($body['status'] ?? false) === true) {
            return $body;
        }

        $reason = $this->detail($body);

        throw new ApiException(
            $reason !== '' ? $reason : 'Fonnte menolak permintaan',
            200,
            $body,
            $reason !== '' ? $reason : null
        );
    }

    /**
     * Kirim pesan ke Fonnte.
     *
     * Fonnte selalu membalas HTTP 200; keberhasilan sebenarnya ada di field
     * `status`. Karena itu respons 2xx dengan `status` kosong tetap dilaporkan
     * sebagai kegagalan.
     *
     * Jeda antar pesan diserahkan ke server lewat kolom `delay` tiap pesan;
     * bila pemanggil tidak mengisinya, nilainya diambil dari pacing
     * (`WHATSAPP_PACING_*`), lalu jatuh ke bawaan 2 detik.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @return string `"Sukses: <detail>"` atau `"Sukses"` — awalan `Sukses`
     *                adalah penanda seragam antar provider, dipakai pemanggil
     *                untuk memilih level log.
     *
     * @throws ApiException
     */
    public function sendMessage(array|string $message): string
    {
        $items = $this->plan($message);

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        $payload = $this->compose(fn (): string => (new FonnteBulkMessage($items))->toJson());

        // Fonnte menerima seluruh batch dalam satu field `data`, jadi SDK tidak
        // punya kesempatan menyisipkan indikator di antara pesan. Yang bisa
        // dilakukan adalah memunculkannya untuk tujuan pesan pertama:
        // memunculkan untuk semua tujuan sekaligus justru membuat penerima
        // terakhir melihat "sedang mengetik" lalu diam lama sebelum pesannya
        // datang, dan itu lebih buruk daripada tanpa indikator.
        $this->announceTyping($items[0] ?? null);

        return $this->sent(
            $this->http->post(
                $this->urlApi,
                ['data' => $payload],
                $this->authHeaders()
            )
        );
    }

    /**
     * Kirim satu berkas ke Fonnte.
     *
     * Fonnte tidak punya kolom base64: berkasnya harus diunggah sebagai
     * multipart, atau diserahkan sebagai URL publik supaya server Fonnte yang
     * mengunduhnya. Karena itu data URI dan base64 telanjang diubah dulu
     * menjadi byte mentah.
     *
     * Pengiriman media baru tersedia pada paket berbayar (super/advanced/
     * ultra). Fonnte menolaknya lewat `reason` seperti kegagalan lain, jadi
     * pesannya muncul apa adanya di log.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        $target = PhoneNumber::normalize($destination);

        if ($target === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        $parts = [
            ['name' => 'target', 'contents' => $target],
            // `filename` hanya dipakai Fonnte untuk berkas dan audio, tapi
            // mengirimkannya selalu aman.
            ['name' => 'filename', 'contents' => $file->filename],
        ];

        if ($caption !== '') {
            $parts[] = ['name' => 'message', 'contents' => $caption];
        }

        if ($file->isUrl()) {
            $parts[] = ['name' => 'url', 'contents' => $file->payload];
        } else {
            $parts[] = [
                'name' => 'file',
                'contents' => $file->bytes(),
                'filename' => $file->filename,
                'headers' => ['Content-Type' => $file->mime],
            ];
        }

        return $this->sent($this->http->postMultipart($this->urlApi, $parts, $this->authHeaders()));
    }

    /**
     * Tampilkan atau hentikan indikator "sedang mengetik": `POST /typing`.
     *
     * Fonnte memakai satu endpoint dengan kolom `stop`, bukan dua keadaan yang
     * saling menggantikan seperti gateway lain. `duration` juga dituntut di
     * sini — itulah lama indikatornya tampil, dan Fonnte sendiri yang
     * menghitungnya, bukan SDK.
     *
     * Fonnte tidak punya indikator merekam suara, jadi permintaan `recording`
     * ditolak dengan jelas alih-alih diam-diam berubah menjadi indikator
     * mengetik.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $target = PhoneNumber::normalize($destination);

        if ($target === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        if ($presence->isRecording()) {
            throw new ConfigurationException(
                'Fonnte tidak punya indikator merekam suara, hanya indikator mengetik: '
                . "pakai 'composing', atau gateway lain untuk 'recording'"
            );
        }

        // `duration` selalu ikut karena Fonnte menandainya wajib; saat
        // berhenti, angkanya tidak lagi menentukan apa pun.
        $form = ['target' => $target, 'duration' => $presence->duration];

        if ($presence->isPaused()) {
            // Boolean Fonnte dibaca dari teks, sama seperti di Device API.
            $form['stop'] = 'true';
        }

        $this->accepted(
            $this->http->post("{$this->deviceBase}/typing", $form, $this->authHeaders()),
            'indikator ketik'
        );

        return $this->presenceResult($presence, $target);
    }

    /**
     * Baca balasan Fonnte dan pastikan `status` benar-benar berhasil.
     *
     * Fonnte selalu membalas HTTP 200, bahkan saat menolak; keberhasilan
     * sebenarnya ada di field `status`. Dipakai bersama oleh pengiriman teks,
     * pengiriman berkas, dan indikator ketik supaya ketiganya ditolak dengan
     * kalimat yang sama.
     *
     * @param string $subject Apa yang ditolak, untuk pesan error — mis. `pesan`.
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function accepted(HttpResponse $response, string $subject = 'pesan'): array
    {
        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        $body = $this->requireJson($body, $response->status);

        if (empty($body['status'])) {
            $reason = $this->detail($body);

            throw new ApiException(
                $reason !== '' ? $reason : "Fonnte menolak {$subject} (HTTP {$response->status})",
                $response->status,
                $body,
                $reason !== '' ? $reason : null
            );
        }

        return $body;
    }

    /**
     * Terjemahkan balasan Fonnte menjadi string hasil pengiriman.
     *
     * @throws ApiException
     */
    private function sent(HttpResponse $response): string
    {
        $body = $this->accepted($response);

        $detail = $body['detail'] ?? '';
        $detail = \is_array($detail) ? implode('; ', array_map('strval', $detail)) : (string) $detail;

        return $detail !== '' ? "Sukses: {$detail}" : 'Sukses';
    }
}
