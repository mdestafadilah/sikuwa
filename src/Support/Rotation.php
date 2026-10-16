<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pembagian batch pesan ke beberapa gateway sekaligus.
 *
 * Berangkat dari satu perilaku `auto` yang mudah terlewat: undian gateway
 * terjadi **sekali per panggilan**, jadi satu `send()` berisi seratus pesan
 * tetap keluar lewat satu gateway saja. Untuk pengiriman massal itu justru
 * pola yang paling cepat menabrak batas laju gateway — semua beban menumpuk di
 * satu perangkat, padahal gateway lain sudah dikonfigurasi dan menganggur.
 *
 * Saat fitur ini menyala, batch dibagi ke **semua** gateway yang punya token:
 *
 * - Urutan gatewaynya **diundi tiap panggilan**, jadi gateway mana yang
 *   mendapat bagian lebih banyak tidak pernah tetap. Rotasi yang urutannya
 *   selalu sama justru lebih mudah dikenali daripada tidak berotasi.
 * - Pembagiannya **bergiliran**, sehingga jumlah pesan tiap gateway berbeda
 *   paling banyak satu. Undian murni per pesan terlihat lebih "acak", tetapi
 *   ia bisa menaruh 90 dari 100 pesan di satu gateway — yang berarti fiturnya
 *   gagal tepat pada satu-satunya alasan ia dinyalakan.
 *
 * Bawaannya **mati**. Selama `WHATSAPP_ROTATE` tidak diisi, `Client::send()`
 * berjalan persis seperti sebelum fitur ini ada.
 *
 * Yang **tidak** diurus kelas ini: kapan rotasi dipakai. Itu keputusan
 * {@see \Sikuwa\Whatsapp\Client} — kelas ini murni aritmetika pembagian, tanpa
 * jaringan dan tanpa konfigurasi lain, supaya bisa diuji sendiri.
 */
final class Rotation
{
    private function __construct(private readonly bool $enabled)
    {
    }

    /** Rotasi yang tidak mengubah apa pun. */
    public static function off(): self
    {
        return new self(false);
    }

    /**
     * Bangun dari isi environment: `WHATSAPP_ROTATE=1`.
     *
     * Apa pun yang tidak dikenali berarti **mati** — sama seperti
     * `WHATSAPP_CHECK_NUMBERS`. Salah tulis satu kunci tidak boleh tiba-tiba
     * memindahkan separuh pesan ke gateway lain.
     */
    public static function fromConfig(mixed $enabled): self
    {
        return new self(filter_var($enabled, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Bangun dari opsi pemanggil, mis. `['enabled' => true]`.
     *
     * Bentuknya sengaja dibuat sama dengan {@see Typing::fromArray()} supaya
     * dua pengaturan yang bersebelahan di `.env` tidak menuntut dua cara
     * penulisan yang berbeda.
     *
     * @param array<string,mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        return new self(filter_var($spec['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN));
    }

    /** Apakah satu batch boleh dibagi ke beberapa gateway. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Bagi `$count` pesan ke `$gateways`, bergiliran setelah diundi.
     *
     * Hasilnya memetakan nama gateway ke **posisi** pesan di batch asal, bukan
     * ke pesannya sendiri: pemanggil yang memegang daftarnya, dan memindahkan
     * isi pesan ke sini hanya akan membuat dua salinan yang bisa berbeda.
     *
     * Urutan kunci hasil mengikuti urutan undian — itulah urutan gateway
     * dipakai mengirim, dan bagian yang kosong tidak pernah muncul. Daftar
     * gateway yang kosong atau satu pesan saja menghasilkan hasil kosong:
     * tidak ada yang bisa dibagi.
     *
     * @param array<int,string> $gateways Nama gateway, tanpa duplikat.
     *
     * @return array<string,array<int,int>> Nama gateway => posisi pesan, menaik.
     */
    public function assign(int $count, array $gateways): array
    {
        $gateways = array_values(array_unique($gateways));

        if ($this->enabled === false || $count <= 0 || $gateways === []) {
            return [];
        }

        shuffle($gateways);

        $slices = [];
        $jumlah = \count($gateways);

        for ($i = 0; $i < $count; $i++) {
            $slices[$gateways[$i % $jumlah]][] = $i;
        }

        return $slices;
    }
}
