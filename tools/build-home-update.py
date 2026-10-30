"""Pacote visual da home para a base main 81702ac, sem dados privados."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'public/index.php', 'public/assets/app.js', 'public/sw.js',
    'public/assets/home-hero.css', 'public/assets/piscina-home-1280.webp',
    'public/assets/piscina-home-2125.webp', 'docs/HOME_ILUSTRADA.md',
    'docs/images/home-desktop.webp', 'docs/images/home-mobile.webp',
)


def main():
    if (ROOT / 'app/AdminDanger.php').exists():
        raise SystemExit('Integre e valide o pacote completo da Danger Zone; este pacote visual é para a base main 81702ac.')
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
