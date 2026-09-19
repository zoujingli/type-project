<?php

declare(strict_types=1);

$root = getenv('TYPE_APP_TEST_DIRECTORY') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Type\Testing\Assert;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use Type\Testing\Suite;

/** 仅验收控制器可指定数组命令；参数不经过 shell，端口来自本测试的监听探测。 */
function templateCommand(array $fallback, array $environment, bool $server = false): array
{
    $key = $server && isset($environment['TYPE_APP_SERVER_COMMAND']) ? 'TYPE_APP_SERVER_COMMAND' : 'TYPE_APP_COMMAND';
    if (!isset($environment[$key])) {
        return $fallback;
    }
    $command = json_decode($environment[$key], true, 512, JSON_THROW_ON_ERROR);
    Assert::true(is_array($command) && array_is_list($command) && $command !== [], '应用验收命令必须是非空数组');
    foreach ($command as $index => $argument) {
        Assert::true(is_string($argument) && !str_contains($argument, "\0"), '应用验收命令参数无效');
        if (str_contains($argument, '{{port}}')) {
            $port = $environment['APP_PORT'] ?? '';
            Assert::true(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535, '应用验收发布端口无效');
            $command[$index] = str_replace('{{port}}', $port, $argument);
        }
    }
    return $command;
}

$target = getenv('TYPE_APP_BINARY');
$command = $target === false ? [PHP_BINARY, '-d', 'swoole.enable_library=Off', $root . '/dev.php'] : [$target];
$environment = getenv();
$suite = new Suite();
$suite->test('帮助和检查不连接外部服务', static function () use ($command, $root, $environment): void {
    $isolated = $environment;
    $isolated['DB_HOST'] = '192.0.2.1';
    $isolated['APP_API_TOKEN'] = '';
    $isolated['DB_SQLITE_FILE'] = '/不存在的目录/app.sqlite';
    foreach (['help', 'check'] as $mode) {
        $process = new Process([...templateCommand($command, $isolated), $mode], $root, $isolated);
        try {
            // OCI 创建进程和冷读取运行库也计入预算；离线语义仍由不可达数据库与无效凭据验证。
            $result = $process->wait(isset($isolated['TYPE_APP_COMMAND']) ? 10 : 2);
            Assert::true($result->successful(), '离线命令失败' . ($result->timedOut ? '（启动超时）' : '') . '：' . $result->stderr);
        } finally {
            $process->stop();
        }
    }
});
if ($target === false && !isset($environment['TYPE_APP_COMMAND'])) {
    $suite->test('开发入口读取外部dotenv并保持进程优先级', static function () use ($command, $root, $environment): void {
        $directory = $root . '/build/environment-check-' . bin2hex(random_bytes(6));
        Assert::true(mkdir($directory, 0700, true), '无法建立本轮配置数据根');
        $settings = $environment;
        $settings['APP_BASE_PATH'] = $directory;
        $settings['APP_ENV'] = 'production';
        unset($settings['APP_DEBUG']);
        try {
            file_put_contents($directory . '/.env', "APP_DEBUG=true\nAPP_API_TOKEN=do-not-expose-template-test-secret\n");
            $enabled = new Process([...$command, 'check'], $root, $settings);
            try {
                $result = $enabled->wait(5);
                Assert::true($result->successful() && str_contains($result->stdout, 'development') && str_contains($result->stdout, '调试：on'));
                Assert::true(!str_contains($result->stdout . $result->stderr, 'do-not-expose-template-test-secret'));
            } finally {
                $enabled->stop();
            }
            $settings['APP_DEBUG'] = 'false';
            $overridden = new Process([...$command, 'check'], $root, $settings);
            try {
                $result = $overridden->wait(5);
                Assert::true($result->successful() && str_contains($result->stdout, '调试：off'), '进程环境没有覆盖dotenv');
            } finally {
                $overridden->stop();
            }
            file_put_contents($directory . '/.env', "APP_DEBUG='unterminated-template-test-secret\n");
            $invalid = new Process([...$command, 'check'], $root, $settings);
            try {
                $result = $invalid->wait(5);
                Assert::true(!$result->successful() && str_contains($result->stderr, 'dotenv'), '非法dotenv没有被拒绝');
                Assert::true(!str_contains($result->stdout . $result->stderr, 'unterminated-template-test-secret'));
            } finally {
                $invalid->stop();
            }
            $help = new Process([...$command, 'help'], $root, $settings);
            try {
                Assert::true($help->wait(5)->successful(), '帮助不应要求有效dotenv');
            } finally {
                $help->stop();
            }
            Assert::true(!is_dir($directory . '/var'), '配置检查创建了SQLite数据目录');
        } finally {
            unlink($directory . '/.env');
            rmdir($directory);
        }
    });
}
$suite->test('迁移独立执行并保持版本与历史', static function () use ($command, $root, $environment): void {
    $isolated = $environment;
    $isolated['APP_API_TOKEN'] = '';
    $isolated['REDIS_HOST'] = '192.0.2.1';
    foreach (['run', 'run', 'status', 'history'] as $action) {
        $process = new Process([...templateCommand($command, $isolated), 'migrate', $action], $root, $isolated);
        try {
            $result = $process->wait(10);
            Assert::true($result->successful(), '迁移失败：' . $result->stderr);
            Assert::true(is_array(json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR)));
        } finally {
            $process->stop();
        }
    }
});
$suite->test('真实用户 API、分页、PATCH 和授权隔离', static function () use ($command, $root, $environment): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    Assert::true(is_resource($listener));
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = substr(strrchr($address, ':'), 1);
    $settings = $environment;
    $settings['APP_PORT'] = $port;
    $settings['APP_ALLOWED_HOSTS'] = $address;
    $settings['APP_API_TOKEN'] = str_repeat('test-only-', 4);
    $process = new Process([...templateCommand($command, $settings, true), 'serve'], $root, $settings);
    $client = new HttpClient('http://' . $address, 2);
    $authorization = ['Authorization' => 'Bearer ' . $settings['APP_API_TOKEN'], 'Content-Type' => 'application/json'];
    try {
        $ready = false;
        $deadline = microtime(true) + 5;
        do {
            Assert::true($process->running(), 'HTTP 提前退出：' . $process->stderr());
            try {
                if ($client->request('GET', '/readyz')->status === 200) {
                    $ready = true;
                    break;
                }
            } catch (RuntimeException) {
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        Assert::true($ready, 'HTTP 没有按时就绪：' . $process->stderr());
        $observation = $settings['TYPE_APP_OBSERVE_COMMAND'] ?? null;
        if ($observation !== null) {
            $observer = new Process(json_decode($observation, true, 512, JSON_THROW_ON_ERROR), $root, $settings);
            try {
                $observed = $observer->wait(5);
                Assert::true($observed->successful(), '部署边界检查失败：' . $observed->stderr);
            } finally {
                $observer->stop();
            }
        }
        Assert::same(401, $client->request('GET', '/users')->status);
        Assert::same(401, $client->request('GET', '/')->status);
        Assert::same(200, $client->request('GET', '/', $authorization)->status, '一级控制器没有静态注册');
        if (isset($settings['TYPE_TEMPLATE_EXPECTED_MESSAGE'])) {
            Assert::same($settings['TYPE_TEMPLATE_EXPECTED_MESSAGE'], $client->request('GET', '/', $authorization)->json()['message'], '创建项目后的业务修改没有在当前执行入口生效');
        }
        $created = $client->request('POST', '/users', $authorization, '{"name":"模板用户","age":20,"email":"demo@example.test"}');
        Assert::same(201, $created->status, '创建失败：' . $created->body);
        $user = $created->json()['data'];
        $id = $user['id'];
        Assert::true(!array_key_exists('deleted_at', $user));
        $list = $client->request('GET', '/users?page=1', $authorization);
        Assert::same(200, $list->status);
        Assert::same(1, $list->json()['total']);
        $secondCreated = $client->request('POST', '/users', $authorization, '{"name":"排序用户","age":10}');
        Assert::same(201, $secondCreated->status);
        $secondId = $secondCreated->json()['data']['id'];
        $sorted = $client->request('GET', '/users?sort=age&direction=ASC', $authorization);
        Assert::same([$secondId, $id], array_column($sorted->json()['data'], 'id'), '用户排序被固定主键覆盖');
        $filtered = $client->request('GET', '/users?age=20&sort=id&direction=DESC', $authorization);
        Assert::same([$id], array_column($filtered->json()['data'], 'id'), '排序改变了筛选范围');
        Assert::same(422, $client->request('GET', '/users?sort=email', $authorization)->status);
        Assert::same(422, $client->request('GET', '/users?direction=DESC', $authorization)->status);
        Assert::same(422, $client->request('GET', '/users?sort=id&direction=DESC%3B--', $authorization)->status);
        $patched = $client->request('PATCH', '/users/' . $id, $authorization, '{"email":null,"version":1}');
        Assert::same(200, $patched->status, 'PATCH 失败：' . $patched->body);
        Assert::same(null, $patched->json()['data']['email']);
        Assert::same('模板用户', $patched->json()['data']['name']);
        Assert::same(20, $patched->json()['data']['age']);
        Assert::same(409, $client->request('PATCH', '/users/' . $id, $authorization, '{"name":"过期版本","version":1}')->status);
        Assert::same(422, $client->request('POST', '/users', $authorization, '{"name":"","age":-1}')->status);
        Assert::same(400, $client->request('POST', '/users', $authorization, '{bad')->status);
        Assert::same(405, $client->request('PUT', '/users/' . $id, $authorization)->status);
        Assert::same(200, $client->request('DELETE', '/users/' . $id, $authorization)->status);
        Assert::same(404, $client->request('GET', '/users/' . $id, $authorization)->status);
        Assert::same(200, $client->request('DELETE', '/users/' . $secondId, $authorization)->status);
    } finally {
        $result = $process->stop(5);
        Assert::true(!$result->outputExceeded && $result->signal !== 9, 'HTTP 未能正常限时停止：' . $result->stderr);
        if (isset($settings['TYPE_APP_SERVER_COMMAND'])) {
            Assert::true($result->successful(), '部署进程未以成功状态正常停止：' . $result->stderr);
        }
    }
});
$exit = $suite->run();
foreach ($suite->results() as $result) {
    echo ($result['passed'] ? '通过' : '失败') . '：' . $result['name'] . ($result['passed'] ? '' : '，' . $result['message']) . "\n";
}
exit($exit);
