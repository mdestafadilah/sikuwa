<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * Gateway menjawab, tapi menolak pesannya.
 *
 * Membawa status HTTP dan body hasil decode, jadi pemanggil bisa memeriksa
 * detailnya tanpa harus mengurai ulang pesan exception. Pakai subclass bernama
 * untuk status yang umum, atau bercabang pada {@see getStatus()}.
 *
 * Perlu dicatat: beberapa gateway membalas HTTP 200 walau pesannya gagal
 * (Fonnte memakai `status`, wuzapi memakai `success`). Kegagalan seperti itu
 * tetap dilaporkan sebagai ApiException, dengan status dari amplop body.
 */
class ApiException extends WhatsappException
{
    /**
     * @param mixed $body
     * @param int|null $retryAfter Detik yang diminta gateway lewat header
     *                             `Retry-After`, bila ada. Disimpan di kelas
     *                             dasar, bukan hanya di {@see RateLimitException},
     *                             karena 503 juga sering menyertakannya — dan
     *                             {@see \Sikuwa\Whatsapp\Providers\AbstractProvider::withRetry()}
     *                             perlu membacanya dari kedua status itu.
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly mixed $body = null,
        private readonly ?string $errorKind = null,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /** Penanda jenis error dari gateway, bila ada (mis. `error` pada amplop). */
    public function getErrorKind(): ?string
    {
        return $this->errorKind;
    }

    /**
     * Detik yang diminta gateway sebelum percobaan berikutnya.
     *
     * Null berarti gateway tidak mengirim `Retry-After` — pemanggil yang harus
     * memutuskan jedanya sendiri, karena menebak terlalu cepat hanya akan
     * menabrak dinding yang sama lagi.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Bangun subclass paling spesifik untuk sebuah status HTTP.
     *
     * @param mixed $body
     * @param int|null $retryAfter Nilai header `Retry-After` dalam detik, bila ada.
     */
    public static function classify(
        int $status,
        string $message,
        mixed $body = null,
        ?string $errorKind = null,
        ?\Throwable $previous = null,
        ?int $retryAfter = null
    ): self {
        return match ($status) {
            401 => new AuthException($message, $status, $body, $errorKind, $previous, $retryAfter),
            403 => new ForbiddenException($message, $status, $body, $errorKind, $previous, $retryAfter),
            404 => new NotFoundException($message, $status, $body, $errorKind, $previous, $retryAfter),
            409 => new ConflictException($message, $status, $body, $errorKind, $previous, $retryAfter),
            429 => new RateLimitException($message, $status, $body, $errorKind, $previous, $retryAfter),
            503 => new ServiceUnavailableException($message, $status, $body, $errorKind, $previous, $retryAfter),
            default => new self($message, $status, $body, $errorKind, $previous, $retryAfter),
        };
    }
}
