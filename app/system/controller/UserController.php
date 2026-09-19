<?php

declare(strict_types=1);

namespace app\system\controller;

use app\generated\UserOperations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\ValidationException;

/**
 * system 模块的 HTTP 控制器只做输入、响应与请求资源适配，业务交给 UserOperations。
 *
 * 目录层数由 PSR-4 和显式 Attribute 文件声明决定，没有固定 controller 目录扫描。
 */
#[Group(namePrefix: 'users.')]
final class UserController
{
    private DatabaseManager $database;
    private UserOperations $users;
    private Factory $messages;

    /** 注入进程内数据库管理器与生成的事务调用入口，不在构造时借用连接。 */
    public function __construct(DatabaseManager $database, UserOperations $users, Factory $messages)
    {
        $this->database = $database;
        $this->users = $users;
        $this->messages = $messages;
    }

    /**
     * 每页固定 20 条；连接在当前请求作用域结束时归还。
     *
     * @throws ValidationException 筛选、分页或排序未通过白名单；不能用客户端文本指定 SQL 列或方向表达式。
     */
    #[Route('/users', methods: ['GET'], name: 'index')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = \_vali([
            'page' => Field::integer()->from('query')->cast()->range(1, 100000),
            'name' => Field::text()->from('query')->trim()->length(1, 100),
            'age' => Field::integer()->from('query')->cast()->range(0, 150),
            'sort' => Field::text()->from('query')->oneOf(['', 'id', 'name', 'age']),
            'direction' => Field::text()->from('query')->matches('/^(?:ASC|DESC)?$/iD')
                ->rule('sort_required', static function (mixed $value, Input $input, string $scenario): bool {
                    $query = $input->source('query');

                    return $value === '' || (array_key_exists('sort', $query) && is_string($query['sort']) && $query['sort'] !== '');
                }),
        ], (new Input([]))->withQuery($request->getUri()->getQuery()));
        $filters = $parameters;
        unset($filters['page']);
        $connection = $this->database->connect($this->scope($request));

        return $this->response(200, $this->users->page(
            $connection,
            array_key_exists('page', $parameters) ? $parameters['page'] : 1,
            $filters
        ));
    }

    /**
     * 只接受有界 JSON 对象，创建成功返回 201 与数据库确认后的记录。
     *
     * @throws ValidationException JSON 数据或创建字段不符合输入声明。
     * @throws HttpError 请求体不是 application/json。
     */
    #[Route('/users', methods: ['POST'], name: 'store')]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->payload($request, false);
        unset($values['version']);
        $connection = $this->database->connect($this->scope($request));

        return $this->response(201, ['data' => $this->users->create($connection, $values)]);
    }

    /** @throws ValidationException 路由 id 超出本应用支持的整数范围。 */
    #[Route('/users/{id}', methods: ['GET'], name: 'show', constraints: ['id' => '[1-9][0-9]*'])]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        $connection = $this->database->connect($this->scope($request));

        return $this->response(200, ['data' => $this->users->find($connection, $id)]);
    }

    /**
     * 局部更新区分未传字段与 null；业务版本冲突由统一错误中间件映射为 409。
     *
     * @throws ValidationException 路由参数或补丁数据无效。
     * @throws HttpError 请求体不是 application/json。
     */
    #[Route('/users/{id}', methods: ['PATCH'], name: 'update', constraints: ['id' => '[1-9][0-9]*'])]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        $data = $this->payload($request, true);
        $values = $data;
        unset($values['version']);
        $connection = $this->database->connect($this->scope($request));

        return $this->response(200, ['data' => $this->users->update(
            $connection,
            $id,
            $values,
            array_key_exists('version', $data) ? $data['version'] : null
        )]);
    }

    /**
     * 执行软删除；重复删除按不存在处理，不暴露内部生命周期字段。
     *
     * @throws ValidationException 路由 id 无效。
     */
    #[Route('/users/{id}', methods: ['DELETE'], name: 'destroy', constraints: ['id' => '[1-9][0-9]*'])]
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        $connection = $this->database->connect($this->scope($request));

        return $this->response(200, ['deleted' => $this->users->delete($connection, $id)]);
    }

    /** 使用路由的独立输入来源，不能被 query 或 body 中的同名 id 覆盖。 */
    private function id(ServerRequestInterface $request): int
    {
        $parameters = \_vali([
            'id' => Field::integer()->from('route')->required()->cast()->range(1, PHP_INT_MAX),
        ], (new Input([]))->with('route', $request->getAttribute('type.route.params', [])));

        return $parameters['id'];
    }

    /**
     * 读取不超过 16 KiB、最多 8 层的 JSON；校验不占用连接，缺失字段不会变为 null。
     *
     * @return array{name?: string, age?: int, email?: ?string, version?: int}
     */
    private function payload(ServerRequestInterface $request, bool $patch): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }

        return \_vali([
            'name' => Field::text()->required()->trim()->length(2, 100),
            'age' => Field::integer()->required()->range(0, 150),
            'email' => Field::text()->nullable()->email()->length(1, 255),
            'version' => Field::integer()->range(1, PHP_INT_MAX),
        ], Input::json(RequestBody::read($request->getBody(), 16384), 16384, 8), 'default', $patch);
    }

    /** 只有受管 HTTP 请求可取得连接，离开作用域的手工请求不能借用资源。 */
    private function scope(ServerRequestInterface $request): ExecutionScope
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('用户控制器需要受管请求作用域');
        }

        return $scope;
    }

    /** @param array<string, mixed> $body 已明确选择对外字段的业务结果。 */
    private function response(int $status, array $body): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->messages->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
