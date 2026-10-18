<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use OutOfBoundsException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Http\HttpRequest;
use Sikuwa\Whatsapp\Http\HttpResponse;
use Sikuwa\Whatsapp\Http\Transport;

/**
 * Gateway palsu untuk pengujian: membalas dari antrean respons dan merekam
 * setiap request yang masuk.
 *
 * Ia mengimplementasikan {@see Transport} — antarmuka yang sama dengan
 * transport cURL bawaan — jadi tidak ada klien HTTP pihak ketiga yang perlu
 * dipalsukan, dan tidak ada jaringan yang tersentuh. Pemasangannya lewat opsi
 * `httpClient`/`transport` pada Client atau lewat {@see self::executor()}.
 */
final class MockBackend implements Transport
{
    /** @var array<int,HttpResponse> */
    private array $queue;

    /** @var array<int,array{request:HttpRequest,response:HttpResponse}> */
    public array $history = [];

    /**
     * @param array<int,HttpResponse> $responses Dibalas berurutan.
     */
    public function __construct(array $responses = [])
    {
        $this->queue = array_values($responses);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->queue === []) {
            // Sama seperti antrean Guzzle dulu: kehabisan balasan adalah
            // kesalahan penyusunan tes, bukan keadaan yang boleh ditelan.
            throw new OutOfBoundsException(
                'MockBackend kehabisan respons untuk ' . $request->getMethod() . ' ' . $request->getUri()
            );
        }

        $response = array_shift($this->queue);

        $this->history[] = ['request' => $request, 'response' => $response];

        return $response;
    }

    public function client(): Transport
    {
        return $this;
    }

    /**
     * Executor yang menembak ke backend ini — untuk menguji provider secara
     * langsung tanpa lewat {@see \Sikuwa\Whatsapp\Client}.
     *
     * @param array<string,string> $defaultHeaders
     */
    public function executor(float $timeout = 10.0, array $defaultHeaders = []): HttpExecutor
    {
        return new HttpExecutor($this, $timeout, $defaultHeaders);
    }

    /** @param array<string,mixed> $body */
    public static function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** Respons 2xx yang body-nya bukan JSON. */
    public static function raw(string $body = '<html>oops</html>', int $status = 200): HttpResponse
    {
        return new HttpResponse($status, $body);
    }

    /**
     * Respons dengan status dan header bebas.
     *
     * Hanya `Retry-After` yang disimpan: itulah satu-satunya header yang
     * dibaca SDK dari respons, jadi menyalin yang lain hanya akan menyesatkan.
     *
     * @param array<string,string> $headers
     */
    public static function response(int $status, array $headers = [], string $body = ''): HttpResponse
    {
        $retryAfter = null;

        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Retry-After') === 0) {
                $retryAfter = (string) $value;
            }
        }

        return new HttpResponse($status, $body, '', false, $retryAfter);
    }

    public static function timeout(): HttpResponse
    {
        return new HttpResponse(
            0,
            null,
            'cURL error 28: Operation timed out after 10000 milliseconds',
            true
        );
    }

    public static function connectionFailure(): HttpResponse
    {
        return new HttpResponse(0, null, 'cURL error 6: Could not resolve host');
    }

    public function count(): int
    {
        return \count($this->history);
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->history === [] ? null : $this->history[array_key_last($this->history)]['request'];
    }

    public function lastBody(): string
    {
        return $this->lastRequest()?->getBody() ?? '';
    }

    /** @return array<string,mixed> */
    public function lastJson(): array
    {
        return json_decode($this->lastBody(), true) ?? [];
    }

    /**
     * Body form-encoded dari request terakhir, sudah di-decode.
     *
     * @return array<string,string>
     */
    public function lastForm(): array
    {
        $form = [];
        parse_str($this->lastBody(), $form);

        /** @var array<string,string> $form */
        return $form;
    }

    public function lastHeader(string $name): string
    {
        return $this->lastRequest()?->getHeaderLine($name) ?? '';
    }
}
