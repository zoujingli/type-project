<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;
use Type\Orm\ModelException;

/**
 * 用户类型属性交由构建器转换为模型状态钩子，业务方法与类名保持一致。
 */
#[Table('users', softDelete: 'deleted_at', version: 'version')]
final class User extends Model
{
    public int $id;
    public string $name;
    public int $age;

    #[Column(required: false)]
    public ?string $email;

    public int $version;
    public ?\DateTimeImmutable $deleted_at;

    /**
     * 对外投影显式选择字段，不暴露内部软删除标记或依赖属性表序列化。
     *
     * @return array{id: int, name: string, age: int, email: ?string, version: int}
     * @throws ModelException 模型已失效或投影字段尚未加载。
     */
    public function present(): array
    {
        return $this->project(['id', 'name', 'age', 'email', 'version']);
    }
}
