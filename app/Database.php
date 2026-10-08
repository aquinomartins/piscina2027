<?php
declare(strict_types=1);
namespace Piscina;
final class Database {
    public \PDO $pdo;
    public function __construct(array $config) {
        $this->pdo = new \PDO($config['dsn'], $config['user'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_STRINGIFY_FETCHES => true]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    public function run(string $sql, array $params = []): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function row(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(); }
    public function now(): string { return $this->row('SELECT UTC_TIMESTAMP(6) AS t')['t']; }
    public function transaction(callable $fn): mixed {
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            try { $result = $fn(); $this->pdo->commit(); return $result; }
            catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($e instanceof \PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205,1213], true) && $attempt < 2) { usleep(random_int(20000,80000)); continue; }
                throw $e;
            }
        }
    }
}
