<?php
declare(strict_types=1);

function permitted_google_identity(array $claims, array $allowed): bool
{
    $email = strtolower(trim((string)($claims['email'] ?? '')));
    return !empty($claims['sub']) && ($claims['email_verified'] ?? false) === true
        && str_ends_with($email, '@gmail.com') && empty($claims['hd'])
        && in_array($email, array_map(static fn($e) => strtolower(trim($e)), $allowed), true);
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function checked_text(mixed $value, string $label, int $max, bool $required = true): string
{
    if (!is_string($value)) throw new InvalidArgumentException("$label tidak valid.");
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) throw new InvalidArgumentException("$label wajib diisi dan maksimal $max karakter.");
    return $value;
}

function checked_recipe(array $recipe, array $media): array
{
    if (($recipe['version'] ?? null) !== 1 || !in_array($recipe['orientation'] ?? '', ['landscape', 'portrait'], true)
        || !in_array($recipe['transition'] ?? '', ['cut', 'fade'], true)) throw new InvalidArgumentException('Pengaturan film tidak valid.');
    $items = $recipe['items'] ?? null;
    // Empty drafts are permitted, but the renderer requires at least one item.
    if (!is_array($items) || !array_is_list($items) || count($items) > 30) throw new InvalidArgumentException('Maksimal 30 adegan.');
    $sources = array_column($media, null, 'id'); $duration = 0; $selected = []; $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($sources[$item['mediaId'] ?? ''])) throw new InvalidArgumentException('Media sumber film tidak tersedia.');
        $source = $sources[$item['mediaId']];
        $photo = $item['duration'] ?? null; $start = $item['start'] ?? null; $end = $item['end'] ?? null;
        foreach ([$photo, $start, $end] as $number) if (!is_int($number) && !is_float($number)) throw new InvalidArgumentException('Durasi tidak valid.');
        if ($source['kind'] === 'image') {
            if ($photo < 1 || $photo > 15) throw new InvalidArgumentException('Durasi foto harus 1–15 detik.');
            $duration += $photo;
        } elseif ($source['kind'] === 'video') {
            if ($start < 0 || $end - $start < 1 || !$source['duration'] || $end > (float)$source['duration'] + 0.1) throw new InvalidArgumentException('Potongan video tidak valid.');
            $duration += $end - $start;
        } else throw new InvalidArgumentException('Adegan harus berupa foto atau video.');
        $selected[$source['id']] = (int)$source['size'];
        $normalized[] = ['mediaId' => $source['id'], 'duration' => $photo, 'start' => $start, 'end' => $end];
    }
    $music = $recipe['musicId'] ?? null;
    if ($music !== null) {
        if (!is_string($music) || ($sources[$music]['kind'] ?? '') !== 'audio') throw new InvalidArgumentException('Musik tidak tersedia.');
        $selected[$music] = (int)$sources[$music]['size'];
    }
    if ($recipe['transition'] === 'fade') $duration -= max(0, count($items) - 1) * 0.5;
    if ($duration > 180.01 || array_sum($selected) > 100 * 1024 * 1024) throw new InvalidArgumentException('Film maksimal 3 menit dan sumber maksimal 100 MB.');
    return ['version' => 1, 'items' => $normalized, 'orientation' => $recipe['orientation'], 'transition' => $recipe['transition'], 'musicId' => $music];
}
