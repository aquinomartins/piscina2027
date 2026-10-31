"""Pacote restrito aos seis arquivos da atualização administrativa, sem SQL ou dados."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'app/Domain.php', 'app/Queries.php', 'public/api.php',
    'public/assets/app.js', 'public/assets/app.css', 'public/sw.js',
    'docs/ADMIN_CARTEIRAS.md',
)


def main():
    if (ROOT / 'app/Galeria.php').exists():
        raise SystemExit('Use tools/build-galeria-update.py: os pacotes anteriores não contêm as dependências da Galeria.')
    if (ROOT / 'app/AdminDanger.php').exists():
        raise SystemExit('Use tools/build-danger-update.py: o pacote administrativo antigo não contém as novas dependências.')
    payload = {name: (ROOT / name).read_bytes() for name in FILES}
    payload['SHA256SUMS.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Atualizacao_Admin.zip'
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
    (ROOT / 'downloads/ADMIN_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    print(f'{target.name}: seis arquivos da aplicação, guia e hashes conferidos.')


if __name__ == '__main__':
    main()
