<?php

declare(strict_types=1);

namespace app\common\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

/** 请求日志使用组件的有界输出与作用域回收，不记录请求令牌或完整请求体。 */
final class RequestLog implements MiddlewareInterface
{
    private string $application;

    /** 只保留用于日志命名的应用标识，不读取或保存配置秘密。 */
    public function __construct(string $application)
    {
        $this->application = $application;
    }

    /**
     * 日志资源由请求作用域持有，成功、失败与取消路径均交给作用域清理。
     *
     * @throws \RuntimeException 请求不含框架执行作用域。
     * @throws \Throwable 下游未处理的业务或基础设施异常。
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('请求日志需要受管请求作用域');
        }
        $logs = new LogManager($this->application, ['app' => new Channel(Output::stdout(128, 65536, 2048))], 0.05);
        $scope->open($logs);
        $requestId = bin2hex(random_bytes(16));
        $logger = $logs->logger($scope, ['request_id' => $requestId]);
        try {
            $response = $handler->handle($request->withAttribute('app.request_id', $requestId)->withAttribute('app.logger', $logger));
            $logger->info('HTTP 请求完成', ['method' => $request->getMethod(), 'status' => $response->getStatusCode()]);

            return $response->withHeader('X-Request-Id', $requestId);
        } catch (\Throwable $error) {
            $logger->error('HTTP 请求失败', ['error' => 'internal_error']);
            throw $error;
        }
    }
}
