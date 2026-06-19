<?php
// OneManager API handler - called from main() in common.php when path starts with /api

function apiHandler($path) {
    $route = trim(substr($path, 4), '/');

    $apiKey = apiAuth();
    if ($apiKey === false || $apiKey === null) {
        $publicEndpoints = ['list', 'file', 'download', 'info', ''];
        $base = apiGetAction($route);
        if (!in_array($base, $publicEndpoints)) {
            return apiResponse(401, null, 'Unauthorized: api_key required');
        }
    }

    if ($route === '' || $route === '/' || $route === 'info') {
        return apiInfo();
    }

    $action = apiGetAction($route);
    if (!$action) {
        return apiResponse(404, null, 'Unknown endpoint');
    }

    $disktag = apiResolveDisktag();
    if (!$disktag) {
        return apiResponse(400, null, 'No disk configured, add a disk in admin panel first');
    }
    $_SERVER['disktag'] = $disktag;
    $_SERVER['list_path'] = getListpath($_SERVER['HTTP_HOST']);

    // Authorize write operations
    $writeOps = ['upload', 'upload-session', 'delete', 'mkdir', 'rename', 'move', 'copy'];
    if (in_array($action, $writeOps)) {
        $_SERVER['admin'] = 1;
    }

    // Parse JSON body for POST requests
    $body = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $body = json_decode($raw, true);
            if (!$body) parse_str($raw, $body);
        }
    }

    switch ($action) {
        case 'list': return apiList($disktag);
        case 'file': return apiFile($disktag);
        case 'download': return apiDownload($disktag);
        case 'upload': return apiUpload($disktag);
        case 'upload-session': return apiUploadSession($disktag, $body);
        case 'delete': return apiDelete($disktag, $body);
        case 'mkdir': return apiMkdir($disktag, $body);
        case 'rename': return apiRename($disktag, $body);
        case 'move': return apiMove($disktag, $body);
        case 'copy': return apiCopy($disktag, $body);
        default: return apiResponse(404, null, 'Unknown endpoint: ' . $action);
    }
}

function apiAuth() {
    if (getConfig('admin') === '') return true;
    $apiKey = getConfig('api_key');
    if (!$apiKey) return true;

    $providedKey = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $providedKey = trim(str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']));
    } elseif (isset($_GET['api_key'])) {
        $providedKey = $_GET['api_key'];
    } elseif (isset($_POST['api_key'])) {
        $providedKey = $_POST['api_key'];
    }

    if ($providedKey === $apiKey) return $providedKey;
    if ($providedKey === '') return null;
    return false;
}

function apiGetAction($route) {
    $parts = explode('/', $route);
    $base = $parts[0];
    if (strpos($base, '?') !== false) $base = substr($base, 0, strpos($base, '?'));
    return $base === '' ? null : $base;
}

function apiResolveDisktag() {
    if (!empty($_GET['disktag'])) return $_GET['disktag'];
    $disktags = explode('|', getConfig('disktag'));
    if (count($disktags) > 0 && $disktags[0] !== '') return $disktags[0];
    return null;
}

function apiGet($key, $default = null) {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function apiResponse($code, $data = null, $message = '') {
    $body = ['code' => $code];
    if ($message) $body['message'] = $message;
    if ($data !== null) $body['data'] = $data;
    return output(json_encode($body, JSON_UNESCAPED_SLASHES), $code, ['Content-Type' => 'application/json']);
}

function apiError($code, $message) {
    return apiResponse($code, null, $message);
}

function apiDriveOk($disktag) {
    global $drive;
    return driveisfine($disktag, $drive);
}

// ==================== Endpoints ====================

function apiInfo() {
    $disktags = explode('|', getConfig('disktag'));
    $disks = [];
    foreach ($disktags as $tag) {
        if ($tag !== '') {
            $disks[] = [
                'tag' => $tag,
                'name' => getConfig('diskname', $tag),
                'driver' => getConfig('Driver', $tag),
            ];
        }
    }
    global $drive; $drive = null;
    return apiResponse(200, [
        'name' => getConfig('sitename') ?: 'OneManager',
        'version' => file_get_contents(__DIR__ . '/version'),
        'disks' => $disks,
        'endpoints' => [
            'GET /api/list' => 'List directory contents (?path=/folder&disktag=xxx)',
            'GET /api/file' => 'Get file metadata and download URL (?path=/to/file)',
            'GET /api/download' => 'Redirect to direct download URL (?path=/to/file)',
            'POST /api/upload' => 'Upload file (multipart: file= field, ?path=/folder)',
            'POST /api/upload-session' => 'Upload session for large files (JSON: name,size)',
            'POST /api/delete' => 'Delete file/folder (JSON or query: path)',
            'POST /api/mkdir' => 'Create folder (JSON or query: path,name)',
            'POST /api/rename' => 'Rename file/folder (JSON or query: path,name)',
            'POST /api/move' => 'Move file/folder (JSON or query: path,to)',
            'POST /api/copy' => 'Copy file/folder (JSON or query: path)',
        ],
        'auth' => 'Set api_key in admin panel. Pass via Authorization: Bearer <key> or ?api_key=<key>',
    ]);
}

function apiList($disktag) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available: ' . ($drive ? $drive->error['body'] : ''));
    $path = apiGet('path', '/');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $files = $drive->list_files($fullPath);
    if (isset($files['error'])) return apiError(500, json_encode($files['error']));

    if ($files['type'] === 'folder') {
        $result = [
            'type' => 'folder',
            'path' => $path,
            'name' => isset($files['name']) ? $files['name'] : '',
            'childcount' => isset($files['childcount']) ? $files['childcount'] : 0,
            'items' => [],
        ];
        if (isset($files['list'])) {
            foreach ($files['list'] as $key => $item) {
                if (isHideFile($key)) continue;
                $result['items'][] = [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'size' => isset($item['size']) ? $item['size'] : 0,
                    'sizeFormatted' => isset($item['size']) ? size_format($item['size']) : '',
                    'time' => isset($item['time']) ? $item['time'] : '',
                ];
            }
        }
        return apiResponse(200, $result);
    }

    if ($files['type'] === 'file') {
        return apiResponse(200, [
            'type' => 'file',
            'name' => isset($files['name']) ? $files['name'] : '',
            'size' => isset($files['size']) ? $files['size'] : 0,
            'sizeFormatted' => isset($files['size']) ? size_format($files['size']) : '',
            'time' => isset($files['time']) ? $files['time'] : '',
            'mime' => isset($files['mime']) ? $files['mime'] : '',
            'downloadUrl' => isset($files['url']) ? $files['url'] : '',
        ]);
    }

    return apiError(500, 'Unknown response from drive');
}

function apiFile($disktag) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = apiGet('path', '');
    if ($path === '' || $path === '/') return apiError(400, 'path parameter is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $file = $drive->list_files($fullPath);
    if (isset($file['error'])) return apiError(404, 'File not found');
    if ($file['type'] !== 'file') return apiError(400, 'Path is a folder');
    return apiResponse(200, [
        'name' => isset($file['name']) ? $file['name'] : '',
        'size' => isset($file['size']) ? $file['size'] : 0,
        'sizeFormatted' => isset($file['size']) ? size_format($file['size']) : '',
        'time' => isset($file['time']) ? $file['time'] : '',
        'mime' => isset($file['mime']) ? $file['mime'] : '',
        'downloadUrl' => isset($file['url']) ? $file['url'] : '',
    ]);
}

function apiDownload($disktag) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = apiGet('path', '');
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $file = $drive->list_files($fullPath);
    if (isset($file['error']) || $file['type'] !== 'file' || empty($file['url'])) {
        return apiError(404, 'File not found or not downloadable');
    }
    $url = $file['url'];
    $domainforproxy = getConfig('domainforproxy', $disktag);
    if ($domainforproxy) $url = proxy_replace_domain($url, $domainforproxy);
    return output('', 302, ['Location' => $url]);
}

function apiUpload($disktag) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    if (empty($_FILES['file'])) return apiError(400, 'No file provided (use multipart field name "file")');
    $path = apiGet('path', '/');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return apiError(400, 'Upload error: ' . $_FILES['file']['error']);
    }
    if ($_FILES['file']['size'] > 4 * 1024 * 1024) {
        return apiError(413, 'File too large (>4MB), use /api/upload-session for large files');
    }
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    return $drive->smallfileupload($fullPath, $_FILES['file']);
}

function apiUploadSession($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    if (empty($body['name'])) return apiError(400, '"name" is required');
    if (empty($body['size'])) return apiError(400, '"size" is required (bytes)');
    $path = isset($body['path']) ? $body['path'] : '/';
    $_POST['upbigfilename'] = $body['name'];
    $_POST['filesize'] = $body['size'];
    $_POST['filelastModified'] = isset($body['filelastModified']) ? $body['filelastModified'] : (time() . '000');
    if (isset($body['md5'])) $_POST['filemd5'] = $body['md5'];
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    return $drive->bigfileupload($fullPath);
}

function apiDelete($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $body ? (isset($body['path']) ? $body['path'] : '') : apiGet('path', '');
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => isset($body['id']) ? $body['id'] : ''];
    $result = $drive->Delete($file);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        $cachePath = $fullPath;
        if ($cachePath !== '/' && substr($cachePath, -1) === '/') $cachePath = substr($cachePath, 0, -1);
        savecache('path_' . $cachePath, '', $disktag, 1);
        return apiResponse(200, ['deleted' => $name]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiMkdir($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $body ? (isset($body['path']) ? $body['path'] : '/') : apiGet('path', '/');
    $name = $body ? (isset($body['name']) ? $body['name'] : '') : apiGet('name', '');
    if ($name === '') return apiError(400, '"name" is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $parent = ['path' => $fullPath, 'name' => '', 'id' => ''];
    $result = $drive->Create($parent, 'folder', $name);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $fullPath, '', $disktag, 1);
        return apiResponse(201, ['created' => path_format($fullPath . '/' . $name)]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiRename($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $body ? (isset($body['path']) ? $body['path'] : '') : apiGet('path', '');
    $newName = $body ? (isset($body['name']) ? $body['name'] : '') : apiGet('name', '');
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    if ($newName === '') return apiError(400, '"name" (new name) is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $decoded = urldecode($fullPath);
    $oldName = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $oldName, 'id' => isset($body['id']) ? $body['id'] : ''];
    $result = $drive->Rename($file, $newName);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $fullPath, '', $disktag, 1);
        savecache('path_' . path_format($parentPath), '', $disktag, 1);
        return apiResponse(200, ['renamed' => $oldName, 'to' => $newName]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiMove($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $body ? (isset($body['path']) ? $body['path'] : '') : apiGet('path', '');
    $to = $body ? (isset($body['to']) ? $body['to'] : '') : apiGet('to', '');
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    if ($to === '') return apiError(400, '"to" (destination path) is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $destPath = path_format($_SERVER['list_path'] . path_format($to));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => isset($body['id']) ? $body['id'] : ''];
    $folder = ['path' => $destPath, 'name' => '', 'id' => ''];
    $result = $drive->Move($file, $folder);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $fullPath, '', $disktag, 1);
        savecache('path_' . $destPath, '', $disktag, 1);
        return apiResponse(200, ['moved' => $name, 'to' => $to]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiCopy($disktag, $body) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $body ? (isset($body['path']) ? $body['path'] : '') : apiGet('path', '');
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $fullPath = path_format($_SERVER['list_path'] . path_format($path));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => isset($body['id']) ? $body['id'] : ''];
    $result = $drive->Copy($file);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $parentPath, '', $disktag, 1);
        return apiResponse(200, ['copied' => $name]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}
