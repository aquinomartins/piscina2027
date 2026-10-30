"""Pacote visual da home para instalações com a Danger Zone da PR #9."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'public/index.php', 'public/assets/app.js', 'public/sw.js',
    'public/assets/home-hero.css', 'public/assets/piscina-home-1280.webp',
    'public/assets/piscina-home-2125.webp', 'docs/HOME_ILUSTRADA.md',
    'docs/images/home-desktop.webp', 'docs/images/home-mobile.webp', 'docs/DANGER_ZONE.md',
)


def main():
    required = ('app/AdminDanger.php', 'public/assets/admin-danger.js',
                'database/migrations/003_admin_danger.sql')
    if not all((ROOT / name).is_file() for name in required):
        raise SystemExit('Este pacote pressupõe a versão com a PR #9 (Danger Zone).')
    assert 'assets/admin-danger.js' in (ROOT / 'public/sw.js').read_text()
    payload = {name: (ROOT / name).read_bytes() for name in FILES}
    payload['SHA256SUMS_HOME.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Home_Ilustrada.zip'
    with zipfile.ZipFile(target, 'w') as archive:
        for name, data in sorted(payload.items()):
            entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(payload)
        for name, data in payload.items():
            assert archive.read(name) == data
    (ROOT / 'downloads/HOME_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    print(f'{target.name}: seis arquivos públicos, guia e hashes conferidos.')


if __name__ == '__main__':
    main()
