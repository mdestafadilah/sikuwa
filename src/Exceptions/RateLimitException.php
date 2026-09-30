<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * HTTP 429 — kena batas laju gateway. Satu-satunya status yang aman diretry.
 *
 * `Retry-After` dari gateway dibawa oleh {@see ApiException::getRetryAfter()}
 * di kelas dasar, karena 503 juga sering menyertakannya.
 *
 * SDK memakai nilai itu untuk mencoba ulang sendiri di dalam satu batch
 * (lihat {@see \Sikuwa\Whatsapp\Providers\AbstractProvider::withRetry()}),
 * tetapi exception ini tetap dilempar bila percobaan ulangnya gagal — jadi
 * pemanggil yang menangani batch besar tetap bisa membaca `getRetryAfter()`
 * dan mengatur jadwalnya sendiri.
 */
class RateLimitException extends ApiException
{
}
