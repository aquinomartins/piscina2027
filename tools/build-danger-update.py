"""Pacote incremental da Danger Zone, integrando a troca administrativa de imagem."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = (
    'app/Auth.php', 'app/Domain.php', 'app/Ledger.php', 'app/Queries.php',
    'app/Restrictions.php', 'app/AdminDanger.php', 'app/AdminCredentials.php', 'app/CredentialKey.php',
    'app/Upload.php', 'app/AdminImageUpload.php', 'app/NftImage.php',
    'public/api.php', 'public/image.php', 'public/assets/app.js',
    'public/assets/admin-danger.js', 'public/assets/app.css', 'public/sw.js',
    'public/index.php', 'public/assets/home-hero.css',
    'public/assets/piscina-home-1280.webp', 'public/assets/piscina-home-2125.webp',
)
FILES = RUNTIME + (
    'database/migrations/003_admin_danger.sql', 'docs/DANGER_ZONE.md',
    'docs/RESULTADOS_DANGER_ZONE.txt', 'docs/HOME_ILUSTRADA.md',
    'docs/images/home-desktop.webp', 'docs/images/home-mobile.webp',
)


def main():
    payload = {name: (ROOT / name).read_bytes() for name in FILES}
    assert not any(name.startswith(('storage/', 'config/', 'tests/')) for name in payload)
    payload['SHA256SUMS_DANGER.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n' for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Danger_Zone.zip'
    with zipfile.ZipFile(target, 'w') as archive:
        for name, data in sorted(payload.items()):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(payload)
        for name, data in payload.items():
            assert archive.read(name) == data
    (ROOT / 'downloads/DANGER_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    print(f'{target.name}: {len(RUNTIME)} arquivos de execução, migração, documentação e hashes conferidos.')


if __name__ == '__main__':
    main()
