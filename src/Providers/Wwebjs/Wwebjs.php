<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wwebjs;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\Presence;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Gateway Wwebjs (https://github.com/avoylenko/wwebjs-api), pembungkus REST
 * untuk whatsapp-web.js — WhatsApp self-hosted berbasis Node.js + Chromium.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER   = Wwebjs
 *   WHATSAPP_TOKEN      = API key global (env `API_KEY` di sisi server), header `x-api-key`
 *   WHATSAPP_URL_Wwebjs = base URL instance, mis. https://wwebjs.example.com
 *                         (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_SESSION    = id session: huruf, angka, `_`, dan `-` saja
 *                         (`WHATSAPP_SESSION_Wwebjs` menang atasnya, jadi
 *                         session wwebjs tidak bertabrakan dengan gateway lain)
 *
 * Dua hal yang membedakannya dari gateway lain di SDK ini:
 *
 * - **API key-nya opsional.** Selama `API_KEY` tidak diisi di sisi server,
 *   wwebjs menerima request tanpa autentikasi apa pun. SDK tetap mengirim
 *   headernya, jadi keduanya bekerja.
 * - **Pengiriman menuntut session yang sudah tersambung.** Middleware
 *   `sessionValidation` menolak dengan HTTP 404 selama session belum
 *   `CONNECTED`, sehingga pesan errornya di sini menjelaskan keadaan itu alih-
 *   alih meneruskan "Not Found" apa adanya.
 */
final class Wwebjs extends AbstractProvider
{
    public const NAME = 'Wwebjs';

    public const DEFAULT_URL = 'https://wwebjs.whatsapp.com';

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
        // Lihat {@see Config::session()}: kunci `WHATSAPP_SESSION_Wwebjs`
        // didahulukan atas `WHATSAPP_SESSION`.
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
        return ['x-api-key' => $this->getToken()];
    }

    /**
     * Nyalakan session: `POST /session/start/{sessionId}`.
     *
     * wwebjs tidak punya pendaftaran session di luar ini — namanya ditentukan
     * pemanggil, dan memanggil endpoint ini untuk nama yang sudah ada berarti
     * menyambungkannya kembali.
     *
     * **Panggilan ini ikut menunggu.** Servernya baru membalas setelah Chromium
     * selesai dimuat, dan itu bisa memakan waktu mendekati batas
     * `WHATSAPP_TIMEOUT` yang bawaannya 10 detik. Naikkan timeout kalau
     * pembuatan session sering berakhir `TimeoutException`.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `id` (nama
     *                                     session, alias `name`), `webhookUrl`
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $sessionId = WwebjsSession::name($options, $this->sessionId);

        return WwebjsSession::fromStart(
            $this->postJson(
                $this->endpoint('session/start/' . rawurlencode($sessionId)),
                WwebjsSession::payload($options)
            ),
            $sessionId
        );
    }

    /**
     * Baca keadaan session: `GET /session/status/{sessionId}`.
     *
     * @param string|null $id Session yang diperiksa; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        return WwebjsSession::fromStatus(
            $this->getJson($this->endpoint('session/status/' . rawurlencode($sessionId))),
            $sessionId
        );
    }

    /**
     * Tampilkan semua session: `GET /session/getSessions`.
     *
     * Tidak butuh `WHATSAPP_SESSION` — endpoint ini mendaftar seluruh
     * session yang dikenal server. Balasannya hanya berisi nama session
     * (string), tanpa keadaan sambungan, sehingga setiap `Session` hanya
     * terisi `$id` dan `$provider`. Panggil {@see self::checkSession()}
     * per session untuk mengetahui keadaannya.
     *
     * @return array<int,Session>
     *
     * @throws ApiException
     */
    public function listSessions(): array
    {
        $body = $this->getJson($this->endpoint('session/getSessions'));

        $ids = $body['result'] ?? null;

        if (!\is_array($ids)) {
            return [];
        }

        $sessions = [];

        foreach ($ids as $id) {
            $id = Text::of($id);

            if ($id !== '') {
                $sessions[] = new Session(
                    provider: Wwebjs::NAME,
                    id: $id,
                );
            }
        }

        return $sessions;
    }

    /**
     * Ambil QR session: `GET /session/qr/{sessionId}/image`.
     *
     * wwebjs menyediakan QR dalam dua bentuk — teks isinya
     * (`/session/qr/{id}`) dan gambarnya (`/session/qr/{id}/image`). Yang
     * dipakai di sini adalah gambarnya, karena hanya itu yang bisa langsung
     * dipasang di atribut `src` tanpa merender ulang QR-nya.
     *
     * Saat QR-nya tidak ada, endpoint yang sama menjawab JSON
     * `{success:false, message:...}` alih-alih PNG. Dua di antaranya adalah
     * keadaan biasa: session belum selesai dimuat, dan QR-nya sudah dipindai.
     * Yang terakhir dibedakan dengan menanyakan status session — sekali saja,
     * dan hanya di jalur itu — supaya sesi yang sudah tersambung dilaporkan
     * sebagai `connected` tanpa QR, bukan sebagai kegagalan.
     *
     * @param string|null $id Session yang diminta QR-nya; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $sessionId = $this->requireConfigured($id ?? $this->sessionId, 'WHATSAPP_SESSION');

        $response = $this->http->get(
            $this->endpoint('session/qr/' . rawurlencode($sessionId) . '/image'),
            $this->authHeaders()
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        // Body JSON di jalur ini berarti QR-nya memang tidak ada — bukan gambar
        // yang gagal di-decode. `read()` mengembalikan null untuk PNG.
        if ($body !== null) {
            if (WwebjsShowQr::isAlreadyScanned($body) && $this->checkSession($sessionId)->isConnected()) {
                return WwebjsShowQr::alreadyConnected($body, $sessionId);
            }

            $this->reject($response, $body);
        }

        return WwebjsShowQr::fromImage((string) $response->body, $sessionId);
    }

    /**
     * Kirim pesan lewat wwebjs.
     *
     * wwebjs tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu. Jeda antar pesan dihormati lewat kunci `delay` atau, bila tidak
     * diisi, lewat pacing (`WHATSAPP_PACING_*`), sehingga mengirim banyak pesan
     * MEMBLOKIR pemanggil selama total jeda itu.
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
            fn (WwebjsMessage $message): string => $this->sendText($message),
            static fn (WwebjsMessage $message): string => $message->chatId
        );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int,typing:?int}> $items
     * @return array<int,array{message:WwebjsMessage,delay:?int,destination:string,typing:?int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        return $this->buildItems(
            $items,
            static fn (array $item): WwebjsMessage => WwebjsMessage::text($item['destination'], $item['message']),
            static fn (WwebjsMessage $message): string => $message->chatId
        );
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik".
     *
     * wwebjs memakai endpoint berbeda untuk tiap keadaan — bukan satu endpoint
     * dengan kolom status seperti gateway lain:
     *
     * - `POST /chat/sendStateTyping/{sessionId}`
     * - `POST /chat/sendStateRecording/{sessionId}`
     * - `POST /chat/clearState/{sessionId}` — untuk berhenti
     *
     * Tidak ada kolom durasi: indikatornya bertahan sekitar 25 detik di sisi
     * server, atau sampai dihapus. Jadi `duration` di sini hanya menentukan
     * berapa lama SDK menunggu sebelum pesannya dikirim — lihat
     * {@see AbstractProvider::announceTyping()}.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $sessionId = $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');
        $chatId = WwebjsMessage::chatIdFor($destination);

        if ($chatId === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        $path = match (true) {
            $presence->isRecording() => 'chat/sendStateRecording',
            $presence->isPaused() => 'chat/clearState',
            default => 'chat/sendStateTyping',
        };

        $this->postJson($this->endpoint("{$path}/" . rawurlencode($sessionId)), ['chatId' => $chatId]);

        return $this->presenceResult($presence, $chatId);
    }

    /**
     * Kirim satu berkas lewat wwebjs.
     *
     * Gambar dan dokumen memakai endpoint yang sama dengan teks; yang
     * membedakan hanya `contentType` di dalam payload — lihat
     * {@see WwebjsMessage::media()}. Jenis berkasnya yang menentukan gambar atau
     * dokumen, bukan method yang dipanggil pemanggil.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        $this->requireConfigured($this->sessionId, 'WHATSAPP_SESSION');

        $message = WwebjsMessage::media($destination, $file, $caption);

        if ($message->chatId === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        return $this->sent($this->send($message->toArray()));
    }

    /** POST /client/sendMessage/{sessionId} untuk satu pesan teks. */
    private function sendText(WwebjsMessage $message): string
    {
        return $this->sent($this->send($message->toArray()));
    }

    /**
     * Satu-satunya endpoint pengiriman wwebjs.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function send(array $payload): array
    {
        return $this->postJson(
            $this->endpoint('client/sendMessage/' . rawurlencode($this->sessionId)),
            $payload
        );
    }

    /**
     * Terjemahkan balasan pengiriman menjadi string hasil.
     *
     * Balasannya `{"success":true,"message":{...Message}}`; id pesannya ada di
     * `message.id._serialized`, dan sebagian versi hanya mengisi `message.id.id`.
     *
     * @param array<string,mixed> $body
     */
    private function sent(array $body): string
    {
        $message = \is_array($body['message'] ?? null) ? $body['message'] : [];
        $id = \is_array($message['id'] ?? null) ? $message['id'] : [];

        return 'Sukses, messageId: ' . (Text::first($id['_serialized'] ?? null, $id['id'] ?? null) ?: '-');
    }

    /** URL endpoint di bawah base URL instance. */
    private function endpoint(string $path): string
    {
        return "{$this->baseUrl}/{$path}";
    }

    /**
     * Amplop error wwebjs: `{"success":false,"error":"..."}` dengan status HTTP
     * yang sesuai (lihat `sendErrorResponse` di `src/utils.js`).
     *
     * Dua keadaan punya arti khusus yang layak dijelaskan di log, karena
     * "Not Found" dari middleware `sessionValidation` tidak menyebut session
     * sama sekali — ia hanya mengirim `session_not_found` atau
     * `session_not_connected` di kolom `error`.
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);

        $konteks = match (true) {
            $status === 403 => 'API key salah, cek WHATSAPP_TOKEN',
            str_contains($detail, 'session_not_found') => 'session belum dibuat, cek WHATSAPP_SESSION',
            str_contains($detail, 'session_not_connected') => 'session belum tersambung, pindai QR-nya lebih dulu',
            $status === 422 => 'nama session hanya boleh huruf, angka, garis bawah, dan tanda minus',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }
}
