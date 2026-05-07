# LingDraw灵绘

灵绘 AI 无限画布作图agent，基于 Vue 3 + ThinkPHP 6 的 SaaS 多租户 AI 内容生成系统。

![项目截图](https://gitee.com/byby123/lingdraw/raw/master/images/5.png)
![项目截图](https://gitee.com/byby123/lingdraw/raw/master/images/4.png)

## 项目架构

```
lingdraw/
├── admin-backend/          # 平台总管理后台
│   ├── admin-frontend/     # 平台管理前端（Vue 3 + Element Plus）
│   └── ai-saas-backend/    # 平台管理后端（ThinkPHP 6）
│
├── frontend/               # 用户端前端（Vue 3 + Three.js VRM）
├── tenant-admin/           # 租户管理后台前端（Vue 3 + Element Plus）
└── tenant-user-api/        # 租户、用户端后端（ThinkPHP 6）
```

<br />

## 演示地址

[http://www.xuku.cc  ](http://www.xuku.cc)

## 文件夹说明

| 文件夹                              | 说明                                                       |
| -------------------------------- | -------------------------------------------------------- |
| `admin-backend/`                 | 平台总管理后台，负责所有租户实例的管理、系统配置、模型配置等                           |
| `admin-backend/admin-frontend/`  | 平台管理前端，基于 Vue 3 + Element Plus，提供租户管理、用户管理、财务统计等后台功能     |
| `admin-backend/ai-saas-backend/` | 平台管理后端，基于 ThinkPHP 6，提供管理后台的 API 接口                      |
| `frontend/`                      | 用户端前端，基于 Vue 3 + Three.js，支持 AI 图片生成、VRM 模型              |
| `tenant-admin/`                  | 租户管理后台，基于 Vue 3 + Element Plus，供每个租户（客户）管理自己的用户、套餐、订单等   |
| `tenant-user-api/`               | 租户用户端后端，基于 ThinkPHP 6，提供用户注册登录、AI 模型调用、图片/视频生成、订单支付等业务接口 |

## 环境要求

- **PHP**: >= 7.2.5（推荐 PHP 8.1）
- **Node.js**: >= 18（推荐 Node 20+）
- **MySQL**: 5.7+
- **Redis**: 6.0+
- **Composer**: 2.x

## 安装部署

### 部署帮助联系方式

配置较复杂，如果需要可以联系作者代一条龙部署 <img width="200" style="text-align: left;" src="https://gitee.com/byby123/lingdraw/raw/master/images/10.jpg">

QQ:1211284542

Email:<1211284542@qq.com>

### 1. 初始化数据库

创建 MySQL 数据库（以 `lingdraw` 为例），导入lingdraw\.sql。

<br />

2.系统前后端分离，新建四个站点分别是：

总管理后台=>admin-backend/ai-saas-backend

用户端=>frontend

租户端=>tenant-admin

api=>tenant-user-api

### 3. 配置后端

#### 平台管理后端（admin-backend/ai-saas-backend）

```bash
# 复制环境配置
cp .example.env .env

# 安装依赖
composer install

```

#### 租户用户端后端（tenant-user-api）

```bash

# 复制环境配置
cp .example.env .env

# 安装依赖
composer install

```

#### 环境变量配置（.env）

```ini
[APP]
DEFAULT_TIMEZONE = Asia/Shanghai

[DATABASE]
TYPE = mysql
HOSTNAME = 127.0.0.1
DATABASE = lingdraw
USERNAME = root
PASSWORD = your_password
HOSTPORT = 3306
CHARSET = utf8mb4

[REDIS]
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_PASSWORD =
REDIS_SELECT = 0

[SSO]
SSO_SECRET = your_sso_secret_here
```

### 3. 配置前端

#### 平台管理前端（admin-backend/admin-frontend）

```bash
# 安装依赖
npm install

# 开发模式
npm run dev

# 生产构建
npm run build
```

#### 租户管理后台（tenant-admin）

```bash
# 安装依赖
npm install

# 开发模式
npm run dev

# 生产构建
npm run build
```

#### 用户端前端（frontend）

```bash
# 安装依赖
npm install

# 开发模式
npm run dev

# 生产构建
npm run build
```

### 4. WebSocket 部署

如需启用实时生成进度推送，需配置 WebSocket 反向代理（Nginx 示例）：

```nginx
location /ws {
    proxy_pass http://127.0.0.1:2348;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 600s;
}
```

前端环境变量：

```
VITE_WS_URL=wss://your-domain.com/ws
```

启动WebSocket服务支持

```Shell
#在api文件夹下执行、建议加入守护进程
php think worker:gateway -d
```

<br />

### 5.启动任务队列（tenant-user-api）

```Shell
#在api文件夹下执行、建议加入守护进程
php think queue:work redis --queue=default --sleep=1 --tries=1 --timeout=0
```

### 6.会员到期重置

```Shell
#在api文件夹下执行
#【每天0点计算套餐到期】
#-积分到期重置
#-自动运行 ：您需要确保服务器的定时任务（Cron）配置了运行
php think package:reset
```

<br />

<br />

# 开源协议

&#x20;

本项目采用 AGPL-3.0 协议开源，商用请联系作者获取授权。

## 商业授权 & 技术支持

如需商用授权、定制开发、部署服务，请联系作者。
