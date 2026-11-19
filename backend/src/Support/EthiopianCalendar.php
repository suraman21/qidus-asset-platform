<?php
declare(strict_types=1);

namespace QidusAsset\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Ethiopian ↔ Gregorian date helper (aligned with SSMS `ethiopian_date.php`).
 */
final class EthiopianCalendar
{
    public const MONTHS_EN = [
        1 => 'Meskerem', 2 => 'Tikimt', 3 => 'Hidar', 4 => 'Tahsas',
        5 => 'Tir', 6 => 'Yekatit', 7 => 'Megabit', 8 => 'Miazia',
        9 => 'Ginbot', 10 => 'Sene', 11 => 'Hamle', 12 => 'Nehase',
        13 => 'Pagume',
    ];

    public const MONTHS_AM = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ኅዳር', 4 => 'ታኅሣሥ',
        5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
        9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ',
        13 => 'ጳጉሜ',
    ];

    /**
     * @return array{year:int,month:int,day:int,month_en:string,month_am:string}
     */
    public static function fromGregorian(DateTimeImmutable $dt): array
    {
        $y = (int)$dt->format('Y');
        $m = (int)$dt->format('n');
        $d = (int)$dt->format('j');

        $jdn = gregoriantojd_fallback($m, $d, $y);
        $r = ($jdn - 1723856) % 1461;
        $n = ($r % 365) + 365 * (int)floor($r / 1460);

        $ecYear = 4 * (int)floor(($jdn - 1723856) / 1461)
            + (int)floor($r / 365)
            - (int)floor($r / 1460);
        $ecMonth = (int)floor($n / 30) + 1;
        $ecDay = ($n % 30) + 1;

        return [
            'year' => $ecYear,
            'month' => $ecMonth,
            'day' => $ecDay,
            'month_en' => self::MONTHS_EN[$ecMonth] ?? 'Pagume',
            'month_am' => self::MONTHS_AM[$ecMonth] ?? 'ጳጉሜ',
        ];
    }

    /**
     * @return array{year:int,month:int,day:int,month_en:string,month_am:string,formatted_en:string,formatted_am:string}
     */
    public static function today(string $tz = 'Africa/Addis_Ababa'): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($tz));
        $ec = self::fromGregorian($now);
        $ec['formatted_en'] = sprintf('%s %d, %d', $ec['month_en'], $ec['day'], $ec['year']);
        $ec['formatted_am'] = sprintf('%s %d፣ %d', $ec['month_am'], $ec['day'], $ec['year']);
        return $ec;
    }
}

function gregoriantojd_fallback(int $month, int $day, int $year): int
{
    if ($month <= 2) {
        $year -= 1;
        $month += 12;
    }
    $a = (int)floor($year / 100);
    $b = 2 - $a + (int)floor($a / 4);
    return (int)(floor(365.25 * ($year + 4716))
        + floor(30.6001 * ($month + 1))
        + $day + $b - 1524);
}
