<?php
// OneManager API handler - called from main() in common.php when path starts with /api

function apiHandler($path) {
    $route = trim(substr($path, 4), '/');

    $apiKey = apiAuth();
    if ($apiKey === false) {
        $publicEndpoints = ['list', 'file', 'download', 'info', ''];
        $base = apiGetAction($route);
        if (!in_array($base, $publicEndpoints)) {
            return apiResponse(401, null, 'Unauthorized: invalid api_key');
        }
    }

    $inputs = apiParseInput();

    if ($route === '' || $route === '/' || $route === 'info') {
        return apiInfo();
    }

    $action = apiGetAction($route);
    if (!$action) {
        return apiResponse(404, null, 'Unknown endpoint');
    }

    $disktag = apiResolveDisktag($inputs);
    if (!$disktag) {
        return apiResponse(400, null, 'No disk configured, add a disk in admin panel first');
    }
    $_SERVER['disktag'] = $disktag;

    // Authorize write operations
    $writeOps = ['upload', 'upload-session', 'delete', 'mkdir', 'rename', 'move', 'copy'];
    if (in_array($action, $writeOps) && !$_SERVER['admin']) {
        $_SERVER['admin'] = 1;
    }

    switch ($action) {
        case 'list': return apiList($disktag, $inputs);
        case 'file': return apiFile($disktag, $inputs);
        case 'download': return apiDownload($disktag, $inputs);
        case 'upload': return apiUpload($disktag, $inputs);
        case 'upload-session': return apiUploadSession($disktag, $inputs);
        case 'delete': return apiDelete($disktag, $inputs);
        case 'mkdir': return apiMkdir($disktag, $inputs);
        case 'rename': return apiRename($disktag, $inputs);
        case 'move': return apiMove($disktag, $inputs);
        case 'copy': return apiCopy($disktag, $inputs);
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

function apiParseInput() {
    $inputs = $_GET;
    if (count($_POST) > 0) $inputs = array_merge($inputs, $_POST);
    $rawBody = file_get_contents('php://input');
    if ($rawBody && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $json = json_decode($rawBody, true);
        if ($json) $inputs = array_merge($inputs, $json);
    }
    return $inputs;
}

function apiGetAction($route) {
    $parts = explode('/', $route);
    $base = $parts[0];
    if (strpos($base, '?') !== false) $base = substr($base, 0, strpos($base, '?'));
    return $base === '' ? null : $base;
}

function apiResolveDisktag($inputs) {
    if (isset($inputs['disktag']) && $inputs['disktag'] !== '') return $inputs['disktag'];
    $disktags = explode('|', getConfig('disktag'));
    if (count($disktags) > 0 && $disktags[0] !== '') return $disktags[0];
    return null;
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

function apiList($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available: ' . ($drive ? $drive->error['body'] : ''));
    $path = isset($inputs['path']) ? $inputs['path'] : '/';
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $files = $drive->list_files($fullPath);
    if (isset($files['error'])) return apiError(500, json_encode($files['error']));

    if ($files['type'] === 'folder') {
        $result = [
            'type' => 'folder',
            'path' => $path,
            'name' => $files['name'] ?? '',
            'childcount' => $files['childcount'] ?? 0,
            'items' => [],
        ];
        if (isset($files['list'])) {
            foreach ($files['list'] as $key => $item) {
                if (isHideFile($key)) continue;
                $result['items'][] = [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'size' => $item['size'] ?? 0,
                    'sizeFormatted' => isset($item['size']) ? size_format($item['size']) : '',
                    'time' => $item['time'] ?? '',
                ];
            }
        }
        return apiResponse(200, $result);
    }

    if ($files['type'] === 'file') {
        return apiResponse(200, [
            'type' => 'file',
            'name' => $files['name'] ?? '',
            'size' => $files['size'] ?? 0,
            'sizeFormatted' => isset($files['size']) ? size_format($files['size']) : '',
            'time' => $files['time'] ?? '',
            'mime' => $files['mime'] ?? '',
            'downloadUrl' => $files['url'] ?? '',
        ]);
    }

    return apiError(500, 'Unknown response from drive');
}

function apiFile($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path parameter is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $file = $drive->list_files($fullPath);
    if (isset($file['error'])) return apiError(404, 'File not found');
    if ($file['type'] !== 'file') return apiError(400, 'Path is a folder');
    return apiResponse(200, [
        'name' => $file['name'] ?? '',
        'size' => $file['size'] ?? 0,
        'sizeFormatted' => isset($file['size']) ? size_format($file['size']) : '',
        'time' => $file['time'] ?? '',
        'mime' => $file['mime'] ?? '',
        'downloadUrl' => $file['url'] ?? '',
    ]);
}

function apiDownload($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $file = $drive->list_files($fullPath);
    if (isset($file['error']) || $file['type'] !== 'file' || empty($file['url'])) {
        return apiError(404, 'File not found or not downloadable');
    }
    $url = $file['url'];
    $domainforproxy = getConfig('domainforproxy', $disktag);
    if ($domainforproxy) $url = proxy_replace_domain($url, $domainforproxy);
    return output('', 302, ['Location' => $url]);
}

function apiUpload($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    if (empty($_FILES['file'])) return apiError(400, 'No file provided (use multipart field name "file")');
    $path = $inputs['path'] ?? '/';
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return apiError(400, 'Upload error: ' . $_FILES['file']['error']);
    }
    if ($_FILES['file']['size'] > 4 * 1024 * 1024) {
        return apiError(413, 'File too large (>4MB), use /api/upload-session for large files');
    }
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    return $drive->smallfileupload($fullPath, $_FILES['file']);
}

function apiUploadSession($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    if (empty($inputs['name'])) return apiError(400, '"name" is required');
    if (empty($inputs['size'])) return apiError(400, '"size" is required (bytes)');
    $path = $inputs['path'] ?? '/';
    $_POST['upbigfilename'] = $inputs['name'];
    $_POST['filesize'] = $inputs['size'];
    $_POST['filelastModified'] = $inputs['filelastModified'] ?? (time() . '000');
    if (isset($inputs['md5'])) $_POST['filemd5'] = $inputs['md5'];
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    return $drive->bigfileupload($fullPath);
}

function apiDelete($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => $inputs['id'] ?? ''];
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

function apiMkdir($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '/';
    $name = $inputs['name'] ?? '';
    if ($name === '') return apiError(400, '"name" is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $parent = ['path' => $fullPath, 'name' => '', 'id' => ''];
    $result = $drive->Create($parent, 'folder', $name);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $fullPath, '', $disktag, 1);
        return apiResponse(201, ['created' => path_format($fullPath . '/' . $name)]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiRename($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    $newName = $inputs['name'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    if ($newName === '') return apiError(400, '"name" (new name) is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $decoded = urldecode($fullPath);
    $oldName = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $oldName, 'id' => $inputs['id'] ?? ''];
    $result = $drive->Rename($file, $newName);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $fullPath, '', $disktag, 1);
        savecache('path_' . path_format($parentPath), '', $disktag, 1);
        return apiResponse(200, ['renamed' => $oldName, 'to' => $newName]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}

function apiMove($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    $to = $inputs['to'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    if ($to === '') return apiError(400, '"to" (destination path) is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $destPath = path_format($listPath . path_format($to));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => $inputs['id'] ?? ''];
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

function apiCopy($disktag, $inputs) {
    global $drive;
    if (!apiDriveOk($disktag)) return apiError(503, 'Drive not available');
    $path = $inputs['path'] ?? '';
    if ($path === '' || $path === '/') return apiError(400, 'path is required');
    $listPath = getListpath($_SERVER['HTTP_HOST']);
    $fullPath = path_format($listPath . path_format($path));
    $decoded = urldecode($fullPath);
    $name = splitlast($decoded, '/')[1];
    $parentPath = splitlast($decoded, '/')[0];
    if ($parentPath === '') $parentPath = '/';
    $file = ['path' => $parentPath, 'name' => $name, 'id' => $inputs['id'] ?? ''];
    $result = $drive->Copy($file);
    $parsed = json_decode($result['body'], true);
    if ($result['statusCode'] < 400 && (!$parsed || !isset($parsed['error']))) {
        savecache('path_' . $parentPath, '', $disktag, 1);
        return apiResponse(200, ['copied' => $name]);
    }
    return output($result['body'], $result['statusCode'], ['Content-Type' => 'application/json']);
}
