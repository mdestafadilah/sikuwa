<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\WPPConnect;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR sesi WPPConnect — menormalkan balasan `GET /api/{session}/qrcode-session`.
 *
 * Endpoint ini **tidak selalu menjawab JSON**. Selama QR-nya ada, servernya
 * mengirim PNG biner langsung (`Content-Type: image/png`, lihat
 * `getQrCode()` di `sessionController.ts`), dan hanya saat QR-nya tidak ada ia
 * menjawab JSON. Karena itu pembungkusnya di sini menerima dua bentuk:
 *
 * - byte PNG → data URI, siap dipasang di atribut `src`;
 * - balasan JSON "sudah tersambung" → sesi `connected` tanpa QR.
 *
 * Balasan JSON yang menyatakan sesi belum dimulai ditangani provider, karena
 * itu bukan QR melainkan petunjuk bahwa `createSession()` belum dipanggil.
 */
final class WPPConnectShowQr
{
    /**
     * Bungkus byte PNG yang dikirim server menjadi data URI.
     *
     * @param string $bytes Isi body apa adanya, bukan base64.
     */
    public static function fromImage(string $bytes, string $fallbackId = ''): Session
    {
        return new Session(
            provider: WPPConnect::NAME,
            id: $fallbackId,
            // Tanpa status: balasan PNG tidak membawa keadaan sesi, dan
            // menebaknya akan menyesatkan. Status sebenarnya ada di
            // checkSession().
            qr: Qr::dataUri(base64_encode($bytes)),
        );
    }

    /**
     * Sesi yang sudah tersambung tidak punya QR untuk dipindai.
     *
     * Server menjawab JSON `{status: "CONNECTED", message: "QRCode is not
     * available..."}` di keadaan itu, dan itu bukan kegagalan.
     *
     * @param array<string,mixed> $body
     */
    public static function alreadyConnected(array $body, string $fallbackId = ''): Session
    {
        return new Session(
            provider: WPPConnect::NAME,
            id: $fallbackId,
            status: Text::of($body['status'] ?? null),
            connected: true,
            raw: $body,
        );
    }
}
