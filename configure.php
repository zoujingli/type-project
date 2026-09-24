<?php

declare(strict_types=1);

// 显式选择生成后的生产驱动；不运行 Composer 安装钩子。
$driver = $argv[1] ?? 'sqlite';
if (count($argv) > 2 || !in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
    fwrite(STDERR, "用法：php configure.php <mysql|pgsql|sqlite>\n");
    exit(1);
}
if (is_dir(__DIR__ . '/vendor') || is_file(__DIR__ . '/composer.lock')) {
    fwrite(STDERR, "请在首次安装之前选择驱动；已有应用应通过代码审查调整依赖和连接配置。\n");
    exit(1);
}
$composer = json_decode(file_get_contents(__DIR__ . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
    unset($composer['require']['zoujingli/type-orm-' . $name]);
}
$composer['require']['zoujingli/type-orm-' . $driver] = '~1.0.0@dev';
foreach ($composer['repositories'] ?? [] as $index => $repository) {
    if (preg_match('~/type-orm-(mysql|pgsql|sqlite)\.git$~', $repository['url'])) {
        $composer['repositories'][$index]['url'] = 'https://github.com/zoujingli/type-orm-' . $driver . '.git';
    }
}
$source = $driver === 'sqlite' ? __DIR__ . '/scaffold/sqlite.php' : __DIR__ . '/scaffold/' . $driver . '.php';
if (!is_file($source)) {
    throw new RuntimeException('模板缺少所选驱动源码');
}
if (!copy($source, __DIR__ . '/app/common/database/DatabaseFactory.php')) {
    throw new RuntimeException('无法配置应用驱动');
}
file_put_contents(__DIR__ . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo '应用已选择 ' . $driver . "，下一步显式运行 Composer 安装。\n";
