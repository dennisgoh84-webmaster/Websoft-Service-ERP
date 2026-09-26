<?php

namespace App\Models\Concerns;

/**
 * A document line that keeps the order it was keyed in (`line_no`).
 *
 * These line tables have UUID keys, so ordering by id -- what their
 * documents did until 2026-09-26 -- returned the lines in random order:
 * a quotation keyed as "12 hours support, 2 switches" could print the
 * switches first. Found by the self-test (docs/self-test.md). Each new
 * line takes the next number under its document, so every place that
 * creates lines keeps their order without having to say so.
 *
 * The model names its parent's foreign key in LINE_PARENT_KEY.
 */
trait HasLineNumber
{
    protected static function bootHasLineNumber(): void
    {
        static::creating(function ($line) {
            if ($line->line_no === null) {
                $parent = static::LINE_PARENT_KEY;
                $line->line_no = (int) static::query()->where($parent, $line->{$parent})->max('line_no') + 1;
            }
        });
    }
}
