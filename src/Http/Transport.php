<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Pengantar satu request HTTP ke jaringan.
 *
 * Antarmuka sekecil ini sengaja dipilih supaya SDK tidak terikat pada pustaka
 * HTTP mana pun: bawaannya {@see CurlTransport}, sedangkan pengujian memakai
 * transport palsu yang membalas dari antrean. Provider tidak pernah melihat
 * kelas konkretnya — hanya {@see HttpResponse}.
 *
 * Kontrak yang harus dipatuhi setiap implementasi:
 *
 * - **tidak pernah melempar** untuk kegagalan jaringan; kembalikan
 *   {@see HttpResponse} dengan `$error` terisi;
 * - status 4xx/5xx tetap dianggap "sampai" (`$error` kosong, `$status` terisi);
 * - `Retry-After` diteruskan apa adanya ke `$retryAfter`;
 * - redirect tidak diikuti.
 */
interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
