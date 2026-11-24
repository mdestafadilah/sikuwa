<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\WPPConnect;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Sesi WPPConnect — menyusun payload `POST /start-session` dan menormalkan
 * balasan keadaan sesi menjadi {@see Session}.
 *
 * `start-session` dan `status-session` membalas bentuk yang sama, karena
 * keduanya melewati `getSessionState()` yang sama di sisi server:
 *
 * ```json
 * {"status":"CONNECTED","qrcode":"data:image/png;base64,…","urlcode":"2@…","version":"2.3.6"}
 * ```
 *
 * `status` di sini milik klien WhatsApp-nya sendiri (`CLOSED`, `QRCODE`,
 * `INITIALIZING`, `CONNECTED`, `TIMEOUT`, `CONFLICT`, …), dan hanya
 * `CONNECTED` yang berarti sesi siap mengirim pesan.
 *
 * `qrcode` sudah berupa data URI lengkap (server yang merender lewat
 * `QRCode.toDataURL`), jadi ia dipakai apa adanya. Nilainya kosong selama
 * `urlcode` belum ada — dan karena itu {@see Session::$qr} dibiarkan kosong,
 * bukan diisi teks yang bukan gambar.
 *
 * Sesi yang belum pernah dimulai juga membalas bentuk yang sama, dengan
 * `status: "CLOSED"` — bukan error. Itu keadaan, bukan kegagalan, jadi
 * dilaporkan sebagai sesi yang belum tersambung.
 */
final class WPPConnectSession
{
    /** Satu-satunya status yang berarti sesi siap mengirim pesan. */
    public const CONNECTED = 'CONNECTED';

    /**
     * Id sesi yang dipakai: `$options['session']`, lalu `id`, lalu `name`,
     * lalu konfigurasi.
     *
     * `name` diterima karena gateway lain memakai kunci itu untuk hal yang
     * sama, sehingga kode pemanggil tidak perlu diubah saat berpindah gateway.
     *
     * @param array<string,mixed> $options
     */
    public static function name(array $options, string $defaultName = ''): string
    {
        return Text::first(
            $options['session'] ?? null,
            $options['id'] ?? null,
            $options['name'] ?? null,
            $defaultName
        );
    }

    /**
     * Payload `POST /api/{session}/start-session`.
     *
     * Yang diteruskan hanya kunci yang benar-benar disebut pemanggil: server
     * memakai nilai bawaannya sendiri untuk sisanya, dan mengirim `webhook`
     * kosong akan menghapus webhook yang sudah terpasang di sesi itu.
     *
     * `waitQrCode` sengaja tidak dinyalakan dari sini. Dengan `true`, server
     * baru membalas setelah QR-nya siap — artinya panggilan ini **menunggu**,
     * dan bisa melewati `WHATSAPP_TIMEOUT` yang bawaannya 10 detik. Pemanggil
     * yang memang ingin menunggu bisa menuliskannya sendiri.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `waitQrCode`,
     *                                     `webhook`, `proxy`.
     * @return array<string,mixed>
     */
    public static function payload(array $options): array
    {
        $payload = [];

        if (isset($options['waitQrCode'])) {
            $payload['waitQrCode'] = filter_var($options['waitQrCode'], FILTER_VALIDATE_BOOLEAN);
        }

        $webhook = Text::of($options['webhook'] ?? null);

        if ($webhook !== '') {
            $payload['webhook'] = $webhook;
        }

        if (\is_array($options['proxy'] ?? null) && $options['proxy'] !== []) {
            $payload['proxy'] = $options['proxy'];
        }

        return $payload;
    }

    /**
     * Terima balasan `start-session` maupun `status-session`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromState(array $body, string $fallbackId = ''): Session
    {
        $status = Text::of($body['status'] ?? null);

        return new Session(
            provider: WPPConnect::NAME,
            id: Text::first($body['session'] ?? null, $fallbackId),
            status: $status,
            // Status mentahnya sudah huruf besar dan jelas, jadi tidak ada
            // yang perlu dinormalkan seperti pada gateway lain.
            connected: Text::equalsAny($status, self::CONNECTED),
            qr: Qr::dataUri(Text::of($body['qrcode'] ?? null)),
            raw: $body,
        );
    }
}
