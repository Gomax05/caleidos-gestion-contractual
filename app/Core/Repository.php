<?php
declare(strict_types=1);
namespace Caleidos\Core;
use PDO;
class Repository
{
    public function __construct(protected PDO $db) {}
    public function all(string $sql, array $parameters = []): array {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
    public function one(string $sql, array $parameters = []): ?array { return $this->all($sql, $parameters)[0] ?? null; }
    public function execute(string $sql, array $parameters = []): void { $this->db->prepare($sql)->execute($parameters); }
    public function insert(string $table, array $record): void {
        // Identificadores definidos exclusivamente por código PHP, nunca por entradas del usuario.
        $columns = implode(', ', array_keys($record));
        $marks = implode(', ', array_fill(0, count($record), '?'));
        $this->execute("INSERT INTO $table ($columns) VALUES ($marks)", array_values($record));
    }
    public function driver(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME); }
    public function window(string $sql, int $limit, int $offset = 0): string {
        $limit = max(1, min(200, $limit)); $offset = max(0, $offset);
        return $sql . ($this->driver() === 'sqlsrv' ? " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY" : " LIMIT $limit OFFSET $offset");
    }
    public function transaction(callable $operation) {
        $this->db->beginTransaction();
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
}
