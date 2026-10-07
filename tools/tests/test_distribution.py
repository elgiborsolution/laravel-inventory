import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location("distribution", Path(__file__).parents[1] / "distribute.py")
distribution = importlib.util.module_from_spec(spec)
spec.loader.exec_module(distribution)


class DistributionTest(unittest.TestCase):
    def test_runtime_archives_are_independent_reproducible_and_versioned(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            previous = Path.cwd()
            try:
                os.chdir(root)
                for directory in [root] + [root / "packages" / name for name in
                        ("asset", "automotive", "food", "healthcare", "library", "manufacturing", "project", "retail", "wms")]:
                    (directory / "src").mkdir(parents=True)
                    (directory / "src" / "Provider.php").write_text("<?php // provider\n")
                    manifest = {
                        "name": distribution.CORE + ("" if directory == root else "-" + directory.name),
                        "autoload": {"psr-4": {("Example" + ("Core" if directory == root else directory.name) + "\\"): "src/"}},
                        "require": {"php": "^8.1"},
                        "extra": {"laravel": {"providers": ["Example\\Provider"]}},
                        "autoload-dev": {"psr-4": {"Test\\": "tests/"}},
                    }
                    if directory != root:
                        manifest["require"][distribution.CORE] = "^2.0"
                    (directory / "composer.json").write_text(json.dumps(manifest))
                core_manifest = json.loads((root / 'composer.json').read_text())
                core_manifest['replace'] = {}
                for file in (root / 'packages').glob('*/composer.json'):
                    module = json.loads(file.read_text())
                    core_manifest['replace'][module['name']] = '*'
                    for namespace, target in module['autoload']['psr-4'].items():
                        core_manifest['autoload']['psr-4'][namespace] = 'packages/' + file.parent.name + '/' + target
                (root / 'composer.json').write_text(json.dumps(core_manifest))
                (root / "LICENSE").write_text("Test license")
                (root / ".env").write_text("DO_NOT_SHIP=1")
                (root / "vendor").mkdir()
                (root / "vendor" / "secret").write_text("DO_NOT_SHIP")
                for args in [("init",), ("add", "."),
                             ("-c", "user.name=Test", "-c", "user.email=test@example.com", "commit", "-m", "fixture"),
                             ("tag", "v2.0.2")]:
                    subprocess.run(["git", *args], check=True, capture_output=True)
                first = distribution.build("v2.0.2", "example/inventory", root / "first")
                # Dirty worktree changes must never leak into a tagged release.
                (root / "src" / "Provider.php").write_text("uncommitted")
                second = distribution.build("v2.0.2", "example/inventory", root / "second")
                self.assertEqual(first, second)
                self.assertEqual(len(first["packages"]), 1)
                for package, versions in first["packages"].items():
                    metadata = versions["2.0.2"]
                    filename = metadata["dist"]["url"].split("/")[-1]
                    content = (root / "first" / filename).read_bytes()
                    self.assertEqual(content, (root / "second" / filename).read_bytes())
                    self.assertEqual(distribution.hashlib.sha1(content).hexdigest(), metadata["dist"]["shasum"])
                    with zipfile.ZipFile(root / "first" / filename) as archive:
                        self.assertEqual(len([file for file in archive.namelist() if file.startswith('packages/')]), 18)
                        self.assertNotIn('.env', archive.namelist())
                        self.assertNotIn('vendor/secret', archive.namelist())
                        self.assertIn('packages/wms/src/Provider.php', archive.namelist())
                        manifest = json.loads(archive.read("composer.json"))
                        self.assertEqual(manifest["name"], package)
                        self.assertNotIn("autoload-dev", manifest)
                        self.assertIn("laravel", manifest["extra"])
                        self.assertNotEqual(archive.read("src/Provider.php"), b"uncommitted")
                subprocess.run(["git", "tag", "v2.0.3"], check=True)
                newer = distribution.build("v2.0.3", "example/inventory", root / "newer")
                merged = distribution.merge([first, newer, first])
                self.assertEqual(set(merged["packages"][distribution.CORE]), {"2.0.2", "2.0.3"})
                conflict = json.loads(json.dumps(first))
                conflict["packages"][distribution.CORE]["2.0.2"]["dist"]["reference"] = "changed"
                with self.assertRaisesRegex(ValueError, "Conflicting"):
                    distribution.merge([first, conflict])
                # Old module constraints must block distribution, not be silently rewritten.
                path = root / "packages/wms/composer.json"
                manifest = json.loads(path.read_text())
                manifest["require"][distribution.CORE] = "^1.0"
                path.write_text(json.dumps(manifest))
                subprocess.run(["git", "add", "."], check=True, capture_output=True)
                subprocess.run(["git", "-c", "user.name=Test", "-c", "user.email=test@example.com",
                                "commit", "-m", "old constraint"], check=True, capture_output=True)
                subprocess.run(["git", "tag", "v2.0.4"], check=True)
                with self.assertRaisesRegex(ValueError, "require Core"):
                    distribution.build("v2.0.4", "example/inventory", root / "invalid")
            finally:
                os.chdir(previous)

    def test_rejects_branch_as_release(self):
        with self.assertRaises(ValueError):
            distribution.build("dev-main", "example/inventory", Path("unused"))

    def test_publish_retry_preserves_history_and_rejects_changed_assets(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            artifacts = root / "artifacts"
            artifacts.mkdir()
            archive = artifacts / "core-2.0.3.zip"
            archive.write_bytes(b"immutable zip fixture")
            current = {"packages": {distribution.CORE: {"2.0.3": {"version": "2.0.3"}}}}
            old = {"packages": {distribution.CORE: {"2.0.2": {"version": "2.0.2"}}}}
            distribution.write_json(artifacts / distribution.METADATA, current)
            remote = {archive.name: archive.read_bytes()}
            uploads = []

            def fake_run(*args):
                if args[1] == "api" and args[2].endswith("/tags/v2.0.3"):
                    return json.dumps({"draft": False, "assets": [{"name": name} for name in remote]}).encode()
                if args[1] == "api":
                    return json.dumps([[{"draft": False, "tag_name": "v2.0.2",
                                         "assets": [{"name": distribution.METADATA}]}]]).encode()
                if args[2] == "download":
                    filename = args[args.index("--pattern") + 1]
                    destination = Path(args[args.index("--dir") + 1]) / filename
                    destination.write_bytes(json.dumps(old).encode() if args[3] == "v2.0.2" else remote[filename])
                    return b""
                if args[2] == "upload":
                    path = Path(args[4])
                    uploads.append(path.name)
                    remote[path.name] = path.read_bytes()
                    return b""
                raise AssertionError(args)

            with patch.object(distribution, "run", side_effect=fake_run):
                distribution.publish("v2.0.3", "example/inventory", artifacts, root / "site")
                self.assertEqual(uploads, [distribution.METADATA])
                catalog = json.loads((root / "site/packages.json").read_text())
                self.assertEqual(set(catalog["packages"][distribution.CORE]), {"2.0.2", "2.0.3"})
                distribution.publish("v2.0.3", "example/inventory", artifacts, root / "site")
                self.assertEqual(uploads, [distribution.METADATA])
                archive.write_bytes(b"changed")
                with self.assertRaisesRegex(ValueError, "Refusing to overwrite"):
                    distribution.publish("v2.0.3", "example/inventory", artifacts, root / "site")


if __name__ == "__main__":
    unittest.main()
