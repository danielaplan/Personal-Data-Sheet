from html.parser import HTMLParser
from pathlib import Path
import json

class FormCheck(HTMLParser):
    def __init__(self):
        super().__init__()
        self.stack=[]; self.ids=[]; self.labels=[]; self.controls=[]; self.links=[]
        self.in_form=False
    def handle_starttag(self, tag, attrs):
        data=dict(attrs)
        if tag not in {'meta','link','input','br','hr','img'}: self.stack.append(tag)
        if 'id' in data: self.ids.append(data['id'])
        if tag=='label': self.labels.append(data.get('for'))
        if tag=='form' and data.get('id')=='pds-form': self.in_form=True
        if tag in {'input','select'} and self.in_form: self.controls.append(data)
        if tag=='a' and data.get('href','').startswith('#'): self.links.append(data['href'][1:])
    def handle_endtag(self, tag):
        assert self.stack and self.stack.pop()==tag, (tag,self.stack)
        if tag=='form': self.in_form=False

root=Path(__file__).resolve().parent.parent
form=FormCheck(); form.feed((root/'index.html').read_text(encoding='utf-8'))
schema=json.loads((root/'api/field-schema.json').read_text())
assert not form.stack
assert len(form.ids)==len(set(form.ids))
assert all(key in form.ids for key in form.labels+form.links)
assert len(form.controls)==99
assert {field['name'] for field in form.controls}==set(schema)
assert all(field['id'] in form.labels or field.get('aria-label') for field in form.controls)
print('PASS: HTML structure, 99 labeled controls, 98 matching storage keys, and valid navigation.')
