<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Waxum;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Sesi waxum — menyusun payload `POST /sessions` dan menormalkan balasan
 * menjadi {@see Session}.
 *
 * Ketiga endpoint sesi waxum membungkus datanya dengan cara yang berbeda, jadi
 * masing-masing punya penormal sendiri:
 *
 * - `POST /sessions` membalas `{"session": {...}}`;
 * - `GET /sessions/{id}/status` mengirim objeknya langsung di akar body;
 * - `GET /sessions/{id}` mengirim objek sesinya langsung, tanpa amplop.
 *
 * Statusnya sama di ketiganya — `disconnected`, `connecting`, `waiting_for_qr`,
 * `waiting_for_pair_code`, `connected`, `logged_in`. Yang menentukan sesi bisa
 * dipakai mengirim pesan adalah `is_logged_in`, bukan `connected`: websocket
 * bisa hidup tanpa login, dan sebaliknya.
 */
final class WaxumSession
{
    /** Status waxum yang berarti sesi siap mengirim pesan. */
    public const LOGGED_IN = 'logged_in';

    /**
     * Payload untuk POST /sessions.
     *
     * Waxum membuat sendiri id sesinya bila tidak disebutkan, tetapi id yang
     * eksplisit tetap dikirim supaya `WHATSAPP_SESSION` bisa diisi sekali dan
     * sesinya punya nama yang tetap. `WHATSAPP_SESSION` dipakai sebagai
     * cadangan, sama seperti gateway lain.
     *
     * @param array<string,mixed> $options     Kunci yang dikenali: `id`
     *                                         (alias `name` untuk namanya),
     *                                         `webhook`, `device`.
     * @param string              $defaultName Dipakai kalau `id` tidak
     *                                         diberikan — biasanya dari
     *                                         `WHATSAPP_SESSION`.
     * @return array<string,mixed>
     */
    public static function payload(array $options, string $defaultName = ''): array
    {
        $payload = [];

        $id = Text::first($options['id'] ?? null, $options['name'] ?? null, $defaultName);

        if ($id !== '') {
            $payload['id'] = $id;
        }

        $name = Text::of($options['name'] ?? null);

        if ($name !== '') {
            $payload['name'] = $name;
        }

        if (\is_array($options['webhook'] ?? null) && $options['webhook'] !== []) {
            $payload['webhook'] = $options['webhook'];
        }

        if (\is_array($options['device'] ?? null) && $options['device'] !== []) {
            $payload['device'] = $options['device'];
        }

        return $payload;
    }

    /**
     * Terima balasan `POST /sessions`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromCreate(array $body, string $fallbackId = ''): Session
    {
        $session = \is_array($body['session'] ?? null) ? $body['session'] : [];

        return self::toSession($session, $body, $fallbackId);
    }

    /**
     * Terima balasan `GET /sessions/{id}/status` maupun `GET /sessions/{id}`.
     *
     * `status` mengirim `is_logged_in` secara eksplisit; endpoint detail sesi
     * tidak, dan di sana statusnya disimpulkan dari `status`. Keduanya dibaca
     * lewat penanda yang sama supaya pemanggil tidak perlu tahu endpoint mana
     * yang dipanggil.
     *
     * @param array<string,mixed> $body
     */
    public static function fromStatus(array $body, string $fallbackId = ''): Session
    {
        return self::toSession($body, $body, $fallbackId);
    }

    /**
     * @param array<string,mixed> $data     Objek sesi, dari mana pun ia datang
     * @param array<string,mixed> $envelope Amplop asli, untuk {@see Session::$raw}
     */
    private static function toSession(array $data, array $envelope, string $fallbackId): Session
    {
        $status = Text::of($data['status'] ?? null);

        return new Session(
            provider: Waxum::NAME,
            id: Text::first($data['id'] ?? null, $fallbackId),
            // Status apa adanya: waxum sudah memakai kata yang jelas
            // (`logged_in`, `waiting_for_qr`), jadi tidak ada yang perlu
            // dinormalkan menjadi huruf besar seperti pada gateway lain.
            status: $status,
            connected: ($data['is_logged_in'] ?? null) === true
                || Text::equalsAny($status, self::LOGGED_IN),
            phoneNumber: Text::of($data['phone_number'] ?? null),
            profileName: Text::of($data['push_name'] ?? null),
            raw: $envelope,
        );
    }
}
