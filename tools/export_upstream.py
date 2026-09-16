"""Run using a venv containing the pinned upstream checkout. No Python at runtime."""
import hashlib
import json
import subprocess
import sys
from pathlib import Path
import followthemoney
from followthemoney import model
from followthemoney.types import registry

root = Path(__file__).resolve().parents[1]
checkout = Path(sys.argv[1]).resolve()
installed = Path(followthemoney.__file__).resolve().parent
for source in (checkout / 'followthemoney').rglob('*'):
    if source.suffix not in ('.py', '.yaml'):
        continue
    target = installed / source.relative_to(checkout / 'followthemoney')
    if not target.exists() or target.read_bytes() != source.read_bytes():
        raise RuntimeError(f'Installed Python package differs from checkout: {source}')
revision = subprocess.check_output(['git', '-C', str(checkout), 'rev-parse', 'HEAD'], text=True).strip()
schemata = {}
for name, schema in sorted(model.schemata.items()):
    definition = schema.to_dict()
    definition['properties'] = {n: p.to_dict() for n, p in sorted(schema.properties.items())}
    schemata[name] = definition
snapshot = {'schemata': schemata, 'types': {t.name: t.to_dict() for t in sorted(registry.types, key=lambda t:t.name)}}
from babel import Locale
locale = Locale('en')
for name, labels in [('country', locale.territories), ('language', locale.languages)]:
    type_ = registry.get(name)
    lookup = {}
    for value in list(labels.keys()) + list(labels.values()) + list(type_.names.keys()) + list(type_.names.values()):
        cleaned = type_.clean(value)
        if cleaned is not None:
            lookup[value.lower()] = cleaned
    snapshot['types'][name]['lookup'] = dict(sorted(lookup.items()))
path = root / 'resources/model.json'
path.write_text(json.dumps(snapshot, ensure_ascii=False, indent=2) + '\n')
(root / 'resources/upstream.json').write_text(json.dumps({
    'repository': 'https://github.com/alephdata/followthemoney',
    'revision': revision,
    'model_sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
    'license': 'MIT',
    'export': 'Resolved properties, including inherited and reverse properties; English metadata.'
}, indent=2) + '\n')
(root / 'resources/UPSTREAM-LICENSE').write_bytes((checkout / 'LICENSE').read_bytes())
print(f'Exported {len(schemata)} schemas at {revision}')
