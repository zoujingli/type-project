<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\generated\ProjectConfig;
use InvalidArgumentException;
use Type\Core\Config\Environment;
use Type\Core\Config\Repository;

/**
 * 选择应用数据根并建立本次启动的配置快照。
 *
 * 配置结构来自构建期生成类，运行期只把 .env 当作数据读取，不 require 配置源码。
 */
final class Settings
{
    /**
     * APP_BASE_PATH 由进程环境提供，不能依赖尚未定位的 .env 自己改变所在目录。
     *
     * @throws InvalidArgumentException 指定的应用根不是已存在的绝对目录。
     */
    public static function basePath(): string
    {
        $configured = getenv('APP_BASE_PATH');
        $base = $configured === false ? getcwd() : $configured;
        if (!is_string($base) || !self::absolutePath($base)) {
            throw new InvalidArgumentException('APP_BASE_PATH 必须为已存在的绝对目录');
        }
        $resolved = realpath($base);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('APP_BASE_PATH 必须为已存在的绝对目录');
        }

        return $resolved;
    }

    /**
     * 进程环境覆盖 .env；缺省值由编译过的 config 声明提供，不写回全局环境。
     *
     * @throws InvalidArgumentException 环境数据或配置类型不符合声明。
     */
    public static function load(string $basePath): Repository
    {
        return ProjectConfig::load(Environment::load($basePath . '/.env'));
    }

    /**
     * 区分配置声明的环境与显式开发入口；开发启动器的标记不写回环境或秘密配置。
     *
     * @throws InvalidArgumentException APP_ENV 不是 development 或 production。
     */
    public static function environment(Repository $configuration, bool $developmentEntry): string
    {
        $environment = $configuration->text('app.environment');
        if (!in_array($environment, ['development', 'production'], true)) {
            throw new InvalidArgumentException('APP_ENV 只接受 development 或 production');
        }

        return $developmentEntry ? 'development' : $environment;
    }

    /** 只有开发启动器的显式标记可以开启调试，HTTP 头与生产环境误配不能打开它。 */
    public static function debug(Repository $configuration, bool $developmentEntry): bool
    {
        return $developmentEntry && $configuration->boolean('app.debug');
    }

    /**
     * 识别 Unix、Windows 盘符与 UNC 绝对路径，不把此语法判断当作路径存在或平台支持证明。
     *
     * Windows 的 C:relative 与单个反斜线不是完整绝对路径。
     */
    public static function absolutePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1
            || preg_match('~^\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+(?:[\\\\/]|$)~D', $path) === 1;
    }

    /** @throws InvalidArgumentException 已取得的配置整数不在该用途的允许范围内。 */
    public static function integer(Repository $configuration, string $key, int $minimum, int $maximum): int
    {
        $value = $configuration->integer($key);
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('启动配置整数越界：' . $key);
        }

        return $value;
    }

    /**
     * 解析最多 64 项的配置列表；空字符串表示使用调用方的明确默认值。
     *
     * @return list<string>
     * @throws InvalidArgumentException 列表含空项或超出容量。
     */
    public static function list(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $values = array_map('trim', explode(',', $value));
        if (count($values) > 64 || in_array('', $values, true)) {
            throw new InvalidArgumentException('启动配置列表无效');
        }

        return $values;
    }
}
