<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function init(array $cfg): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Db não inicializado');
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $values = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = ?";
            $values[] = $v;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        $st = self::pdo()->prepare($sql);
        $st->execute(array_merge($values, $whereParams));
        return $st->rowCount();
    }

    public static function getSetting(string $key): ?string
    {
        $row = self::one('SELECT value, is_encrypted FROM settings WHERE key_name = ?', [$key]);
        if (!$row) return null;
        $val = $row['value'];
        if ($row['is_encrypted'] && $val !== '' && $val !== null) {
            $val = Crypto::decrypt($val);
        }
        return $val;
    }

    public static function setSetting(string $key, string $value, bool $encrypted = false): void
    {
        $stored = $encrypted ? Crypto::encrypt($value) : $value;
        self::exec(
            'INSERT INTO settings (key_name, value, is_encrypted) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_encrypted = VALUES(is_encrypted)',
            [$key, $stored, $encrypted ? 1 : 0]
        );
    }
}
