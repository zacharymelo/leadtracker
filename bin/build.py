#!/usr/bin/env python3
"""
Build script for Lead Tracker module.
Usage: python3 bin/build.py <version>
  e.g. python3 bin/build.py 1.1.17

Updates version strings in source files FIRST, then builds the zip.
Never build the zip before updating the source — that was the bug.
"""

import re
import sys
import zipfile
import pathlib

ROOT = pathlib.Path(__file__).parent.parent
MODULE = ROOT / 'module'
BIN = ROOT / 'bin'

VERSION_FILES = {
    MODULE / 'core' / 'modules' / 'modLeadtracker.class.php':
        (r"(\$this->version\s*=\s*')[^']+(')", r'\g<1>{version}\g<2>'),
    MODULE / 'core' / 'triggers' / 'interface_99_modLeadtracker_LeadtrackerTrigger.class.php':
        (r"(public\s+\$version\s+=\s+')[^']+(')", r'\g<1>{version}\g<2>'),
}


def set_versions(version):
    for path, (pattern, replacement) in VERSION_FILES.items():
        text = path.read_text()
        new_text = re.sub(pattern, replacement.format(version=version), text)
        if new_text == text:
            print(f'  WARNING: no version string found in {path.name}')
        else:
            path.write_text(new_text)
            print(f'  updated {path.name}')


def verify_versions(version):
    ok = True
    for path in VERSION_FILES:
        text = path.read_text()
        if f"'{version}'" not in text:
            print(f'  ERROR: {version} not found in {path.name}')
            ok = False
        else:
            print(f'  verified {path.name} = {version}')
    return ok


def build_zip(version):
    out = BIN / f'module_leadtracker-{version}.zip'
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for f in sorted(MODULE.rglob('*')):
            if f.is_file():
                zf.write(f, 'leadtracker/' + str(f.relative_to(MODULE)))
    # Verify version inside the zip
    with zipfile.ZipFile(out) as zf:
        for name in [
            'leadtracker/core/modules/modLeadtracker.class.php',
            'leadtracker/core/triggers/interface_99_modLeadtracker_LeadtrackerTrigger.class.php',
        ]:
            content = zf.read(name).decode()
            if f"'{version}'" not in content:
                print(f'  ERROR: {version} not in zip entry {name}')
                return None
    print(f'  created {out.name} ({out.stat().st_size // 1024} KB)')
    return out


def main():
    if len(sys.argv) != 2:
        print('Usage: python3 bin/build.py <version>')
        sys.exit(1)

    version = sys.argv[1]
    print(f'\n=== Building v{version} ===\n')

    print('1. Updating version strings in source files...')
    set_versions(version)

    print('\n2. Verifying source files...')
    if not verify_versions(version):
        print('\nABORTED — version strings not set correctly.')
        sys.exit(1)

    print('\n3. Building zip...')
    out = build_zip(version)
    if not out:
        print('\nABORTED — version not found inside zip.')
        sys.exit(1)

    print(f'\nDone. Next steps:')
    print(f'  git add -A && git commit -m "v{version} — ..."')
    print(f'  git push')
    print(f'  gh release create v{version} {out} --title "v{version}" --notes "..."')


if __name__ == '__main__':
    main()
