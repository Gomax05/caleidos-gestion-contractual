<?php
declare(strict_types=1);
namespace Caleidos\Core;
use PDO;
use RuntimeException;
final class Database
{
    private PDO $connection;
    public function __construct(private array $config) {
        if ($config['driver'] === 'sqlite') {
            if (!is_dir(dirname($config['sqlite_path']))) mkdir(dirname($config['sqlite_path']), 0775, true);
            $this->connection = new PDO('sqlite:' . $config['sqlite_path']);
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA busy_timeout = 5000');
        } elseif ($config['driver'] === 'sqlsrv') {
            if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Instala PDO_SQLSRV para conectar SQL Server.');
            $this->connection = new PDO($config['sqlsrv_dsn'], $config['username'], $config['password']);
        } else { throw new RuntimeException('Motor de base de datos no admitido.'); }
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    public function connection(): PDO { return $this->connection; }
    public function driver(): string { return $this->config['driver']; }
}
