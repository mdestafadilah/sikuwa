<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Envelope;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Sesi OpenWA — menyusun payload `POST /api/sessions` dan menormalkan balasan
 * gateway menjadi {@see Session} yang seragam.
 *
 * Status yang dipakai OpenWA: `CONNECTED`, `DISCONNECTED`, `INITIALIZING`.
 * Sesi yang baru dibuat selalu `INITIALIZING`, jadi belum bisa dipakai kirim.
 */
final class OpenWASession
{
    public const CONNECTED = 'CONNECTED';

    /**
     * Payload untuk POST /api/sessions.
     *
     * @param array<string,mixed> $options   Kunci yang dikenali: `id`, `name`, `config`.
     * @param string              $defaultId Dipakai kalau `id` tidak diberikan —
     *                                       biasanya dari `WHATSAPP_SESSION`.
     * @return array<string,mixed>
     */
    public static function payload(array $options, string $defaultId = ''): array
    {
        $payload = [];

        $id = Text::first($options['id'] ?? null, $defaultId);

        if ($id !== '') {
            $payload['id'] = $id;
        }

        $name = Text::of($options['name'] ?? null);

        if ($name !== '') {
            $payload['name'] = $name;
        }

        // `config` diteruskan apa adanya: kuncinya milik OpenWA
        // (autoReconnect, webhookUrl, proxy) dan bisa bertambah antar versi.
        if (\is_array($options['config'] ?? null) && $options['config'] !== []) {
            $payload['config'] = $options['config'];
        }

        return $payload;
    }

    /**
     * Terima balasan `GET /api/sessions` — daftar seluruh sesi.
     *
     * OpenWA bisa membalas array telanjang `[{...}, ...]` maupun terbungkus
     * `{success, data: [...]}`, sama seperti endpoint sesi tunggal. Setiap
     * entri dinormalkan lewat {@see self::fromResponse()}.
     *
     * @param array<string,mixed> $body
     * @return array<int,Session>
     */
    public static function fromListResponse(array $body): array
    {
        $items = \array_is_list($body) ? $body : ($body['data'] ?? []);

        $sessions = [];

        foreach ($items as $item) {
            if (\is_array($item)) {
                $sessions[] = self::fromResponse($item);
            }
        }

        return $sessions;
    }

    /**
     * Terima balasan `POST /api/sessions` maupun `GET /api/sessions/{id}`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $data = Envelope::unwrap($body);
        $status = strtoupper(Text::of($data['status'] ?? null));

        return new Session(
            provider: OpenWA::NAME,
            id: Text::first($data['id'] ?? null, $fallbackId),
            status: $status,
            connected: $status === self::CONNECTED,
            qr: Qr::dataUri(Text::of($data['qr'] ?? null)),
            phoneNumber: Text::of($data['phoneNumber'] ?? null),
            profileName: Text::first($data['profileName'] ?? null, $data['pushName'] ?? null),
            raw: $body,
        );
    }

}
