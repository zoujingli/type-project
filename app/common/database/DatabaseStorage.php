<?php

declare(strict_types=1);

namespace app\common\database;

use app\common\bootstrap\Settings;
use InvalidArgumentException;
use Type\Core\Config\Repository;

/** SQLite 文件的应用生命周期；不包含任何 PDO 驱动，其他驱动不会调用此模块。 */
final class DatabaseStorage
{
    /**
     * 相对路径以运行根解释，拒绝跨命令无法共享的内存库，不读取或创建文件。
     *
     * @throws InvalidArgumentException 数据路径为空、指向目录或不符合本地文件约定。
     */
    public static function file(Repository $configuration, string $basePath): string
    {
        $filename = $configuration->text('database.sqlite_file');
        if ($filename === '' || $filename === ':memory:' || str_contains($filename, "\0") || str_ends_with($filename, '/')) {
            throw new InvalidArgumentException('DB_SQLITE_FILE 必须为本地文件路径，应用模板不使用内存库');
        }
        $resolved = Settings::absolutePath($filename) ? $filename : $basePath . '/' . $filename;
        if (is_dir($resolved)) {
            throw new InvalidArgumentException('DB_SQLITE_FILE 不能指向目录');
        }

        return $resolved;
    }

    /**
     * 仅显式 migrate run 调用，创建私有父目录并保留已有数据库与迁移记录。
     *
     * @throws InvalidArgumentException 数据路径无效或无法创建父目录。
     */
    public static function prepare(Repository $configuration, string $basePath): void
    {
        $directory = dirname(self::file($configuration, $basePath));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('无法创建 SQLite 数据目录');
        }
    }

    /**
     * 非初始化命令拒绝隐式建库；文件存在不表示用户表已完成迁移。
     *
     * @throws InvalidArgumentException 数据库文件不存在，需要先显式 migrate run。
     */
    public static function requireExisting(Repository $configuration, string $basePath): void
    {
        if (!is_file(self::file($configuration, $basePath))) {
            throw new InvalidArgumentException('SQLite 应用库尚未初始化，请先运行 migrate run');
        }
    }
}
