<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Type;

/** Port of prefixdate 0.6.1 prefix parsing; see resources/PREFIXDATE-LICENSE. */
final class PrefixDate
{
    public static function clean(string $text): ?string
    {
        $regex = '~^\s*((?<year>[12]\d{3}\b)'
            .'(-(?<month>\d{1,2}\b)'
            .'(-(?<day>\d{1,2})'
            .'([T ]'
            .'((?<hour>\d{1,2}\b)'
            .'(:(?<minute>\d{1,2}\b)'
            .'(:(?<second>\d{1,2}\b)'
            .'(\.\d{4,6}\b)?'
            .'(Z|(?<tzsign>[-+])(?<tzhour>\d{2})(:?(?<tzminute>\d{2}\b))'
            .'?)?)?)?)?)?)?)?)?.*~u';
        preg_match($regex, $text, $match, PREG_UNMATCHED_AS_NULL);
        $precision = 19;
        $parts = [];
        foreach ([['year', 1000, 0], ['month', 1, 4], ['day', 1, 7], ['hour', 0, 10], ['minute', 0, 13], ['second', 0, 16]] as [$key, $lowest, $fallback]) {
            $raw = $match[$key] ?? null;
            if ($raw === null || (int) $raw < $lowest) {
                $precision = min($precision, $fallback);
                $parts[$key] = $lowest;
            } else {
                $parts[$key] = (int) $raw;
            }
        }
        if ($precision === 0 || !checkdate($parts['month'], $parts['day'], $parts['year']) || $parts['hour'] > 23 || $parts['minute'] > 59 || $parts['second'] > 59) {
            return null;
        }
        $iso = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', ...array_values($parts));
        if (($match['tzhour'] ?? null) !== null && ($match['tzminute'] ?? null) !== null) {
            $offset = ((int) $match['tzhour'] * 60 + (int) $match['tzminute']) * ($match['tzsign'] === '-' ? -1 : 1);
            if (abs($offset) < 1440) {
                $date = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
                $iso = $date->modify(sprintf('%+d minutes', -$offset))->format('Y-m-d\TH:i:s');
            }
        }
        return substr($iso, 0, $precision);
    }
}
