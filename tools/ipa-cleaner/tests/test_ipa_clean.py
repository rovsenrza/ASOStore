import contextlib
import hashlib
import importlib.util
import io
import json
import plistlib
import struct
import tempfile
import unittest
import zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location("ipa_clean", Path(__file__).parents[1] / "ipa_clean.py")
cleaner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cleaner)
RULES = cleaner.load_rules()
ROOT = "Payload/App.app/"


def library(path, cmd=0xC):
    name = path.encode() + b"\0"
    size = (24 + len(name) + 7) & ~7
    return struct.pack("<6I", cmd, size, 24, 0, 0, 0) + name + bytes(size - 24 - len(name))


def macho(loads, refs=(1,), data=b"", binding="classic", encrypted=None, minos=None, install_name=None):
    """A small arm64 Mach-O that binds symbols from the libraries at `refs` (1-based ordinals)."""
    commands = b"".join(library(name) for name in loads)
    extra = library(install_name, cmd=0xD) if install_name else b""
    if encrypted is not None:
        extra += struct.pack("<6I", 0x2C, 24, 0, 0, 1 if encrypted else 0, 0)
    if minos is not None:
        extra += struct.pack("<6I", 0x32, 24, 2, minos, minos, 0)
    if binding == "classic":
        payload = b"".join(bytes([0x10 | ordinal, 0x90]) for ordinal in refs) + b"\0"
        offset = 32 + len(commands) + len(extra) + 48
        metadata = struct.pack("<12I", 0x80000022, 48, 0, 0, offset, len(payload), 0, 0, 0, 0, 0, 0)
    elif binding == "chained":
        imports = b"".join(struct.pack("<I", ordinal) for ordinal in refs)
        payload = struct.pack("<7I", 0, 0, 28, 28 + len(imports), len(refs), 1, 0) + imports + b"symbol\0"
        offset = 32 + len(commands) + len(extra) + 16
        metadata = struct.pack("<4I", 0x80000034, 16, offset, len(payload))
    else:
        offset = 32 + len(commands) + len(extra) + 24
        payload = b"".join(struct.pack("<IBBHQ", 0, 1, 0, ordinal << 8, 0) for ordinal in refs)
        metadata = struct.pack("<6I", 2, 24, offset, len(refs), offset + len(payload), 1)
        payload += b"\0"
    commands += extra + metadata
    count = len(loads) + (encrypted is not None) + (minos is not None) + bool(install_name) + 1
    return struct.pack("<8I", 0xFEEDFACF, 0x100000C, 0, 2, count, len(commands), 0, 0) + commands + payload + data


def write_ipa(path, files, info=None):
    info = info or {"CFBundleIdentifier": "test.app", "CFBundleExecutable": "App", "CFBundleShortVersionString": "1.0", "MinimumOSVersion": "15.0"}
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(ROOT + "Info.plist", plistlib.dumps(info))
        for name, data in files.items():
            archive.writestr(ROOT + name, data)
    return path


SYSTEM = "/usr/lib/libSystem.B.dylib"
PROMO = macho([SYSTEM], data=b"https://t.me/promochannel RSPill")


def injected_app():
    return {
        "App": macho([SYSTEM, "@rpath/Real.framework/Real", "@executable_path/libobjcpatch.dylib", "@executable_path/hook.dylib"], refs=(1, 2)),
        "Frameworks/Real.framework/Real": macho([SYSTEM]),
        "Frameworks/Real.framework/Info.plist": b"real framework",
        "libobjcpatch.dylib": PROMO,
        "hook.dylib": macho([SYSTEM], data=b"MSHookMessageEx"),
        "ru.lproj/Пример.txt": b"retained resource" * 100,
    }


class AnalysisTests(unittest.TestCase):
    def test_reports_reviewed_promotions_and_unknown_hooks_separately(self):
        with tempfile.TemporaryDirectory() as temp:
            report = cleaner.analyze(write_ipa(Path(temp) / "app.ipa", injected_app()), RULES)
        modules = {module["path"]: module for module in report["modules"]}
        self.assertEqual(set(modules), {ROOT + "libobjcpatch.dylib", ROOT + "hook.dylib"})
        promo = modules[ROOT + "libobjcpatch.dylib"]
        self.assertEqual((promo["rule"], promo["category"], promo["recommended"], promo["removable"]), ("libobjcpatch", "promotion", True, True))
        self.assertEqual(promo["telegram_links"], ["https://t.me/promochannel"])
        hook = modules[ROOT + "hook.dylib"]
        self.assertEqual((hook["rule"], hook["category"], hook["recommended"], hook["removable"]), (None, "suspected-hook", False, True))
        self.assertEqual(hook["hook_markers"], ["MSHookMessageEx"])
        self.assertEqual(report["recommended"]["remove"], [ROOT + "libobjcpatch.dylib"])

    def test_a_module_whose_symbols_are_used_is_not_removable(self):
        files = {"App": macho([SYSTEM, "@executable_path/libobjcpatch.dylib"], refs=(1, 2)), "libobjcpatch.dylib": PROMO}
        with tempfile.TemporaryDirectory() as temp:
            source = write_ipa(Path(temp) / "app.ipa", files)
            module = cleaner.analyze(source, RULES)["modules"][0]
            self.assertEqual((module["removable"], module["recommended"]), (False, False))
            self.assertIn("uses symbols", module["blocked_reason"])
            with self.assertRaisesRegex(cleaner.Refused, "uses symbols"):
                cleaner.clean(source, Path(temp) / "out.ipa", RULES, remove=[ROOT + "libobjcpatch.dylib"])

    def test_a_substrate_that_tweaks_reach_by_install_name_is_not_offered(self):
        substrate = "/Library/Frameworks/CydiaSubstrate.framework/CydiaSubstrate"
        files = {
            "App": macho([SYSTEM, "@rpath/CydiaSubstrate.framework/CydiaSubstrate", "@executable_path/tweak.dylib"]),
            "Frameworks/CydiaSubstrate.framework/CydiaSubstrate": macho([SYSTEM], data=b"MSHookMessageEx", install_name=substrate),
            "tweak.dylib": macho([SYSTEM, substrate], refs=(1, 2), data=b"_logos_method"),
        }
        with tempfile.TemporaryDirectory() as temp:
            source = write_ipa(Path(temp) / "app.ipa", files)
            modules = {module["path"]: module for module in cleaner.analyze(source, RULES)["modules"]}
            self.assertEqual(set(modules), {ROOT + "tweak.dylib"})
            # Removing the tweak keeps the substrate: the app itself still loads it.
            report = cleaner.clean(source, Path(temp) / "out.ipa", RULES, remove=[ROOT + "tweak.dylib"])
            self.assertEqual([module["path"] for module in report["removed_modules"]], [ROOT + "tweak.dylib"])

    def test_a_hook_runtime_goes_only_together_with_the_tweaks_that_call_it(self):
        files = {
            "App": macho([SYSTEM, "@executable_path/Frameworks/CydiaSubstrate.framework/CydiaSubstrate", "@executable_path/tweak.dylib"]),
            "Frameworks/CydiaSubstrate.framework/CydiaSubstrate": macho([SYSTEM], data=b"MSHookMessageEx", install_name="/usr/local/lib/libellekit.dylib"),
            "tweak.dylib": macho([SYSTEM], data=b"_logos_method"),
        }
        runtime = ROOT + "Frameworks/CydiaSubstrate.framework/CydiaSubstrate"
        with tempfile.TemporaryDirectory() as temp:
            source = write_ipa(Path(temp) / "app.ipa", files)
            modules = {module["path"]: module for module in cleaner.analyze(source, RULES)["modules"]}
            self.assertEqual(modules[runtime]["category"], "hook-runtime")
            with self.assertRaisesRegex(cleaner.Refused, "still needed by tweak.dylib"):
                cleaner.clean(source, Path(temp) / "out.ipa", RULES, remove=[runtime])
            report = cleaner.clean(source, Path(temp) / "out.ipa", RULES, remove=[runtime, ROOT + "tweak.dylib"])
            self.assertEqual(report["removed_entries"], 2)

    def test_names_alone_never_match_a_rule(self):
        files = {"App": macho([SYSTEM, "@rpath/libobjcpatch.dylib"], refs=(1,)), "Frameworks/libobjcpatch.dylib": macho([SYSTEM], data=b"ordinary")}
        with tempfile.TemporaryDirectory() as temp:
            report = cleaner.analyze(write_ipa(Path(temp) / "app.ipa", files), RULES)
        self.assertEqual(report["modules"], [])

    def test_reports_metadata_problems_encryption_and_ad_sdks(self):
        info = {"CFBundleIdentifier": "com.inv.gen.", "CFBundleExecutable": "App", "CFBundleShortVersionString": "17.6", "MinimumOSVersion": "10.0"}
        files = {"App": macho([SYSTEM], data=b"GADBannerView", minos=0x000F0000), "PlugIns/N.appex/N": macho([SYSTEM], encrypted=True),
                 "PlugIns/N.appex/Info.plist": plistlib.dumps({"CFBundleIdentifier": "com.inv.gen.n", "CFBundleExecutable": "N",
                                                               "NSExtension": {"NSExtensionPointIdentifier": "com.apple.usernotifications.service"}})}
        with tempfile.TemporaryDirectory() as temp:
            report = cleaner.analyze(write_ipa(Path(temp) / "app.ipa", files, info), RULES)
        self.assertEqual([issue["code"] for issue in report["metadata_issues"]], ["BUNDLE_ID_TRAILING_DOT", "MINIMUM_OS_BELOW_BINARY"])
        self.assertEqual(report["metadata_issues"][1]["fix"], "15.0")
        self.assertEqual(report["encrypted_binaries"], [ROOT + "PlugIns/N.appex/N"])
        self.assertEqual(report["extensions"], [{"path": ROOT + "PlugIns/N.appex/", "bundle_id": "com.inv.gen.n",
                                                 "point": "com.apple.usernotifications.service", "encrypted": True}])
        self.assertEqual(report["native_ad_sdk_markers"], ["GADBannerView"])
        self.assertTrue(report["recommended"]["fix_metadata"])


class CleanTests(unittest.TestCase):
    def test_recommended_cleanup_removes_only_the_promotion_and_keeps_everything_else(self):
        with tempfile.TemporaryDirectory() as temp:
            source, output = write_ipa(Path(temp) / "app.ipa", injected_app()), Path(temp) / "clean.ipa"
            report = cleaner.clean(source, output, RULES, recommended=True)
            self.assertTrue(report["changed"])
            self.assertEqual(report["removed_loads"], {ROOT + "App": ["@executable_path/libobjcpatch.dylib"]})
            self.assertEqual([module["path"] for module in report["removed_modules"]], [ROOT + "libobjcpatch.dylib"])
            with zipfile.ZipFile(source) as old, zipfile.ZipFile(output) as new:
                self.assertNotIn(ROOT + "libobjcpatch.dylib", new.namelist())
                self.assertIn(ROOT + "hook.dylib", new.namelist())
                for name in (ROOT + "ru.lproj/Пример.txt", ROOT + "Frameworks/Real.framework/Real", ROOT + "hook.dylib"):
                    self.assertEqual(new.read(name), old.read(name))
                before, after = cleaner.MachO(old.read(ROOT + "App")), cleaner.MachO(new.read(ROOT + "App"))
                self.assertEqual(after.libraries, [SYSTEM, "@rpath/Real.framework/Real", "@executable_path/hook.dylib"])
                self.assertEqual(after.ordinals(), before.ordinals())
                self.assertEqual(new.read(ROOT + "App")[before.header + before.command_bytes:], old.read(ROOT + "App")[before.header + before.command_bytes:])
            self.assertEqual(cleaner.analyze(output, RULES)["recommended"]["remove"], [])

            # Cleaning the result again changes nothing.
            again = cleaner.clean(output, Path(temp) / "again.ipa", RULES, recommended=True)
            self.assertFalse(again["changed"])
            self.assertEqual(again["output_sha256"], report["output_sha256"])

    def test_an_explicitly_selected_unknown_module_goes_too(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "clean.ipa"
            cleaner.clean(write_ipa(Path(temp) / "app.ipa", injected_app()), output, RULES, remove=[ROOT + "hook.dylib"])
            with zipfile.ZipFile(output) as archive:
                self.assertNotIn(ROOT + "hook.dylib", archive.namelist())
                self.assertIn(ROOT + "libobjcpatch.dylib", archive.namelist())
                self.assertNotIn("@executable_path/hook.dylib", cleaner.MachO(archive.read(ROOT + "App")).libraries)

    def test_removing_an_extension_takes_the_framework_only_it_used(self):
        files = {
            "App": macho([SYSTEM, "@rpath/Shared.framework/Shared"], refs=(1, 2)),
            "Frameworks/Shared.framework/Shared": macho([SYSTEM]),
            "Frameworks/Private.framework/Private": macho([SYSTEM], encrypted=True),
            "Frameworks/Private.framework/Info.plist": b"private",
            "PlugIns/N.appex/N": macho([SYSTEM, "@rpath/Private.framework/Private", "@rpath/Shared.framework/Shared"], refs=(1, 2, 3), encrypted=True),
            "PlugIns/N.appex/Info.plist": plistlib.dumps({"CFBundleIdentifier": "test.app.n", "CFBundleExecutable": "N"}),
        }
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "clean.ipa"
            report = cleaner.clean(write_ipa(Path(temp) / "app.ipa", files), output, RULES, remove_extensions=[ROOT + "PlugIns/N.appex/"])
            self.assertEqual(report["removed_modules"], [{"path": ROOT + "Frameworks/Private.framework/Private", "orphaned_dependency": True}])
            with zipfile.ZipFile(output) as archive:
                names = set(archive.namelist())
            self.assertFalse({name for name in names if "/N.appex/" in name or "/Private.framework/" in name})
            self.assertIn(ROOT + "Frameworks/Shared.framework/Shared", names)
            self.assertEqual(cleaner.analyze(output, RULES)["encrypted_binaries"], [])

    def test_fixes_the_bundle_id_and_minimum_os_only_when_asked(self):
        info = {"CFBundleIdentifier": "com.inv.gen.", "CFBundleExecutable": "App", "CFBundleShortVersionString": "17.6", "MinimumOSVersion": "10.0"}
        with tempfile.TemporaryDirectory() as temp:
            source = write_ipa(Path(temp) / "app.ipa", {"App": macho([SYSTEM], minos=0x000F0000)}, info)
            self.assertFalse(cleaner.clean(source, Path(temp) / "plain.ipa", RULES)["changed"])
            report = cleaner.clean(source, Path(temp) / "fixed.ipa", RULES, fix_metadata=True)
            self.assertEqual(report["metadata_fixes"], [{"key": "CFBundleIdentifier", "from": "com.inv.gen.", "to": "com.inv.gen"},
                                                        {"key": "MinimumOSVersion", "from": "10.0", "to": "15.0"}])
            with zipfile.ZipFile(Path(temp) / "fixed.ipa") as archive:
                fixed = plistlib.loads(archive.read(ROOT + "Info.plist"))
            self.assertEqual((fixed["CFBundleIdentifier"], fixed["MinimumOSVersion"], fixed["CFBundleShortVersionString"]), ("com.inv.gen", "15.0", "17.6"))

    def test_library_patch_applies_to_the_reviewed_build_only_and_is_idempotent(self):
        original = b"unchanged prefix" + bytes.fromhex("ff8301d1") + b"unchanged suffix"
        updated = original[:16] + bytes.fromhex("c0035fd6") + original[20:]
        rules = dict(RULES, library_patches=[{"id": "test", "name": "test.dylib", "reason": "test promotion callback",
                                              "original_sha256": hashlib.sha256(original).hexdigest(),
                                              "patched_sha256": hashlib.sha256(updated).hexdigest(),
                                              "sites": [(16, "ff8301d1", "test callback")]}])
        result, report = cleaner.patch_library(ROOT + "test.dylib", original, rules)
        self.assertEqual(result, updated)
        self.assertEqual(report["sites"][0]["offset"], 16)
        self.assertEqual(cleaner.patch_library("test.dylib", result, rules), (result, None))
        with self.assertRaisesRegex(cleaner.Refused, "Unsupported library build"):
            cleaner.patch_library("test.dylib", original + b"different build", rules)

    def test_refuses_to_overwrite_its_source(self):
        with tempfile.TemporaryDirectory() as temp:
            source = write_ipa(Path(temp) / "app.ipa", injected_app())
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                status = cleaner.main(["clean", str(source), str(source), "--recommended"])
            self.assertEqual(status, 2)
            self.assertIn("new path", json.loads(out.getvalue())["error"]["message"])


class MachOTests(unittest.TestCase):
    def test_keeps_code_offsets_and_imports_when_removing_an_unused_load(self):
        for binding in ("classic", "chained", "symbols"):
            with self.subTest(binding=binding):
                original = macho([SYSTEM, "@executable_path/promo.dylib", "@executable_path/helper.dylib"], binding=binding)
                old = cleaner.MachO(original)
                cleaned, removed = cleaner.remove_loads(original, lambda name: name.endswith("/promo.dylib"))
                new = cleaner.MachO(cleaned)
                self.assertEqual(removed, ["@executable_path/promo.dylib"])
                self.assertEqual(new.libraries, [SYSTEM, "@executable_path/helper.dylib"])
                self.assertEqual(new.ordinals(), old.ordinals())
                self.assertEqual(cleaned[old.header + old.command_bytes:], original[old.header + old.command_bytes:])
                self.assertEqual(len(cleaned), len(original))

    def test_refuses_a_used_load_and_one_before_a_used_ordinal(self):
        for binding in ("classic", "chained", "symbols"):
            for refs in ((1, 2), (1, 3)):
                with self.subTest(binding=binding, refs=refs):
                    with self.assertRaisesRegex(cleaner.Refused, "referenced library ordinal"):
                        cleaner.remove_loads(macho([SYSTEM, "@executable_path/promo.dylib", "@executable_path/helper.dylib"], refs=refs, binding=binding),
                                             lambda name: name.endswith("/promo.dylib"))

    def test_patches_each_fat_slice_without_moving_slice_offsets(self):
        arm = macho([SYSTEM, "@executable_path/promo.dylib"])
        first, second = 4096, 8192
        fat = bytearray(second + len(arm))
        struct.pack_into(">II", fat, 0, 0xCAFEBABE, 2)
        struct.pack_into(">IIIII", fat, 8, 0x100000C, 0, first, len(arm), 12)
        struct.pack_into(">IIIII", fat, 28, 0x100000C, 1, second, len(arm), 12)
        fat[first:first + len(arm)] = arm
        fat[second:second + len(arm)] = arm
        result, _ = cleaner.remove_loads(bytes(fat), lambda name: name.endswith("/promo.dylib"))
        self.assertEqual(result[:48], fat[:48])
        for offset, size in cleaner.slices(result):
            self.assertNotIn("@executable_path/promo.dylib", cleaner.MachO(result[offset:offset + size]).libraries)

    def test_zip_rewrite_preserves_unicode_resources_modes_and_compressed_records(self):
        with tempfile.TemporaryDirectory() as temp:
            source, destination = Path(temp) / "old.ipa", Path(temp) / "new.ipa"
            with zipfile.ZipFile(source, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                archive.writestr(ROOT + "promo.dylib", b"remove")
                archive.writestr(ROOT + "App", b"old")
                resource = zipfile.ZipInfo(ROOT + "ru.lproj/Пример.txt")
                resource.external_attr = 0o100644 << 16
                resource.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(resource, b"retained resource" * 1000)
                archive.comment = b"preserved"
            cleaner.rewrite_zip(source, destination, {ROOT + "promo.dylib"}, {ROOT + "App": b"new"})
            with zipfile.ZipFile(source) as old, zipfile.ZipFile(destination) as new:
                self.assertIsNone(new.testzip())
                self.assertNotIn(ROOT + "promo.dylib", new.namelist())
                self.assertEqual(new.comment, old.comment)
                name = resource.filename
                self.assertEqual(new.read(name), old.read(name))
                self.assertEqual(new.getinfo(name).external_attr, old.getinfo(name).external_attr)
                self.assertEqual(new.getinfo(name).compress_size, old.getinfo(name).compress_size)
                self.assertEqual(new.read(ROOT + "App"), b"new")


if __name__ == "__main__":
    unittest.main()
