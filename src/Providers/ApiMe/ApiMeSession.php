<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\ApiMe;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Envelope;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Instance ApiMe — menyusun payload `POST /api/instances` dan menormalkan
 * balasan menjadi {@see Session}.
 *
 * Istilah ApiMe untuk "sesi" adalah *instance*. Perlu diingat: membuat
 * instance menuntut token **user** (JWT) atau API token, sedangkan token
 * ber-scope instance ditolak dengan HTTP 403 — lihat catatan di
 * {@see ApiMe}. Memeriksa instance cukup dengan token instance itu sendiri.
 */
final class ApiMeSession
{
    /** Nilai `status`/`state` ApiMe yang berarti sesi siap dipakai. */
    private const CONNECTED_STATES = ['connected', 'open', 'online', 'ready'];

    /**
     * Payload untuk POST /api/instances.
     *
     * @param array<string,mixed> $options     Kunci yang dikenali: `name`,
     *                                         `webhook_url`, `webhook_secret`.
     * @param string              $defaultName Dipakai kalau `name` tidak diberikan —
     *                                         biasanya dari `WHATSAPP_INSTANCE`.
     * @return array<string,mixed>
     *
     * @throws ConfigurationException
     */
    public static function payload(array $options, string $defaultName = ''): array
    {
        $name = Text::first($options['name'] ?? null, $defaultName);

        if ($name === '') {
            throw new ConfigurationException(
                "ApiMe membutuhkan nama instance: isi WHATSAPP_INSTANCE atau kirim ['name' => ...]"
            );
        }

        $payload = ['name' => $name];

        foreach (['webhook_url', 'webhook_secret'] as $key) {
            $value = Text::of($options[$key] ?? null);

            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Terima balasan `GET /api/instances` — daftar seluruh instance.
     *
     * ApiMe selalu membungkus datanya di dalam kunci `data`, jadi daftarnya
     * ada di sana. Setiap entri dinormalkan lewat {@see self::fromResponse()}.
     *
     * @param array<string,mixed> $body
     * @return array<int,Session>
     */
    public static function fromListResponse(array $body): array
    {
        $items = $body['data'] ?? null;

        if (!\is_array($items)) {
            $items = \array_is_list($body) ? $body : [];
        }

        $sessions = [];

        foreach ($items as $item) {
            if (\is_array($item)) {
                $sessions[] = self::fromResponse($item);
            }
        }

        return $sessions;
    }

    /**
     * Terima balasan `POST /api/instances` maupun `GET /api/instances/{id}`.
     *
     * ApiMe tidak memakai amplop seragam, jadi field dibaca dengan beberapa
     * kemungkinan nama sekaligus.
     *
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $data = Envelope::unwrap($body);
        $status = Text::first($data['status'] ?? null, $data['state'] ?? null);

        return new Session(
            provider: ApiMe::NAME,
            id: Text::first($data['id'] ?? null, $data['instance_id'] ?? null, $fallbackId),
            status: $status,
            connected: ($data['connected'] ?? null) === true
                || Text::equalsAny($status, ...self::CONNECTED_STATES),
            qr: Qr::dataUri(Text::of($data['qr'] ?? null)),
            phoneNumber: Text::first(
                $data['phone_number'] ?? null,
                $data['phoneNumber'] ?? null,
                $data['jid'] ?? null
            ),
            profileName: Text::first($data['profile_name'] ?? null, $data['profileName'] ?? null),
            raw: $body,
        );
    }

}
