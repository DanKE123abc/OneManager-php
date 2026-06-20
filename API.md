# OneManager API

English | [中文](#api-接口文档)

Base URL: `https://your-domain.com/api/`

---

## Authentication

Set `api_key` in admin panel (`?setup` > Platform Config).

When `api_key` is set, **all endpoints except `info`** require it via one of:

- **Header**: `Authorization: Bearer <api_key>`
- **Query**: `?api_key=<api_key>`
- **POST param**: `api_key=<api_key>`

Only `info` is public. `list`, `file`, `download` all require auth when `api_key` is set.

| Scenario | `info` | `list`, `file`, `download` | `upload`, `delete`, `mkdir`, etc. |
|---|---|---|---|
| `api_key` not set | ✅ Open | ✅ Open | ✅ Open |
| `api_key` set, no key provided | ✅ Open | ❌ 401 | ❌ 401 |
| `api_key` set, wrong key | ✅ Open | ❌ 401 | ❌ 401 |
| `api_key` set, correct key | ✅ Open | ✅ Allowed | ✅ Allowed |

---

## Response Format

All responses are JSON:

```json
{
  "code": 200,
  "data": { ... },
  "message": ""
}
```

Error:

```json
{
  "code": 401,
  "message": "Unauthorized: api_key required"
}
```

---

## Endpoints

### `GET /api` or `GET /api/info`

Site info, disk list, endpoint reference. No auth required.

```
GET /api/info
```

Response:

```json
{
  "code": 200,
  "data": {
    "name": "OneManager",
    "version": "20220127-1234.43 ...",
    "disks": [
      { "tag": "dumping", "name": "My Drive", "driver": "Onedrive" }
    ],
    "endpoints": { ... }
  }
}
```

---

### `GET /api/list`

List directory contents. Auth required when `api_key` is set.

| Param | Required | Default | Description |
|---|---|---|---|
| `path` | No | `/` | Directory path |
| `disktag` | No | first disk | Disk tag |

```
GET /api/list?disktag=dumping&path=/
```

Response (folder):

```json
{
  "code": 200,
  "data": {
    "type": "folder",
    "path": "/",
    "name": "root",
    "childcount": 3,
    "items": [
      { "name": "photo.jpg", "type": "file", "size": 102400, "sizeFormatted": "100 KB", "time": "2026-01-01T00:00:00Z" },
      { "name": "docs", "type": "folder", "size": 0, "sizeFormatted": "0 B", "time": "" }
    ]
  }
}
```

Response (single file):

```json
{
  "code": 200,
  "data": {
    "type": "file",
    "name": "photo.jpg",
    "size": 102400,
    "sizeFormatted": "100 KB",
    "time": "2026-01-01T00:00:00Z",
    "mime": "image/jpeg",
    "downloadUrl": "https://..."
  }
}
```

---

### `GET /api/file`

Get file metadata. Auth required when `api_key` is set.

| Param | Required | Description |
|---|---|---|
| `path` | Yes | File path |
| `disktag` | No | Disk tag |

```
GET /api/file?path=/photo.jpg&disktag=dumping
```

Response:

```json
{
  "code": 200,
  "data": {
    "name": "photo.jpg",
    "size": 102400,
    "sizeFormatted": "100 KB",
    "time": "2026-01-01T00:00:00Z",
    "mime": "image/jpeg",
    "downloadUrl": "https://..."
  }
}
```

---

### `GET /api/download`

Download a file. Auth required when `api_key` is set.

| Param | Required | Description |
|---|---|---|
| `path` | Yes | File path |
| `disktag` | No | Disk tag |
| `proxy` | No | Set `true` to proxy file through server (for CORS). Returns file content with `Access-Control-Allow-Origin: *` header. |

```
GET /api/download?path=/photo.jpg&disktag=dumping
```

**Default mode**: Returns HTTP 302 redirect to the storage provider's direct URL. No CORS headers.

**Proxy mode** (`?proxy=true`): Server fetches the file and returns it with CORS headers. Required when calling from a cross-origin frontend (e.g. Cloudflare Workers).

> **Vercel limitation**: Vercel serverless functions have execution time limits (~10s on hobby plan). Proxy mode may timeout for large files. For Vercel deployments, use the Cloudflare Worker CORS proxy approach below.

```bash
# Default: 302 redirect (no CORS)
curl -I "https://example.com/api/download?path=/photo.jpg&disktag=dumping"

# Proxy: server-side fetch with CORS
curl "https://example.com/api/download?path=/photo.jpg&disktag=dumping&proxy=true"
```

Frontend usage (fetch API):

```javascript
// Option 1: Server proxy (works for small files <4MB)
const resp = await fetch(
  `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping&proxy=true`
);
const blob = await resp.blob();

// Option 2: Cloudflare Worker CORS proxy (recommended for Vercel)
const fileUrl = `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping`;
const resp = await fetch(
  `https://your-cf-worker.your-subdomain.workers.dev/?url=${encodeURIComponent(fileUrl)}`
);
const blob = await resp.blob();

// Option 3: Direct download (no fetch, just trigger download)
const a = document.createElement('a');
a.href = `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping`;
a.download = filename;
document.body.appendChild(a);
a.click();
a.remove();
```

**Cloudflare Worker CORS proxy** (add to your CF Worker):

```javascript
export default {
  async fetch(request) {
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, OPTIONS',
          'Access-Control-Allow-Headers': '*',
          'Access-Control-Max-Age': '86400',
        },
      });
    }

    const url = new URL(request.url);
    const target = url.searchParams.get('url');
    if (!target) return new Response('Missing ?url= parameter', { status: 400 });

    const resp = await fetch(target);
    const headers = new Headers(resp.headers);
    headers.set('Access-Control-Allow-Origin', '*');
    headers.set('Access-Control-Expose-Headers', '*');

    return new Response(resp.body, {
      status: resp.status,
      headers,
    });
  },
};
```

---

### `POST /api/upload`

Upload a file via multipart/form-data. Auth required. Max 4 MB.

| Param | Type | Required | Description |
|---|---|---|---|
| `file` | multipart | Yes | File to upload |
| `path` | string | No | Destination folder (default `/`) |
| `disktag` | string | No | Disk tag |

```bash
curl -X POST "https://example.com/api/upload?path=/&disktag=dumping&api_key=YOUR_KEY" \
  -F "file=@photo.jpg"
```

Response:

```json
{
  "code": 201,
  "data": {
    "type": "file",
    "id": "01TPO5A3...",
    "name": "photo.jpg",
    "time": "2026-01-01T00:00:00Z",
    "size": 102400,
    "mime": "image/jpeg",
    "url": "https://..."
  }
}
```

---

### `POST /api/upload-session`

Initiate upload session for large files. Auth required. Returns a presigned URL for direct upload.

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Filename |
| `size` | int | Yes | File size in bytes |
| `path` | string | No | Destination folder (default `/`) |
| `md5` | string | No | MD5 hash |

```bash
curl -X POST "https://example.com/api/upload-session?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"big.zip","size":52428800}'
```

---

### `POST /api/delete`

Delete a file or folder. Auth required.

| Param | Type | Required | Description |
|---|---|---|---|
| `path` | string | Yes | File/folder path |

```bash
curl -X POST "https://example.com/api/delete?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/old_file.txt"}'
```

Response:

```json
{ "code": 200, "data": { "deleted": "old_file.txt" } }
```

---

### `POST /api/mkdir`

Create a folder. Auth required.

| Param | Type | Required | Description |
|---|---|---|---|
| `path` | string | No | Parent folder (default `/`) |
| `name` | string | Yes | New folder name |

```bash
curl -X POST "https://example.com/api/mkdir?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/","name":"new_folder"}'
```

Response:

```json
{ "code": 201, "data": { "created": "/new_folder" } }
```

---

### `POST /api/rename`

Rename a file or folder. Auth required.

| Param | Type | Required | Description |
|---|---|---|---|
| `path` | string | Yes | Current path |
| `name` | string | Yes | New name |

```bash
curl -X POST "https://example.com/api/rename?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/old_name.txt","name":"new_name.txt"}'
```

Response:

```json
{ "code": 200, "data": { "renamed": "old_name.txt", "to": "new_name.txt" } }
```

---

### `POST /api/move`

Move a file or folder. Auth required.

| Param | Type | Required | Description |
|---|---|---|---|
| `path` | string | Yes | Source path |
| `to` | string | Yes | Destination folder |

```bash
curl -X POST "https://example.com/api/move?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/file.txt","to":"/archive"}'
```

Response:

```json
{ "code": 200, "data": { "moved": "file.txt", "to": "/archive" } }
```

---

### `POST /api/copy`

Copy a file or folder. Auth required.

| Param | Type | Required | Description |
|---|---|---|---|
| `path` | string | Yes | Source path |

```bash
curl -X POST "https://example.com/api/copy?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/file.txt"}'
```

Response:

```json
{ "code": 200, "data": { "copied": "file.txt" } }
```

---

## Supported Storage Backends

| Driver | Disk Type |
|---|---|
| `Onedrive` | OneDrive International |
| `OnedriveCN` | OneDrive 世纪互联 |
| `Sharepoint` | SharePoint International |
| `SharepointCN` | SharePoint 世纪互联 |
| `Aliyundrive` | 阿里云盘 |
| `AliyundriveOpen` | 阿里云盘 Open |
| `Googledrive` | Google Drive |
| `BaiduDisk` | 百度网盘 |
| `Sharelink` | Share Link |

---

## Quick Start

```bash
# 1. Check site info
curl https://example.com/api/info

# 2. List root folder (auth needed when api_key is set)
curl "https://example.com/api/list?disktag=dumping&api_key=YOUR_KEY"

# 3. Upload a file (auth needed)
curl -X POST "https://example.com/api/upload?path=/&disktag=dumping&api_key=YOUR_KEY" \
  -F "file=@test.txt"

# 4. Delete the file (auth needed)
curl -X POST "https://example.com/api/delete?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/test.txt"}'
```

---

---

# API 接口文档

Base URL: `https://your-domain.com/api/`

---

## 鉴权

在管理面板（`?setup` > Platform Config）中设置 `api_key`。

设置后，**除 `info` 外的所有接口**都需要携带密钥，支持以下方式：

- **请求头**：`Authorization: Bearer <api_key>`
- **URL参数**：`?api_key=<api_key>`
- **POST参数**：`api_key=<api_key>`

只有 `info` 为公开接口。`list`、`file`、`download` 在设置 `api_key` 后均需要鉴权。

| 场景 | `info` | `list`/`file`/`download` | `upload`/`delete`/`mkdir`等 |
|---|---|---|---|
| 未设置 `api_key` | ✅ 免密 | ✅ 免密 | ✅ 免密 |
| 已设置，请求中未携带密钥 | ✅ 免密 | ❌ 401 拒绝 | ❌ 401 拒绝 |
| 已设置，密钥错误 | ✅ 免密 | ❌ 401 拒绝 | ❌ 401 拒绝 |
| 已设置，密钥正确 | ✅ 免密 | ✅ 允许 | ✅ 允许 |

---

## 响应格式

所有接口返回 JSON：

```json
{
  "code": 200,
  "data": { ... },
  "message": ""
}
```

错误示例：

```json
{
  "code": 401,
  "message": "Unauthorized: api_key required"
}
```

---

## 接口列表

### `GET /api` 或 `GET /api/info`

站点信息，无需鉴权。

```
GET /api/info
```

响应示例：

```json
{
  "code": 200,
  "data": {
    "name": "OneManager",
    "disks": [
      { "tag": "dumping", "name": "我的网盘", "driver": "Onedrive" }
    ]
  }
}
```

---

### `GET /api/list`

列出目录内容。设置 `api_key` 后需要鉴权。

| 参数 | 必填 | 默认值 | 说明 |
|---|---|---|---|
| `path` | 否 | `/` | 目录路径 |
| `disktag` | 否 | 第一个盘 | 盘标签 |

```
GET /api/list?disktag=dumping&path=/
```

响应（文件夹）：

```json
{
  "code": 200,
  "data": {
    "type": "folder",
    "path": "/",
    "name": "root",
    "childcount": 3,
    "items": [
      { "name": "photo.jpg", "type": "file", "size": 102400, "sizeFormatted": "100 KB", "time": "2026-01-01T00:00:00Z" },
      { "name": "文档", "type": "folder", "size": 0, "sizeFormatted": "0 B", "time": "" }
    ]
  }
}
```

---

### `GET /api/file`

获取文件元信息。设置 `api_key` 后需要鉴权。

| 参数 | 必填 | 说明 |
|---|---|---|
| `path` | 是 | 文件路径 |
| `disktag` | 否 | 盘标签 |

```
GET /api/file?path=/photo.jpg&disktag=dumping
```

---

### `GET /api/download`

下载文件。设置 `api_key` 后需要鉴权。

| 参数 | 必填 | 说明 |
|---|---|---|
| `path` | 是 | 文件路径 |
| `disktag` | 否 | 盘标签 |
| `proxy` | 否 | 设为 `true` 时通过服务器代理下载（用于跨域），返回文件内容并附带 `Access-Control-Allow-Origin: *` 头 |

**默认模式**：返回 HTTP 302 重定向到存储商的直链，无 CORS 头。

**代理模式**（`?proxy=true`）：服务器从存储商拉取文件并返回，附带 CORS 头。跨域前端（如 Cloudflare Workers）必须使用此模式。

> **Vercel 限制**：Vercel serverless 函数有执行时间限制（hobby 计划约 10 秒）。代理模式对大文件可能超时。Vercel 部署建议使用下方 Cloudflare Worker CORS 代理方案。

```bash
# 默认：302 重定向（无 CORS）
curl -I "https://example.com/api/download?path=/photo.jpg&disktag=dumping"

# 代理：服务器拉取，带 CORS
curl "https://example.com/api/download?path=/photo.jpg&disktag=dumping&proxy=true"
```

前端用法（fetch API）：

```javascript
// 方案一：服务器代理（适合小文件 <4MB）
const resp = await fetch(
  `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping&proxy=true`
);
const blob = await resp.blob();

// 方案二：Cloudflare Worker CORS 代理（推荐用于 Vercel）
const fileUrl = `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping`;
const resp = await fetch(
  `https://your-cf-worker.your-subdomain.workers.dev/?url=${encodeURIComponent(fileUrl)}`
);
const blob = await resp.blob();

// 方案三：直接下载（不用 fetch，触发浏览器下载）
const a = document.createElement('a');
a.href = `https://my-onemanager.danke666.top/api/download?path=${path}&disktag=dumping`;
a.download = filename;
document.body.appendChild(a);
a.click();
a.remove();
```

**Cloudflare Worker CORS 代理**（添加到你的 CF Worker）：

```javascript
export default {
  async fetch(request) {
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, OPTIONS',
          'Access-Control-Allow-Headers': '*',
          'Access-Control-Max-Age': '86400',
        },
      });
    }

    const url = new URL(request.url);
    const target = url.searchParams.get('url');
    if (!target) return new Response('Missing ?url= parameter', { status: 400 });

    const resp = await fetch(target);
    const headers = new Headers(resp.headers);
    headers.set('Access-Control-Allow-Origin', '*');
    headers.set('Access-Control-Expose-Headers', '*');

    return new Response(resp.body, {
      status: resp.status,
      headers,
    });
  },
};
```

---

### `POST /api/upload`

上传文件（multipart/form-data），需要鉴权。限制 4 MB。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `file` | multipart | 是 | 要上传的文件 |
| `path` | string | 否 | 目标文件夹，默认 `/` |
| `disktag` | string | 否 | 盘标签 |

```bash
curl -X POST "https://example.com/api/upload?path=/&disktag=dumping&api_key=YOUR_KEY" \
  -F "file=@photo.jpg"
```

响应：

```json
{
  "code": 201,
  "data": {
    "id": "01TPO5A3...",
    "name": "photo.jpg",
    "size": 102400,
    "time": "2026-01-01T00:00:00Z"
  }
}
```

---

### `POST /api/upload-session`

大文件上传（分块），需要鉴权。返回预签名 URL 供浏览器直传。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `name` | string | 是 | 文件名 |
| `size` | int | 是 | 文件大小（字节） |
| `path` | string | 否 | 目标文件夹，默认 `/` |
| `md5` | string | 否 | MD5 校验值 |

```bash
curl -X POST "https://example.com/api/upload-session?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"big.zip","size":52428800}'
```

---

### `POST /api/delete`

删除文件或文件夹，需要鉴权。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `path` | string | 是 | 要删除的路径 |

```bash
curl -X POST "https://example.com/api/delete?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/old_file.txt"}'
```

---

### `POST /api/mkdir`

创建文件夹，需要鉴权。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `path` | string | 否 | 父文件夹，默认 `/` |
| `name` | string | 是 | 新文件夹名称 |

```bash
curl -X POST "https://example.com/api/mkdir?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/","name":"新文件夹"}'
```

---

### `POST /api/rename`

重命名，需要鉴权。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `path` | string | 是 | 当前路径 |
| `name` | string | 是 | 新名称 |

```bash
curl -X POST "https://example.com/api/rename?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/旧名.txt","name":"新名.txt"}'
```

---

### `POST /api/move`

移动文件，需要鉴权。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `path` | string | 是 | 源路径 |
| `to` | string | 是 | 目标文件夹 |

```bash
curl -X POST "https://example.com/api/move?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/file.txt","to":"/归档"}'
```

---

### `POST /api/copy`

复制文件，需要鉴权。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `path` | string | 是 | 源路径 |

```bash
curl -X POST "https://example.com/api/copy?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/file.txt"}'
```

---

## 快速开始

```bash
# 查看站点信息
curl https://example.com/api/info

# 列出根目录（设置api_key后需鉴权）
curl "https://example.com/api/list?disktag=dumping&api_key=YOUR_KEY"

# 上传文件（需鉴权）
curl -X POST "https://example.com/api/upload?path=/&disktag=dumping&api_key=YOUR_KEY" \
  -F "file=@test.txt"

# 删除文件（需鉴权）
curl -X POST "https://example.com/api/delete?disktag=dumping&api_key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/test.txt"}'
```
