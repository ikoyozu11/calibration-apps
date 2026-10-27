<?php

namespace App\StagingSqlDeployment;

/**
 * Statement-aware PostgreSQL script splitter.
 *
 * Honors line/block comments, single/double quotes, and dollar-quoted bodies
 * so PL/pgSQL BEGIN/END and embedded semicolons are not treated as top-level
 * transaction boundaries.
 */
final class StagingSqlScriptParser
{
    /**
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $i = 0;
        $state = 'normal';
        $dollarTag = null;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'normal') {
                if ($char === '-' && $next === '-') {
                    $state = 'line_comment';
                    $i += 2;

                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $state = 'block_comment';
                    $i += 2;

                    continue;
                }

                if ($char === "'") {
                    $state = 'single_quote';
                    $buffer .= $char;
                    $i++;

                    continue;
                }

                if ($char === '"') {
                    $state = 'double_quote';
                    $buffer .= $char;
                    $i++;

                    continue;
                }

                if ($char === '$') {
                    $tag = $this->readDollarTag($sql, $i);
                    if ($tag !== null) {
                        $state = 'dollar_quote';
                        $dollarTag = $tag;
                        $buffer .= '$'.$tag.'$';
                        $i += strlen($tag) + 2;

                        continue;
                    }
                }

                if ($char === ';') {
                    $buffer .= $char;
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

                continue;
            }

            if ($state === 'line_comment') {
                if ($char === "\n") {
                    $state = 'normal';
                }
                $i++;

                continue;
            }

            if ($state === 'block_comment') {
                if ($char === '*' && $next === '/') {
                    $i += 2;
                    $state = 'normal';

                    continue;
                }
                $i++;

                continue;
            }

            if ($state === 'single_quote') {
                $buffer .= $char;
                if ($char === "'" && $next === "'") {
                    $buffer .= $next;
                    $i += 2;

                    continue;
                }
                if ($char === "'") {
                    $state = 'normal';
                }
                $i++;

                continue;
            }

            if ($state === 'double_quote') {
                $buffer .= $char;
                if ($char === '"' && $next === '"') {
                    $buffer .= $next;
                    $i += 2;

                    continue;
                }
                if ($char === '"') {
                    $state = 'normal';
                }
                $i++;

                continue;
            }

            if ($state === 'dollar_quote') {
                if ($char === '$' && $dollarTag !== null) {
                    $candidate = '$'.$dollarTag.'$';
                    if (substr($sql, $i, strlen($candidate)) === $candidate) {
                        $buffer .= $candidate;
                        $i += strlen($candidate);
                        $state = 'normal';
                        $dollarTag = null;

                        continue;
                    }
                }
                $buffer .= $char;
                $i++;

                continue;
            }

            throw new StagingSqlDeploymentException('SQL parser entered an unknown state.');
        }

        if ($state !== 'normal') {
            throw new StagingSqlDeploymentException('Ambiguous SQL parsing: unclosed quote or comment.');
        }

        $trailing = trim($buffer);
        if ($trailing !== '') {
            throw new StagingSqlDeploymentException('Ambiguous SQL parsing: trailing unterminated statement.');
        }

        return array_values(array_filter(
            $statements,
            static fn (string $statement): bool => ! self::isCommentOnly($statement)
        ));
    }

    public function stripOuterTransactionWrapper(string $sql): string
    {
        $statements = $this->splitStatements($sql);

        if (count($statements) < 2) {
            throw new StagingSqlDeploymentException('SQL artifact is missing a transaction wrapper.');
        }

        $first = $this->normalizeCommand($statements[0]);
        $last = $this->normalizeCommand($statements[array_key_last($statements)]);

        if ($first !== 'BEGIN') {
            throw new StagingSqlDeploymentException('SQL artifact must start with a top-level BEGIN;');
        }

        if ($last !== 'COMMIT') {
            throw new StagingSqlDeploymentException('SQL artifact must end with a top-level COMMIT;');
        }

        $bodyStatements = array_slice($statements, 1, -1);

        if ($bodyStatements === []) {
            throw new StagingSqlDeploymentException('SQL artifact body is empty after wrapper removal.');
        }

        foreach ($bodyStatements as $statement) {
            $this->assertBodyStatementAllowed($statement);
        }

        $beginCount = 0;
        foreach ($statements as $statement) {
            if ($this->normalizeCommand($statement) === 'BEGIN') {
                $beginCount++;
            }
        }

        if ($beginCount !== 1) {
            throw new StagingSqlDeploymentException('SQL artifact must contain exactly one top-level BEGIN;');
        }

        $commitCount = 0;
        foreach ($statements as $statement) {
            if ($this->normalizeCommand($statement) === 'COMMIT') {
                $commitCount++;
            }
        }

        if ($commitCount !== 1) {
            throw new StagingSqlDeploymentException('SQL artifact must contain exactly one top-level COMMIT;');
        }

        return implode("\n\n", $bodyStatements);
    }

    public function assertBodyStatementAllowed(string $statement): void
    {
        $normalized = $this->normalizeCommand($statement);

        if (in_array($normalized, [
            'BEGIN',
            'COMMIT',
            'ROLLBACK',
            'SAVEPOINT',
            'RELEASE',
            'START',
        ], true) || str_starts_with($normalized, 'START TRANSACTION')
            || str_starts_with($normalized, 'BEGIN TRANSACTION')
            || str_starts_with($normalized, 'BEGIN WORK')
            || str_starts_with($normalized, 'COMMIT TRANSACTION')
            || str_starts_with($normalized, 'ROLLBACK TRANSACTION')
            || str_starts_with($normalized, 'ROLLBACK TO')
            || str_starts_with($normalized, 'RELEASE SAVEPOINT')
            || str_starts_with($normalized, 'SAVEPOINT ')) {
            throw new StagingSqlDeploymentException('Nested or unexpected top-level transaction control is not allowed.');
        }

        if (preg_match('/^CREATE\s+INDEX\s+CONCURRENTLY\b/i', $statement) === 1
            || preg_match('/^REINDEX\b/i', $statement) === 1
            || preg_match('/^VACUUM\b/i', $statement) === 1
            || preg_match('/^ALTER\s+SYSTEM\b/i', $statement) === 1
            || preg_match('/^CREATE\s+DATABASE\b/i', $statement) === 1
            || preg_match('/^DROP\s+DATABASE\b/i', $statement) === 1
            || preg_match('/^CREATE\s+TABLESPACE\b/i', $statement) === 1) {
            throw new StagingSqlDeploymentException('Unsupported or transaction-incompatible SQL statement.');
        }
    }

    public function normalizeCommand(string $statement): string
    {
        $withoutTrailing = preg_replace('/;\s*$/', '', trim($statement)) ?? trim($statement);
        $collapsed = preg_replace('/\s+/', ' ', $withoutTrailing) ?? $withoutTrailing;

        return strtoupper($collapsed);
    }

    private function readDollarTag(string $sql, int $offset): ?string
    {
        if (($sql[$offset] ?? '') !== '$') {
            return null;
        }

        $length = strlen($sql);
        $i = $offset + 1;
        $tag = '';

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '$') {
                return $tag;
            }
            if (preg_match('/[A-Za-z0-9_]/', $char) !== 1) {
                return null;
            }
            $tag .= $char;
            $i++;
        }

        return null;
    }

    private static function isCommentOnly(string $statement): bool
    {
        $withoutLine = preg_replace('/--[^\n]*/', '', $statement) ?? $statement;
        $withoutBlock = preg_replace('/\/\*.*?\*\//s', '', $withoutLine) ?? $withoutLine;

        return trim($withoutBlock) === '';
    }
}
