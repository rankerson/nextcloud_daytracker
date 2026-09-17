"""Verify original snapshots and publish their existing tags using GitHub CLI."""
import hashlib
import json
import pathlib
import subprocess
import tarfile
import xml.etree.ElementTree as ET

REPO = "rankerson/nextcloud_daytracker"
SOURCE = "f084db23e7c48af51a621e22400383cbaecc36ae"
VERSIONS = {
    "1.1.3": "8dcecadbcb9c64146cac7a5ecacc48f837a9307a",
    "2.0.5": "64b23da1d3e94d8968a56ef10a613a558037e797",
    "3.0.0": "83f48f0377955402adfadc48d35aba0e98ed1514",
    "3.0.2": "151e4b4fa193c4708b2a90ea0b0c72c90f8dfe42",
}


def run(*args):
    return subprocess.check_output(args, text=True, encoding="utf-8").strip()


def files(ref):
    entries = run("git", "ls-tree", "-r", ref).splitlines()
    return {line.split("\t", 1)[1]: line.split()[2] for line in entries}


def main():
    run("git", "clone", "--quiet", f"https://github.com/{REPO}.git", "source")
    import os
    os.chdir("source")
    source = files(SOURCE)
    prepared = []
    # Check all four snapshots before creating any release.
    for version, commit in VERSIONS.items():
        tag = f"v{version}"
        if run("git", "rev-parse", f"{tag}^{{commit}}") != commit:
            raise RuntimeError(f"Unexpected commit for {tag}")
        prefix = f"daytracker_Version {version}/"
        expected = {p[len(prefix):]: sha for p, sha in source.items() if p.startswith(prefix)}
        expected["LICENSE"] = source["LICENSE"]
        if files(commit) != expected:
            raise RuntimeError(f"Snapshot differs from original folder: {tag}")
        info = ET.fromstring(run("git", "show", f"{commit}:appinfo/info.xml"))
        if info.findtext("version") != version:
            raise RuntimeError(f"Version mismatch: {tag}")
        archive = f"daytracker-{version}.tar.gz"
        run("git", "archive", "--format=tar.gz", "--prefix=daytracker/", f"--output={archive}", commit)
        with tarfile.open(archive) as package:
            packaged = {m.name.removeprefix("daytracker/") for m in package.getmembers() if m.isfile()}
            if packaged != set(expected):
                raise RuntimeError(f"Incomplete archive: {tag}")
            for path, sha in expected.items():
                data = package.extractfile(f"daytracker/{path}").read()
                if hashlib.sha1(f"blob {len(data)}\0".encode() + data).hexdigest() != sha:
                    raise RuntimeError(f"Archive content mismatch: {path}")
        checksum_path = archive + ".sha256"
        checksum = hashlib.sha256(pathlib.Path(archive).read_bytes()).hexdigest()
        pathlib.Path(checksum_path).write_text(f"{checksum}  {archive}\n", encoding="utf-8")
        notes_path = f"release-{version}.md"
        notes = f"""Historische Version {version}, nachträglich als GitHub-Release veröffentlicht.

Der Quellcode entspricht bytegenau dem ursprünglichen Ordner `daytracker_Version {version}` aus Commit `{SOURCE}`. Zusätzlich ist die unveränderte Repository-Lizenz enthalten.

- Geprüfter Tag: `{tag}` / Commit `{commit}`.
- Das Archiv `{archive}` enthält den App-Ordner `daytracker/`. Für Installation und Upgrades gilt die README dieser Version.
- Voraussetzungen laut App-Metadaten: Nextcloud 33, PHP ab 8.1.
- Eine SHA-256-Prüfsumme liegt bei.

Geprüft wurden die Dateiinhalte, das vollständige Archiv und die Versionsnummer. Ein neuer Laufzeittest in Nextcloud wurde nicht durchgeführt.
"""
        pathlib.Path(notes_path).write_text(notes, encoding="utf-8")
        prepared.append((tag, archive, checksum_path, notes_path))
        print(f"Verified {tag}: {len(expected)} files", flush=True)
    existing = {r["tag_name"] for r in json.loads(run("gh", "api", f"repos/{REPO}/releases?per_page=100"))}
    for tag, archive, checksum_path, notes_path in prepared:
        if tag in existing:
            print(f"Already published: {tag}; leaving unchanged", flush=True)
            continue
        print(run("gh", "release", "create", tag, archive, checksum_path,
                  "--repo", REPO, "--verify-tag", "--title", f"Daytracker {tag}",
                  "--notes-file", notes_path,
                  "--latest" if tag == "v3.0.2" else "--latest=false"), flush=True)


if __name__ == "__main__":
    main()
