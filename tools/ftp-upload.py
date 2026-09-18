#!/usr/bin/env python3
import os
import sys
from ftplib import FTP_TLS, error_perm

HOSTS = ['ftpupload.net', 'ftp.epizy.com']
USER = os.environ.get('SMS2_FTP_USER', '')
PASSWORD = os.environ.get('SMS2_FTP_PASS', '')
LOCAL = os.environ.get('SMS2_DEPLOY_STAGING', r'C:\xampp\htdocs\sms2_deploy_staging')
REMOTE_ROOT = '/htdocs'


def upload_tree(ftp, local_dir, remote_dir):
    try:
        ftp.mkd(remote_dir)
    except error_perm:
        pass
    ftp.cwd(remote_dir)
    for name in sorted(os.listdir(local_dir)):
        local_path = os.path.join(local_dir, name)
        if os.path.isdir(local_path):
            upload_tree(ftp, local_path, name)
            ftp.cwd('..')
        else:
            print(f'Uploading {remote_dir}/{name}')
            with open(local_path, 'rb') as source:
                ftp.storbinary(f'STOR {name}', source)


if not USER or not PASSWORD:
    print('Set SMS2_FTP_USER and SMS2_FTP_PASS before running this tool.')
    sys.exit(2)

for host in HOSTS:
    print(f'Trying {host}...')
    try:
        ftp = FTP_TLS()
        ftp.connect(host, 21, timeout=60)
        ftp.auth()
        ftp.prot_p()
        ftp.login(USER, PASSWORD)
        print(f'Logged in to {host}')
        upload_tree(ftp, LOCAL, REMOTE_ROOT)
        ftp.quit()
        print('Upload complete.')
        sys.exit(0)
    except Exception as error:
        print(f'Failed on {host}: {error}')

sys.exit(1)
