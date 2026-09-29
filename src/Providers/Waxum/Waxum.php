<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Waxum;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\Presence;

/**
 * Gateway waxum (https://github.com/imtaqin/waxum), WhatsApp self-hosted
 * berbasis Rust — protokol multi-device-nya dari `whatsapp-rust`.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER   = Waxum
 *   WHATSAPP_TOKEN      = bearer token waxum (header `Authorization: Bearer …`)
 *   WHATSAPP_URL_Waxum  = base URL instance, mis. http://localhost:3451
 *                         (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_SESSION    = id sesi yang sudah dipindai (juga id bawaan
 *                         createSession()/checkSession()).
 *                         `WHATSAPP_SESSION_Waxum` menang atasnya, sehingga
 *                         id sesi waxum tidak perlu sama dengan gateway lain.
 *
 * Berbeda dari OpenWA dan Wwebjs, sesi di sini tidak perlu dibuat lebih dulu:
 * `createSession()` dengan id yang sudah ada akan ditolak HTTP 409, jadi
 * alurnya adalah `checkSession()` dulu, dan baru membuat kalau memang belum ada.
 *
 * Seluruh API-nya berada di bawah `/api/v1`, dan tokennya adalah token
 * superadmin — waxum juga menerima JWT yang ditandatangani `JWT_SECRET` dengan
 * klaim `role: superadmin`, tapi token superadmin biasa cukup.
 */
final class Waxum extends AbstractProvider
{
    public const NAME = 'Waxum';

    public const DEFAULT_URL = 'https://waxum.whatsapp.com';

    /** Semua endpoint REST-nya bernaung di bawah prefiks ini. */
    private const API = '/api/v1';

    private string $baseUrl;
    private string $sessionId;

    /**
     * @param array{
     *     token?:string, url?:string, session?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>,
     *     urls?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $url = $this->config->url(self::DEFAULT_URL, self::NAME);

        // Terima "http://host:3451" maupun "http://host:3451/api/v1" tanpa jadi
        // "/api/v1/api/v1".
        $this->baseUrl = preg_replace('#/api/v1$#', '', $url) ?? $url;
        // Lihat {@see Config::session()}: `WHATSAPP_SESSION_Waxum` didahulukan.
        $this->sessionId = $this->config->session(self::NAME);
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->getToken()];
    }

    /**
     * Sesi waxum tidak menahan pemanggil saat indikator ketik ditampilkan.
     *
     * `POST .../chatstate/send` hanya menyimpan statusnya, dan pesannya
     * benar-benar dikirim setelah pemanggil menghapusnya dengan `paused` atau
     * setelah sebuah pesan terkirim. Jadi durasinya tetap dihabiskan SDK
     * sendiri — lihat {@see \Sikuwa\Whatsapp\Providers\AbstractProvider::announceTyping()}.
     */
    protected function presenceBlocks(): bool
    {
        return false;
    }

    /**
     * Buat sesi baru: `POST /api/v1/sessions`.
     *
     * Id sesi diambil dari `$options` bila ada, selain itu dari
     * `WHATSAPP_SESSION`. Mengosongkan keduanya sah — waxum akan membuat id
     * acak — tetapi sesi seperti itu tidak bisa dicari lagi lewat
     * {@see self::checkSession()} tanpa argumen.
     *
     * Sesi baru langsung mulai menyambung dan berstatus `connecting`, jadi
     * ambil QR-nya lewat {@see self::showQr()}, lalu pantau dengan
     * {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `id`, `name`,
     *                                     `webhook`, `device`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $payload = WaxumSession::payload($options, $this->sessionId);
        $body = $this->postJson("{$this->baseUrl}" . self::API . '/sessions', $payload);

        return WaxumSession::fromCreate($body, (string) ($payload['id'] ?? ''));
    }

    /**
     * Baca keadaan sesi: `GET /api/v1/sessions/{id}/status`.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        return WaxumSession::fromStatus(
            $this->getJson($this->sessionUrl($sessionId, 'status')),
            $sessionId
        );
    }

    /**
     * Ambil QR sesi: `GET /api/v1/sessions/{id}/qr`.
     *
     * Hanya menjawab saat sesi sedang menunggu dipindai. Sesi yang sudah login
     * membalas `qr_codes` kosong dengan `status: "logged_in"` — itu keadaan,
     * bukan kegagalan, jadi dilaporkan sebagai sesi `connected` tanpa QR. Sesi
     * yang belum tersambung sama sekali justru ditolak: waxum membalas HTTP 503
     * untuk sesi yang runtime-nya belum ada, dan itu tetap dilempar karena
     * pemanggil perlu tahu sesinya memang belum siap.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        $body = $this->getJson($this->sessionUrl($sessionId, 'qr'));

        // Diperiksa sebelum balasannya dianggap selesai: `qr_codes` kosong
        // bersama status `logged_in` berarti sesinya sudah siap dipakai.
        if (($body['qr_codes'] ?? []) === [] && WaxumSession::fromStatus($body)->isConnected()) {
            return WaxumShowQr::alreadyConnected($body, $sessionId);
        }

        return WaxumShowQr::fromResponse($body, $sessionId);
    }

    /**
     * Kirim pesan lewat waxum.
     *
     * Waxum tidak punya endpoint batch — satu pesan satu request — jadi
     * beberapa pesan dikirim berurutan. Jeda antar pesan dihormati lewat kunci
     * `delay` atau, bila tidak diisi, lewat pacing (`WHATSAPP_PACING_*`),
     * sehingga mengirim banyak pesan MEMBLOKIR pemanggil selama total jeda
     * itu. Halaman scan hanya mengirim satu pesan.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function sendMessage(array|string $message): string
    {
        return $this->sendIndividually(
            $message,
            fn (array $items): array => $this->build($items),
            fn (WaxumMessage $message): string => $this->sendText($message),
            static fn (WaxumMessage $message): string => $message->to
        );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int,typing:?int}> $items
     * @return array<int,array{message:WaxumMessage,delay:?int,destination:string,typing:?int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        return $this->buildItems(
            $items,
            static fn (array $item): WaxumMessage => new WaxumMessage($item['destination'], $item['message']),
            static fn (WaxumMessage $message): string => $message->to
        );
    }

    /**
     * Kirim satu berkas lewat waxum.
     *
     * Dua endpoint terpisah — `messages/image` dan `messages/document` — dan
     * yang menentukan adalah jenis berkasnya, sama seperti di gateway lain.
     * Isinya boleh URL publik maupun base64: waxum mengunduh sendiri berkas
     * dari URL, lewat pemeriksa SSRF-nya, jadi keduanya diteruskan apa adanya.
     *
     * Bentuk base64-nya adalah `{data, mimetype}`, bukan data URI — waxum
     * memisahkan isi dari jenisnya, jadi awalan `data:…;base64,` harus dibuang.
     * Untuk dokumen, `filename` ikut dikirim karena itulah nama yang dilihat
     * penerima.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        $to = $this->target($destination);

        // Waxum menerima kedua bentuk itu, dan keduanya punya arti yang
        // berbeda: URL berarti "unduh sendiri", base64 berarti "inilah isinya".
        $media = $file->isUrl()
            ? ['url' => $file->payload]
            : ['data' => $file->base64(), 'mimetype' => $file->mime];

        if ($file->isImage()) {
            $payload = ['to' => $to, 'image' => $media];
        } else {
            $payload = ['to' => $to, 'document' => $media, 'filename' => $file->filename];
        }

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $body = $this->postJson(
            $this->sessionUrl($this->sessionId, 'messages/' . ($file->isImage() ? 'image' : 'document')),
            $payload
        );

        return 'Sukses, messageId: ' . (string) ($body['message_id'] ?? '-');
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik":
     * `POST /api/v1/sessions/{id}/chatstate/send`.
     *
     * Waxum memakai kosakata yang sama dengan SDK ini (`composing`, `paused`,
     * `recording`), jadi tidak ada penerjemahan kata yang perlu dilakukan.
     * `duration` tidak ikut dikirim: statusnya bertahan sampai dihapus dengan
     * `paused` atau sampai ada pesan yang benar-benar terkirim.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        $to = $this->target($destination);

        $this->postJson($this->sessionUrl($this->sessionId, 'chatstate/send'), [
            'to' => $to,
            // Kosakata waxum kebetulan sama dengan kosakata baku SDK ini,
            // jadi keadaannya diteruskan apa adanya.
            'state' => $presence->state,
        ]);

        return $this->presenceResult($presence, $to);
    }

    /** POST /api/v1/sessions/{id}/messages/text */
    private function sendText(WaxumMessage $message): string
    {
        $body = $this->postJson($this->sessionUrl($this->sessionId, 'messages/text'), $message->toArray());

        return 'Sukses, messageId: ' . (string) ($body['message_id'] ?? '-');
    }

    /**
     * Url endpoint yang bernaung di bawah satu sesi.
     *
     * Tidak memakai `rawurlencode()`, berbeda dari gateway lain: id sesi waxum
     * dipakai apa adanya sebagai nama direktori penyimpanannya, dan id yang
     * butuh di-encode memang tidak bisa dipakai di sana.
     */
    private function sessionUrl(string $sessionId, string $path): string
    {
        return "{$this->baseUrl}" . self::API . "/sessions/{$sessionId}/{$path}";
    }

    /**
     * Waxum memakai amplop error `{"success":false,"error":{"code":N,"message":"…"}}`
     * (lihat `ApiError::into_response`). Beberapa status punya arti khusus yang
     * layak dijelaskan di log, karena pesan bawaannya tidak menyebut apa pun
     * tentang cara memperbaikinya.
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);

        $konteks = match (true) {
            $status === 401 => 'token waxum salah atau kosong, cek WHATSAPP_TOKEN',
            $status === 403 => 'token bukan superadmin, atau tidak berhak atas sesi ini',
            $status === 404 => 'sesi tidak ditemukan, cek WHATSAPP_SESSION',
            $status === 409 => 'sesi dengan id itu sudah ada — pakai checkSession(), bukan createSession()',
            // HTTP 503 dari waxum selalu berarti klien WhatsApp-nya belum
            // hidup; pesan terpentingnya adalah bahwa tidak ada yang terkirim.
            $status === 503 => 'sesi WhatsApp belum tersambung, tidak ada pesan yang terkirim — pindai QR-nya lewat showQr()',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }

    /**
     * Detail error waxum bersarang di `error.message`, sedangkan penolong baku
     * hanya membaca `error` yang berupa string.
     */
    protected function detail(?array $body): string
    {
        $error = $body['error'] ?? null;

        if (\is_array($error)) {
            return parent::detail(['error' => $error['message'] ?? null]);
        }

        return parent::detail($body);
    }
}
