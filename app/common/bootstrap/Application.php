<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\database\DatabaseFactory;
use app\common\database\Schema;
use app\common\database\DatabaseStorage;
use app\common\middleware\ApiErrors;
use app\common\middleware\RequestLog;
use app\controller\HomeController;
use app\generated\Routes;
use app\generated\UserOperations;
use app\system\controller\UserController;
use app\system\service\UserService;
use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Type\Core\Config\Repository;
use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\HttpControl;
use Type\Core\Http\HttpServerInterface;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\DatabaseManager;
use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;

/**
 * 组合业务应用的角色、声明与模式；HTTP 业务处理链独立于实际服务器适配器。
 *
 * 开发入口通过显式参数取得开发权限，原生入口不加载开发生成器或源码。
 */
final class Application
{
    /**
     * 帮助不要求配置或生成代码；其余角色读取一次配置，失败以非零进程状态退出。
     *
     * @param list<string> $arguments 包含程序名的完整 CLI 参数。
     */
    public static function run(array $arguments, bool $development = false): void
    {
        $debug = false;
        try {
            $command = $arguments[1] ?? 'help';
            if ($command === 'verify-runtime') {
                if (count($arguments) !== 2 || !class_exists(\Type\Generated\BuildIdentity::class, false)) {
                    throw new InvalidArgumentException('verify-runtime 只接受原生产物且不接受额外参数');
                }
                \Type\Generated\BuildIdentity::verifyDeployment();
                echo "运行环境完整性校验通过。\n";
                return;
            }
            if (class_exists(\Type\Generated\BuildIdentity::class, false)) {
                \Type\Generated\BuildIdentity::verifyRuntime();
            }
            if ($command === 'help' || $command === '--help') {
                if (count($arguments) > 2) {
                    throw new InvalidArgumentException('help 不接受额外参数');
                }
                echo "Type 业务应用：help、check、verify-runtime、serve、migrate <run|status|history|recover>。\n";
                echo "驱动由安装前 configure.php 选择；先显式 migrate run，再设置 APP_API_TOKEN 并 serve。\n";

                return;
            }
            if (!in_array($command, ['check', 'serve', 'migrate'], true)) {
                throw new InvalidArgumentException('未知应用命令，请使用 help');
            }
            if ($command === 'migrate' && (count($arguments) === 2 || (count($arguments) === 3 && $arguments[2] === 'help'))) {
                echo "迁移命令：run、status、history、recover <版本> <retry|applied> <恢复说明>。只有 run 初始化 SQLite 数据目录。\n";

                return;
            }
            $basePath = Settings::basePath();
            $settings = Settings::load($basePath);
            $environment = Settings::environment($settings, $development);
            $debug = Settings::debug($settings, $development);
            if ($command === 'check') {
                if (count($arguments) !== 2) {
                    throw new InvalidArgumentException('check 不接受额外参数');
                }
                echo '应用声明有效，数据库驱动：' . DatabaseFactory::NAME
                    . '；运行模式：' . $environment . '；调试：' . ($debug ? 'on' : 'off')
                    . "；配置、模型、路由与调用包装已静态装配，未连接数据库。\n";

                return;
            }
            if ($command === 'migrate') {
                $exit = self::migrate($settings, $basePath, array_slice($arguments, 2));
                if ($exit !== 0) {
                    exit($exit);
                }

                return;
            }
            if (count($arguments) !== 2) {
                throw new InvalidArgumentException('serve 不接受额外参数，启动值从配置读取');
            }
            self::serve($settings, $basePath, $development);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $publicMessage = $error instanceof InvalidArgumentException || str_starts_with($message, 'dotenv ')
                ? $message : 'internal_error';
            fwrite(STDERR, '应用启动或命令失败：' . $publicMessage . "\n");
            if ($debug) {
                fwrite(STDERR, json_encode([
                    'error' => 'internal_error',
                    'exception_type' => get_class($error),
                    'file' => basename(str_replace('\\', '/', $error->getFile())),
                    'line' => max(0, $error->getLine()),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            exit(1);
        }
    }

    /**
     * 构建与服务器实现无关的 PSR-15 业务处理链；构造驱动但不借用真实数据库连接。
     *
     * 每次实际请求必须由服务器创建执行作用域，响应结束后收回所有请求租约。
     *
     * @throws InvalidArgumentException 模式、令牌、Host、预算或 SQLite 初始化条件无效。
     */
    public static function handler(Repository $settings, string $basePath, bool $development = false): RequestHandlerInterface
    {
        $environment = Settings::environment($settings, $development);
        $debug = Settings::debug($settings, $development);
        $token = $settings->text('app.http.api_token');
        if (strlen($token) < 32 || !preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token)) {
            throw new InvalidArgumentException('APP_API_TOKEN 必须为至少 32 字符的有效 Bearer 令牌');
        }
        $port = Settings::integer($settings, 'app.http.port', 1, 65535);
        $hosts = Settings::list($settings->text('app.http.allowed_hosts'));
        if ($hosts === []) {
            $hosts = ['127.0.0.1:' . $port, 'localhost:' . $port];
        }
        $policy = new RequestPolicy($hosts, Settings::list($settings->text('app.http.trusted_proxies')));
        if (DatabaseFactory::NAME === 'sqlite') {
            DatabaseStorage::requireExisting($settings, $basePath);
        }
        $budget = new DeploymentBudget(
            Settings::integer($settings, 'database.budget.server', 2, 1000000),
            Settings::integer($settings, 'database.budget.replicas', 1, 10000),
            Settings::integer($settings, 'database.budget.surge', 0, 10000),
            Settings::integer($settings, 'database.budget.processes', 1, 10000),
            Settings::integer($settings, 'database.budget.reserve', 0, 100000),
            Settings::integer($settings, 'database.budget.threads', 1, 10000)
        );
        $database = new DatabaseManager(
            ['default' => DatabaseFactory::create($settings, $basePath)],
            4,
            0,
            $budget,
            Settings::integer($settings, 'database.pool.waiters', 0, 65536),
            Settings::integer($settings, 'database.pool.wait_ms', 0, 60000) / 1000.0
        );
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $users = new UserOperations(new UserService());
        Routes::register($router, [
            HomeController::class => static fn (): HomeController => new HomeController($messages),
            UserController::class => static fn (): UserController => new UserController($database, $users, $messages),
        ]);

        return new Pipeline([
            static fn (): RequestLog => new RequestLog($settings->text('app.name')),
            static fn (): ApiErrors => new ApiErrors($messages, $debug, dirname(__DIR__, 3)),
            static fn (): RequestPolicy => $policy,
            static fn (): Authentication => new Authentication(
                static fn (string $provided): ?Identity => hash_equals($token, $provided) ? new Identity('application-api', ['users']) : null,
                static fn (Identity $identity, CanonicalRequest $request, string $method): bool => in_array('users', $identity->roles(), true),
                $messages,
                $messages
            ),
        ], $router);
    }

    /**
     * 独立迁移角色不创建 HTTP 或缓存资源；只有精确 run 命令初始化 SQLite 父目录。
     *
     * @param list<string> $arguments 不含程序名和 migrate 的动作参数。
     * @throws InvalidArgumentException 参数无效或非初始化角色缺少 SQLite 文件。
     */
    public static function migrate(Repository $settings, string $basePath, array $arguments): int
    {
        if (!((count($arguments) === 1 && in_array($arguments[0], ['run', 'status', 'history'], true))
            || (count($arguments) === 4 && $arguments[0] === 'recover'))) {
            throw new InvalidArgumentException('迁移命令参数无效，请使用 migrate help');
        }
        if (DatabaseFactory::NAME === 'sqlite') {
            if ($arguments === ['run']) {
                DatabaseStorage::prepare($settings, $basePath);
            } else {
                DatabaseStorage::requireExisting($settings, $basePath);
            }
        }
        $driver = DatabaseFactory::create($settings, $basePath);

        return (new MigrationConsole(new Migrator($driver), Schema::migrations($driver->name())))->run($arguments);
    }

    /** 返回服务器可复用的有界输入声明，不打开临时文件或创建目录。 */
    public static function requestLimits(Repository $settings): RequestLimits
    {
        $temporary = $settings->text('app.http.upload_temp');

        return new RequestLimits(1048576, 100, 16, 8, 1048576, 65536, $temporary === '' ? null : $temporary);
    }

    /**
     * 显式选择实现同一接口的传输适配器，不把同步 stream 引擎伪装成协程并发。
     *
     * 创建对象不监听端口；返回的服务器由调用方负责 serve/stop 生命周期。
     */
    public static function server(Repository $settings, string $basePath, bool $development = false): HttpServerInterface
    {
        $handler = self::handler($settings, $basePath, $development);
        $messages = new Factory();
        CoroutineRuntime::enableIo();

        return new SwooleServer(
            $handler,
            $messages,
            $messages,
            $messages,
            self::requestLimits($settings),
            new HttpControl(probes: true)
        );
    }

    /** 运行已选择的服务器，并在正常退出或异常后关闭；不会自动迁移或生成生产源码。 */
    public static function serve(Repository $settings, string $basePath, bool $development = false): void
    {
        $server = self::server($settings, $basePath, $development);
        try {
            $server->serve($settings->text('app.http.listen'), Settings::integer($settings, 'app.http.port', 1, 65535));
        } finally {
            $server->stop();
        }
    }
}
