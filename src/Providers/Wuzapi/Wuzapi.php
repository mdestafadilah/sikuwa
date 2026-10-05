<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wuzapi;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\Presence;

/**
 * Gateway wuzapi (https://github.com/asternic/wuzapi), WhatsApp self-hosted
 * berbasis Go + WhatsMeow.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = Wuzapi
 *   WHATSAPP_TOKEN    = token milik user/sesi (header `Token`)
 *   WHATSAPP_URL_Wuzapi = base URL instance, mis. https://v4.example.com
 *                         (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *
 * Tidak butuh `WHATSAPP_INSTANCE`: tokennya sendiri yang menentukan sesi
 * WhatsApp mana yang dipakai, jadi URL-nya tanpa id instance.
 *
 * Catatan: README wuzapi menyebut endpoint user memakai header
 * `Authorization`. Kodenya tidak demikian — `authalice()` membaca header
 * `token`, sedangkan `Authorization` hanya dibaca `authadmin()` untuk endpoint
 * `/admin`. Yang dipakai di sini adalah yang sesuai kode.
 */
final class Wuzapi extends AbstractProvider
{
    public const NAME = 'Wuzapi';

    public const DEFAULT_URL = 'https://wuzapi.whatsapp.com';

    private string $baseUrl;

    /**
     * @param array{
     *     token?:string, url?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>,
     *     urls?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $this->baseUrl = $this->config->url(self::DEFAULT_URL, self::NAME);
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    protected function authHeaders(): array
    {
        return ['Token' => $this->getToken()];
    }

    /**
     * Sambungkan sesi: `POST /session/connect`.
     *
     * wuzapi tidak punya id sesi — token yang terpasang sudah menentukan sesi
     * mana yang dipakai, jadi tidak ada nama yang perlu dikirim. Bila sesi
     * belum pernah dipindai, wuzapi mulai menghasilkan QR; pantau kesiapannya
     * dengan {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `subscribe`
     *                                     (list jenis event), `immediate` (bool).
     *
     * @throws ApiException
     */
    public function createSession(array $options = []): Session
    {
        $body = $this->postJson("{$this->baseUrl}/session/connect", WuzapiSession::payload($options));

        return WuzapiSession::fromConnect($this->requireSuccess($body));
    }

    /**
     * Baca keadaan sesi: `GET /session/status`.
     *
     * @param string|null $id Diabaikan: wuzapi tidak mengenal id sesi, token
     *                        yang terpasang sudah menentukan sesinya. Diterima
     *                        supaya tanda tangannya sama dengan gateway lain.
     *
     * @throws ApiException
     */
    public function checkSession(?string $id = null): Session
    {
        $body = $this->getJson("{$this->baseUrl}/session/status");

        return WuzapiSession::fromStatus($this->requireSuccess($body));
    }

    /**
     * Wuzapi tidak bisa mendaftar sesi.
     *
     * Token wuzapi sendiri yang menentukan sesi mana yang dipakai — satu
     * token adalah satu sesi, tidak ada endpoint yang mendaftar sesi lain.
     * Karena itu method ini melempar {@see ConfigurationException}, bukan
     * mengembalikan array kosong yang seolah-olah tidak ada sesi sama sekali.
     *
     * @return array<int,Session>
     *
     * @throws ConfigurationException
     */
    public function listSessions(): array
    {
        throw new ConfigurationException(
            'Wuzapi tidak bisa mendaftar sesi: tokennya sendiri yang menentukan sesi mana yang dipakai, '
            . 'jadi satu token adalah satu sesi. Pakai checkSession() untuk memeriksa sesi yang aktif, '
            . 'atau gateway lain untuk mengelola beberapa sesi sekaligus.'
        );
    }

    /**
     * Ambil QR sesi: `GET /session/qr`.
     *
     * wuzapi hanya mengeluarkan QR saat sesinya tersambung ke server WhatsApp
     * tetapi belum login. Ketiga penolakannya dibedakan di sini supaya
     * pemanggil tidak perlu mencocokkan pesan teks: `already logged in`
     * dilaporkan sebagai sesi `connected` tanpa QR — memang tidak ada lagi yang
     * perlu dipindai — sedangkan `no session` dan `not connected` adalah
     * kegagalan sungguhan dan tetap dilempar.
     *
     * @param string|null $id Diabaikan, seperti di {@see self::checkSession()}.
     *
     * @throws ApiException
     */
    public function showQr(?string $id = null): Session
    {
        $response = $this->http->get("{$this->baseUrl}/session/qr", $this->authHeaders());
        $body = $this->read($response);

        // Diperiksa sebelum amplopnya ditolak: wuzapi melaporkan "sudah login"
        // sebagai error HTTP, padahal bagi pemanggil itu keadaan, bukan gagal.
        if ($body !== null && WuzapiShowQr::isAlreadyLoggedIn($body)) {
            return WuzapiShowQr::alreadyLoggedIn($body);
        }

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        return WuzapiShowQr::fromResponse(
            $this->requireSuccess($this->requireJson($body, $response->status), $response->status)
        );
    }

    /**
     * Kirim pesan lewat wuzapi.
     *
     * wuzapi tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu. Jeda antar pesan dihormati lewat kunci `delay` atau, bila tidak
     * diisi, lewat pacing (`WHATSAPP_PACING_*`), sehingga mengirim banyak
     * pesan MEMBLOKIR pemanggil selama total jeda itu. Halaman scan hanya
     * mengirim satu pesan.
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
            fn (WuzapiMessage $message): string => $this->sendText($message),
            static fn (WuzapiMessage $message): string => $message->phone
        );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int,typing:?int}> $items
     * @return array<int,array{message:WuzapiMessage,delay:?int,destination:string,typing:?int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        return $this->buildItems(
            $items,
            static fn (array $item): WuzapiMessage => new WuzapiMessage($item['destination'], $item['message']),
            static fn (WuzapiMessage $message): string => $message->phone
        );
    }

    /**
     * Tampilkan atau hapus indikator "sedang mengetik": `POST /chat/presence`.
     *
     * wuzapi tidak punya keadaan "recording" tersendiri: merekam suara
     * dikirim sebagai `composing` dengan `Media` berisi `audio`. `duration`
     * tidak ikut dikirim — statusnya bertahan sampai dihapus dengan `paused`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendPresence(string $destination, Presence $presence): string
    {
        $phone = $this->target($destination);

        $this->postChat('chat/presence', [
            'Phone' => $phone,
            'State' => $presence->isPaused() ? 'paused' : 'composing',
            // Penanda rekaman suara; string kosong berarti pesan teks biasa.
            'Media' => $presence->isRecording() ? 'audio' : '',
        ]);

        return $this->presenceResult($presence, $phone);
    }

    /** POST /chat/send/text */
    private function sendText(WuzapiMessage $message): string
    {
        $body = $this->sendJson('text', $message->toArray());
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        return 'Sukses, messageId: ' . ($data['Id'] ?? '-');
    }

    /**
     * Kirim satu berkas lewat wuzapi.
     *
     * Ada dua endpoint terpisah — `image` dan `document` — dan keduanya hanya
     * menerima data URI, bukan URL publik: wuzapi tidak mengunduh apa pun
     * sendiri. Dokumen diminta sebagai `octet-stream` apa pun jenis aslinya,
     * jadi jenis berkasnya tidak diteruskan ke sana.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        if ($file->isUrl()) {
            throw new ConfigurationException(
                'wuzapi hanya menerima isi berkas sebagai data URI, bukan URL: '
                . 'unduh berkasnya lebih dulu, lalu kirim isinya sebagai base64 atau data URI'
            );
        }

        $phone = $this->target($destination);

        if ($file->isImage()) {
            $payload = ['Phone' => $phone, 'Image' => $file->dataUri()];

            if ($caption !== '') {
                $payload['Caption'] = $caption;
            }

            $body = $this->sendJson('image', $payload);
        } else {
            $body = $this->sendJson('document', [
                'Phone' => $phone,
                'FileName' => $file->filename,
                // Dokumentasi wuzapi meminta dokumen dikirim sebagai
                // octet-stream, bukan sebagai jenis berkas sebenarnya.
                'Document' => 'data:' . File::DEFAULT_MIME . ';base64,' . $file->base64(),
            ]);
        }

        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        return 'Sukses, messageId: ' . ($data['Id'] ?? '-');
    }

    /**
     * POST JSON ke `/chat/send/<jenis>`, lalu wajibkan penanda `success`.
     *
     * Dipakai bersama pengiriman teks dan berkas: keduanya memakai amplop yang
     * sama, dan wuzapi membalas HTTP 200 bahkan saat gagal.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function sendJson(string $kind, array $payload): array
    {
        return $this->postChat("chat/send/{$kind}", $payload);
    }

    /**
     * POST JSON ke jalur mana pun di bawah `/chat`, lalu wajibkan `success`.
     *
     * Dipisahkan dari {@see self::sendJson()} karena indikator ketik tidak
     * berada di bawah `/chat/send` melainkan di `/chat/presence`, sementara
     * amplop balasan dan cara menolaknya sama persis.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function postChat(string $path, array $payload): array
    {
        $response = $this->http->post(
            "{$this->baseUrl}/{$path}",
            (string) json_encode($payload),
            $this->jsonHeaders()
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        return $this->requireSuccess(
            $this->requireJson($body, $response->status),
            $response->status
        );
    }

    /**
     * wuzapi membalas HTTP 200 pada hampir semua jalur, termasuk yang gagal.
     * Amplopnya sendiri punya penanda `success` yang lebih dipercaya, jadi
     * inilah yang diperiksa — bukan status HTTP-nya.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function requireSuccess(array $body, int $fallbackStatus = 0): array
    {
        if (($body['success'] ?? false) !== true) {
            $status = (int) ($body['code'] ?? $fallbackStatus);

            throw ApiException::classify(
                $status,
                $this->describe($status, $body),
                $body,
                $this->kind($body)
            );
        }

        return $body;
    }

    /**
     * Amplop error wuzapi: `{"code":N,"error":"...","success":false}`
     * (lihat `server.Respond` di handlers.go).
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);

        $konteks = match (true) {
            $status === 401 => 'token salah, cek WHATSAPP_TOKEN',
            str_contains($detail, 'no session') => 'sesi WhatsApp belum tersambung, scan QR di /login',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }
}
