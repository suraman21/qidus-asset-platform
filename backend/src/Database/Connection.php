<?php
declare(strict_types=1);

namespace QidusAsset\Database;

use PDO;
use RuntimeException;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string)qa_config('db.host', '127.0.0.1');
        $port = (int)qa_config('db.port', 3306);
        $name = (string)qa_config('db.name', 'qidus_asset');
        $user = (string)qa_config('db.user', 'root');
        $pass = (string)qa_config('db.pass', '');
        $charset = (string)qa_config('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return self::$pdo;
    }

    /**
     * Allow injecting a test PDO instance (e.g., in integration tests).
     */
    public static function setForTesting(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Execute callback inside an atomic transaction.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::get();
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
