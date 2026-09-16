"""Generate expectations using Python, never using the PHP implementation."""
import json
import hashlib
from pathlib import Path
from followthemoney import model
from followthemoney.types import registry

root = Path(__file__).resolve().parents[1]
cases = {
    'string': [None, '', '  Emily  Berry  ', 'first\nsecond', 'a\u0000b', 'e\u0301', True, 1.23456],
    'name': [' "Emily   Berry" ', "'Emily'", 'Emily\nBerry', 'Miss Emily A. Berry'],
    'address': [' Culpeper\nVirginia ', 'a,,b'],
    'date': ['1953', '1953-01', '1953-01-02', '1953-02-30', '1953-13', 'not a date', '1953-01-02T12', '1953-01-02T12:34', '1953-01-02T12:34:56', '1953-01-02T12:34:00Z', '1953-1-2', '1890 circa', '0999', '1953-00', '1953-01-02T12:34:56+02:00'],
    'url': ['https://example.org', 'example.org/article/1', 'https://example.org/a?q=1', 'not-url'],
    'entity': ['person-emily', 'bad_id', 'a', 'bad id', '.bad'],
    'number': ['1,000.00', 'unknown', 42],
    'gender': ['F', 'woman', 'female', 'divers', 'unknown'],
    'country': ['US', 'gb', 'United States', 'United Kingdom', 'zz'],
    'language': ['eng', 'en', 'English', 'fra', 'French', 'zz'],
    'email': ['Emily@EXAMPLE.COM', 'not mail', '"Emily@example.com"'],
    'identifier': [' Q42 ', 'q42', 'Q0', 'Q001', 'https://www.wikidata.org/wiki/Q42', 'not-qid'],
}
rows = []
for type_name, inputs in cases.items():
    format_ = 'qid' if type_name == 'identifier' else None
    for raw in inputs:
        rows.append({'type': type_name, 'format': format_, 'input': raw, 'expected': registry.get(type_name).clean(raw, format=format_)})
entities = []
for schema, id_, properties in [
    ('Person','emily-berry', {'name': [' "Emily Armstead Berry" ', 'Emily Armstead Berry'], 'alias': ['Emily A. Berry'], 'deathDate': ['1953-01'], 'gender': ['F'], 'wikidataId': ['q42']}),
    ('Family','emily-mother', {'person':['emily-berry'], 'relative':['lucy-berry'], 'relationship':['mother']}),
    ('Organization','culpeper-baptist', {'name':['Culpeper Baptist church'], 'country':['US']}),
]:
    entity = model.make_entity(schema)
    entity.id = id_
    for prop, values in properties.items(): entity.add(prop,values)
    output = entity.to_dict()
    entities.append({'schema':schema,'id':id_,'input':properties,'expected':{k:output[k] for k in ['id','schema','properties']}})
(root/'tests/fixtures/python.json').write_text(json.dumps({'upstream': json.loads((root/'resources/upstream.json').read_text()), 'requirements_sha256': hashlib.sha256((root/'tools/requirements.lock').read_bytes()).hexdigest(), 'normalization':rows,'entities':entities},ensure_ascii=False,indent=2)+'\n')
