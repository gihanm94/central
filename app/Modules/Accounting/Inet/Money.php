<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

/** Amount helpers: round half up to 2 places, written without thousands separators. */
final class Money
{
    public static function r(float $v, int $p = 2): float
    {
        $f = 10 ** $p;

        return round(($v < 0 ? $v - 1e-9 : $v + 1e-9) * $f) / $f;
    }

    public static function s(float $v, int $p = 2): string { return number_format(self::r($v, $p), $p, '.', ''); }

    /** 1,234.50 for the PDF. */
    public static function fmt(mixed $v): string { return number_format((float) $v, 2, '.', ','); }

    /* ------------------------------------------------------------- amount in words (AmountTextUtil) */

    private const CUR = [
        'THB' => ['บาท', 'สตางค์', 'Baht', 'Satang'], 'USD' => ['ดอลลาร์', 'เซนต์', 'Dollar', 'Cent'], 'EUR' => ['ยูโร', 'เซนต์', 'Euro', 'Cent'],
        'GBP' => ['ปอนด์', 'เพนนี', 'Pound', 'Penny'], 'CNY' => ['หยวน', 'เฟิน', 'Yuan', 'Fen'], 'VND' => ['ด่ง', 'เฮ่า', 'Dong', 'Hao'],
    ];
    private const TH_ONES = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    private const TH_POS  = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    public static function words(float $amount, string $cur, bool $thai): string
    {
        $c     = self::CUR[strtoupper($cur)] ?? self::CUR['THB'];
        $abs   = abs(self::r($amount));
        $main  = (int) floor($abs);
        $sub   = (int) round(($abs - $main) * 100);
        if ($thai) {
            if ($main === 0 && $sub === 0) { return 'ศูนย์'.$c[0].'ถ้วน'; }

            return ($amount < 0 ? 'ลบ' : '').($main ? self::thNumber($main).$c[0] : '').($sub ? self::thNumber($sub).$c[1] : '').'ถ้วน';
        }
        if ($main === 0 && $sub === 0) { return 'Zero '.$c[2].'s Only'; }
        $t = ($amount < 0 ? 'Negative ' : '').($main ? self::enNumber($main).' '.$c[2] : '').($main && $sub ? ' and ' : '').($sub ? self::enNumber($sub).' '.($sub === 1 ? $c[3] : $c[3].'s') : '');

        return $t.' Only';
    }

    private static function thNumber(int $n): string
    {
        if ($n === 0) { return 'ศูนย์'; }
        if ($n >= 1000000) { $r = $n % 1000000; return self::thNumber(intdiv($n, 1000000)).'ล้าน'.($r ? self::thUnder($r) : ''); }

        return self::thUnder($n);
    }

    private static function thUnder(int $n): string
    {
        $d = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $digit = (int) $d[$i];
            $pos   = 5 - $i;
            if ($digit === 0) { continue; }
            if ($pos === 1 && $digit === 2) { $out .= 'ยี่'.self::TH_POS[1]; }
            elseif ($pos === 1 && $digit === 1) { $out .= self::TH_POS[1]; }
            elseif ($pos === 0 && $digit === 1 && $n > 10 && (int) $d[4] !== 0) { $out .= 'เอ็ด'; }
            else { $out .= self::TH_ONES[$digit].self::TH_POS[$pos]; }
        }

        return $out;
    }

    private static function enNumber(int $n): string
    {
        $o = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        if ($n === 0) { return 'Zero'; }
        $parts = [];
        foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'] as $div => $name) {
            if ($n >= $div) { $parts[] = self::enNumber(intdiv($n, $div)).' '.$name; $n %= $div; }
        }
        if ($n >= 100) { $parts[] = $o[intdiv($n, 100)].' Hundred'; $n %= 100; }
        if ($n >= 20) { $parts[] = $t[intdiv($n, 10)].($n % 10 ? '-'.$o[$n % 10] : ''); }
        elseif ($n > 0) { $parts[] = $o[$n]; }

        return implode(' ', $parts);
    }
}
