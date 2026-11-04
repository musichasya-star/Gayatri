"""Snapshot/compare the local business database without changing any business row."""
import hashlib
import json
import sqlite3
import sys
from datetime import datetime
from pathlib import Path

root = Path(__file__).resolve().parents[1]
db_path = root / 'storage/app/baileys-local.sqlite'
output = root / 'storage/logs/ui-package-a'
output.mkdir(parents=True, exist_ok=True)
tables = ['customers', 'bookings', 'booking_payments', 'rental_orders', 'rental_items',
          'rental_payments', 'rental_extensions', 'rental_documents', 'laundry_orders',
          'laundry_order_items', 'laundry_payments', 'laundry_documents', 'promos',
          'services', 'service_addons', 'rental_products', 'rental_units', 'retail_products',
          'laundry_tariffs', 'app_settings', 'messages', 'jobs', 'failed_jobs',
          'knowledge_bases', 'knowledge_chunks', 'ai_logs']
connection = sqlite3.connect(db_path.as_uri() + '?mode=ro', uri=True)
connection.execute('PRAGMA query_only = ON')
present = {row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
snapshot = {}
for table in tables:
    if table not in present:
        raise RuntimeError('Missing expected table: ' + table)
    rows = connection.execute(f'SELECT * FROM "{table}" ORDER BY id').fetchall()
    snapshot[table] = {'count': len(rows), 'sha256': hashlib.sha256(json.dumps(rows, ensure_ascii=False).encode()).hexdigest()}
baseline = output / 'data-before.json'
if sys.argv[1:] == ['before']:
    if baseline.exists():
        raise RuntimeError('Baseline already exists; do not overwrite it.')
    backup = root / 'storage/app/backups' / ('ui-package-a-before-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.sqlite')
    with sqlite3.connect(backup) as target:
        connection.backup(target)
    baseline.write_text(json.dumps({'backup': backup.name, 'tables': snapshot}, indent=2))
    print(json.dumps({'backup': backup.name, 'tables': len(snapshot), 'integrity': connection.execute('PRAGMA integrity_check').fetchone()[0]}))
elif sys.argv[1:] == ['check']:
    before = json.loads(baseline.read_text())['tables']
    result = {'tables': {key: before[key] == value for key, value in snapshot.items()}, 'integrity': connection.execute('PRAGMA integrity_check').fetchone()[0]}
    (output / 'data-after.json').write_text(json.dumps(result, indent=2))
    print(json.dumps(result))
    sys.exit(0 if all(result['tables'].values()) and result['integrity'] == 'ok' else 1)
else:
    raise RuntimeError('Use before or check.')
