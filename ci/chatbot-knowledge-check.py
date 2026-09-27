#!/usr/bin/env python3
"""Check the reviewed knowledge boundary without PHP or production credentials."""
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
prompt = (ROOT / 'chatbot/api/prompt.php').read_text()
manifest = re.search(r'const CHATBOT_KNOWLEDGE_FILES = \[(.*?)\];', prompt, re.S)
assert manifest, 'Missing explicit knowledge manifest'
names = re.findall(r"'([^']+\.md)'", manifest[1])
assert len(names) == len(set(names)) and len(names) >= 7
assert all(re.fullmatch(r'\d{2}-[a-z-]+\.md', name) for name in names)
approved = {'.htaccess', '.gitkeep', *names}
deploy = (ROOT / '.deployignore').read_text().splitlines()
assert '/chatbot/knowledge/*' in deploy
exclusion = deploy.index('/chatbot/knowledge/*')
total = 0
for name in names:
    path = ROOT / 'chatbot/knowledge' / name
    body = path.read_text()
    total += len(body.encode())
    assert body.startswith('# '), f'{name}: missing title'
    assert not re.search(r'TODO|\[[^\]\n]{0,80}\]', body, re.I), name
    assert not re.search(r'[$€£]\s*\d|\b(?:USD|ETB|birr)\s*\d|\d\s*(?:USD|ETB|birr)\b', body, re.I), f'{name}: monetary amount'
    include = '+ /chatbot/knowledge/' + name
    assert include in deploy and deploy.index(include) < exclusion, f'{name}: deploy exclusion mismatch'
    ignored = subprocess.run(['git', 'check-ignore', '--no-index', '-q', str(path)], cwd=ROOT)
    assert ignored.returncode == 1, f'{name}: reviewed file is ignored'
assert total <= 48000, f'Knowledge exceeds prompt budget: {total}'
tracked = subprocess.check_output(['git', 'ls-files', 'chatbot/knowledge/'], cwd=ROOT, text=True).splitlines()
for path in tracked:
    assert path.removeprefix('chatbot/knowledge/') in approved, f'Unreviewed source tracked: {path}'
for path in (ROOT / 'chatbot/knowledge').iterdir():
    if path.name in approved:
        continue
    ignored = subprocess.run(['git', 'check-ignore', '--no-index', '-q', str(path)], cwd=ROOT)
    assert ignored.returncode == 0, f'Raw source is not ignored: {path.name}'
assert 'Require all denied' in (ROOT / 'chatbot/knowledge/.htaccess').read_text()
assert 'glob(' not in prompt, 'Knowledge loading must not ingest arbitrary Markdown'
print(f'PASS: {len(names)} reviewed files, {total} bytes; Git/deploy boundaries aligned')
