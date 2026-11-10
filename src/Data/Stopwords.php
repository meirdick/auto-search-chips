<?php

namespace Meirdick\SearchChips\Data;

/**
 * Filler words of a natural-language query. Every leftover word must match a
 * row, so one stray "written" or "show" would empty the results.
 */
final class Stopwords
{
    public const WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'about', 'with', 'for', 'of', 'in', 'on', 'at', 'to', 'from', 'by', 'as',
        'is', 'are', 'was', 'were', 'be', 'been', 'that', 'which', 'who', 'this', 'these', 'those', 'it', 'its',
        'all', 'any', 'some', 'lots', 'many', 'much', 'more', 'most', 'than', 'then', 'only', 'just', 'also', 'not', 'no',
        'show', 'me', 'find', 'get', 'list', 'give', 'search', 'see', 'want', 'my', 'i', 'please',
        // A comparison or direction word that no rule used carries no topic.
        'after', 'before', 'since', 'until', 'above', 'below', 'over', 'under', 'between', 'during', 'least', 'fewer', 'less', 'greater',
        'written', 'posted', 'created', 'made', 'published', 'added', 'ones', 'items', 'things', 'stuff',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return [...self::WORDS, ...array_map('mb_strtolower', (array) config('search-chips.stopwords', []))];
    }

    /** The text without filler words. A quoted phrase never reaches here; it is its own chip. */
    public static function strip(string $text): string
    {
        $stopwords = self::all();

        return implode(' ', array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            fn (string $word) => $word !== '' && ! in_array(mb_strtolower($word), $stopwords, true),
        ));
    }
}
