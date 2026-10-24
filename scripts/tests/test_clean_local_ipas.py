import importlib.util
import hashlib
import struct
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("clean_local_ipas", Path(__file__).parents[1] / "clean-local-ipas.py")
cleaner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cleaner)


def library(path):
    name = path.encode() + b"\0"
    size = (24 + len(name) + 7) & ~7
    return struct.pack("<6I", 0x80000018, size, 24, 0, 0, 0) + name + bytes(size - 24 - len(name))


def binary(binding="classic", ordinal=1):
    loads = library("/usr/lib/libSystem.B.dylib") + library("@executable_path/promo.dylib") + library("@executable_path/helper.dylib")
    if binding == "classic":
        payload = bytes([0x10 | ordinal, 0x90, 0])
        offset = 32 + len(loads) + 48
        metadata = struct.pack("<12I", 0x80000022, 48, 0, 0, offset, len(payload), 0, 0, 0, 0, 0, 0)
    elif binding == "chained":
        payload = struct.pack("<7I", 0, 0, 28, 32, 1, 1, 0) + struct.pack("<I", ordinal) + b"symbol\0"
        offset = 32 + len(loads) + 16
        metadata = struct.pack("<4I", 0x80000034, 16, offset, len(payload))
    else:
        payload = struct.pack("<IBBHQ", 0, 1, 0, ordinal << 8, 0)
        offset = 32 + len(loads) + 24
        metadata = struct.pack("<6I", 2, 24, offset, 1, offset + 16, 1)
        payload += b"\0"
    commands = loads + metadata
    return struct.pack("<8I", 0xFEEDFACF, 0x100000C, 0, 2, 4, len(commands), 0, 0) + commands + payload


class CleanupTests(unittest.TestCase):
    def test_library_patch_preserves_other_bytes_is_idempotent_and_rejects_unknown_builds(self):
        original = b"unchanged prefix" + bytes.fromhex("ff8301d1") + b"unchanged suffix"
        updated = original[:16] + bytes.fromhex("c0035fd6") + original[20:]
        rule = {"original_sha256": hashlib.sha256(original).hexdigest(),
                "patched_sha256": hashlib.sha256(updated).hexdigest(),
                "reason": "test promotion callback", "sites": [(16, "ff8301d1", "test callback")]}
        with patch.dict(cleaner.LIBRARY_PATCHES, {"test.dylib": rule}):
            result, report = cleaner.patch_library("Payload/App.app/test.dylib", original)
            self.assertEqual(result, updated)
            self.assertEqual(report["sites"][0]["offset"], 16)
            self.assertEqual(cleaner.patch_library("test.dylib", result), (result, None))
            with self.assertRaisesRegex(ValueError, "Unsupported library build"):
                cleaner.patch_library("test.dylib", original + b"different build")
            wrong_instruction = dict(rule, sites=[(16, "00000000", "wrong callback")])
            with patch.dict(cleaner.LIBRARY_PATCHES, {"test.dylib": wrong_instruction}):
                with self.assertRaisesRegex(ValueError, "instruction precondition"):
                    cleaner.patch_library("test.dylib", original)

    def test_process_applies_library_only_patch_and_preserves_other_resources(self):
        with tempfile.TemporaryDirectory() as temp:
            source, destination = Path(temp) / "old.ipa", Path(temp) / "new.ipa"
            original = binary() + bytes.fromhex("ff8301d1")
            offset = len(original) - 4
            # The synthetic bind payload isn't aligned, so add padding before code.
            padding = (-offset) % 4
            original = original[:offset] + bytes(padding) + original[offset:]
            offset += padding
            updated = original[:offset] + bytes.fromhex("c0035fd6")
            rule = {"original_sha256": hashlib.sha256(original).hexdigest(),
                    "patched_sha256": hashlib.sha256(updated).hexdigest(),
                    "reason": "test callback", "sites": [(offset, "ff8301d1", "test callback")]}
            import plistlib
            with zipfile.ZipFile(source, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                archive.writestr("Payload/App.app/Info.plist", plistlib.dumps({
                    "CFBundleIdentifier": "test.app", "CFBundleExecutable": "App"}))
                archive.writestr("Payload/App.app/App", binary())
                archive.writestr("Payload/App.app/test.dylib", original)
                archive.writestr("Payload/App.app/resource.txt", b"retain")
            with patch.dict(cleaner.LIBRARY_PATCHES, {"test.dylib": rule}):
                report = cleaner.process(source, destination, False)
            self.assertTrue(report["requires_resigning"])
            self.assertEqual(len(report["patched_files"]), 1)
            self.assertFalse(report["removed_files"])
            with zipfile.ZipFile(destination) as archive:
                self.assertEqual(archive.read("Payload/App.app/test.dylib"), updated)
                self.assertEqual(archive.read("Payload/App.app/App"), binary())
                self.assertEqual(archive.read("Payload/App.app/resource.txt"), b"retain")

    def test_max_promotions_require_content_evidence(self):
        self.assertIsNotNone(cleaner.classify("NewFix2.dylib", b"https://ipacrack.com SCLAlertView", False))
        self.assertIsNone(cleaner.classify("NewFix2.dylib", b"functional helper", False))
        self.assertIsNotNone(cleaner.classify("HelpshiftX.dylib", b"https://t.me/MIHALSANCH alertControllerWithTitle:message:preferredStyle:", False))
        self.assertIsNone(cleaner.classify("HelpshiftX.dylib", b"ordinary support library", False))

    def test_keeps_code_offsets_and_original_imports_when_removing_unreferenced_injection(self):
        for binding in ("classic", "chained", "symbols"):
            with self.subTest(binding=binding):
                original = binary(binding)
                old = cleaner.MachO(original)
                cleaned, removed = cleaner.patch(original, {"promo.dylib"})
                new = cleaner.MachO(cleaned)
                self.assertEqual(removed, ["@executable_path/promo.dylib"])
                self.assertEqual(new.libraries, ["/usr/lib/libSystem.B.dylib", "@executable_path/helper.dylib"])
                self.assertEqual(new.ordinals(), old.ordinals())
                self.assertEqual(cleaned[old.header + old.command_bytes:], original[old.header + old.command_bytes:])
                self.assertEqual(len(cleaned), len(original))

    def test_refuses_an_imported_injection_and_a_later_ordinal_that_would_move(self):
        for binding in ("classic", "chained", "symbols"):
            for ordinal in (2, 3):
                with self.subTest(binding=binding, ordinal=ordinal):
                    with self.assertRaisesRegex(ValueError, "referenced library ordinal"):
                        cleaner.patch(binary(binding, ordinal), {"promo.dylib"})

    def test_patches_each_fat_slice_without_moving_slice_offsets(self):
        arm = binary()
        first, second = 4096, 8192
        fat = bytearray(second + len(arm))
        struct.pack_into(">II", fat, 0, 0xCAFEBABE, 2)
        struct.pack_into(">IIIII", fat, 8, 0x100000C, 0, first, len(arm), 12)
        struct.pack_into(">IIIII", fat, 28, 0x100000C, 1, second, len(arm), 12)
        fat[first:first + len(arm)] = arm
        fat[second:second + len(arm)] = arm
        result, _ = cleaner.patch(bytes(fat), {"promo.dylib"})
        self.assertEqual(result[:48], fat[:48])
        for offset, size in cleaner.slices(result):
            self.assertNotIn("@executable_path/promo.dylib", cleaner.MachO(result[offset:offset + size]).libraries)

    def test_zip_rewrite_preserves_unicode_resources_modes_and_original_compressed_records(self):
        with tempfile.TemporaryDirectory() as temp:
            source, destination = Path(temp) / "old.ipa", Path(temp) / "new.ipa"
            with zipfile.ZipFile(source, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                archive.writestr("Payload/App.app/promo.dylib", b"remove")
                archive.writestr("Payload/App.app/App", binary())
                resource = zipfile.ZipInfo("Payload/App.app/ru.lproj/Пример.txt")
                resource.external_attr = 0o100644 << 16
                resource.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(resource, b"retained resource" * 1000)
                archive.comment = b"preserved"
            updated, _ = cleaner.patch(binary(), {"promo.dylib"})
            cleaner.rewrite_zip(source, destination, {"Payload/App.app/promo.dylib"}, {"Payload/App.app/App":updated})
            with zipfile.ZipFile(source) as old, zipfile.ZipFile(destination) as new:
                self.assertIsNone(new.testzip())
                self.assertNotIn("Payload/App.app/promo.dylib", new.namelist())
                self.assertEqual(new.comment, old.comment)
                name = resource.filename
                self.assertEqual(new.read(name), old.read(name))
                self.assertEqual(new.getinfo(name).external_attr, old.getinfo(name).external_attr)
                self.assertEqual(new.getinfo(name).compress_size, old.getinfo(name).compress_size)
                self.assertEqual(new.read("Payload/App.app/App"), updated)


if __name__ == "__main__":
    unittest.main()
