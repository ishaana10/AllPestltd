<?php
declare(strict_types=1);

/** Strict numeric parsing for values typed by people: "4.5" is a number; "", ">4", "1,500" and "abc" are errors, never 0 or 1. */
final class ApNum
{
    public static function strict(mixed $v, string $label): float
    {
        if (is_int($v) || is_float($v)) { $s = (string)$v; }
        elseif (is_string($v)) { $s = trim($v); }
        else { throw new InvalidArgumentException("$label must be a number."); }
        if (!preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $s)) { throw new InvalidArgumentException("$label must be a number (digits and one decimal point only)."); }
        $f = (float)$s;
        if (!is_finite($f)) { throw new InvalidArgumentException("$label is out of range."); }
        return $f;
    }
}
