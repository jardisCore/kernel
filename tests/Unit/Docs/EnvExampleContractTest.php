<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * PRD-V2 §4 K.7 — `docs/.env.example` is a CONTRACT, not a loose sample.
 *
 * It replaced the eight per-service `.env.<service>.example` templates with ONE
 * file whose `# === <block> ===` headers carry the grouping the old file names
 * used to carry. The Jardis Builder's runtime catalogue and the App-Template's
 * `bin/sync-env-from-kernel.sh --check` both read it blockwise, so the shape
 * is verified here rather than trusted.
 *
 * The pre-split key inventory is pinned as a constant on purpose: the files it
 * was measured from are deleted, so this is the only remaining record that the
 * merge lost nothing.
 */
final class EnvExampleContractTest extends TestCase
{
    /** Closed vocabulary of block names — the same list the file's own header states. */
    private const ALLOWED_BLOCKS = ['app', 'database', 'redis', 'cache', 'logger', 'http', 'mail', 'messaging'];

    /**
     * Active keys of the eight deleted per-service templates, per file, measured
     * before the merge. The former cascade root and the http template carried no
     * active key at all — the http block is
     * documented entirely in commented examples, and the cascade root only held
     * `load()` directives, which the one-file model has no use for.
     *
     * @var array<string, array<string>>
     */
    private const LEGACY_KEYS_PER_BLOCK = [
        'app' => [],
        'database' => ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_DATABASE', 'DB_CHARSET'],
        'redis' => ['REDIS_HOST', 'REDIS_PORT'],
        'cache' => ['CACHE_LAYERS', 'CACHE_NAMESPACE'],
        'logger' => ['LOG_HANDLERS', 'LOG_CONTEXT', 'LOG_LEVEL'],
        'http' => [],
        'mail' => [
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_ENCRYPTION',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_TIMEOUT',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
        ],
        'messaging' => ['MESSAGING_TRANSPORT', 'RABBITMQ_HOST'],
    ];

    private function examplePath(): string
    {
        return __DIR__ . '/../../../docs/.env.example';
    }

    /**
     * Active `KEY=value` lines, in file order, as `[key, block]` pairs.
     * `$block` is null for a line that sits above every block header.
     *
     * @return array<array{key: string, block: string|null, line: int}>
     */
    private function activeAssignments(): array
    {
        $lines = file($this->examplePath(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'docs/.env.example must exist and be readable');

        $assignments = [];
        $block = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^#\s*===\s*([a-z][a-z0-9]*)\s*===\s*$/', $line, $matches) === 1) {
                $block = $matches[1];
                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!str_contains($trimmed, '=')) {
                continue;
            }

            $assignments[] = [
                'key' => trim(explode('=', $trimmed, 2)[0]),
                'block' => $block,
                'line' => $index + 1,
            ];
        }

        return $assignments;
    }

    public function testEveryBlockHeaderUsesTheClosedVocabulary(): void
    {
        $lines = file($this->examplePath(), FILE_IGNORE_NEW_LINES) ?: [];
        $blocks = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s*===\s*([a-z][a-z0-9]*)\s*===\s*$/', $line, $matches) === 1) {
                $blocks[] = $matches[1];
            }
        }

        self::assertSame(self::ALLOWED_BLOCKS, $blocks, 'every block appears exactly once, in the documented order');
    }

    public function testEveryAssignmentSitsUnderExactlyOneKnownBlock(): void
    {
        foreach ($this->activeAssignments() as $assignment) {
            self::assertNotNull(
                $assignment['block'],
                sprintf('line %d ("%s=") sits above every block header', $assignment['line'], $assignment['key']),
            );
            self::assertContains($assignment['block'], self::ALLOWED_BLOCKS);
        }
    }

    public function testEveryKeyAppearsExactlyOnce(): void
    {
        $keys = array_column($this->activeAssignments(), 'key');

        self::assertSame(array_unique($keys), $keys, 'a key set twice would make its own file ambiguous');
    }

    public function testKeySetPerBlockMatchesTheEightDeletedExampleFiles(): void
    {
        $actual = array_fill_keys(self::ALLOWED_BLOCKS, []);

        foreach ($this->activeAssignments() as $assignment) {
            /** @var string $block */
            $block = $assignment['block'];
            $actual[$block][] = $assignment['key'];
        }

        foreach (self::LEGACY_KEYS_PER_BLOCK as $block => $expected) {
            sort($expected);
            $found = $actual[$block];
            sort($found);

            self::assertSame($expected, $found, sprintf('block "%s" lost or gained a key in the merge', $block));
        }
    }

    public function testRedisKeysLiveOnlyInTheRedisBlock(): void
    {
        foreach ($this->activeAssignments() as $assignment) {
            if (str_starts_with($assignment['key'], 'REDIS_')) {
                self::assertSame('redis', $assignment['block'], 'REDIS_* is set once, in the redis block');
            }
        }
    }

    public function testDatabaseNameKeyIsDbDatabaseNotDbName(): void
    {
        $keys = array_column($this->activeAssignments(), 'key');

        self::assertContains('DB_DATABASE', $keys);
        self::assertNotContains('DB_NAME', $keys, 'the DB_NAME/DB_DATABASE rename is part of the one-file contract');
    }

    public function testAppSecretKeyIsMentionedButNeverActive(): void
    {
        $keys = array_column($this->activeAssignments(), 'key');
        self::assertNotContains(
            'APP_SECRET_KEY',
            $keys,
            'the master key belongs in the process environment only — an entry here would be a plaintext key',
        );

        $contents = file_get_contents($this->examplePath());
        self::assertIsString($contents);
        self::assertStringContainsString(
            'APP_SECRET_KEY',
            $contents,
            'it must still be documented, as a comment, so nobody looks for it elsewhere',
        );
    }

    public function testNoLoadDirectivesRemain(): void
    {
        $lines = file($this->examplePath(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/^load\??\(/',
                $trimmed,
                sprintf('line %d still cascades into another file — one file is the whole point', $index + 1),
            );
        }
    }
}
