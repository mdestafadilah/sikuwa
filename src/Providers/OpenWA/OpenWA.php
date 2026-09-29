<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;
use Sikuwa\Whatsapp\Support\Presence;

/**
 * Gateway OpenWA (https://github.com/rmyndharis/OpenWA), WhatsApp self-hosted
 * berbasis Node.js.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = OpenWA
 *   WHATSAPP_TOKEN    = API key OpenWA, dikirim sebagai header `X-API-Key`
 *   WHATSAPP_URL_OpenWA = base URL instance, mis. https://v15.example.com
 *                         (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_SESSION  = id session yang sudah di-start dan tersambung
 *                       (`WHATSAPP_SESSION_OpenWA` menang atasnya, sehingga
 *                       OpenWA bisa memakai sesi sendiri tanpa mengganggu
 *                       gateway lain)
 */
final class OpenWA extends AbstractProvider
{
    public const NAME = 'OpenWA';

    public const DEFAULT_URL = 'https://openwa.whatsapp.com';

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

        $this->baseUrl = $this->config->url(self::DEFAULT_URL, self::NAME);
        // Nama provider ikut diberikan supaya `WHATSAPP_SESSION_OpenWA`
        // didahulukan — lihat {@see Config::session()}.
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
        return ['X-API-Key' => $this->getToken()];
    }

    /**
     * Buat sesi baru: `POST /api/sessions`.
     *
     * Sesi baru berstatus `INITIALIZING`, jadi belum bisa dipakai mengirim.
     * Ambil QR-nya lewat `GET /api/sessions/{id}/qr`, lalu pantau dengan
     * {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `id`, `name`, `config`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $payload = OpenWASession::payload($options, $this->sessionId);
        $body = $this->postJson("{$this->baseUrl}/api/sessions", $payload);

        return OpenWASession::fromResponse($body, (string) ($payload['id'] ?? ''));
    }

    /**
     * Baca keadaan sesi: `GET /api/sessions/{id}`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        return OpenWASession::fromResponse(
            $this->getJson("{$this->baseUrl}/api/sessions/" . rawurlencode($sessionId)),
            $sessionId
        );
    }

    /**
     * Ambil QR sesi: `GET /api/sessions/{id}/qr`.
     *
     * Hanya menjawab saat sesi sedang menunggu dipindai. Sesi yang belum
     * mencapai `qr_ready` — termasuk yang sudah tersambung, dan yang sedang
     * menyambung ulang — ditolak dengan HTTP 400, sedangkan API key yang bukan
     * kunci berperan operator ditolak dengan HTTP 403. Karena satu status
     * dipakai untuk beberapa sebab sekaligus, pesan dari gateway sendiri yang
     * paling menentukan.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        return OpenWAShowQr::fromResponse(
            $this->getJson("{$this->baseUrl}/api/sessions/" . rawurlencode($sessionId) . '/qr'),
            $sessionId
        );
    }

    /**
     * Kirim pesan lewat OpenWA.
     *
     * Satu pesan dikirim ke endpoint send-text (sinkron, balasannya messageId).
     * Lebih dari satu pesan dikirim ke send-bulk (asinkron, balasannya batchId).
     *
     * Jeda antar pesan di send-bulk diisi dari `delay` pesan pertama, atau
     * dari pacing (`WHATSAPP_PACING_*`) bila tidak diisi. OpenWA juga
     * menambahkan pengacakan sendiri di sisinya (`randomizeDelay`).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function sendMessage(array|string $message): string
    {
        $items = $this->plan($message);

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        // OpenWA hanya menerima satu angka jeda untuk seluruh batch, bukan jeda
        // per pesan. Yang paling mewakili adalah jeda pesan kedua: jeda pertama
        // yang benar-benar terasa di antara dua pesan. `delay` pada pesan
        // pertama tetap dihormati sebagai angka untuk seluruh batch.
        $delay = $items[1]['delay'] ?? $items[0]['delay'] ?? OpenWABulkMessage::DEFAULT_DELAY;

        $bulk = $this->compose(fn (): OpenWABulkMessage => new OpenWABulkMessage($items, $delay));

        if ($bulk->count() === 0) {
            return 'Tidak ada pesan untuk dikirim';
        }

        // OpenWA menerima seluruh batch dalam satu request, jadi SDK tidak
        // punya kesempatan menyisipkan indikator di antara pesan. Yang bisa
        // dilakukan adalah memunculkannya untuk tujuan pesan pertama:
        // memunculkan untuk semua tujuan sekaligus justru membuat penerima
        // terakhir melihat "sedang mengetik" lalu diam lama sebelum pesannya
        // datang, dan itu lebih buruk daripada tanpa indikator.
        $this->announceTyping($items[0] ?? null);

        return $bulk->count() === 1
            ? $this->sendText($bulk->first())
            : $this->sendBulk($bulk);
    }

    /**
     * Kirim satu berkas lewat OpenWA.
     *
     * Ada dua endpoint terpisah — `send-image` dan `send-document` — dengan
     * bentuk body yang sama; yang menentukan adalah jenis berkasnya.
     *
     * OpenWA menerima base64 MENTAH dan mengirim `mimetype` di kolom sendiri,
     * jadi awalan `data:…;base64,` justru harus dibuang. Kalau sumbernya URL
     * publik, yang dikirim cukup `url` saja supaya server OpenWA yang
     * mengunduh — bukan keduanya sekaligus.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        // OpenWA menolak nomor mentah: `chatId` wajib berupa JID lengkap.
        $chatId = PhoneNumber::toWid($destination);

        // `toWid()` membentuk "@c.us" begitu nomornya kosong, dan itu akan
        // ditolak server dengan pesan yang tidak menjelaskan apa-apa.
        if (str_starts_with($chatId, '@')) {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        $payload = [
            'chatId' => $chatId,
            'mimetype' => $file->mime,
            'filename' => $file->filename,
        ];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($file->isUrl()) {
            $payload['url'] = $file->payload;
        } else {
            $payload['base64'] = $file->base64();
        }

        $body = $this->postJson(
            $this->sessionUrl($file->isImage() ? 'messages/send-image' : 'messages/send-document'),
            $payload
        );

        return 'Sukses, messageId: ' . ($body['messageId'] ?? '-');
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik": `POST …/chats/typing`.
     *
     * OpenWA memakai kata yang berbeda dari kosakata baku di sini — `typing`
     * untuk mengetik, `recording` untuk merekam suara — jadi penerjemahannya
     * dilakukan di tempat ini. `duration` tidak ikut dikirim: OpenWA
     * menyimpan statusnya sampai dihapus dengan `paused`, atau sampai ada
     * pesan yang benar-benar terkirim.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        // Sama seperti pengiriman berkas: `chatId` wajib berupa JID lengkap,
        // dan `toWid()` membentuk "@c.us" begitu nomornya kosong.
        $chatId = PhoneNumber::toWid($destination);

        if (str_starts_with($chatId, '@')) {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        $state = match (true) {
            $presence->isRecording() => 'recording',
            $presence->isPaused() => 'paused',
            default => 'typing',
        };

        $this->postJson($this->sessionUrl('chats/typing'), ['chatId' => $chatId, 'state' => $state]);

        return $this->presenceResult($presence, $chatId);
    }

    /** POST /api/sessions/{sessionId}/messages/send-text */
    private function sendText(OpenWAMessage $message): string
    {
        $body = $this->postJson($this->sessionUrl('messages/send-text'), $message->toArray());

        return 'Sukses, messageId: ' . ($body['messageId'] ?? '-');
    }

    /** POST /api/sessions/{sessionId}/messages/send-bulk */
    private function sendBulk(OpenWABulkMessage $bulk): string
    {
        $body = $this->postJson($this->sessionUrl('messages/send-bulk'), $bulk->toArray());

        $total = $body['totalMessages'] ?? $bulk->count();
        $batchId = $body['batchId'] ?? '-';

        return "Batch diterima ({$total} pesan), batchId: {$batchId}";
    }

    /** URL endpoint yang bernaung di bawah sesi, mis. `messages/send-text`. */
    private function sessionUrl(string $path): string
    {
        return "{$this->baseUrl}/api/sessions/" . rawurlencode($this->sessionId) . "/{$path}";
    }
}
