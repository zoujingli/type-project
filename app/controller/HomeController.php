<?php

declare(strict_types=1);

namespace app\controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\Message\Factory;

/** 一级应用控制器，与 system 多级业务模块共用静态路由，不连接额外服务。 */
final class HomeController
{
    private Factory $messages;

    /** 响应工厂只负责消息创建，不持有当前请求或配置秘密。 */
    public function __construct(Factory $messages)
    {
        $this->messages = $messages;
    }

    /** 返回固定的应用用法，不输出运行配置、凭据或源码路径。 */
    #[Route('/', methods: ['GET'], name: 'home')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $body = [
            'name' => 'type-project',
            'message' => 'Type 业务应用模板已启动。',
            'routes' => [
                'GET/POST /users：查询或创建用户',
                'GET/PATCH/DELETE /users/{id}：查看、更新或软删除用户',
                'GET /users?name=示例&sort=age&direction=DESC：白名单筛选与排序',
            ],
        ];

        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->messages->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
