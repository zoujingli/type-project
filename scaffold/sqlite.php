<?php

declare(strict_types=1);

namespace app\common\database;

use Type\Core\Config\Repository;
use Type\Orm\Driver;
use Type\Orm\Sqlite\SqliteDriver;

/** 首次安装选择的 SQLite 驱动；其余驱动源码不进入应用生产输入。 */
final class DatabaseFactory
{
    public const NAME = 'sqlite';

    /**
     * 只构造驱动配置；文件目录由显式迁移准备，连接由请求或迁移作用域持有。
     *
     * @throws \Type\Orm\DatabaseException SQLite 路径或驱动配置不满足要求。
     */
    public static function create(Repository $configuration, string $basePath): Driver
    {
        return new SqliteDriver(DatabaseStorage::file($configuration, $basePath), 1000, true);
    }
}
