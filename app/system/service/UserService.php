<?php

declare(strict_types=1);

namespace app\system\service;

use app\system\model\User;
use Type\Orm\Attribute\Transactional;
use Type\Orm\Connection;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;

/**
 * 用户业务只接收已校验的数据与显式连接，不依赖 HTTP 请求或全局容器。
 *
 * 写方法的事务由构建期生成的 UserOperations 执行；直接调用本类不会魔法开启事务。
 */
final class UserService
{
    /**
     * 先按白名单选择排序，再由分页补真实主键保证稳定收尾；不提前固定 id 抹掉用户排序。
     *
     * @param array{name?: string, age?: int, sort?: string, direction?: string} $filters 已校验输入；客户端名称必须映射到下方受信字段。
     * @return array{data: list<array{id: int, name: string, age: int, email: ?string, version: int}>, total: int, page: int}
     * @throws ModelException 行数据或模型水合类型不符合声明。
     */
    public function page(Connection $connection, int $number, array $filters = []): array
    {
        $filtered = \_query(User::query($connection), $filters)->like('name')->equal('age')
            ->order(['id' => 'id', 'name' => 'name', 'age' => 'age'], ['id' => 'ASC'])->query();
        if (!$filtered instanceof ModelQuery) {
            throw new ModelException('invalid_user_query', '用户筛选必须保留模型查询');
        }
        $page = $filtered->paginate($number, 20);
        $users = [];
        foreach ($page->items() as $item) {
            if (!$item instanceof User) {
                throw new ModelException('invalid_user_hydration', '用户查询未水合业务模型');
            }
            $users[] = $item->present();
        }

        return ['data' => $users, 'total' => $page->total(), 'page' => $page->number()];
    }

    /**
     * 只返回可见用户，软删除的用户与不存在的用户使用同一失败语义。
     *
     * @return array{id: int, name: string, age: int, email: ?string, version: int}
     * @throws ModelException 用户不存在或查询映射失败。
     */
    public function find(Connection $connection, int $id): array
    {
        return $this->load($connection, $id)->present();
    }

    /**
     * 创建后从同一事务连接读回数据库生成值；提交结果由 UserOperations 确认。
     *
     * @param array{name: string, age: int, email?: ?string} $values 已通过输入校验的创建字段。
     * @return array{id: int, name: string, age: int, email: ?string, version: int}
     * @throws ModelException 字段不符合模型规则或写后记录不可见。
     */
    #[Transactional(connection: 'connection')]
    public function create(Connection $connection, array $values): array
    {
        $user = new User($values);
        $user->save($connection);

        return $this->find($connection, $user->id);
    }

    /**
     * 未提供字段保持原值，明确 null 写回空值；可选版本断言与模型乐观锁共同保护更新。
     *
     * @param array{name?: string, age?: int, email?: ?string} $values 已校验且排除 version 的局部更新字段。
     * @return array{id: int, name: string, age: int, email: ?string, version: int}
     * @throws ModelException 用户不存在、版本已过期或并发写入冲突。
     */
    #[Transactional(connection: 'connection')]
    public function update(Connection $connection, int $id, array $values, ?int $version = null): array
    {
        $user = $this->load($connection, $id);
        if ($version !== null && $version !== $user->getVersion()) {
            throw new ModelException('stale_version', '用户版本已过期，请重新读取后更新');
        }
        $user->fill($values);
        $user->save($connection);

        return $this->find($connection, $user->id);
    }

    /**
     * 使用模型软删除而非物理删除；UserOperations 的事务提交成功后才向调用方返回。
     *
     * @throws ModelException 用户不存在或并发乐观锁冲突。
     */
    #[Transactional(connection: 'connection')]
    public function delete(Connection $connection, int $id): bool
    {
        return $this->load($connection, $id)->delete($connection);
    }

    /** 查询工厂必须返回业务 User，避免生成基类绕过业务投影约定。 */
    private function load(Connection $connection, int $id): User
    {
        $user = User::query($connection)->find($id);
        if ($user === null) {
            throw new ModelException('user_not_found', '用户不存在');
        }
        if (!$user instanceof User) {
            throw new ModelException('invalid_user_hydration', '用户查询未水合业务模型');
        }

        return $user;
    }
}
