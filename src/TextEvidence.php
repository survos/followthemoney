<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney;

use Survos\FollowTheMoney\Exception\InvalidData;

/** Immutable evidence envelope containing a W3C Web Annotation and extraction provenance. */
final readonly class TextEvidence implements \JsonSerializable
{
    public string $exact;
    public string $sourceSha256;

    /** Offsets are Unicode code points, matching Python string offsets, not UTF-8 bytes. */
    public function __construct(
        public string $id,
        public string $entityId,
        public string $source,
        string $text,
        public int $start,
        public int $end,
        public string $extractor,
        public ?float $confidence = null,
    ) {
        if ($start < 0 || $end <= $start || $end > mb_strlen($text, 'UTF-8')) {
            throw new InvalidData('Evidence span is outside source text');
        }
        if ($confidence !== null && (!is_finite($confidence) || $confidence < 0 || $confidence > 1)) {
            throw new InvalidData('Confidence must be between zero and one');
        }
        $this->exact = mb_substr($text, $start, $end - $start, 'UTF-8');
        $this->sourceSha256 = hash('sha256', $text);
    }

    public function jsonSerialize(): array
    {
        return [
            'annotation' => [
                '@context' => 'http://www.w3.org/ns/anno.jsonld',
                'id' => $this->id,
                'type' => 'Annotation',
                'motivation' => 'identifying',
                'body' => ['id' => 'urn:ftm:'.rawurlencode($this->entityId)],
                'target' => [
                    'source' => $this->source,
                    'selector' => [
                        ['type' => 'TextPositionSelector', 'start' => $this->start, 'end' => $this->end],
                        ['type' => 'TextQuoteSelector', 'exact' => $this->exact],
                    ],
                ],
            ],
            'provenance' => [
                'sourceSha256' => $this->sourceSha256,
                'extractor' => $this->extractor,
                'confidence' => $this->confidence,
            ],
        ];
    }
}
