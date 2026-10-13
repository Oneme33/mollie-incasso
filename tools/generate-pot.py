#!/usr/bin/env python3
"""Rebuild the Dutch source catalog, including labels in compact UI maps."""
from pathlib import Path
import re, json
root=Path(__file__).resolve().parent.parent
entries={}
for path in sorted((root/'src').glob('*.php')):
    source=path.read_text()
    for match in re.finditer(r"'((?:[^'\\]|\\.)*)'", source):
        value=match.group(1).replace("\\'", "'").replace('\\\\','\\')
        direct=bool(re.search(r'(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*$', source[max(0,match.start()-30):match.start()]))
        mapped=bool(re.match(r'[A-ZÀ-Ü][a-zà-ÿ]', value))
        if (direct or mapped) and not any(c in value for c in '<>\\') and not value.startswith(('SELECT ','INSERT ','UPDATE ','DELETE ','CREATE ','DROP ','START ','PRIMARY ','UNIQUE ','Content-Type:','Content-Disposition:')):
            entries.setdefault(value,[]).append(f'{path.relative_to(root)}:{source[:match.start()].count(chr(10))+1}')
header='''msgid ""
msgstr ""
"Project-Id-Version: Mollie Incasso 0.1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: nl_NL\\n"
"X-Domain: mollie-incasso\\n"
'''
with (root/'languages/mollie-incasso.pot').open('w') as out:
    out.write(header)
    for value,refs in sorted(entries.items()):
        out.write('\n#: '+' '.join(refs)+'\nmsgid '+json.dumps(value,ensure_ascii=False)+'\nmsgstr ""\n')
print(f'{len(entries)} catalog entries')
