"""Linux/root CLI: rehearse a Gayatri backup in restricted disposable databases.

Never boots the app, runs jobs, or restores production environment/gateway files.
Only backup files under /srv/gayatri/backups/daily are accepted.
"""
import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--backup', required=True)
    args = parser.parse_args()
    source = Path(args.backup).resolve(strict=True)
    allowed = Path('/srv/gayatri/backups/daily').resolve(strict=True)
    if os.name != 'posix' or os.geteuid() != 0 or source.parent != allowed or not re.fullmatch(r'gayatri-\d{8}T\d{6}Z\.tar\.gz', source.name):
        raise SystemExit('Requires root and a Gayatri daily backup path.')
    os.umask(0o077)
    token = secrets.token_hex(6)
    work = allowed.parent / ('restore-check-' + token)
    work.mkdir(mode=0o700)
    databases = ['gayatri_verify_' + token + suffix for suffix in ['a', 'b']]
    user = 'gayatri_v_' + token
    password = secrets.token_hex(24)
    cnf = work / 'client.cnf'
    cnf.write_text('[client]\nuser=' + user + '\npassword=' + password + '\nprotocol=socket\n')
    started = time.monotonic()
    created = []
    user_created = False

    def command(argv, content=None):
        p = subprocess.run(argv, input=content, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if p.returncode:
            # Client stderr can include SQL rows or configuration details.
            raise RuntimeError('Restore verification command failed: ' + argv[0])
        return p.stdout

    def admin(sql):
        return command(['mariadb', '--batch', '--skip-column-names'], sql.encode())

    def query(db, sql):
        return command(['mariadb', '--defaults-extra-file=' + str(cnf), '--batch', '--skip-column-names', '--database=' + db], sql.encode())

    try:
        with tarfile.open(source, 'r:gz') as backup:
            sql_member = next(m for m in backup.getmembers() if m.name in ['database.sql.gz', './database.sql.gz'])
            storage_member = next(m for m in backup.getmembers() if m.name in ['storage.tar.gz', './storage.tar.gz'])
            sql = gzip.decompress(backup.extractfile(sql_member).read())
            storage = backup.extractfile(storage_member).read()
        if re.search(rb'(?im)^\s*(?:CREATE|DROP)\s+DATABASE\b|^\s*USE\s+', sql):
            raise RuntimeError('Backup unexpectedly selects or creates a database.')
        admin(f"CREATE USER '{user}'@'localhost' IDENTIFIED BY '{password}';")
        user_created = True
        for db in databases:
            admin(f'CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            created.append(db)
            admin(f"GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'localhost';")
        query(databases[0], sql.decode('utf-8'))
        dump_args = ['mariadb-dump', '--defaults-extra-file=' + str(cnf), '--skip-comments', '--skip-add-locks', '--skip-lock-tables', '--skip-disable-keys', '--order-by-primary', '--no-tablespaces']
        roundtrip = command(dump_args + [databases[0]])
        query(databases[1], roundtrip.decode('utf-8'))
        repeated = command(dump_args + [databases[1]])
        if roundtrip != repeated:
            raise RuntimeError('Restored schema/data differ after the second restore.')
        tables = query(databases[0], 'SHOW TABLES;').decode().splitlines()
        if len(tables) < 50 or not {'bookings', 'rental_orders', 'laundry_orders', 'customers', 'app_settings'}.issubset(tables):
            raise RuntimeError('Backup is missing required tables.')
        counts = {}
        for table in tables:
            if not re.fullmatch(r'[a-zA-Z0-9_]+', table):
                raise RuntimeError('Unexpected table identifier.')
            check = query(databases[0], f'CHECK TABLE `{table}`;').decode()
            if not check.rstrip().endswith('status\tOK'):
                raise RuntimeError('Restored table integrity check failed.')
            counts[table] = int(query(databases[0], f'SELECT COUNT(*) FROM `{table}`;'))
        fk_rows = query(databases[0], "SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION;").decode().splitlines()
        foreign_keys = {}
        for row in fk_rows:
            name, table, col, parent, parent_col = row.split('\t')
            if not all(re.fullmatch('[a-zA-Z0-9_]+', x) for x in [name, table, col, parent, parent_col]):
                raise RuntimeError('Unexpected constraint identifier.')
            foreign_keys.setdefault((name, table, parent), []).append((col, parent_col))
        for (_, table, parent), columns in foreign_keys.items():
            join = ' AND '.join(f'c.`{c}`=p.`{p}`' for c, p in columns)
            present = ' AND '.join(f'c.`{c}` IS NOT NULL' for c, _ in columns)
            orphan_count = int(query(databases[0], f'SELECT COUNT(*) FROM `{table}` c LEFT JOIN `{parent}` p ON {join} WHERE {present} AND p.`{columns[0][1]}` IS NULL;'))
            if orphan_count:
                raise RuntimeError('Backup contains orphaned foreign keys.')
        files = 0
        with tarfile.open(fileobj=io.BytesIO(storage), mode='r:gz') as archive:
            for member in archive.getmembers():
                dest = (work / 'storage' / member.name).resolve()
                if not dest.is_relative_to(work / 'storage') or member.issym() or member.islnk():
                    raise RuntimeError('Unsafe storage archive entry.')
                if member.isfile():
                    data = archive.extractfile(member).read()
                    dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    dest.write_bytes(data)
                    if hashlib.sha256(data).digest() != hashlib.sha256(dest.read_bytes()).digest():
                        raise RuntimeError('Restored upload hash mismatch.')
                    files += 1
        result = {'passed': True, 'checked_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'backup': source.name, 'backup_sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'tables': len(tables), 'foreign_keys': len(foreign_keys), 'restored_files': files, 'roundtrip_sha256': hashlib.sha256(roundtrip).hexdigest(), 'seconds': round(time.monotonic() - started, 2), 'business_counts': {k: counts[k] for k in ['customers', 'bookings', 'rental_orders', 'laundry_orders']}}
        print(json.dumps(result, indent=2))
    finally:
        for db in created:
            if not re.fullmatch(r'gayatri_verify_[0-9a-f]{12}[ab]', db):
                raise RuntimeError('Refusing cleanup outside disposable databases.')
            admin(f'DROP DATABASE `{db}`;')
        if user_created:
            admin(f"DROP USER '{user}'@'localhost';")
        if work.resolve().parent == allowed.parent and re.fullmatch(r'restore-check-[0-9a-f]{12}', work.name):
            shutil.rmtree(work)


if __name__ == '__main__':
    main()
