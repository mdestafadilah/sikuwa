<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Hasil satu request HTTP.
 *
 * `$error` hanya terisi bila request gagal di level transport — DNS, timeout,
 * TLS. Respons HTTP 4xx/5xx tetap dianggap "sampai", jadi `$error` kosong dan
 * statusnya ada di `$status`. Pemisahan ini disengaja: penolakan dari gateway
 * perlu diurai per gateway (amplop errornya berbeda-beda), sedangkan kegagalan
 * transport tidak.
 *
 * `$retryAfter` adalah nilai mentah header `Retry-After` bila ada; pakai
 * {@see self::retryAfterSeconds()} untuk membacanya sebagai detik.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $body,
        public readonly string $error = '',
        public readonly bool $timedOut = false,
        public readonly ?string $retryAfter = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    /**
     * Berapa detik pemanggil sebaiknya menunggu sebelum mencoba lagi.
     *
     * Header `Retry-After` datang dalam dua bentuk, dan keduanya diurus di
     * sini supaya provider tidak perlu tahu bedanya:
     *
     * - **detik** (`Retry-After: 30`) — dikembalikan apa adanya;
     * - **tanggal HTTP** (`Retry-After: Wed, 21 Oct 2026 07:28:00 GMT`) —
     *   dikurangi waktu sekarang, karena selisih itulah yang berguna.
     *
     * Null bila headernya tidak ada, tidak terbaca, atau sudah lewat. Nol
     * dikembalikan sebagai null juga: menunggu nol detik sama saja dengan
     * tidak menunggu, dan pemanggil yang memperlakukannya sebagai "coba
     * langsung" akan menabrak dinding yang sama.
     */
    public function retryAfterSeconds(): ?int
    {
        if ($this->retryAfter === null) {
            return null;
        }

        $text = trim($this->retryAfter);

        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            $seconds = (int) $text;

            return $seconds > 0 ? $seconds : null;
        }

        // Bentuk tanggal: apa pun yang tidak bisa diurai dianggap tidak ada,
        // bukan dilempar sebagai error — header rusak bukan alasan menggagalkan
        // pengiriman yang mungkin masih bisa diselamatkan.
        $timestamp = strtotime($text);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Decode body sebagai array. Mengembalikan null kalau body kosong atau
     * bukan JSON objek/list yang valid.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === null || $this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
