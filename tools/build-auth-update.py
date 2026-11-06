"""Pacote incremental de autenticação: um arquivo PHP, guia e hashes."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ('app/Auth.php', 'docs/CADASTRO_TURMAS.md', 'docs/RESULTADOS_CADASTRO_TURMAS.txt')


def main():
    if (ROOT / 'app/Galeria.php').exists():
        raise SystemExit('Use tools/build-galeria-update.py: os pacotes anteriores não contêm as dependências da Galeria.')
    if (ROOT / 'app/AdminDanger.php').exists():
        raise SystemExit('Use tools/build-danger-update.py: o pacote isolado de cadastro não é compatível com a Danger Zone.')
    payload = {name: (ROOT / name).read_bytes() for name in FILES}
    payload['SHA256SUMS_CADASTRO.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Cadastro_Turmas.zip'
    target.parent.mkdir(exist_ok=True)
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
    (ROOT / 'downloads/CADASTRO_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    print(f'{target.name}: Auth.php, documentação e hashes conferidos.')


if __name__ == '__main__':
    main()
