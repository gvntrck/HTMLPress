import contextlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile


spec = importlib.util.spec_from_file_location(
    "build_zip", Path(__file__).resolve().parents[1] / "scripts" / "build-zip.py"
)
build_zip = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build_zip)


class BuildZipTest(unittest.TestCase):
    def test_package_cleanup_and_failed_build_preserves_previous_zip(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for name in build_zip.FILES:
                target = root / name
                if name == "plugin-update-checker":
                    target.mkdir()
                    (target / "plugin-update-checker.php").write_text("<?php", encoding="utf-8")
                else:
                    target.write_text("fixture", encoding="utf-8")
            (root / "htmlpress.php").write_text(
                "<?php\n/**\n * Version: 1.4.1\n */", encoding="utf-8"
            )
            dist = root / "dist"
            dist.mkdir()
            old_zip = dist / "htmlpress-1.4.0.zip"
            old_zip.write_bytes(b"old")
            outside_zip = root / "htmlpress.zip"
            outside_zip.write_bytes(b"keep")
            (root / ".env").write_text("SECRET=excluded", encoding="utf-8")
            with patch.object(build_zip, "ROOT", root), patch.object(build_zip, "DIST", dist):
                self.assertEqual(build_zip.main(), 0)
                archive = dist / "htmlpress-1.4.1.zip"
                self.assertEqual(list(dist.iterdir()), [archive])
                self.assertEqual(outside_zip.read_bytes(), b"keep")
                with zipfile.ZipFile(archive) as package:
                    self.assertIsNone(package.testzip())
                    self.assertIn("htmlpress/htmlpress.php", package.namelist())
                    self.assertIn(
                        "htmlpress/plugin-update-checker/plugin-update-checker.php",
                        package.namelist(),
                    )
                    self.assertNotIn("htmlpress/.env", package.namelist())
                previous = archive.read_bytes()
                (root / "htmlpress.php").write_text(
                    "<?php\n/**\n * Version: 1.4.2\n */", encoding="utf-8"
                )
                with patch.object(build_zip.subprocess, "run", side_effect=OSError("failure")):
                    with contextlib.redirect_stderr(io.StringIO()):
                        self.assertEqual(build_zip.main(), 1)
                self.assertEqual(archive.read_bytes(), previous)
                self.assertEqual(list(dist.iterdir()), [archive])
                self.assertEqual(build_zip.main(), 0)
                self.assertEqual(list(dist.iterdir()), [dist / "htmlpress-1.4.2.zip"])


if __name__ == "__main__":
    unittest.main()
