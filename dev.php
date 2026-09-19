<?php

declare(strict_types=1);

// 仅本地开发 CLI；生产执行 build/type-project，禁止以此启动器作源码解释回退。
$root = __DIR__;
try {
    if (!chdir($root)) {
        throw new RuntimeException('无法切换到应用目录');
    }
    $command = $argv[1] ?? 'help';
    $help = in_array($command, ['help', '--help'], true)
        || ($command === 'migrate' && (count($argv) === 2 || (count($argv) === 3 && $argv[2] === 'help')));
    if (!$help) {
        require __DIR__ . '/prepare.php';
        $generation = prepareTypeProject($root);
        foreach ($generation['files'] as $generated) {
            require $generation['directory'] . '/' . $generated;
        }
    } else {
        // help 只声明角色入口，不要求 Composer、开发生成结果或有效 .env。
        require $root . '/app/common/bootstrap/Application.php';
    }
    // 明确的开发权限入参，不通过 putenv 覆盖使用者配置，更不由 HTTP 输入开启。
    app\common\bootstrap\Application::run($argv, true);
} catch (Throwable $error) {
    fwrite(STDERR, '应用开发启动失败：' . $error->getMessage() . "\n");
    exit(1);
}
