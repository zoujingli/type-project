<?php

declare(strict_types=1);

namespace app\common\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Type\Core\Http\HttpError;
use Type\Core\Http\Message\Factory;
use Type\Log\Logger;
use Type\Orm\ModelException;
use Type\Runtime\CapacityException;
use Type\Runtime\TaskException;
use Type\Validate\ValidationException;

/**
 * 统一公开失败响应与请求关联；开发详情只输出到受控日志，不随 HTTP 响应泄漏。
 *
 * 调试权限由启动器参数固定，请求头、query、body 都不能更改该状态。
 */
final class ApiErrors implements MiddlewareInterface
{
    private Factory $messages;
    private bool $debug;
    private string $basePath;

    /** 只保存响应工厂、明确的调试权限和代码路径根，不保存配置值或异常对象。 */
    public function __construct(Factory $messages, bool $debug = false, string $basePath = '')
    {
        $this->messages = $messages;
        $this->debug = $debug;
        $this->basePath = $basePath;
    }

    /**
     * 保持框架公开错误的状态码；内部异常统一为 internal_error 和服务端生成的请求 ID。
     *
     * 标识从受管请求属性取得，不相信客户端的同名 HTTP 头；单独使用时自行生成。
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getAttribute('app.request_id');
        if (!is_string($requestId) || preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
            $requestId = bin2hex(random_bytes(16));
        }
        try {
            return $handler->handle($request->withAttribute('app.request_id', $requestId));
        } catch (HttpError $httpError) {
            return $this->response($httpError->status(), ['error' => $httpError->errorCode()], $requestId);
        } catch (CapacityException $capacityError) {
            return $this->response(503, ['error' => 'resource_capacity_exceeded'], $requestId)->withHeader('Retry-After', '1');
        } catch (TaskException $taskError) {
            return $this->response(
                $taskError->errorCode() === 'deadline_exceeded' ? 504 : 503,
                ['error' => $taskError->errorCode()],
                $requestId
            );
        } catch (ValidationException $validationError) {
            return $this->response($validationError->status(), [
                'error' => $validationError->errorCode(),
                'fields' => $validationError->errors(),
            ], $requestId);
        } catch (ModelException $modelError) {
            if ($modelError->errorCode() === 'user_not_found') {
                return $this->response(404, ['error' => 'user_not_found'], $requestId);
            }
            if (in_array($modelError->errorCode(), ['stale_version', 'optimistic_conflict'], true)) {
                return $this->response(409, ['error' => 'stale_version'], $requestId);
            }

            return $this->failure($modelError, $request, $requestId);
        } catch (Throwable $error) {
            return $this->failure($error, $request, $requestId);
        }
    }

    /** 不字符串化异常或记录消息、参数；调试日志只选取代码位置、函数名与异常类型。 */
    private function failure(Throwable $error, ServerRequestInterface $request, string $requestId): ResponseInterface
    {
        if ($this->debug) {
            $filename = str_replace('\\', '/', $error->getFile());
            $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
            $location = $base !== '' && str_starts_with($filename, $base . '/')
                ? substr($filename, strlen($base) + 1) : basename($filename);
            $details = [
                'error' => 'internal_error',
                'request_id' => $requestId,
                'exception_type' => get_class($error),
                'file' => $location === '' ? '[native]' : $location,
                'line' => max(0, $error->getLine()),
            ];
            $frames = [];
            foreach ($error->getTrace() as $frame) {
                if (count($frames) >= 12) {
                    break;
                }
                $frameFile = $frame['file'] ?? null;
                if (!is_string($frameFile)) {
                    continue;
                }
                $frameFile = str_replace('\\', '/', $frameFile);
                if ($base === '' || !str_starts_with($frameFile, $base . '/app/')) {
                    continue;
                }
                // 只复制允许的字段；原始 frame 可能含令牌或请求对象，不能交给日志层。
                $frames[] = [
                    'file' => substr($frameFile, strlen($base) + 1),
                    'line' => max(0, (int) ($frame['line'] ?? 0)),
                    'function' => is_string($frame['function'] ?? null) ? $frame['function'] : '[unknown]',
                ];
            }
            $details['frames'] = $frames;
            $logger = $request->getAttribute('app.logger');
            if ($logger instanceof Logger) {
                $logger->error('开发请求失败', $details);
            } else {
                fwrite(STDERR, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
        }

        return $this->response(500, ['error' => 'internal_error'], $requestId);
    }

    /** @param array<string, mixed> $body 稳定错误码与不含输入值的公开校验结果。 */
    private function response(int $status, array $body, string $requestId): ResponseInterface
    {
        return $this->messages->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Request-Id', $requestId)
            ->withBody($this->messages->createStream(json_encode(
                $body + ['request_id' => $requestId],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )));
    }
}
