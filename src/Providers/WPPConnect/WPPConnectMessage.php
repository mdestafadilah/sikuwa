<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\WPPConnect;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks WPPConnect, sekaligus alamat tujuan yang dipakai bersama
 * oleh semua endpoint-nya.
 *
 * WPPConnect tidak menerima JID berkode seperti gateway lain: ia menerima
 * nomor polos atau JID, lalu menambahkan sufiksnya sendiri lewat
 * `contactToArray()` (`6281234567890` menjadi `…@c.us`). Karena itu yang
 * dikirim di sini adalah nomor yang sudah dinormalkan, atau JID apa adanya
 * bila pemanggil memang mengirim JID.
 *
 * Tiga penanda tujuan (`isGroup`, `isNewsletter`, `isLid`) ikut dikirim bila
 * alamatnya memang berbentuk begitu, dan itu bukan kerapian belaka:
 * middleware `statusConnection` memanggil `checkNumberStatus()` untuk setiap
 * tujuan yang **tidak** ditandai grup/newsletter, jadi mengirim JID grup tanpa
 * `isGroup: true` membuat server menanyakan nomor ke grup — dan permintaannya
 * ditolak sebelum pesannya sempat dikirim.
 */
final class WPPConnectMessage
{
    /** Nomor polos atau JID yang siap dikirim, tanpa sufiks tambahan. */
    public string $phone;

    /** @var array<string,bool> */
    private array $flags;

    public function __construct(string $destination, public readonly string $message)
    {
        $this->phone = self::phone($destination);
        $this->flags = self::flags($destination);
    }

    /** Payload `POST /api/{session}/send-message`. */
    public function toArray(): array
    {
        return ['phone' => $this->phone] + $this->flags + ['message' => $this->message];
    }

    /**
     * Alamat tujuan saja, tanpa isi pesan — untuk endpoint yang tidak
     * mengirim teks (media, indikator ketik).
     *
     * @return array<string,mixed>
     *
     * @throws ConfigurationException
     */
    public static function address(string $destination): array
    {
        return ['phone' => self::phone($destination)] + self::flags($destination);
    }

    /**
     * Nomor atau JID yang bisa dikirim.
     *
     * JID diteruskan apa adanya: memotong sufiksnya akan mengubah alamatnya,
     * dan WPPConnect sendiri yang menambahkan `@c.us` untuk nomor polos.
     *
     * @throws ConfigurationException
     */
    private static function phone(string $destination): string
    {
        $destination = trim($destination);

        $phone = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);

        if ($phone === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        return $phone;
    }

    /**
     * Penanda jenis tujuan, hanya untuk sufiks yang memang dikenali.
     *
     * @return array<string,bool>
     */
    private static function flags(string $destination): array
    {
        $destination = strtolower($destination);

        return match (true) {
            str_ends_with($destination, '@g.us') => ['isGroup' => true],
            str_ends_with($destination, '@newsletter') => ['isNewsletter' => true],
            str_ends_with($destination, '@lid') => ['isLid' => true],
            default => [],
        };
    }
}
