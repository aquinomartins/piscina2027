"""Overlay de atualização Galeria/Molduras, sem configuração privada ou dados."""
from pathlib import Path
import hashlib
import zipfile
import shutil

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = sorted(
    [str(p.relative_to(ROOT)) for p in (ROOT / 'app').glob('*.php')]
    + [str(p.relative_to(ROOT)) for p in (ROOT / 'public/assets').iterdir() if p.is_file()]
    + [str(p.relative_to(ROOT)) for p in (ROOT / 'public/icons').iterdir() if p.is_file()]
    + ['public/' + name for name in (
        'bootstrap.php', 'api.php', 'index.php', 'image.php', 'galeria-media.php',
        'form.php', 'manifest.php', 'sw.js', 'offline.html',
    )]
)
DOCS = (
    'docs/ADMIN_MOBILE.md',
    'docs/MOLDURAS_CARTEIRA.md', 'docs/MOLDURAS_CARTEIRA_TESTES.md',
    'docs/RESULTADOS_CARTEIRA.txt', 'docs/images/carteira-moldura-mobile.png',
    'docs/images/carteira-nft-mobile.png', 'docs/images/carteira-pendencias-mobile.png',
    'docs/GALERIA.md', 'docs/GALERIA_DEPLOY.md', 'docs/GALERIA_TESTES.md',
    'docs/GALERIA_DIAGNOSTICO.md', 'docs/DANGER_ZONE.md',
    'docs/RESULTADOS_GALERIA.txt', 'docs/images/galeria-catalogo-mobile.png',
    'docs/images/galeria-admin-desktop.png', 'docs/images/galeria-evento-mobile.png',
    'docs/GALERIA_AVANCADA.md', 'docs/images/molduras-simples-mobile.png',
    'docs/images/molduras-acordo-mobile.png', 'docs/images/molduras-admin-desktop.png',
)


def main():
    files = RUNTIME + list(DOCS) + [
        'database/migrations/003_admin_danger.sql',
        'database/migrations/004_galeria.sql',
        'database/migrations/005_molduras_simples.sql',
    ]
    assert len(files) == len(set(files))
    assert all(not p.startswith(('storage/', 'config/', 'tests/')) for p in files)
    payload = {p: (ROOT / p).read_bytes() for p in files}
    assert b'galeria.js' in payload['public/sw.js']
    assert b'admin-danger.js' in payload['public/sw.js']
    assert b'qr-generator.js' in payload['public/sw.js']
    assert b'molduras-simples.js' in payload['public/sw.js']
    assert b'molduras-carteira.js' in payload['public/sw.js']
    assert b'molduras-carteira.css' in payload['public/sw.js']
    payload['SHA256SUMS_GALERIA.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Galeria_Molduras.zip'
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, 'w') as archive:
        for name, data in sorted(payload.items()):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert len(archive.namelist()) == len(payload)
        for name, data in payload.items():
            assert archive.read(name) == data
    (ROOT / 'downloads/GALERIA_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    simple = ROOT / 'downloads/Piscina_Molduras_Simples.zip'
    shutil.copyfile(target, simple)
    (ROOT / 'downloads/MOLDURAS_SIMPLES_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(simple.read_bytes()).hexdigest()}  {simple.name}\n'
    )
    wallet = ROOT / 'downloads/Piscina_Portfolio_Pendencias.zip'
    shutil.copyfile(target, wallet)
    (ROOT / 'downloads/CARTEIRA_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(wallet.read_bytes()).hexdigest()}  {wallet.name}\n'
    )
    print(f'{wallet.name}, {target.name} e {simple.name}: {len(RUNTIME)} arquivos de execução, migrações 003/004/005, guias e hashes conferidos.')


if __name__ == '__main__':
    main()
