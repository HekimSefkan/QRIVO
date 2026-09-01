<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Infrastructure\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: no SQL statement may reuse a named placeholder.
 *
 * Why this test is STATIC rather than behavioural: the whole test suite runs on
 * SQLite, which happily binds one named parameter to several occurrences. MySQL
 * with `PDO::ATTR_EMULATE_PREPARES = false` (which is how QRIVO connects — see
 * config/database.php) does NOT: it raises
 *
 *     SQLSTATE[HY093]: Invalid parameter number
 *
 * So a behavioural test could never catch this on the test driver. Two real
 * defects of exactly this shape shipped undetected and were found in Phase 25
 * only by exercising the web client against a live MySQL:
 *
 *   - AttendanceRecordRepository::liveRoster()            `:q`   x3 → HTTP 500 on roster search
 *   - RelationshipRepository::teacherSharesClassWithStudent() `:tid` x2 → teacher wrongly denied
 *
 * The scan looks at each single-quoted SQL literal in the repository layer and
 * fails if any placeholder appears more than once inside it.
 *
 * Known limitation: a statement assembled from several concatenated literals is
 * only checked per-literal. Both real defects had their duplicates within one
 * literal, and keeping each fragment internally consistent is the habit this
 * test enforces.
 */
final class SqlPlaceholderReuseTest extends TestCase
{
    public function test_no_repository_sql_literal_reuses_a_named_placeholder(): void
    {
        $offenders = [];

        foreach ($this->repositoryFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            foreach ($this->methodBodies($source) as $method => $body) {
                // A method issuing several queries has an ambiguous placeholder
                // scope (the same name may legitimately appear in two different
                // statements), so it is skipped rather than falsely flagged.
                if ($this->queryCallCount($body) > 1) {
                    continue;
                }

                $sql = implode(' ', $this->singleQuotedLiterals($body));
                $duplicates = $this->duplicatePlaceholders($sql);

                if ($duplicates !== []) {
                    $offenders[] = sprintf(
                        '%s::%s() → %s',
                        basename($file, '.php'),
                        $method,
                        implode(', ', $duplicates),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Reused named placeholder(s) found. MySQL rejects these with SQLSTATE[HY093]\n"
            . "even though SQLite accepts them. Give each occurrence its own name:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * Method name => body source, for every method in the file.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $source): array
    {
        $bodies = [];
        $offset = 0;

        while (preg_match('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $bracePos = strpos($source, '{', $m[0][1] + strlen($m[0][0]));
            if ($bracePos === false) {
                break;
            }

            // Walk to the matching closing brace.
            $depth = 0;
            $end = $bracePos;
            for ($i = $bracePos, $len = strlen($source); $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) { $end = $i; break; }
                }
            }

            $bodies[$name] = substr($source, $bracePos, $end - $bracePos + 1);
            $offset = $end + 1;
        }

        return $bodies;
    }

    private function queryCallCount(string $body): int
    {
        return preg_match_all('/->(fetchAll|fetchOne|execute|insert|update|query)\s*\(/', $body);
    }

    /** @return list<string> */
    private function singleQuotedLiterals(string $body): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $body, $matches);

        return $matches[1] ?? [];
    }

    /** @return list<string> */
    private function repositoryFiles(): array
    {
        $root = QRIVO_ROOT . '/src/Infrastructure/Repository';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);
        self::assertNotEmpty($files, 'No repository files found to scan.');

        return $files;
    }


    /**
     * Named placeholders occurring more than once in one literal.
     *
     * @return list<string>
     */
    private function duplicatePlaceholders(string $sql): array
    {
        // Ignore `::` (PHP static access) and time-ish `:MM` patterns by
        // requiring a lower-case-led identifier, which is the project convention.
        preg_match_all('/(?<![:\w]):([a-z_][a-zA-Z0-9_]*)\b/', $sql, $matches);

        $counts = array_count_values($matches[1] ?? []);
        $duplicates = [];
        foreach ($counts as $name => $count) {
            if ($count > 1) {
                $duplicates[] = ':' . $name . ' (x' . $count . ')';
            }
        }

        return $duplicates;
    }
}
