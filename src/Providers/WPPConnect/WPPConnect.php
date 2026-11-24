<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\WPPConnect;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\NumberCheck;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\Presence;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Gateway WPPConnect Server (https://github.com/wppconnect-team/wppconnect-server),
 * pembungkus REST untuk WPPConnect — WhatsApp self-hosted berbasis Node.js +
 * Chromium.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER        = WPPConnect
 *   WHATSAPP_TOKEN           = token sesi (header `Authorization: Bearer …`)
 *   WHATSAPP_URL_WPPConnect  = base URL instance, mis. http://localhost:21465
 *                              (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_SESSION         = nama sesi, mis. sikuwa
 *                              (`WHATSAPP_SESSION_WPPConnect` menang atasnya)
 *
 * **Tokennya per sesi, bukan per server.** WPPConnect menerbitkan token lewat
 * `POST /api/{session}/{secretkey}/generate-token`, dan token itu adalah hash
 * bcrypt dari `session + SECRET_KEY` — jadi satu token hanya berlaku untuk satu
 * nama sesi. `SECRET_KEY` server sendiri **tidak** bisa dipakai sebagai Bearer
 * token; ia hanya dipakai endpoint admin (`/api/show-all-sessions`) lewat
 * header `x-secret-key`. Itulah sebabnya `WHATSAPP_SESSION` wajib diisi dan
 * harus sama dengan sesi yang tokennya Anda pegang.
 *
 * **Nama sesi tidak boleh memuat titik dua.** Server memakai `session:token`
 * di URL sebagai cara autentikasi alternatif (`verifyToken` di
 * `src/middleware/auth.ts`), jadi nama yang mengandung `:` akan dibaca sebagai
 * sesi + token dan token di header diabaikan. SDK menolaknya lebih awal dengan
 * pesan yang jelas, bukan meneruskan penolakan 401 yang membingungkan.
 *
 * Seluruh endpoint REST-nya berada di bawah `/api/{session}`, dan pengiriman
 * apa pun menuntut sesi yang sudah `CONNECTED`: middleware `statusConnection`
 * menjawab HTTP 404 `{status: "Disconnected"}` selama belum — pesan itu
 * diterjemahkan SDK menjadi petunjuk yang bisa ditindaklanjuti.
 */
final class WPPConnect extends AbstractProvider
{
    public const NAME = 'WPPConnect';

    public const DEFAULT_URL = 'https://wppconnect.whatsapp.com';

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

        // Terima "http://host:21465" maupun "http://host:21465/api" tanpa jadi
        // "/api/api". Sama seperti Waxum yang menerima base URL berakhiran
        // "/api/v1".
        $this->baseUrl = preg_replace('#/api$#', '', $url) ?? $url;
        // Lihat {@see Config::session()}: `WHATSAPP_SESSION_WPPConnect`
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
        return ['Authorization' => 'Bearer ' . $this->getToken()];
    }

    /**
     * Indikator ketik WPPConnect bertahan sampai dihapus atau sampai ada pesan
     * terkirim — ia hanya menyimpan status, tidak menunggu.
     *
     * `startTyping`/`startRecording` sebenarnya menerima `duration` dalam
     * milidetik, tetapi memakainya berarti server mengulang statusnya sendiri
     * selama itu sementara SDK juga menunggu — pemanggil jadi menunggu dua
     * kali untuk satu indikator. Jadi durasinya dihabiskan SDK sendiri lewat
     * {@see AbstractProvider::announceTyping()}, dan `paused` yang menghapusnya.
     */
    protected function presenceBlocks(): bool
    {
        return false;
    }

    /**
     * Mulai sesi: `POST /api/{session}/start-session`.
     *
     * Nama sesi diambil dari `$options` (`session`, `id`, atau `name`), selain
     * itu dari `WHATSAPP_SESSION`. Tidak seperti Waxum, memanggil endpoint ini
     * untuk sesi yang sudah ada berarti **menyambungkannya kembali**, bukan
     * ditolak — jadi aman dipanggil tanpa `checkSession()` lebih dulu.
     *
     * Balasannya adalah keadaan sesi saat itu juga, dan untuk sesi baru
     * biasanya masih `CLOSED`: servernya baru membuat klien WhatsApp setelah
     * membalas. Pindai QR-nya lewat {@see self::showQr()} setelah sesinya
     * berjalan, lalu pantau dengan {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `session`/`id`/
     *                                     `name`, `waitQrCode`, `webhook`,
     *                                     `proxy`. Lihat
     *                                     {@see WPPConnectSession::payload()}.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $sessionId = $this->session(WPPConnectSession::name($options, $this->sessionId));
        $payload = WPPConnectSession::payload($options);

        return WPPConnectSession::fromState(
            $this->postJson(
                $this->endpoint($sessionId, 'start-session'),
                // Body kosong lebih tepat daripada `[]`: server membacanya
                // sebagai objek kosong, sama seperti permintaan tanpa body.
                $payload === [] ? null : $payload
            ),
            $sessionId
        );
    }

    /**
     * Baca keadaan sesi: `GET /api/{session}/status-session`.
     *
     * Sesi yang belum pernah dimulai membalas `{"status":"CLOSED",
     * "qrcode":null}` dengan HTTP 200 — bukan error, jadi dilaporkan sebagai
     * sesi yang belum tersambung.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $sessionId = $this->session($id ?? $this->sessionId);

        return WPPConnectSession::fromState(
            $this->getJson($this->endpoint($sessionId, 'status-session')),
            $sessionId
        );
    }

    /**
     * WPPConnect tidak bisa mendaftar sesi lewat token per sesi.
     *
     * Satu-satunya endpoint daftar adalah `POST /api/show-all-sessions`, dan
     * ia menuntut `SECRET_KEY` server di header `x-secret-key` — kredensial
     * yang berbeda dari token Bearer yang dipegang SDK. Balasannya pun hanya
     * daftar token, tanpa keadaan sambungan, sehingga tidak bisa menjadi
     * `Session` yang berguna.
     *
     * Karena itu method ini **melempar**, bukan mengembalikan array kosong yang
     * seolah-olah server tidak punya sesi sama sekali.
     *
     * @return array<int,Session>
     *
     * @throws ConfigurationException Selalu.
     */
    public function listSessions(): array
    {
        throw new ConfigurationException(
            'WPPConnect tidak bisa mendaftar sesi lewat token per sesi: endpoint daftarnya '
            . '(POST /api/show-all-sessions) menuntut SECRET_KEY server di header x-secret-key, '
            . 'kredensial yang berbeda dari token Bearer yang dipegang SDK — dan balasannya pun '
            . 'hanya token, tanpa keadaan sambungan. Simpan id sesinya di '
            . 'WHATSAPP_SESSIONS_WPPConnect lalu panggil checkSession($id) untuk tiap sesi'
        );
    }

    /**
     * Ambil QR sesi: `GET /api/{session}/qrcode-session`.
     *
     * Endpoint ini menjawab **PNG biner** selama QR-nya tersedia, dan JSON
     * hanya saat QR-nya tidak ada. Dua bentuk JSON-nya adalah keadaan, bukan
     * kegagalan:
     *
     * - `{"status":"CONNECTED",…}` → sesi sudah tersambung, tidak ada yang
     *   perlu dipindai;
     * - `{"status":null,"message":"Session not started…"}` → sesinya belum
     *   dimulai, jadi `createSession()` belum dipanggil.
     *
     * Yang kedua sengaja **dilempar**: tanpa sesi yang berjalan, memanggil
     * ulang `showQr()` tidak akan pernah menghasilkan QR, dan pemanggil perlu
     * tahu langkah mana yang kurang. Status lain (mis. `INITIALIZING`) juga
     * dilempar, dengan pesan gateway-nya — QR-nya memang belum siap, dan itu
     * akan berubah sendiri beberapa detik kemudian.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $sessionId = $this->session($id ?? $this->sessionId);

        $response = $this->http->get($this->endpoint($sessionId, 'qrcode-session'), $this->authHeaders());
        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        // Body JSON di jalur ini berarti QR-nya memang tidak ada — bukan PNG
        // yang gagal di-decode. `read()` mengembalikan null untuk PNG.
        if ($body === null) {
            $bytes = (string) $response->body;

            if ($bytes === '') {
                throw new ApiException(
                    'Respons WPPConnect tidak valid (HTTP ' . $response->status . ')',
                    $response->status
                );
            }

            return WPPConnectShowQr::fromImage($bytes, $sessionId);
        }

        $status = Text::of($body['status'] ?? null);

        if ($status === '') {
            throw new ConfigurationException(
                "Sesi WPPConnect '{$sessionId}' belum dimulai, jadi belum ada QR untuk dipindai. "
                . 'Panggil createSession() lebih dulu, lalu showQr() lagi setelah sesinya berjalan'
            );
        }

        if (Text::equalsAny($status, WPPConnectSession::CONNECTED)) {
            return WPPConnectShowQr::alreadyConnected($body, $sessionId);
        }

        $detail = $this->detail($body);

        throw new ApiException(
            "WPPConnect belum menyediakan QR untuk sesi '{$sessionId}' (status {$status})"
            . ($detail === '' ? '' : ": {$detail}")
            . '. Tunggu sebentar lalu panggil showQr() lagi',
            $response->status,
            $body
        );
    }

    /**
     * Periksa nomor satu per satu: `GET /api/{session}/check-number-status/{phone}`.
     *
     * Balasannya `{"status":"success","response":{"numberExists":true,
     * "id":{"_serialized":"6281234567890@c.us",…}}}`. Sama seperti pengiriman,
     * endpoint ini menuntut sesi yang sudah `CONNECTED`.
     *
     * @param array<int,string> $numbers Nomor yang sudah dinormalkan.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function probeNumbers(array $numbers): NumberCheck
    {
        $sessionId = $this->session($this->sessionId);

        $results = [];
        $ids = [];
        $raw = [];

        foreach ($numbers as $number) {
            // Satu nomor = satu request, jadi percobaan ulangnya pun per nomor
            // — bukan membungkus seluruh daftar, yang akan menanyakan ulang
            // nomor yang sudah dijawab.
            $body = $this->withRetry(fn (): array => $this->getJson(
                $this->endpoint($sessionId, 'check-number-status/' . rawurlencode($number))
            ));

            $raw[] = $body;

            $entry = \is_array($body['response'] ?? null) ? $body['response'] : [];

            $results[$number] = ($entry['numberExists'] ?? false) === true;

            $jid = \is_array($entry['id'] ?? null) ? Text::of($entry['id']['_serialized'] ?? null) : '';

            if ($jid !== '') {
                $ids[$number] = $jid;
            }
        }

        return new NumberCheck(self::NAME, $results, $ids, $raw);
    }

    /**
     * Kirim pesan lewat WPPConnect.
     *
     * WPPConnect tidak punya endpoint batch, jadi beberapa pesan dikirim satu
     * per satu. Jeda antar pesan dihormati lewat kunci `delay` atau, bila tidak
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
            fn (WPPConnectMessage $message): string => $this->sendText($message),
            static fn (WPPConnectMessage $message): string => $message->phone
        );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int,typing:?int}> $items
     * @return array<int,array{message:WPPConnectMessage,delay:?int,destination:string,typing:?int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $this->session($this->sessionId);

        return $this->buildItems(
            $items,
            static fn (array $item): WPPConnectMessage => new WPPConnectMessage(
                $item['destination'],
                $item['message']
            ),
            static fn (WPPConnectMessage $message): string => $message->phone
        );
    }

    /**
     * Kirim satu berkas lewat WPPConnect: `POST /api/{session}/send-file-base64`.
     *
     * Satu endpoint untuk gambar maupun dokumen. Server meneruskan isinya ke
     * `client.sendFile()` yang mendeteksi sendiri jenis berkasnya
     * (`type: 'auto-detect'`), jadi yang menentukan sebuah berkas tampil
     * sebagai gambar atau dokumen adalah **jenis berkasnya** — sama seperti
     * gateway lain di SDK ini. `filename` tetap penting: itulah nama yang
     * dilihat penerima.
     *
     * Bentuk isinya **harus data URI**. WPPConnect hanya memakai isinya
     * langsung bila berawalan `data:`; selain itu ia menganggapnya path lokal
     * atau URL yang harus diunduh, sehingga base64 telanjang akan gagal
     * sebagai "file not found". URL publik justru diterima — server yang
     * mengunduhnya sendiri.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        $this->session($this->sessionId);

        $payload = WPPConnectMessage::address($destination) + [
            'filename' => $file->filename,
            'base64' => $file->isUrl() ? $file->payload : $file->dataUri(),
        ];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->sent($this->postJson($this->endpoint($this->sessionId, 'send-file-base64'), $payload));
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik".
     *
     * Dua endpoint, bukan satu dengan kolom keadaan:
     *
     * - `POST /api/{session}/typing` dengan `value: true|false` — mengetik dan
     *   berhenti;
     * - `POST /api/{session}/recording` dengan `value: true` — merekam suara.
     *
     * `paused` dikirim lewat endpoint `typing` karena di server ia memanggil
     * `stopTyping()` → `markIsPaused()`, dan status `paused` itulah yang
     * menghapus indikator apa pun yang sedang tampil, termasuk rekaman.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $this->session($this->sessionId);

        $payload = WPPConnectMessage::address($destination);

        if ($presence->isRecording()) {
            $path = 'recording';
            $payload['value'] = true;
        } else {
            $path = 'typing';
            $payload['value'] = ! $presence->isPaused();
        }

        $this->postJson($this->endpoint($this->sessionId, $path), $payload);

        return $this->presenceResult($presence, $payload['phone']);
    }

    /** POST /api/{session}/send-message untuk satu pesan teks. */
    private function sendText(WPPConnectMessage $message): string
    {
        return $this->sent($this->postJson($this->endpoint($this->sessionId, 'send-message'), $message->toArray()));
    }

    /**
     * Ambil id pesan dari amplop balasan.
     *
     * Balasannya `{"status":"success","response":[…],"mapper":"return"}`, dan
     * isi `response` berbeda bentuk antar endpoint: `send-message` mengirim
     * objek pesan lengkap, sedangkan `send-file-base64` mengirim
     * `{ack, id}`. Keduanya menaruh id di `id._serialized` (sebagian versi
     * hanya mengisi `id.id`).
     *
     * @param array<string,mixed> $body
     */
    private function sent(array $body): string
    {
        $first = $body['response'] ?? null;

        if (\is_array($first) && isset($first[0]) && \is_array($first[0])) {
            $first = $first[0];
        }

        $id = \is_array($first) ? ($first['id'] ?? null) : null;

        $value = \is_array($id)
            ? Text::first($id['_serialized'] ?? null, $id['id'] ?? null)
            : Text::of($id);

        return 'Sukses, messageId: ' . ($value === '' ? '-' : $value);
    }

    /**
     * Id sesi yang siap dipakai di URL, atau exception bila belum diisi.
     *
     * Titik dua ditolak lebih awal: server membaca `session:token` di URL
     * sebagai sesi + token (`verifyToken` di `src/middleware/auth.ts`), jadi
     * nama sesi yang mengandung `:` membuat token di header diabaikan dan
     * jawabannya 401 tanpa sebab yang jelas.
     *
     * @throws ConfigurationException
     */
    private function session(string $sessionId): string
    {
        $sessionId = $this->requireConfigured(trim($sessionId), 'WHATSAPP_SESSION');

        if (str_contains($sessionId, ':')) {
            throw new ConfigurationException(
                "Id sesi WPPConnect '{$sessionId}' tidak boleh memuat ':'. Server memakai "
                . 'session:token di URL sebagai cara autentikasi, sehingga nama seperti itu '
                . 'dibaca sebagai sesi + token dan token di header diabaikan — cek WHATSAPP_SESSION'
            );
        }

        return $sessionId;
    }

    /**
     * URL endpoint yang bernaung di bawah satu sesi.
     *
     * Id sesinya di-encode karena ia segmen path; nama sesi WPPConnect sendiri
     * sebaiknya hanya huruf, angka, `-`, dan `_`, sebab server juga memakainya
     * sebagai nama berkas token di disk.
     */
    private function endpoint(string $sessionId, string $path): string
    {
        return "{$this->baseUrl}/api/" . rawurlencode($sessionId) . "/{$path}";
    }

    /**
     * Amplop error WPPConnect: `{"status":"Error","message":"…","error":{…}}`
     * untuk kegagalan pengiriman, `{"error":"Check that the Session and Token
     * are correct"}` untuk autentikasi, dan `{"status":"Disconnected",
     * "message":"A sessão do WhatsApp não está ativa."}` untuk sesi yang belum
     * tersambung.
     *
     * Pesan bawaannya berbahasa Portugis dan tidak menyebut cara memperbaiki
     * apa pun, jadi beberapa keadaan dijelaskan ulang di sini.
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);
        $state = Text::of($body['status'] ?? null);

        $konteks = match (true) {
            $status === 401 => 'token salah atau kosong. WPPConnect memakai token per sesi dari '
                . 'POST /api/{session}/{secretkey}/generate-token, bukan SECRET_KEY server',
            // 404 di sini hampir selalu datang dari middleware statusConnection,
            // bukan dari rute yang salah — dan pesan aslinya berbahasa Portugis.
            $status === 404 && Text::equalsAny($state, 'Disconnected') => 'sesi WhatsApp belum '
                . 'tersambung atau belum dimulai — panggil createSession(), lalu pindai QR-nya '
                . 'lewat showQr()',
            $status === 404 => 'sesi belum dibuat di server, cek WHATSAPP_SESSION '
                . '(namanya juga tidak boleh memuat ":")',
            $status === 502 => 'server gagal menanyakan nomor ke WhatsApp — itu bukan berarti '
                . 'nomornya tidak terdaftar',
            str_contains($detail, 'não existe') => 'nomor tujuan tidak terdaftar di WhatsApp',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }

    /**
     * Detail error WPPConnect bersarang di `error.text` / `error.message`.
     *
     * `client.sendFile()` dan kawan-kawannya melempar objek biasa
     * (`{erro: true, to, text}`) yang lalu diteruskan apa adanya oleh
     * `returnError()`; penolong baku akan mengubahnya menjadi JSON mentah,
     * padahal yang berguna hanya teksnya.
     */
    protected function detail(?array $body): string
    {
        $error = $body['error'] ?? null;

        if (\is_array($error)) {
            $text = Text::first($error['text'] ?? null, $error['message'] ?? null);

            if ($text !== '') {
                return $text;
            }
        }

        return parent::detail($body);
    }
}
