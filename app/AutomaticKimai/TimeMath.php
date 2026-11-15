<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TimeMath
{
    public static function parse(string|DateTimeImmutable $value, ?DateTimeZone $zone = null): DateTimeImmutable
    {
        $date = $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable($value);
        return $zone ? $date->setTimezone($zone) : $date;
    }

    public static function ts(DateTimeImmutable $date): float
    {
        return (float) $date->format('U.u');
    }

    public static function seconds(DateTimeImmutable $a, DateTimeImmutable $b): float
    {
        return self::ts($b) - self::ts($a);
    }

    public static function dayStart(string $day, DateTimeZone $zone): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) {
            throw new InvalidArgumentException('Use YYYY-MM-DD');
        }
        $value = new DateTimeImmutable($day.' 00:00:00', $zone);
        if ($value->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Invalid calendar day');
        }
        return $value;
    }

    public static function dateTime(string $day, string $hhmm, DateTimeZone $zone): DateTimeImmutable
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $hhmm)) {
            throw new InvalidArgumentException('Use HH:MM');
        }
        $value = new DateTimeImmutable($day.' '.$hhmm.':00', $zone);
        if ($value->format('Y-m-d H:i') !== $day.' '.$hhmm) {
            throw new InvalidArgumentException('Invalid local wall-clock time');
        }
        return $value;
    }

    public static function iso(DateTimeImmutable $date): string
    {
        // Python datetime.isoformat() includes fractional microseconds only
        // when present. Preserve them for stable queue IDs and timestamps.
        return $date->format((int)$date->format('u')===0
            ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
    }

    public static function minuteFloor(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime((int) $date->format('H'), (int) $date->format('i'));
    }

    public static function minuteCeil(DateTimeImmutable $date): DateTimeImmutable
    {
        $floor = self::minuteFloor($date);
        return self::ts($floor) < self::ts($date) ? $floor->modify('+1 minute') : $floor;
    }

    /** @param list<array{0:DateTimeImmutable,1:DateTimeImmutable}> $spans */
    public static function union(array $spans): array
    {
        usort($spans, static fn ($a,$b) => self::ts($a[0]) <=> self::ts($b[0]));
        $result = [];
        foreach ($spans as [$start,$end]) {
            if (self::ts($end) <= self::ts($start)) continue;
            $last = count($result) - 1;
            if ($last < 0 || self::ts($start) > self::ts($result[$last][1])) {
                $result[] = [$start,$end];
            } elseif (self::ts($end) > self::ts($result[$last][1])) {
                $result[$last][1] = $end;
            }
        }
        return $result;
    }

    /** @param list<array{0:DateTimeImmutable,1:DateTimeImmutable}> $spans */
    public static function subtract(array $spans, array $barriers): array
    {
        $remaining = $spans;
        foreach (self::union($barriers) as [$x,$y]) {
            $new = [];
            foreach ($remaining as [$a,$b]) {
                if (self::ts($y) <= self::ts($a) || self::ts($x) >= self::ts($b)) {
                    $new[] = [$a,$b];
                    continue;
                }
                if (self::ts($a) < self::ts($x)) $new[] = [$a,$x];
                if (self::ts($y) < self::ts($b)) $new[] = [$y,$b];
            }
            $remaining = $new;
        }
        return self::union($remaining);
    }

    /** @param list<array{0:DateTimeImmutable,1:DateTimeImmutable}> $spans */
    public static function coveredSeconds(array $spans, DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $clipped = [];
        foreach ($spans as [$start,$end]) {
            if (self::ts($end) <= self::ts($a) || self::ts($start) >= self::ts($b)) continue;
            $clipped[] = [self::ts($start) < self::ts($a) ? $a : $start,
                          self::ts($end) > self::ts($b) ? $b : $end];
        }
        // Python reference uses int(total_seconds), truncating sub-second
        // evidence. Rounding to nearest would invent one second at midnight.
        return (int) floor(array_sum(array_map(static fn($span) =>
            self::seconds($span[0],$span[1]), self::union($clipped))));
    }

    public static function validDailyInterval(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $zone): bool
    {
        $start = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);
        if (self::ts($end) <= self::ts($start)) return false;
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) return true;
        $midnight = self::dayStart($start->modify('+1 day')->format('Y-m-d'),$zone);
        return self::ts($end) === self::ts($midnight);
    }
}
