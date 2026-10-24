#!/usr/bin/env python3
"""Remove the eight injected modules in the reviewed SberBank 17.6.1 archive.

Only this exact source is supported. Preserve main code/data and import ordinals;
remove injected load commands and modules, including the sideload/hook fixes.
This does not reconstruct the vendor's original signed App Store archive.
"""
import argparse
import hashlib
import importlib.util
import json
import plistlib
from pathlib import Path
import zipfile

SOURCE_SHA256 = "730e7920bfa2aa1d9fdf5a7acd2c5aca46a14c5dd1d5d14ee71c5f636a34892b"
ROOT = "Payload/FamilyBudget.app/"
MODULES = {
    "libobjcpatch.dylib": "6d95071e3a340511b59e02469292a95ef0f722ba65840db93dabf47f6cf5efc4",
    "lidersunframe.dylib": "2b49fe4e7945de23bfd367f287c3cc17444b8c7a3e2cd48557211d0ef84abc49",
    "libps.dylib": "c28b69576e4ae87e13ae68956521f4444109d7b88e13a47264cca6ccd15d297c",
    "liframesatrate.dylib": "089742d0b6a127680b8fe155949c2a208ef5c444fcc9dc432164c56f17d1cd25",
    "Frameworks/blatantsPatch.dylib": "3b4f9715273d79e99fac5b9b1c9e42aba828b7dcdc0b1ad61c018b75eeaf2a58",
    "liberdade.dylib": "b0cb2b5862dc36d8d777ea615a34bb78131e888d0c5968f512fd2ecf6d308475",
    "libsubstrate.dylib": "57474521415bc469f0e298c04de493d031b450de177908d0bc31b9934d9b9a6c",
    "CoreServicesExtras.framework/CoreServicesExtras": "2a0700d1a020985f7e0903666c98a29748faed3b5bb6220f355991b82a5cb3c9",
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--report", required=True, type=Path)
    args = parser.parse_args()
    if args.source.resolve() == args.output.resolve() or args.output.exists():
        raise ValueError("Use a new output path; original is retained")
    spec = importlib.util.spec_from_file_location("ipa_clean", Path(__file__).with_name("clean-local-ipas.py"))
    cleaner = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(cleaner)
    if cleaner.digest(args.source) != SOURCE_SHA256:
        raise ValueError("Unreviewed source build")
    with zipfile.ZipFile(args.source) as archive:
        if len(set(archive.namelist())) != len(archive.namelist()):
            raise ValueError("Duplicate archive entries")
        for name in archive.namelist():
            cleaner.valid_path(name)
        for name, expected in MODULES.items():
            if hashlib.sha256(archive.read(ROOT + name)).hexdigest() != expected:
                raise ValueError("Reviewed module hash mismatch: " + name)
        removals = {ROOT + name for name in MODULES}
        removals.update(name for name in archive.namelist() if name.startswith(ROOT + "CoreServicesExtras.framework/"))
        names = {Path(name).name for name in MODULES}
        binary = ROOT + "FamilyBudget"
        patched, loads = cleaner.patch(archive.read(binary), names)
        if {Path(load).name for load in loads} != names:
            raise ValueError("Unexpected injected load commands")
        # Restore the canonical identifier seen in URL registration and the
        # existing catalog source, and the minimum declared by the executable.
        info_path = ROOT + "Info.plist"
        raw_info = archive.read(info_path)
        info = plistlib.loads(raw_info)
        if info["CFBundleIdentifier"] != "com.inv.gen." or info["MinimumOSVersion"] != "10.0":
            raise ValueError("Unexpected modified app metadata")
        for offset, size in cleaner.slices(patched):
            macho = cleaner.MachO(patched[offset:offset + size])
            versions = [macho.unpack("II", at + 8) for cmd, at, _, _ in macho.commands if cmd == 0x32]
            if versions != [(2, 0x000F0000)]:
                raise ValueError("Unexpected Mach-O minimum iOS version")
        info["CFBundleIdentifier"] = "com.inv.gen"
        info["MinimumOSVersion"] = "15.0"
        normalized_info = plistlib.dumps(info, fmt=plistlib.FMT_BINARY if raw_info.startswith(b"bplist") else plistlib.FMT_XML, sort_keys=False)
    cleaner.rewrite_zip(args.source, args.output, removals, {binary: patched, info_path: normalized_info})
    with zipfile.ZipFile(args.source) as original, zipfile.ZipFile(args.output) as cleaned:
        if cleaned.testzip() is not None or removals.intersection(cleaned.namelist()):
            raise ValueError("Archive cleanup verification failed")
        for name in cleaned.namelist():
            if name not in (binary, info_path) and cleaned.read(name) != original.read(name):
                raise ValueError("Retained entry changed: " + name)
        for offset, size in cleaner.slices(cleaned.read(binary)):
            if any(Path(load).name in names for load in cleaner.MachO(cleaned.read(binary)[offset:offset + size]).libraries):
                raise ValueError("Injected dependency survived")
    report = {"source_sha256": SOURCE_SHA256, "output_sha256": cleaner.digest(args.output),
              "removed_modules": list(MODULES), "removed_loads": loads,
              "source_bytes": args.source.stat().st_size, "output_bytes": args.output.stat().st_size,
              "retained_entries_except_main_header_and_info_byte_identical": True, "main_code_data_and_import_ordinals_preserved": True,
              "metadata_restored": {"CFBundleIdentifier": "com.inv.gen", "MinimumOSVersion": "15.0"},
              "requires_resigning": True, "runtime_tested": False,
              "limitations": "Injected hook/sideload fixes are also removed; bank login and device behavior need an iPhone test. Original main binary patches cannot be reconstructed without a vendor reference."}
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(report, ensure_ascii=False))


if __name__ == "__main__":
    main()
