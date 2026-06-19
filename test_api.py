#!/usr/bin/env python3
"""
OneManager-php API Test Script

Usage:
    python test_api.py <base_url> <api_token> [disktag]

Example:
    python test_api.py http://localhost:8000 abc123def456 onedrive

Requirements:
    pip install requests
"""

import sys
import json
import time
import os
import requests

class UploadProgress:
    def __init__(self, total_size, filename=''):
        self.total_size = total_size
        self.uploaded = 0
        self.filename = filename
        self.start_time = time.time()

    def update(self, chunk_size):
        self.uploaded += chunk_size
        elapsed = time.time() - self.start_time
        speed = self.uploaded / elapsed if elapsed > 0 else 0
        percent = (self.uploaded / self.total_size) * 100 if self.total_size > 0 else 0
        bar_len = 30
        filled = int(bar_len * self.uploaded / self.total_size) if self.total_size > 0 else 0
        bar = '#' * filled + '-' * (bar_len - filled)
        sys.stdout.write(f'\r  [{bar}] {percent:.1f}% {self.uploaded}/{self.total_size} bytes ({speed:.0f} bytes/s)')
        sys.stdout.flush()

    def done(self):
        elapsed = time.time() - self.start_time
        speed = self.uploaded / elapsed if elapsed > 0 else 0
        sys.stdout.write(f'\r  [{"#" * 30}] 100.0% {self.uploaded}/{self.total_size} bytes ({speed:.0f} bytes/s)\n')
        sys.stdout.flush()


class ProgressFileWrapper:
    def __init__(self, file_obj, progress_callback):
        self.file_obj = file_obj
        self.progress_callback = progress_callback

    def read(self, size=-1):
        chunk = self.file_obj.read(size)
        if chunk:
            self.progress_callback(len(chunk))
        return chunk

    def __getattr__(self, attr):
        return getattr(self.file_obj, attr)


class APITester:
    def __init__(self, base_url, token, disktag='onedrive'):
        self.base_url = base_url.rstrip('/')
        self.token = token
        self.disktag = disktag
        self.headers = {'Authorization': f'Bearer {token}'}
        self.results = []

    def log(self, test_name, passed, detail=''):
        status = 'PASS' if passed else 'FAIL'
        self.results.append({'test': test_name, 'passed': passed, 'detail': detail})
        print(f'  [{status}] {test_name}' + (f' - {detail}' if detail else ''))

    def test_no_auth(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/')
        passed = r.status_code == 401
        self.log('No auth returns 401', passed, f'status={r.status_code}')

    def test_invalid_token(self):
        headers = {'Authorization': 'Bearer invalid_token_12345'}
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/', headers=headers)
        passed = r.status_code == 401
        self.log('Invalid token returns 401', passed, f'status={r.status_code}')

    def test_invalid_disktag(self):
        r = requests.get(f'{self.base_url}/api/nonexistent_disk/list?path=/', headers=self.headers)
        passed = r.status_code == 404
        self.log('Invalid disktag returns 404', passed, f'status={r.status_code}')

    def test_invalid_action(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/nonexistent_action', headers=self.headers)
        passed = r.status_code == 404
        self.log('Invalid action returns 404', passed, f'status={r.status_code}')

    def test_list_root(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/', headers=self.headers)
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True and 'data' in data
        self.log('List root directory', passed, f'status={r.status_code}')

    def test_list_subfolder(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/', headers=self.headers)
        data = r.json()
        if data.get('ok') and data['data'].get('type') == 'folder' and data['data'].get('list'):
            first_item = list(data['data']['list'].values())[0]
            if first_item['type'] == 'folder':
                folder_name = first_item['name']
                r2 = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/{folder_name}', headers=self.headers)
                passed = r2.status_code == 200 and r2.json().get('ok') is True
                self.log('List subfolder', passed, f'status={r2.status_code}, folder={folder_name}')
                return
        self.log('List subfolder', True, 'skipped - no subfolder found')

    def test_list_nonexistent(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/nonexistent_path_12345', headers=self.headers)
        passed = r.status_code in [404, 500]
        self.log('List nonexistent path', passed, f'status={r.status_code}')

    def test_info(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/', headers=self.headers)
        data = r.json()
        if data.get('ok') and data['data'].get('list'):
            first_item = list(data['data']['list'].values())[0]
            item_name = first_item['name']
            r2 = requests.get(f'{self.base_url}/api/{self.disktag}/info?path=/{item_name}', headers=self.headers)
            passed = r2.status_code == 200 and r2.json().get('ok') is True
            self.log('Get file info', passed, f'status={r2.status_code}, file={item_name}')
        else:
            self.log('Get file info', True, 'skipped - no files found')

    def test_download(self):
        r = requests.get(f'{self.base_url}/api/{self.disktag}/list?path=/', headers=self.headers)
        data = r.json()
        if data.get('ok') and data['data'].get('list'):
            for item in data['data']['list'].values():
                if item['type'] == 'file':
                    r2 = requests.get(
                        f'{self.base_url}/api/{self.disktag}/download?path=/{item["name"]}',
                        headers=self.headers,
                        allow_redirects=False
                    )
                    passed = r2.status_code == 302
                    self.log('Download file (302 redirect)', passed,
                             f'status={r2.status_code}, file={item["name"]}')
                    return
        self.log('Download file', True, 'skipped - no files found')

    def test_upload(self):
        import io
        test_content = f'OneManager API test {time.time()}'
        files = {'file': ('test_api.txt', io.BytesIO(test_content.encode()), 'text/plain')}
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/upload?path=/',
            headers=self.headers,
            files=files
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Upload file', passed, f'status={r.status_code}')
        return 'test_api.txt'

    def test_upload_too_large(self):
        import io
        large_content = b'x' * (5 * 1024 * 1024)
        files = {'file': ('large_test.txt', io.BytesIO(large_content), 'text/plain')}
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/upload?path=/',
            headers=self.headers,
            files=files
        )
        passed = r.status_code == 413
        self.log('Upload too large returns 413', passed, f'status={r.status_code}')

    def test_upload_large_init(self):
        import random
        test_size = 25 * 1024 * 1024
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/upload_init?path=/',
            headers=self.headers,
            json={'name': f'large_test_{int(time.time())}.bin', 'size': test_size}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True and 'data' in data
            if passed:
                upload_info = data['data']
                self.log('Large upload init', True,
                         f'chunks={upload_info["total_chunks"]}, chunk_size={upload_info["chunk_size"]//1024}KB')
                return upload_info
        self.log('Large upload init', passed, f'status={r.status_code}')
        return None

    def test_upload_large_chunks(self, upload_info):
        if not upload_info:
            self.log('Large upload chunks', True, 'skipped - no upload session')
            return
        upload_url = upload_info['upload_url']
        total_size = upload_info['total_size']
        chunk_size = upload_info['chunk_size']
        total_chunks = upload_info['total_chunks']
        progress = UploadProgress(total_size, upload_info['file_name'])
        for i in range(total_chunks):
            start = i * chunk_size
            end = min(start + chunk_size, total_size) - 1
            chunk_data = os.urandom(end - start + 1)
            headers = {
                'Content-Range': f'bytes {start}-{end}/{total_size}',
                'Content-Length': str(len(chunk_data))
            }
            r = requests.put(upload_url, headers=headers, data=chunk_data)
            progress.update(len(chunk_data))
            if r.status_code not in [200, 201, 202]:
                progress.done()
                self.log('Large upload chunks', False, f'chunk {i+1}/{total_chunks} failed: {r.status_code}')
                return
        progress.done()
        self.log('Large upload chunks', True, f'{total_chunks} chunks uploaded')

    def test_upload_large_status(self, upload_info):
        if not upload_info:
            self.log('Large upload status', True, 'skipped - no upload session')
            return
        file_path = upload_info['file_path']
        r = requests.get(
            f'{self.base_url}/api/{self.disktag}/upload_status?path={file_path}',
            headers=self.headers
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True and data.get('data', {}).get('exists') is True
        self.log('Large upload status', passed, f'status={r.status_code}')

    def test_upload_with_progress(self):
        import io
        test_size = 2 * 1024 * 1024
        test_content = os.urandom(test_size)
        progress = UploadProgress(test_size, 'progress_test.bin')
        file_obj = io.BytesIO(test_content)
        wrapped = ProgressFileWrapper(file_obj, progress.update)
        files = {'file': ('progress_test.bin', wrapped, 'application/octet-stream')}
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/upload?path=/',
            headers=self.headers,
            files=files
        )
        progress.done()
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Upload with progress display', passed, f'status={r.status_code}')

    def test_create_folder(self):
        folder_name = f'test_folder_{int(time.time())}'
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/create?path=/',
            headers=self.headers,
            json={'type': 'folder', 'name': folder_name}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Create folder', passed, f'status={r.status_code}, name={folder_name}')
        return folder_name

    def test_create_file(self):
        file_name = f'test_create_{int(time.time())}.txt'
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/create?path=/',
            headers=self.headers,
            json={'type': 'file', 'name': file_name, 'content': 'created via API'}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Create file', passed, f'status={r.status_code}, name={file_name}')
        return file_name

    def test_edit_file(self, filename):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/edit?path=/',
            headers=self.headers,
            json={'name': filename, 'content': 'edited content via API'}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Edit file', passed, f'status={r.status_code}, name={filename}')

    def test_rename_file(self, oldname, newname):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/rename?path=/',
            headers=self.headers,
            json={'oldname': oldname, 'newname': newname}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Rename file', passed, f'status={r.status_code}, {oldname} -> {newname}')
        return newname

    def test_copy_file(self, filename):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/copy?path=/',
            headers=self.headers,
            json={'name': filename}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Copy file', passed, f'status={r.status_code}')

    def test_move_file(self, filename, folder):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/move?path=/',
            headers=self.headers,
            json={'name': filename, 'folder': folder}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Move file', passed, f'status={r.status_code}')

    def test_delete_file(self, filename):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/delete?path=/',
            headers=self.headers,
            json={'name': filename}
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True
        self.log('Delete file', passed, f'status={r.status_code}, name={filename}')

    def test_delete_nonexistent(self):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/delete?path=/',
            headers=self.headers,
            json={'name': 'nonexistent_file_12345.txt'}
        )
        passed = r.status_code in [404, 500]
        self.log('Delete nonexistent returns error', passed, f'status={r.status_code}')

    def test_diskspace(self):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/diskspace',
            headers=self.headers
        )
        passed = r.status_code == 200
        if passed:
            data = r.json()
            passed = data.get('ok') is True and 'data' in data
        self.log('Get disk space', passed, f'status={r.status_code}')

    def test_create_missing_fields(self):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/create?path=/',
            headers=self.headers,
            json={'type': 'file'}
        )
        passed = r.status_code == 400
        self.log('Create missing name returns 400', passed, f'status={r.status_code}')

    def test_delete_missing_fields(self):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/delete?path=/',
            headers=self.headers,
            json={}
        )
        passed = r.status_code == 400
        self.log('Delete missing name returns 400', passed, f'status={r.status_code}')

    def test_rename_missing_fields(self):
        r = requests.post(
            f'{self.base_url}/api/{self.disktag}/rename?path=/',
            headers=self.headers,
            json={'oldname': 'a.txt'}
        )
        passed = r.status_code == 400
        self.log('Rename missing newname returns 400', passed, f'status={r.status_code}')

    def run_all(self):
        print(f'\n{"="*50}')
        print(f'OneManager API Tests')
        print(f'Base URL: {self.base_url}')
        print(f'Disk tag: {self.disktag}')
        print(f'{"="*50}\n')

        print('[1] Authentication tests')
        self.test_no_auth()
        self.test_invalid_token()

        print('\n[2] Validation tests')
        self.test_invalid_disktag()
        self.test_invalid_action()
        self.test_create_missing_fields()
        self.test_delete_missing_fields()
        self.test_rename_missing_fields()

        print('\n[3] List & Info tests')
        self.test_list_root()
        self.test_list_subfolder()
        self.test_list_nonexistent()
        self.test_info()

        print('\n[4] Download test')
        self.test_download()

        print('\n[5] Upload tests (small)')
        self.test_upload()
        self.test_upload_too_large()

        print('\n[6] Upload with progress')
        self.test_upload_with_progress()

        print('\n[7] Large file upload with progress')
        upload_info = self.test_upload_large_init()
        self.test_upload_large_chunks(upload_info)
        self.test_upload_large_status(upload_info)

        print('\n[8] CRUD tests')
        folder_name = self.test_create_folder()
        created_file = self.test_create_file()
        if created_file:
            self.test_edit_file(created_file)
            self.test_copy_file(created_file)
            renamed = self.test_rename_file(created_file, f'renamed_{created_file}')
            if renamed:
                self.test_delete_file(renamed)
        if folder_name:
            self.test_delete_file(folder_name)

        print('\n[9] Delete nonexistent test')
        self.test_delete_nonexistent()

        print('\n[10] Disk space test')
        self.test_diskspace()

        passed = sum(1 for r in self.results if r['passed'])
        failed = sum(1 for r in self.results if not r['passed'])
        total = len(self.results)

        print(f'\n{"="*50}')
        print(f'Results: {passed}/{total} passed, {failed} failed')
        print(f'{"="*50}\n')

        return failed == 0


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)

    base_url = sys.argv[1]
    token = sys.argv[2]
    disktag = sys.argv[3] if len(sys.argv) > 3 else 'onedrive'

    tester = APITester(base_url, token, disktag)
    success = tester.run_all()
    sys.exit(0 if success else 1)
