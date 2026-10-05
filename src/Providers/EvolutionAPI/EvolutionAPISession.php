<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\EvolutionAPI;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Instance Evolution API — menyusun payload `POST /instance/create` dan
 * menormalkan balasan menjadi {@see Session}.
 *
 * Baik `POST /instance/create` maupun `GET /instance/connectionState/{instance}`
 * membungkus datanya di dalam kunci `instance`, jadi satu penormal cukup untuk
 * keduanya. Bedanya hanya nama field status: create memakai `status`, sedangkan
 * connectionState memakai `state` — dengan nilai yang sama
 * (`open`, `connecting`, `close`, `refused`).
 */
final class EvolutionAPISession
{
    /** State Evolution API yang berarti sesi siap mengirim pesan. */
    public const CONNECTED = 'open';

    /**
     * Payload untuk POST /instance/create.
     *
     * @param array<string,mixed> $options     Kunci yang dikenali: `instanceName`
     *                                         (alias `name`), `token`, `integration`,
     *                                         `webhook`, `events`, `qrcode`.
     * @param string              $defaultName Dipakai kalau nama tidak diberikan —
     *                                         biasanya dari `WHATSAPP_INSTANCE`.
     * @return array<string,mixed>
     *
     * @throws ConfigurationException
     */
    public static function payload(array $options, string $defaultName = ''): array
    {
        $name = Text::first($options['instanceName'] ?? null, $options['name'] ?? null, $defaultName);

        if ($name === '') {
            throw new ConfigurationException(
                "Evolution API membutuhkan nama instance: isi WHATSAPP_INSTANCE atau kirim ['instanceName' => ...]"
            );
        }

        // Evolution menolak nama bersimbol ("use only non-accented lowercase
        // alphabetic characters or numbers"). Menangkapnya di sini membuat
        // kesalahannya jelas, bukan HTTP 400 yang harus ditebak.
        if (preg_match('/^[a-z0-9]+$/', $name) !== 1) {
            throw new ConfigurationException(
                "Nama instance Evolution API hanya boleh huruf kecil dan angka, diberikan: {$name}"
            );
        }

        $payload = [
            'instanceName' => $name,
            'qrcode' => (bool) ($options['qrcode'] ?? true),
        ];

        foreach (['token', 'integration', 'webhook'] as $key) {
            $value = Text::of($options[$key] ?? null);

            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        if (\is_array($options['events'] ?? null) && $options['events'] !== []) {
            $payload['events'] = $options['events'];
        }

        return $payload;
    }

    /**
     * Terima balasan `GET /instance/fetchInstances` — daftar seluruh instance.
     *
     * Berbeda dari endpoint sesi tunggal, `fetchInstances` mengembalikan
     * rekaman Prisma langsung tanpa amplop `instance`. Field-nya juga beda:
     * `name` (bukan `instanceName`), `connectionStatus` (bukan `state`), dan
     * `number` (bukan `owner`). Tetapi karena beberapa versi mungkin masih
     * membungkus tiap entri di `instance`, keduanya dikenali.
     *
     * @param array<string,mixed> $body
     * @return array<int,Session>
     */
    public static function fromListResponse(array $body): array
    {
        $items = \array_is_list($body) ? $body : ($body['data'] ?? []);

        $sessions = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            // `fetchInstances` bisa membalas {instance: {...}} atau objek telanjang.
            $data = isset($item['instance']) && \is_array($item['instance'])
                ? $item['instance']
                : $item;

            $sessions[] = self::fromInstance($data);
        }

        return $sessions;
    }

    /**
     * Normalkan satu rekaman instance dari `fetchInstances`.
     *
     * Nama field-nya berbeda dari {@see self::fromResponse()}: `name` bukan
     * `instanceName`, `connectionStatus` bukan `state`, `number` bukan
     * `owner`. Keduanya dibaca berurutan supaya method ini bekerja apa pun
     * bentuk responsnya.
     *
     * @param array<string,mixed> $data
     */
    private static function fromInstance(array $data): Session
    {
        $status = Text::first(
            $data['connectionStatus'] ?? null,
            $data['state'] ?? null,
            $data['status'] ?? null,
        );

        return new Session(
            provider: EvolutionAPI::NAME,
            id: Text::first($data['instanceName'] ?? null, $data['name'] ?? null),
            status: $status,
            connected: Text::equalsAny($status, self::CONNECTED),
            token: Text::of($data['token'] ?? null),
            phoneNumber: Text::first($data['number'] ?? null, $data['owner'] ?? null),
            profileName: Text::first($data['profileName'] ?? null, $data['profile_name'] ?? null),
            raw: $data,
        );
    }

    /**
     * Terima balasan `POST /instance/create` maupun
     * `GET /instance/connectionState/{instance}`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $instance = \is_array($body['instance'] ?? null) ? $body['instance'] : [];
        $qrcode = \is_array($body['qrcode'] ?? null) ? $body['qrcode'] : [];

        $status = Text::first($instance['state'] ?? null, $instance['status'] ?? null);

        // `POST /instance/create` menerbitkan API key instance di `hash` —
        // sebagian versi mengirim objek `{apikey: ...}`, sebagian string.
        $hash = $body['hash'] ?? null;

        return new Session(
            provider: EvolutionAPI::NAME,
            id: Text::first($instance['instanceName'] ?? null, $fallbackId),
            status: $status,
            connected: Text::equalsAny($status, self::CONNECTED),
            // Saat instance masih `close`, QR-nya ada di dalam blok `qrcode`;
            // `GET /instance/connect` mengembalikannya di akar body.
            qr: Qr::dataUri(Text::first($qrcode['base64'] ?? null, $body['base64'] ?? null)),
            token: \is_array($hash) ? Text::of($hash['apikey'] ?? null) : Text::of($hash),
            phoneNumber: Text::of($instance['owner'] ?? null),
            profileName: Text::first($instance['profileName'] ?? null, $instance['profile_name'] ?? null),
            raw: $body,
        );
    }
}
