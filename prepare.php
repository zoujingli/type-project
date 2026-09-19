<?php

declare(strict_types=1);

/** 保留应用准备入口，实现由type-build统一维护。 */
function prepareTypeProject(string $root): array
{
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('请先执行 composer install');
    }
    require_once $root . '/vendor/autoload.php';
    return (new Type\Build\DevelopmentBuilder())->prepareConfiguration($root . '/type-app.json');
}

// 作为脚本直接运行时输出结果；被 dev.php 加载时仅声明上述开发期函数。
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--json')) {
            throw new InvalidArgumentException('用法：prepare.php [--json]');
        }
        $result = prepareTypeProject(__DIR__);
        if (($argv[1] ?? '') === '--json') {
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo '开发代码已生成，代次：' . $result['generation'] . "\n";
            echo "没有读取 .env、连接数据库或执行迁移。\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, '开发生成失败：' . $error->getMessage() . "\n");
        exit(1);
    }
}
