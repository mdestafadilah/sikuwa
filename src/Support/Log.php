<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

use Psr\Log\LoggerInterface;

/**
 * Satu-satunya jalan SDK ini menulis ke log.
 *
 * Dipakai dari dua tempat yang tidak berbagi kelas induk: jalur kirim
 * ({@see \Sikuwa\Whatsapp\Providers\AbstractProvider::log()}) dan pemilihan
 * gateway ({@see \Sikuwa\Whatsapp\Client}). Aturannya cuma satu, dan aturan itu
 * terlalu berbahaya untuk disalin dua kali: **logger tidak pernah boleh
 * menggagalkan pengiriman**. Kelas logger yang melempar — mis. karena disk
 * penuh, atau konteksnya tidak sesuai harapannya — akan mengubah kegagalan
 * hiasan menjadi kegagalan pesan.
 *
 * Selama pemanggil tidak memasang logger, tidak ada yang terjadi sama sekali.
 */
final class Log
{
    /**
     * @param string              $level   Level PSR-3: `debug`, `info`,
     *                                     `warning`, atau `error`.
     * @param array<string,mixed> $context Data tambahan, mis. `provider` dan
     *                                     `destination`.
     */
    public static function write(
        ?LoggerInterface $logger,
        string $level,
        string $message,
        array $context = []
    ): void {
        if ($logger === null) {
            return;
        }

        try {
            $logger->log($level, $message, $context);
        } catch (\Throwable) {
            // Sengaja ditelan — lihat penjelasan di atas.
        }
    }
}
