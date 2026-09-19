<?php

declare(strict_types=1);

namespace app\common\database;

use app\common\bootstrap\Settings;
use Type\Core\Config\Repository;
use Type\Orm\Driver;
use Type\Orm\Pgsql\PgsqlDriver;

/** 首次安装选择的 PostgreSQL 驱动；其余驱动源码不进入应用生产输入。 */
final class DatabaseFactory
{
    public const NAME = 'pgsql';

    /**
     * 只构造启动配置；此网络驱动不读取 basePath，网络连接由请求或迁移作用域持有。
     *
     * @throws \Type\Orm\DatabaseException 驱动配置或可信 CA 文件无效。
     * @throws \InvalidArgumentException 端口未通过允许范围。
     */
    public static function create(Repository $configuration, string $basePath): Driver
    {
        $port = Settings::integer($configuration, 'database.port', 0, 65535);
        $ca = $configuration->text('database.tls_ca');

        return new PgsqlDriver(
            $configuration->text('database.host'),
            $port === 0 ? 5432 : $port,
            $configuration->text('database.database'),
            $configuration->text('database.username'),
            $configuration->text('database.password'),
            1,
            'writer',
            null,
            null,
            $ca === '' ? null : $ca
        );
    }
}
