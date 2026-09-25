# Type 业务应用模板

这是独立业务项目的起点，包名为 `zoujingli/type-project`。TypeApp 是面向原生交付的 PHP 应用框架，以 TypePHP 全量编译生产实现。Plugins 由 Composer 安装，生产组件与业务一起编译；Swoole 作为内置原生运行库随应用交付。主仓中的物联中心是成品案例，不随本模板分发。用本模板创建自己的应用，再按需安装 `type-xxxx` 组件，即可开发其他业务系统。框架组件在公开开发主仓维护，消费应用通过 Packagist 安装对应公开分发包；模板不包含主仓 path repository、分发凭据、缓存/Redis 依赖或全部组件源码。

Linux x64 / ARM64、macOS ARM64、Windows x64 已在同一源码基线上通过默认原生 CI，组件与模板开发分支分发也已通过。模板 HTTP 使用 Unix 单 worker 或 Windows 协程入口，已有真实 HTTP、正常停止及原生发布包回归；各平台的模板、主应用和隔离范围见[平台支持表](https://iots.top/#/guide/platforms?id=当前平台状态)。

## 环境与交付

框架已集成 Swoole 的网络与并发能力，路由、配置和模型在构建期生成，生产代码经 TypePHP 全量编译。`type-build` 已提供四平台预编译 Swoole 模块；安装包含这些资源的组件版本后，匹配构建默认复用，无需另行下载、编译 Swoole。

| 阶段 | 准备内容 |
| --- | --- |
| 源码开发 | PHP CLI `>=8.4 <8.6`、Composer、匹配的 Swoole 和所选 PDO 驱动；组件安装不会自动修改 CLI 的 ini |
| 原生构建 | `toolchain.lock.json` 对应的目标平台 SDK、PHPX 和实际扩展；内置模块固定匹配 PHP 8.5.10 ZTS ABI |
| 部署运行 | 匹配平台的完整运行包、外置配置及持久数据；MySQL/PostgreSQL 按需提供外部服务，SQLite 无需单独服务 |

部署端无需业务 PHP 源码、Composer、TypePHP 或编译 SDK。**最终目标是一个主程序文件加外置配置，启动不释放运行库；当前 `composer package` 仍生成携带原生依赖的目录包，须整体部署。** 详细准备见[环境与依赖](https://iots.top/#/guide/environment)，效率机制与测量见[性能与调优](https://iots.top/#/guide/performance)。

## 创建与驱动选择

从 Packagist 创建独立应用，先选择 MySQL、PostgreSQL 或 SQLite，再安装依赖：

```sh
composer create-project --no-install --no-plugins --no-scripts zoujingli/type-project my-app dev-main
cd my-app
php configure.php sqlite
composer install --no-plugins --no-scripts
php dev.php help
php dev.php check
```

`--no-install` 让驱动选择发生在依赖安装之前。`configure.php <mysql|pgsql|sqlite>` 只在没有 `vendor/` 和 `composer.lock` 时运行：它将所选工厂放到 `app/common/database/DatabaseFactory.php`，同时调整 Composer 的单一驱动依赖；`scaffold/` 中的其他候选不进入应用生产源码。该步骤不修改业务配置，也不通过安装钩子执行。已有应用应在代码审查下调整驱动和迁移，不能用脚本覆盖业务。

模板只安装选定的 `type-orm-*`，共同依赖 core、ORM、runtime、validate、log；构建和测试组件留在 `require-dev`。Composer 从 Packagist 解析传递依赖，无需配置各个 Git 仓库。`dev-main` 是开发分支，组件的 `1.0.x-dev` 别名和 `~1.0.0@dev` 约束也不代表稳定标签。安装后提交应用的 `composer.lock`，固定实际组件提交。

接着按[第一个应用教程](https://iots.top/#/guide/tutorial)完成迁移、配置令牌、真实 HTTP 操作与原生构建。

### 复用本地模板

已经安装 `type-build` 并取得本地模板时，可创建不存在的新目录：

```sh
php /构建工具项目/vendor/bin/type create /本地模板目录 /新项目目录 sqlite
cd /新项目目录
composer install --no-plugins --no-scripts
php vendor/bin/type doctor type-app.json development
php vendor/bin/type dev type-app.json help
```

该入口选择驱动并按白名单复制文件，不执行模板 PHP、不覆盖现有目录，无需再运行 `configure.php`。

### 直接检出模板

需要通过 Git 查看模板源码时，也可使用公开 HTTPS 地址，无需 SSH 密钥：

```sh
git clone https://github.com/zoujingli/type-project.git my-app
cd my-app
php configure.php sqlite
composer install --no-plugins --no-scripts
php dev.php help
php dev.php check
```

## 目录与业务分层

```text
app/
  main.php
  common/
    bootstrap/               Application 角色与模式、Settings 配置
    database/                所选驱动、SQLite 文件生命周期、迁移
    middleware/              关联日志与公开错误处理
  controller/HomeController.php
  system/
    controller/UserController.php
    service/UserService.php
    model/User.php
config/
  app.php
  database.php
  route.php
.env.example
dev.php
prepare.php
configure.php
type-app.json
```

应用使用小写 `app\\` PSR-4 层级，控制器、服务和模型分别承担 HTTP 适配、业务行为与字段/水合责任。一级 `app/controller` 与多级 `app/system/controller` 共用相同机制，也可以继续分组；不会按目录层数自动发布路由。

`User` 直接继承 `Type\Orm\Model`，使用 PHP 类型属性与 `Table/Column` Attribute 声明字段，通过 `present()` 选择公开字段。构建保留业务类名和方法，为属性生成状态钩子；开发入口先加载本代模型，AOT 编译同一转换结果。路由使用 `#[Route]`、`#[Group]`，构建配置指向 `config/route.php`；生产源码里的路由注解会进入生成结果，没有注解也没有写入 `routes` 表的类不会暴露。写方法的 `#[Transactional]` 由 type-build 生成 `UserOperations`，控制器明确调用该组合对象；直接调用原服务不会触发运行时 AOP。模板不为展示缓存引入 Redis。

## 配置、开发与生产模式

`config.files` 中的 PHP 只包含 strict_types 和 return 数组，允许嵌套标量及 `env('KEY', 默认值)`。构建器静态解析这些声明并生成 `ProjectConfig`，生产不 require 原配置。`config/route.php` 是路由声明，不允许 `env()`。字段默认值的类型约束环境转换，例如 APP_PORT 为整数、APP_DEBUG 为布尔值；运行范围另行校验。

可以复制 `.env.example` 为应用根的 `.env` 并填写实际值，也可完全由进程环境注入。读取优先级为：进程环境、外部 dotenv、编译过的默认值。合法空串不等于缺失；dotenv 是数据，不执行命令或插值，不写回全局环境。密码、令牌、认证文件和实际 .env 不入 Git 或编译产物。

`APP_BASE_PATH` 可由进程环境指定为已存在的绝对目录；未指定时用工作目录。开发入口先切到本项目根。SQLite 默认 `var/app.sqlite` 相对这个运行根解释，部署建议明确指向数据卷的绝对文件路径。驱动是安装/构建时已选定的源码，环境不能切换成没有安装的另一个驱动。

每次 `php dev.php ...` 启动都会调用开发生成流程，不必手工清缓存。配置、模型、路由、事务包装生成到 `build/development/<内容摘要>/` 的同一不可变代次；输入前后复核、生成锁和完整目录发布防止进程混用半套代码。身份不包含时间、绝对项目根或秘密环境值，相同内容只复用通过摘要检查的代次，失败不加载旧代码。`composer prepare` 和 `php prepare.php [--json]` 仍可显式执行。

`php dev.php serve` 仍是单次开发进程；需要持续观察变更时使用 `composer watch` 或 `php vendor/bin/type watch type-app.json serve`。监督器先在新进程准备和检查，成功后停止旧进程并重新启动；错误源码或配置保留旧服务。它不是进程内替换类、eval 或零停机生产部署。原生入口固定使用已经编译的代码，修改生产声明必须重新构建，不会自动下载或生成源码。watch 的 Windows 控制事件仍须对应原生验收，不能用 Unix 结果代替。

`APP_ENV` 默认 production，允许 development/production；`APP_DEBUG` 默认 false。dev.php 用显式参数取得开发权限，即使默认环境为 production，有效开发模式仍显示 development。生产 main 不具有该权限；HTTP 头、query、body 不能打开 debug。内部 HTTP 错误仅返回 internal_error 和服务端生成的 request_id，开发细节只记录异常类型、文件位置、行号及稳定码，不记录异常消息、调用参数、配置或令牌。

## 迁移

```sh
php dev.php migrate run
php dev.php migrate status
php dev.php migrate history
```

只有明确 `migrate run` 创建 SQLite 父目录并执行版本化迁移，重复执行保持幂等。help、migrate help 不依赖配置或生成代码；check 读取配置但不连接外部服务。serve 和其他 SQLite 迁移操作要求文件已存在，不暗中建库或迁移。跨命令持久化的模板不使用内存 SQLite，框架驱动本身的内存模式不受影响。

MySQL/PostgreSQL 的数据库和账号由使用者提前准备，设置 DB_HOST、DB_PORT、DB_DATABASE、DB_USERNAME、DB_PASSWORD；DB_PORT 为0时采用3306/5432。DB_TLS_CA 可指定可信 CA 文件，复用驱动的证书与主机名验证。迁移不需要 API token、HTTP 或 Redis；每个版本有校验和与历史。

MySQL DDL 明确非事务。失败后先核对实际数据库与历史，再使用 `migrate recover <版本> <retry|applied> <恢复说明>`，不能假定数据库已自动回滚或重试不会重复效果。

## HTTP 与用户接口

由使用者提供至少32字符的有效 APP_API_TOKEN，再运行：

```sh
composer serve
```

也可继续使用 `php dev.php serve`。默认监听127.0.0.1:9501，Host 白名单采用当前端口的 localhost 和127.0.0.1；对外部署显式设置 APP_LISTEN、APP_PORT、APP_ALLOWED_HOSTS，只将真实可信代理加入 APP_TRUSTED_PROXIES。模板不会创建生产令牌。

HTTP 传输复用 Swoole 的服务、协程和 hook，`Application::handler()` 装配同一 PSR 处理链。`SwooleServer::serve()` 在 Unix 上使用经典单 worker 服务，在 Windows 上使用协程 HTTP 服务并通过 `ProcessSignals` 接收控制台停止事件；Windows CLI 和原生 embed 使用各自的控制事件接入，均需可用控制台。能力缺失时明确失败，对外 TLS 可交给受信任反向代理。实现分支不等于整条链路已经验收，Windows 最新 HTTP 与完整应用的验证范围仍以[平台与验收](https://iots.top/#/guide/platforms)为准。

全部业务路由均需 Bearer token：GET / 返回固定用法，GET/POST /users，GET/PATCH/DELETE /users/{id} 保留既有 CRUD 行为。列表每页20条；`name`、`age` 筛选通过 `_vali()` 和 `_query()`，排序仅允许 `sort=id|name|age` 及 ASC/DESC 方向，分页补真实主键保证稳定。未知字段、数组方向、SQL片段或只提供 direction 均拒绝；不允许用户选择任意数据库列。

PATCH 区分缺失字段与明确 null，email 可以清空；过期 version 或并发乐观锁冲突返回409。无效输入返回422、非法JSON返回400、非JSON写请求返回415、不支持方法返回405。删除使用软删除，默认查询隐藏记录，输出不包含 deleted_at。模板 token 只代表一个受信任应用身份，正式多用户系统需替换认证/授权策略，不能当作完整登录系统。

/readyz 与 /livez 是独立部署探针，不证明用户表已经迁移；入口应限制探针访问。DB_SERVER_BUDGET、APP_MAX_REPLICAS、APP_ROLLING_SURGE、APP_DATABASE_PROCESSES 和 DB_ADMIN_RESERVE 必须对应真实拓扑。输入与数据库容量有界，停止先取消接收并收回当前作用域；需要硬截止时由平台监督兜底。APP_UPLOAD_TEMP 可指定受控临时文件系统，上传协议能力以对应 Swoole 入口的实际验证为准。

## 编译、平台与验证

现有 `toolchain.lock.json` 记录 PHP8.5.10 ZTS、TypePHP0.9.3、PHPX2.9.2 基线；实际产物按当前原生平台构建。SDK 由构建环境准备后执行：

```sh
composer build
composer package
build/release/run verify-runtime
build/release/run help
```

生产源码、配置/模型/路由/事务生成结果及生产 Composer 依赖整体编译。失败直接中止，生产不使用 dev.php、prepare.php、Composer PHP 自动加载或业务源码回退。当前目录包包含二进制、生成资源和实际原生运行库，PHPX、libphp、Swoole 与所选 PDO 等依赖由构建收集，部署时保留完整布局并校验；外置配置、业务服务与数据由部署环境管理。

Windows使用发布目录的`run.cmd`。为独立数据根设置APP_BASE_PATH和配置后，显式执行`migrate run`、检查历史，再启动serve或对应系统服务。发布根的OPERATIONS.md包含首次部署、版本切换、三库备份/恢复和不能自动回滚的情况；不要把切回旧二进制当作数据库回滚。升级生成新发布目录，不覆盖旧版本；恢复默认指向新目标并保留原数据。

当前默认平台矩阵与公共模板分发已有成功结果，具体提交、SDK 前提及包的隔离范围统一见[平台与验收](https://iots.top/#/guide/platforms)。修改业务或升级依赖后，应重新验证模板应用的 HTTP、停止与发布搬迁；Docker/WSL 的 Linux 结果不代替 Windows/macOS 原生结果，目录包也不等于静态单程序。

`composer test` 保留 tests/smoke.php 公开入口，覆盖离线命令、外部dotenv、迁移、一级/多级静态路由、真实用户HTTP、筛选排序、PATCH、软删除和停止。设置 TYPE_APP_BINARY 为准确原生二进制路径，可驱动同一业务套件；已有 TYPE_APP_COMMAND/TYPE_APP_SERVER_COMMAND 部署验收接口保留。测试会创建用户，只能在新建专用数据库运行，不能使用业务数据库。

完整原生、无源码镜像与分发证据记录在[开发主仓](https://github.com/zoujingli/typeapp/blob/main/docs/development/platform-support.md)。使用本模板的新能力时还需对应的新组件批次与验收结果，旧报告不为新增修改背书。
