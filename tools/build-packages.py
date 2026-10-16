"""Reconstrói pacotes sem configuração privada, dados locais ou ZIPs recursivos."""
from pathlib import Path
import hashlib
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "downloads"
FOLDERS = {"app", "bin", "config", "database", "docs", "public", "vendor", "tests", "tools"}
ROOT_FILES = {"README.md", "composer.json", "package.json", ".gitignore"}
PRIVATE = {"config/local.php", "tests/local.php"}
STORAGE = [f"storage/{name}/.gitkeep" for name in ("images", "tmp", "logs", "mail", "sessions")]


def digest(data):
    return hashlib.sha256(data).hexdigest()


def zip_entry(archive, path, data):
    info = zipfile.ZipInfo("piscina-liquidez/" + path, (1980, 1, 1, 0, 0, 0))
    info.create_system = 3
    info.external_attr = 0o100644 << 16
    archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def main():
    tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
    files = sorted(p for p in tracked if p and p not in PRIVATE
                   and (p in ROOT_FILES or p.split("/", 1)[0] in FOLDERS))
    assert all((ROOT / p).is_file() for p in files)
    OUT.mkdir(exist_ok=True)
    sums = []
    for production, name in [(False, "Piscina_de_Liquidez_Completo.zip"),
                             (True, "Piscina_de_Liquidez_Producao.zip")]:
        selected = [p for p in files if not production or (
            not p.startswith(("tests/", "tools/")) and p not in {"bin/demo.php", "package.json"})]
        payload = {p: (ROOT / p).read_bytes() for p in selected}
        payload.update({p: b"" for p in STORAGE})
        manifest = "".join(f"{digest(data)}  {p}\n" for p, data in sorted(payload.items()))
        payload["SHA256SUMS.txt"] = manifest.encode()
        destination = OUT / name
        with zipfile.ZipFile(destination, "w") as archive:
            for p, data in sorted(payload.items()):
                zip_entry(archive, p, data)
        with zipfile.ZipFile(destination) as archive:
            assert archive.testzip() is None
            expected = {"piscina-liquidez/" + p for p in payload}
            assert set(archive.namelist()) == expected
            assert len(archive.namelist()) == len(expected)
            for p, data in payload.items():
                assert archive.read("piscina-liquidez/" + p) == data
        sums.append(f"{digest(destination.read_bytes())}  {name}\n")
        print(f"{name}: {len(payload)} entradas, CRC e conteúdo conferidos")
    (OUT / "SHA256SUMS.txt").write_text("".join(sums))


if __name__ == "__main__":
    main()
