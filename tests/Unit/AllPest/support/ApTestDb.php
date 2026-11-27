<?php
declare(strict_types=1);

/**
 * In-memory SQLite stand-in for NuDatabase, exposing the subset of its API that All Pest services use
 * (query, fetchOne, fetchAll, insert, update, delete, exec, lastInsertId, transactions, getDriverName, getPdo).
 */
final class ApTestDb
{
    public PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function getDriverName(): string { return 'sqlite'; }
    public function getPdo(): PDO { return $this->pdo; }
    public function exec(string $sql) { return $this->pdo->exec($sql); }

    public function query($sql, $params = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function fetchOne($sql, $params = [])
    {
        $r = $this->query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function fetchAll($sql, $params = []) { return $this->query($sql, $params)->fetchAll(); }

    public function insert($table, $data)
    {
        $cols = implode(', ', array_keys($data));
        $ph = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$table} ({$cols}) VALUES ({$ph})", array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $sets = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($data)));
        return $this->query("UPDATE {$table} SET {$sets} WHERE {$where}", array_merge(array_values($data), $whereParams))->rowCount();
    }

    public function delete($table, $where, $params = []) { return $this->query("DELETE FROM {$table} WHERE {$where}", $params)->rowCount(); }
    public function lastInsertId() { return (int)$this->pdo->lastInsertId(); }
    public function beginTransaction() { $this->pdo->beginTransaction(); }
    public function commit() { $this->pdo->commit(); }
    public function rollback() { $this->pdo->rollBack(); }
}
