<?php

function api_response($ok, $data = null, $error = null) {
    $body = json_encode([
        'ok' => $ok,
        'data' => $data,
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $status = 200;
    if ($error) {
        $status = isset($error['code']) ? (int)$error['code'] : 500;
    }
    return output($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
}

function api_check_auth() {
    $token = getConfig('apitoken');
    if ($token === '') {
        return api_response(false, null, ['code' => 500, 'message' => 'API token not configured. Generate one in admin panel ?setup']);
    }
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($auth === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (strncmp($auth, 'Bearer ', 7) !== 0) {
        return api_response(false, null, ['code' => 401, 'message' => 'Missing or invalid Authorization header. Use: Authorization: Bearer <token>']);
    }
    $provided = substr($auth, 7);
    if (!hash_equals($token, $provided)) {
        return api_response(false, null, ['code' => 401, 'message' => 'Invalid API token']);
    }
    return true;
}

function api_read_body_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return null;
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $data;
}

function api_parse_path_from_url($url_path) {
    $p = strpos($url_path, '?');
    if ($p !== false) return substr($url_path, 0, $p);
    return $url_path;
}

function api_get_query_path() {
    $path = isset($_GET['path']) ? $_GET['path'] : '/';
    $path = path_format($path);
    return $path;
}

function api_handle_request($full_path) {
    global $slash;
    global $drive;

    if (!function_exists('curl_init')) {
        return api_response(false, null, ['code' => 500, 'message' => 'PHP curl extension not available']);
    }

    $auth = api_check_auth();
    if ($auth !== true) return $auth;

    $parts = explode('/', trim($full_path, '/'));
    if (count($parts) < 2) {
        return api_response(false, null, ['code' => 400, 'message' => 'Usage: /api/{disktag}/{action}']);
    }
    $disktag = $parts[1];
    $action = isset($parts[2]) ? $parts[2] : '';

    $disktags = explode("|", getConfig('disktag'));
    if (!in_array($disktag, $disktags)) {
        return api_response(false, null, ['code' => 404, 'message' => 'Disk "' . $disktag . '" not found']);
    }
    $_SERVER['disktag'] = $disktag;
    $_SERVER['list_path'] = getListpath($_SERVER['HTTP_HOST']);
    if ($_SERVER['list_path'] == '') $_SERVER['list_path'] = '/';
    $_SERVER['admin'] = 1;

    if (!driveisfine($disktag, $drive)) {
        return api_response(false, null, ['code' => 503, 'message' => 'Disk "' . $disktag . '" is not available']);
    }

    switch ($action) {
        case 'list':
            return api_action_list($disktag, $drive);
        case 'info':
            return api_action_info($disktag, $drive);
        case 'download':
            return api_action_download($disktag, $drive);
        case 'upload':
            return api_action_upload($disktag, $drive);
        case 'upload_init':
            return api_action_upload_init($disktag, $drive);
        case 'upload_status':
            return api_action_upload_status($disktag, $drive);
        case 'delete':
            return api_action_delete($disktag, $drive);
        case 'rename':
            return api_action_rename($disktag, $drive);
        case 'move':
            return api_action_move($disktag, $drive);
        case 'copy':
            return api_action_copy($disktag, $drive);
        case 'create':
            return api_action_create($disktag, $drive);
        case 'edit':
            return api_action_edit($disktag, $drive);
        case 'diskspace':
            return api_action_diskspace($disktag, $drive);
        default:
            return api_response(false, null, [
                'code' => 404,
                'message' => 'Unknown action "' . $action . '". Valid: list, info, download, upload, upload_init, upload_status, delete, rename, move, copy, create, edit, diskspace'
            ]);
    }
}

function api_resolve_path($disktag, $path) {
    $path1 = path_format($_SERVER['list_path'] . path_format($path));
    if ($path1 != '/' && substr($path1, -1) == '/') $path1 = substr($path1, 0, -1);
    return $path1;
}

function api_action_list($disktag, $drive) {
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $files = $drive->list_files($path1);
    if (isset($files['error'])) {
        return api_response(false, null, [
            'code' => isset($files['error']['stat']) ? (int)$files['error']['stat'] : 500,
            'message' => isset($files['error']['message']) ? $files['error']['message'] : 'Error listing files'
        ]);
    }
    unset($files['url']);
    foreach (isset($files['list']) ? $files['list'] : [] as $k => $v) {
        unset($files['list'][$k]['url']);
    }
    return api_response(true, $files);
}

function api_action_info($disktag, $drive) {
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $files = $drive->list_files($path1);
    if (isset($files['error'])) {
        return api_response(false, null, [
            'code' => isset($files['error']['stat']) ? (int)$files['error']['stat'] : 500,
            'message' => isset($files['error']['message']) ? $files['error']['message'] : 'Error getting file info'
        ]);
    }
    unset($files['url']);
    return api_response(true, $files);
}

function api_action_download($disktag, $drive) {
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $files = $drive->list_files($path1);
    if (isset($files['error'])) {
        return api_response(false, null, [
            'code' => isset($files['error']['stat']) ? (int)$files['error']['stat'] : 500,
            'message' => isset($files['error']['message']) ? $files['error']['message'] : 'Error resolving download'
        ]);
    }
    if ($files['type'] !== 'file') {
        return api_response(false, null, ['code' => 400, 'message' => 'Path is not a file']);
    }
    $url = $files['url'];
    $domainforproxy = getConfig('domainforproxy', $disktag);
    if ($domainforproxy != '') {
        $url = proxy_replace_domain($url, $domainforproxy);
    }
    $header['Location'] = $url;
    return output('', 302, $header);
}

function api_action_upload($disktag, $drive) {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err_code = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
        return api_response(false, null, ['code' => 400, 'message' => 'File upload error code: ' . $err_code]);
    }
    if ($_FILES['file']['size'] > 4 * 1024 * 1024) {
        return api_response(false, null, ['code' => 413, 'message' => 'File too large (max 4MB). Use the web UI for larger files.']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $result = $drive->smallfileupload($path1, $_FILES['file']);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : $result['body']
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_upload_init($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['name']) || !isset($data['size'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "name" and "size" fields']);
    }
    $name = $data['name'];
    $size = (int)$data['size'];
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $tmp = splitlast($name, '/');
    $filename = spurlencode($tmp[1] != '' ? $tmp[1] : $name, '/');
    $file_path = path_format($path1 . '/' . $filename);

    if (!method_exists($drive, 'createUploadSession')) {
        return api_response(false, null, ['code' => 501, 'message' => 'Large file upload not supported by this disk driver']);
    }

    $decoded = $drive->createUploadSession($file_path);
    if (isset($decoded['error'])) {
        return api_response(false, null, [
            'code' => 500,
            'message' => $decoded['error']['message']
        ]);
    }
    if (!isset($decoded['uploadUrl'])) {
        return api_response(false, null, ['code' => 500, 'message' => 'Failed to create upload session']);
    }

    $chunk_size = 10 * 1024 * 1024;
    $total_chunks = (int)ceil($size / $chunk_size);

    return api_response(true, [
        'upload_url' => $decoded['uploadUrl'],
        'file_path' => $file_path,
        'file_name' => $filename,
        'total_size' => $size,
        'chunk_size' => $chunk_size,
        'total_chunks' => $total_chunks,
        'expires' => isset($decoded['expirationDateTime']) ? $decoded['expirationDateTime'] : null,
        'instructions' => [
            'description' => 'Upload file in chunks using HTTP PUT to the upload_url',
            'method' => 'PUT',
            'headers' => ['Content-Range' => 'bytes {start}-{end}/{total}', 'Content-Length' => '{chunk_size}'],
            'body' => 'binary file chunk',
            'example' => 'curl -X PUT -H "Content-Range: bytes 0-10485750/{size}" --data-binary @chunk.bin "{upload_url}"'
        ]
    ]);
}

function api_action_upload_status($disktag, $drive) {
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $files = $drive->list_files($path1);
    if (isset($files['error'])) {
        return api_response(false, null, [
            'code' => isset($files['error']['stat']) ? (int)$files['error']['stat'] : 500,
            'message' => isset($files['error']['message']) ? $files['error']['message'] : 'File not found'
        ]);
    }
    if ($files['type'] !== 'file') {
        return api_response(false, null, ['code' => 404, 'message' => 'File not found or is a folder']);
    }
    return api_response(true, [
        'exists' => true,
        'name' => $files['name'],
        'size' => $files['size'],
        'time' => $files['time'],
        'mime' => isset($files['mime']) ? $files['mime'] : null,
    ]);
}

function api_action_delete($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['name'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "name" field']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $file = [
        'path' => $path1,
        'name' => $data['name'],
        'id' => isset($data['id']) ? $data['id'] : '',
    ];
    $result = $drive->Delete($file);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Delete failed'
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_rename($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['oldname']) || !isset($data['newname'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "oldname" and "newname" fields']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $file = [
        'path' => $path1,
        'name' => $data['oldname'],
        'id' => isset($data['id']) ? $data['id'] : '',
    ];
    $result = $drive->Rename($file, $data['newname']);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Rename failed'
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_move($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['name']) || !isset($data['folder'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "name" and "folder" fields']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $file = [
        'path' => $path1,
        'name' => $data['name'],
        'id' => isset($data['id']) ? $data['id'] : '',
    ];
    $folderpath = path_format('/' . urldecode($path1) . '/' . $data['folder']);
    $folder = [
        'path' => $folderpath,
        'name' => $data['folder'],
        'id' => '',
    ];
    $result = $drive->Move($file, $folder);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Move failed'
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_copy($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['name'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "name" field']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $file = [
        'path' => $path1,
        'name' => $data['name'],
        'id' => isset($data['id']) ? $data['id'] : '',
    ];
    $result = $drive->Copy($file);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Copy failed'
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_create($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['type']) || !isset($data['name'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "type" (file|folder) and "name" fields']);
    }
    if ($data['type'] !== 'file' && $data['type'] !== 'folder') {
        return api_response(false, null, ['code' => 400, 'message' => '"type" must be "file" or "folder"']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $parent = [
        'path' => $path1,
        'name' => '',
        'id' => '',
    ];
    $content = isset($data['content']) ? $data['content'] : '';
    $result = $drive->Create($parent, $data['type'], $data['name'], $content);
    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['raw' => $result['body']];
    }
    if ($result['statusCode'] >= 400) {
        return api_response(false, null, [
            'code' => $result['statusCode'],
            'message' => isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Create failed'
        ]);
    }
    return api_response(true, $decoded);
}

function api_action_edit($disktag, $drive) {
    $data = api_read_body_json();
    if (!$data || !isset($data['name']) || !isset($data['content'])) {
        return api_response(false, null, ['code' => 400, 'message' => 'JSON body required with "name" and "content" fields']);
    }
    $path = api_get_query_path();
    $path1 = api_resolve_path($disktag, $path);
    $file = [
        'path' => path_format($path1 . '/' . $data['name']),
        'name' => $data['name'],
        'id' => '',
    ];
    $result = $drive->Edit($file, $data['content']);
    $body = $result['body'];
    if (trim($body) === 'success') {
        return api_response(true, ['name' => $data['name'], 'path' => path_format($path . '/' . $data['name'])]);
    }
    return api_response(false, null, ['code' => $result['statusCode'], 'message' => $body]);
}

function api_action_diskspace($disktag, $drive) {
    $space = $drive->getDiskSpace();
    return api_response(true, ['space' => $space]);
}
