<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Waxum;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks untuk waxum.
 *
 * Kolom `to` menerima JID lengkap apa adanya, atau nomor polos yang akan
 * diubah waxum sendiri menjadi `@s.whatsapp.net` lalu ditranslasi ke `@lid`
 * bila kontaknya sudah memakai mode privasi LID. Karena itu nomornya
 * dinormalkan ke format internasional tanpa tanda plus — sama seperti gateway
 * WhatsMeow lainnya di SDK ini.
 */
final class WaxumMessage
{
    public string $to;

    public function __construct(string $destination, public readonly string $message)
    {
        $this->to = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);
    }

    /** Payload untuk POST /api/v1/sessions/{id}/messages/text */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'text' => $this->message,
        ];
    }
}
