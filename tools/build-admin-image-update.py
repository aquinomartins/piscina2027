"""Pacote limitado à troca administrativa de imagem, sem SQL, configuração ou dados."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'app/AdminImageUpload.php', 'app/NftImage.php', 'app/Domain.php',
    'app/Queries.php', 'app/Upload.php', 'public/api.php', 'public/index.php',
    'public/assets/app.js', 'public/assets/app.css', 'public/sw.js',
    'docs/TROCAR_IMAGEM_ADMIN.md',
)


def main():
    if (ROOT / 'app/AdminDanger.php').exists():
        raise SystemExit('Use tools/build-danger-update.py: o pacote isolado de imagem não contém as novas dependências.')
    payload = {name: (ROOT / name).read_bytes() for name in FILES}
    payload['SHA256SUMS.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Troca_Imagem_Admin.zip'
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
    (ROOT / 'downloads/IMAGEM_ADMIN_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    print(f'{target.name}: dez arquivos da aplicação, guia e hashes conferidos.')


if __name__ == '__main__':
    main()
