<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Database;

/**
 * Splits a `.sql` script into individual executable statements.
 *
 * A naive `explode(';', $sql)` is WRONG for the QRIVO migrations: several of
 * them carry a semicolon inside a string literal, e.g.
 *
 *     COMMENT='Core identity record; password_hash is Argon2id only';
 *
 * so the splitter is a small character scanner that knows about:
 *   - single-quoted strings, with `''` and backslash escapes
 *   - double-quoted strings, with `""` and backslash escapes
 *   - backtick-quoted identifiers
 *   - `-- ` and `#` line comments
 *   - `/* … *\/` block comments (executable `/*! … *\/` comments are kept)
 *
 * It is used only by the migration runner (`backend/scripts/migrate.php`) and
 * the seeder. It never sees user input — migrations are repository files.
 */
final class SqlScriptSplitter
{
    /**
     * @return list<string> non-empty, trimmed statements in file order
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $length     = strlen($sql);
        $i          = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // ── line comments: -- (followed by whitespace/EOL) or # ──────────
            if (($char === '-' && $next === '-' && self::isCommentDash($sql, $i)) || $char === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                $buffer .= "\n";
                continue;
            }

            // ── block comments: /* … */  (but /*! … */ is executable SQL) ────
            if ($char === '/' && $next === '*') {
                $isExecutable = ($i + 2 < $length) && $sql[$i + 2] === '!';
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;

                if ($isExecutable) {
                    $buffer .= substr($sql, $i, $end - $i);
                } else {
                    $buffer .= ' ';
                }
                $i = $end;
                continue;
            }

            // ── quoted regions are copied verbatim, semicolons included ──────
            if ($char === "'" || $char === '"' || $char === '`') {
                $literal = self::readQuoted($sql, $i, $char); // advances $i
                $buffer .= $literal;
                continue;
            }

            // ── statement terminator ─────────────────────────────────────────
            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $trailing = trim($buffer);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return $statements;
    }

    /**
     * True when `--` at $i starts a comment. MySQL requires the double dash to
     * be followed by whitespace or end-of-input; `a--b` is not a comment.
     */
    private static function isCommentDash(string $sql, int $i): bool
    {
        $after = $i + 2 < strlen($sql) ? $sql[$i + 2] : "\n";

        return $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r";
    }

    /**
     * Read a quoted literal/identifier starting at $i (which points at the
     * opening quote). Advances $i past the closing quote and returns the raw
     * text INCLUDING both quotes.
     */
    private static function readQuoted(string $sql, int &$i, string $quote): string
    {
        $length = strlen($sql);
        $out    = $quote;
        $i++; // skip the opening quote

        while ($i < $length) {
            $char = $sql[$i];

            // Backslash escape (MySQL default; NO_BACKSLASH_ESCAPES is not used
            // by these migrations). Not applicable inside backticks.
            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $out .= $char . $sql[$i + 1];
                $i += 2;
                continue;
            }

            // Doubled quote is an escaped quote, not a terminator.
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $out .= $quote . $quote;
                    $i += 2;
                    continue;
                }

                $out .= $quote;
                $i++;

                return $out;
            }

            $out .= $char;
            $i++;
        }

        return $out; // unterminated literal — hand it back and let the server complain
    }
}
