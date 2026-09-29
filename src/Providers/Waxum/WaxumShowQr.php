<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Waxum;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR sesi waxum — menormalkan balasan `GET /sessions/{id}/qr`.
 *
 * Balasannya `{"qr_codes":["2@ABC…"],"timeout_seconds":60,"status":"waiting_for_qr"}`.
 * Perhatikan bahwa `qr_codes` adalah **daftar**, bukan satu nilai: waxum
 * mengganti kodenya berkala, dan yang dikirim adalah yang terbaru. Yang dipakai
 * SDK adalah elemen pertama — daftar itu diurutkan dari yang paling baru.
 *
 * Isi tiap elemen bukan base64, melainkan string mentah yang harus digambar
 * menjadi QR oleh pemanggil. Karena {@see Session::$qr} dijanjikan selalu data
 * URI, string itu dibiarkan kosong kecuali kalau ia memang sudah berupa gambar
 * (base64 atau data URI) — daripada memasang data URI yang isinya bukan PNG dan
 * menghasilkan `<img>` yang rusak. Bentuk mentahnya tetap bisa dibaca lewat
 * {@see Session::$raw}.
 */
final class WaxumShowQr
{
    /**
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $codes = \is_array($body['qr_codes'] ?? null) ? $body['qr_codes'] : [];
        $first = Text::of($codes[0] ?? null);

        return new Session(
            provider: Waxum::NAME,
            id: Text::first($body['id'] ?? null, $fallbackId),
            status: Text::of($body['status'] ?? null),
            connected: false,
            qr: self::dataUri($first),
            raw: $body,
        );
    }

    /**
     * Sesi yang sudah login tidak punya QR untuk dipindai.
     *
     * Dipakai provider saat `qr_codes` kosong: keadaan sesi pada balasan yang
     * sama menentukan apakah itu berarti "sudah selesai" atau "belum siap".
     */
    public static function alreadyConnected(array $body, string $fallbackId = ''): Session
    {
        return new Session(
            provider: Waxum::NAME,
            id: Text::first($body['id'] ?? null, $fallbackId),
            status: Text::of($body['status'] ?? null),
            connected: true,
            raw: $body,
        );
    }

    /**
     * Apakah payload QR ini benar-benar gambar yang bisa dipasang di `src`.
     *
     * Waxum mengirim string mentah untuk digambar sendiri, dan itu bukan
     * gambar. Memasukkannya ke data URI PNG akan menghasilkan gambar rusak
     * yang lebih membingungkan daripada QR yang kosong.
     */
    private static function dataUri(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        if (str_starts_with($payload, 'data:')) {
            return Qr::dataUri($payload);
        }

        // Base64 PNG yang sah selalu dimulai dengan tanda tangan berkas PNG
        // setelah di-decode ("\x89PNG"), yaitu "iVBOR" dalam bentuk base64.
        return str_starts_with($payload, 'iVBOR') ? Qr::dataUri($payload) : '';
    }
}
