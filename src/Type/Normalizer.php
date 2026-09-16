<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Type;

use Survos\FollowTheMoney\Property;
use Survos\FollowTheMoney\Exception\InvalidData;

final class Normalizer
{
    /** @var array<string, callable> */
    private array $cleaners = [];

    /** @param array<string, mixed> $types */
    public function __construct(private readonly array $types)
    {
    }

    public function register(string $type, callable $cleaner): void
    {
        $this->cleaners[$type] = $cleaner;
    }

    public function clean(Property $property, mixed $raw): ?string
    {
        if (isset($this->cleaners[$property->type])) {
            return ($this->cleaners[$property->type])($raw, $property);
        }
        if ($property->type === 'date' && $property->format !== null) {
            throw new \LogicException('Custom date formats require a registered date cleaner');
        }
        if ($raw === null) {
            return null;
        }
        if (!is_scalar($raw)) {
            throw new InvalidData("Expected scalar for {$property->name}");
        }
        $text = is_bool($raw) ? ($raw ? 'True' : 'False') : (is_float($raw) ? rtrim(rtrim(sprintf('%.3F', $raw), '0'), '.') : (string) $raw);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if ($text === false) {
            return null;
        }
        $text = preg_replace('/^\x{FEFF}|[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F-\x9F\x{2028}\x{2029}\x{200B}-\x{200D}]/u', '', trim($text)) ?? '';
        if ($text === '') {
            return null;
        }
        return match ($property->type) {
            'string', 'text', 'html', 'number', 'checksum' => $text,
            'name' => preg_replace('/\s+/u', ' ', $this->stripQuotes($text)),
            'address' => preg_replace('/\s+/u', ' ', preg_replace('/,\s?[,\.]/', ', ', preg_replace('~\r\n|\n|<BR/>|<BR>|\t|ESQ\.,|ESQ,|;~', ', ', $text))),
            'url' => $this->url($text),
            'entity' => preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $text) ? $text : null,
            'date' => PrefixDate::clean($text),
            'gender' => $this->gender($text),
            'country', 'language', 'topic' => $this->enum($property->type, $text),
            'identifier' => $this->identifier($text, $property->format),
            'email' => $this->email($text),
            default => throw new \LogicException("No normalizer for {$property->type}; register one explicitly"),
        };
    }


    private function stripQuotes(string $text): string
    {
        return preg_replace('/^["\'](.*)["\']$/uD', '$1', $text);
    }

    private function url(string $text): ?string
    {
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $text)) {
            $text = 'http://'.ltrim($text, '/');
        }
        $parts = parse_url($text);
        if ($parts === false || !isset($parts['host']) || (!str_contains($parts['host'], '.') && $parts['host'] !== 'localhost')) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https', 'ftp'], true)) {
            return null;
        }
        if (!isset($parts['path'])) {
            $position = strcspn($text, '?#');
            $text = substr($text, 0, $position).'/'.substr($text, $position);
        }
        return $text;
    }

    private function enum(string $type, string $text): ?string
    {
        $code = mb_strtolower($text);
        return $this->types[$type]['lookup'][$code] ?? (isset($this->types[$type]['values'][$code]) ? $code : null);
    }

    private function gender(string $text): ?string
    {
        $code = mb_strtolower($text);
        $code = match ($code) {
            'm', 'man', 'masculin', 'männlich', 'мужской' => 'male',
            'f', 'woman', 'féminin', 'weiblich', 'женский' => 'female',
            'o', 'd', 'divers' => 'other',
            default => $code,
        };
        return $this->enum('gender', $code);
    }

    private function identifier(string $text, ?string $format): ?string
    {
        if ($format === 'qid') {
            $text = strtoupper(preg_replace('~^https?://(?:www\.)?wikidata\.org/(?:wiki/|entity/)~i', '', $text));
            return preg_match('/^Q[0-9]+$/D', $text) ? $text : null;
        }
        if ($format !== null) {
            throw new \LogicException("No identifier normalizer for {$format}; register an identifier cleaner");
        }
        return $text;
    }

    private function email(string $text): ?string
    {
        $text = $this->stripQuotes($text);
        if (!preg_match('/^([^@\s]+)@([^@\s]+\.\w+)$/uD', $text, $matches)) {
            return null;
        }
        $domain = idn_to_ascii(mb_strtolower($matches[2]));
        return $domain === false ? null : $matches[1].'@'.$domain;
    }
}
