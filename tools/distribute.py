"""Build immutable Composer distributions and a cumulative GitHub Pages catalog.

Uses only Python's standard library plus git; publishing also requires gh.
Building is local and never installs Composer dependencies.
"""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile

CORE = "elgibor-solution/laravel-inventory"
METADATA = "inventory-packages.json"


def run(*args):
    return subprocess.check_output(args)


def write_json(path, data):
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def build(tag, repository, output):
    if not re.fullmatch(r"v?2\.\d+\.\d+(?:-(?:alpha|beta|RC)\d+)?", tag):
        raise ValueError("Expected a 2.x release tag, e.g. v2.0.2 or v2.0.2-RC1")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ValueError("Expected GitHub owner/repository")
    version = tag.removeprefix("v")
    ref = "refs/tags/" + tag
    commit = run("git", "rev-parse", "--verify", ref + "^{commit}").decode().strip()
    snapshot = zipfile.ZipFile(io.BytesIO(run("git", "archive", "--format=zip", ref)))
    files = sorted(name for name in snapshot.namelist() if not name.endswith("/"))
    manifests = ["composer.json"] + [
        name for name in files if re.fullmatch(r"packages/[^/]+/composer.json", name)
    ]
    if len(manifests) != 10:
        raise ValueError("Expected Core and nine module manifests")
    output.mkdir(parents=True, exist_ok=True)
    catalog = {"packages": {}}
    for manifest_path in manifests:
        manifest = json.loads(snapshot.read(manifest_path))
        name = manifest["name"]
        if not re.fullmatch(r"elgibor-solution/laravel-inventory(?:-[a-z]+)?", name):
            raise ValueError("Unexpected package name: " + name)
        if name in catalog["packages"]:
            raise ValueError("Duplicate package: " + name)
        if name != CORE and manifest["require"].get(CORE) != "^2.0":
            raise ValueError(name + " must require Core ^2.0 before distribution")
        # Development tooling is maintained in the source monorepo, not runtime ZIPs.
        for key in ("autoload-dev", "require-dev", "scripts", "config", "repositories", "version"):
            manifest.pop(key, None)
        manifest.get("extra", {}).pop("branch-alias", None)
        prefix = manifest_path.removesuffix("composer.json")
        contents = {"composer.json": (json.dumps(manifest, indent=2) + "\n").encode()}
        for file in files:
            if not file.startswith(prefix):
                continue
            relative = file[len(prefix):]
            if relative.startswith(("src/", "config/", "database/")) or relative in ("README.md", "LICENSE"):
                contents[relative] = snapshot.read(file)
        contents.setdefault("LICENSE", snapshot.read("LICENSE"))
        # Validate runtime autoload targets before publishing broken archives.
        for target in manifest.get("autoload", {}).get("files", []):
            if target not in contents:
                raise ValueError(f"Missing autoload file: {name}/{target}")
        for target in manifest.get("autoload", {}).get("psr-4", {}).values():
            if not any(file.startswith(target) for file in contents):
                raise ValueError(f"Missing autoload directory: {name}/{target}")
        archive = output / (name.split("/")[1] + "-" + version + ".zip")
        with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as dist:
            for file, data in sorted(contents.items()):
                info = zipfile.ZipInfo(file, date_time=(2020, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                dist.writestr(info, data)
        manifest["version"] = version
        manifest["dist"] = {
            "type": "zip",
            "url": f"https://github.com/{repository}/releases/download/{tag}/{archive.name}",
            "reference": commit,
            "shasum": hashlib.sha1(archive.read_bytes()).hexdigest(),
        }
        catalog["packages"][name] = {version: manifest}
    write_json(output / METADATA, catalog)
    return catalog


def merge(catalogs):
    merged = {"packages": {}}
    for catalog in catalogs:
        for name, versions in catalog["packages"].items():
            target = merged["packages"].setdefault(name, {})
            for version, manifest in versions.items():
                if version in target and target[version] != manifest:
                    raise ValueError(f"Conflicting immutable version: {name} {version}")
                target[version] = manifest
    return merged


def publish(tag, repository, artifacts, site):
    release = json.loads(run("gh", "api", f"repos/{repository}/releases/tags/{tag}"))
    if release["draft"]:
        raise ValueError("Publish the GitHub release before distributing packages")
    existing = {asset["name"] for asset in release["assets"]}
    # Upload ZIPs before metadata, which is the marker of a complete distribution.
    for file in sorted(artifacts.glob("*.zip")) + [artifacts / METADATA]:
        if file.name in existing:
            with tempfile.TemporaryDirectory() as temporary:
                run("gh", "release", "download", tag, "--repo", repository,
                    "--pattern", file.name, "--dir", temporary)
                if (Path(temporary) / file.name).read_bytes() != file.read_bytes():
                    raise ValueError("Refusing to overwrite release asset: " + file.name)
        else:
            run("gh", "release", "upload", tag, str(file), "--repo", repository)
    # Enumerate all pages, preserving historical releases when publishing or retrying.
    pages = json.loads(run("gh", "api", "--paginate", "--slurp",
                           f"repos/{repository}/releases?per_page=100"))
    catalogs = [json.loads((artifacts / METADATA).read_text(encoding="utf-8"))]
    for page in pages:
        for previous in page:
            if previous["draft"] or previous["tag_name"] == tag:
                continue
            if not any(asset["name"] == METADATA for asset in previous["assets"]):
                continue
            with tempfile.TemporaryDirectory() as temporary:
                run("gh", "release", "download", previous["tag_name"], "--repo", repository,
                    "--pattern", METADATA, "--dir", temporary)
                catalogs.append(json.loads((Path(temporary) / METADATA).read_text(encoding="utf-8")))
    site.mkdir(parents=True, exist_ok=True)
    write_json(site / "packages.json", merge(catalogs))
    (site / "index.html").write_text(
        '<!doctype html><title>Inventory Composer repository</title>'
        '<h1>Inventory Composer repository</h1><p><a href="packages.json">Package catalog</a></p>',
        encoding="utf-8",
    )


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("build", "publish"))
    parser.add_argument("--tag", required=True)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--output", type=Path, default=Path("build/distribution"))
    parser.add_argument("--site", type=Path, default=Path("build/site"))
    args = parser.parse_args()
    if args.action == "build":
        build(args.tag, args.repository, args.output)
    else:
        publish(args.tag, args.repository, args.output, args.site)
