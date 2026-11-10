<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * A key store backed by a temporary SQLite file, so memory stays flat for any row count.
 */
final class SqliteKeyStore implements KeyStore
{
    private const COMMIT_EVERY = 1000;

    private ?PDO $pdo;

    private PDOStatement $insert;

    private PDOStatement $select;

    private string $path;

    private int $pending = 0;

    public function __construct(?string $directory = null)
    {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'spreadsheet-keys-');

        if ($path === false) {
            throw new RuntimeException('Cannot create the key store file.');
        }

        $this->path = $path;
        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = OFF');
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA cache_size = -2000');
        $this->pdo->exec('CREATE TABLE seen (rule TEXT NOT NULL, key TEXT NOT NULL, row INTEGER NOT NULL, PRIMARY KEY (rule, key)) WITHOUT ROWID');

        $this->insert = $this->pdo->prepare('INSERT OR IGNORE INTO seen (rule, key, row) VALUES (?, ?, ?)');
        $this->select = $this->pdo->prepare('SELECT row FROM seen WHERE rule = ? AND key = ?');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function firstSeen(string $ruleId, string $key, int $rowNumber): ?int
    {
        $pdo = $this->pdo ?? throw new RuntimeException('The key store is closed.');

        if ($this->pending === 0) {
            $pdo->beginTransaction();
        }

        $this->insert->execute([$ruleId, $key, $rowNumber]);
        $inserted = $this->insert->rowCount() === 1;

        if (++$this->pending >= self::COMMIT_EVERY) {
            $pdo->commit();
            $this->pending = 0;
        }

        if ($inserted) {
            return null;
        }

        return $this->lookup($ruleId, $key);
    }

    public function seen(string $ruleId, string $key): ?int
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The key store is closed.');
        }

        return $this->lookup($ruleId, $key);
    }

    private function lookup(string $ruleId, string $key): ?int
    {
        $this->select->execute([$ruleId, $key]);
        $row = $this->select->fetchColumn();
        $this->select->closeCursor();

        return $row === false ? null : (int) $row;
    }

    public function close(): void
    {
        if ($this->pdo === null) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        unset($this->insert, $this->select);
        $this->pdo = null;

        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
