"""Build the installable WordPress plugin ZIP with 7-Zip."""

from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parent.parent
PLUGIN = "htmlpress"
DIST = ROOT / "dist"
# Explicit distribution list: do not ship development files or local secrets.
FILES = (
    "htmlpress.php",
    "class-htmlpress-admin.php",
    "class-htmlpress-bundle.php",
    "class-htmlpress-document.php",
    "class-htmlpress-php.php",
    "plugin-update-checker",
    "README.md",
    "readme.txt",
    "Captura.png",
)


def main():
    seven_zip = shutil.which("7z") or shutil.which("7za")
    if not seven_zip:
        seven_zip = next(
            (
                str(path)
                for path in (
                    Path("C:/Program Files/7-Zip/7z.exe"),
                    Path("C:/Program Files (x86)/7-Zip/7z.exe"),
                )
                if path.is_file()
            ),
            None,
        )
    if not seven_zip:
        print("7-Zip não encontrado no PATH (esperado: 7z ou 7za).", file=sys.stderr)
        return 1

    try:
        header = (ROOT / "htmlpress.php").read_text(encoding="utf-8")
        version = re.search(r"^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$", header, re.MULTILINE)
        if not version:
            raise ValueError("Versão do plugin não encontrada no cabeçalho.")
        destination = DIST / f"{PLUGIN}-{version.group(1)}.zip"
        DIST.mkdir(exist_ok=True)
        # Same filesystem as the final ZIP, so replacement is atomic.
        with tempfile.TemporaryDirectory(dir=DIST) as temporary:
            staging = Path(temporary)
            plugin_dir = staging / PLUGIN
            plugin_dir.mkdir()
            for name in FILES:
                source = ROOT / name
                if source.is_dir():
                    shutil.copytree(source, plugin_dir / name)
                else:
                    shutil.copy2(source, plugin_dir / name)

            archive = staging / destination.name
            subprocess.run(
                [seven_zip, "a", "-tzip", str(archive), PLUGIN],
                cwd=staging,
                check=True,
            )
            with zipfile.ZipFile(archive) as package:
                expected = {
                    path.relative_to(staging).as_posix()
                    for path in plugin_dir.rglob("*")
                    if path.is_file()
                }
                actual = {entry.filename for entry in package.infolist() if not entry.is_dir()}
                if actual != expected or package.testzip() is not None:
                    raise ValueError("Conteúdo ou integridade do ZIP inválidos.")
            archive.replace(destination)

        for old_zip in DIST.glob("*.zip"):
            if old_zip != destination:
                old_zip.unlink()
    except (OSError, subprocess.CalledProcessError, zipfile.BadZipFile, ValueError) as error:
        print(f"Falha ao gerar o ZIP: {error}", file=sys.stderr)
        return 1
    print(f"ZIP gerado: {destination}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
