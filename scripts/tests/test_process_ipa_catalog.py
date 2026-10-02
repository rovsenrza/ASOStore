import hashlib
import importlib.util
import json
import os
import plistlib
import shutil
import signal
import subprocess
import tempfile
import time
import unittest
import zipfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "scripts" / "process-ipa-catalog.sh"
# The cleaner's own test helpers build small, valid Mach-O binaries and IPAs.
spec = importlib.util.spec_from_file_location("ipa_clean_fixtures", REPO / "tools" / "ipa-cleaner" / "tests" / "test_ipa_clean.py")
fixtures = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixtures)
SYSTEM, IOS15, ROOT = fixtures.SYSTEM, 0x000F0000, fixtures.ROOT

# Stands in for the cleaner: records its PID, then hangs.
HANGING_TOOL = """import os, sys, time
open(sys.argv[0] + ".pid", "w").write(str(os.getpid()))
time.sleep(120)
"""


def info(bundle_id="test.app", minimum="15.0"):
    return {"CFBundleIdentifier": bundle_id, "CFBundleExecutable": "App", "CFBundleShortVersionString": "1.0",
            "CFBundleVersion": "1", "MinimumOSVersion": minimum}


def extension(name, binary):
    return {f"PlugIns/{name}.appex/{name}": binary,
            f"PlugIns/{name}.appex/Info.plist": plistlib.dumps({"CFBundleIdentifier": "test.app." + name.lower(), "CFBundleExecutable": name,
                                                                "NSExtension": {"NSExtensionPointIdentifier": "com.apple.usernotifications.service"}})}


def make_catalog(directory):
    """One IPA per outcome, a file that is not an IPA, and a non-ZIP named .ipa."""
    directory.mkdir()
    fixtures.write_ipa(directory / "Plain.ipa", {"App": fixtures.macho([SYSTEM], minos=IOS15)}, info())
    fixtures.write_ipa(directory / "Promo [v1.0].ipa", fixtures.injected_app(), info())
    fixtures.write_ipa(directory / "Метаданные.ipa", {"App": fixtures.macho([SYSTEM], minos=IOS15)}, info("com.example.app.", "10.0"))
    fixtures.write_ipa(directory / "Widget.ipa", {"App": fixtures.macho([SYSTEM], minos=IOS15),
                                                  **extension("N", fixtures.macho([SYSTEM], encrypted=True))}, info())
    # Like OK's LibverifyExt: an encrypted framework of the app that only an extension uses.
    fixtures.write_ipa(directory / "Notify.ipa", {"App": fixtures.macho([SYSTEM], minos=IOS15),
                                                  "Frameworks/Lib.framework/Lib": fixtures.macho([SYSTEM], encrypted=True),
                                                  **extension("E", fixtures.macho([SYSTEM, "@rpath/Lib.framework/Lib"], refs=(1, 2)))}, info())
    fixtures.write_ipa(directory / "Store.ipa", {"App": fixtures.macho([SYSTEM], encrypted=True, minos=IOS15)}, info())
    (directory / "Broken.ipa").write_bytes(b"not a zip archive")
    (directory / "notes.txt").write_text("not an IPA")
    return directory


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def summary(output):
    lines = (output / "summary.tsv").read_text(encoding="utf-8").splitlines()
    header = lines[0].split("\t")
    return {line.split("\t")[0]: dict(zip(header, line.split("\t"))) for line in lines[1:]}


def gone(pid, seconds=10):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return True
        time.sleep(0.1)
    return False


class ProcessCatalogTests(unittest.TestCase):
    def setUp(self):
        self.temp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.temp, ignore_errors=True)
        self.catalog = make_catalog(self.temp / "catalog")
        self.output = self.temp / "out"

    def environment(self, **extra):
        return {**os.environ, "NO_COLOR": "1", **extra}

    def run_script(self, *options, inspect=False, **extra):
        command = ["bash", str(SCRIPT), "--output", str(self.output), *options, str(self.catalog)]
        if not inspect:
            command.insert(2, "--no-inspect")
        return subprocess.run(command, capture_output=True, text=True, env=self.environment(**extra), timeout=600)

    def hanging_tool(self):
        tool = self.temp / "hanging_tool.py"
        tool.write_text(HANGING_TOOL)
        return tool

    def start_hanging_run(self):
        tool = self.hanging_tool()
        process = subprocess.Popen(["bash", str(SCRIPT), "--no-inspect", "--only", "Plain.ipa", "--output", str(self.output), str(self.catalog)],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                                   env=self.environment(IPA_CLEANER=str(tool), STEP_TIMEOUT="0"))
        self.addCleanup(process.kill)
        pid_file = Path(str(tool) + ".pid")
        deadline = time.monotonic() + 30
        while not pid_file.exists() or not pid_file.read_text():
            self.assertLess(time.monotonic(), deadline, "the tool never started")
            time.sleep(0.1)
        return process, int(pid_file.read_text())

    def test_cleans_checks_and_repairs_each_app_and_never_touches_the_sources(self):
        before = {path.name: digest(path) for path in self.catalog.iterdir()}
        result = self.run_script()

        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        rows = summary(self.output)
        self.assertEqual({name: row["status"] for name, row in rows.items()}, {
            "Plain.ipa": "OK", "Promo [v1.0].ipa": "OK", "Метаданные.ipa": "FIXED",
            "Widget.ipa": "FAILED", "Notify.ipa": "FAILED", "Store.ipa": "FAILED", "Broken.ipa": "FAILED"})
        self.assertEqual({path.name: digest(path) for path in self.catalog.iterdir()}, before)
        self.assertEqual(sorted(path.name for path in (self.output / "ready").iterdir()), ["Plain.ipa", "Promo [v1.0].ipa", "Метаданные.ipa"])
        for name in ("Plain.ipa", "Promo [v1.0].ipa", "Метаданные.ipa"):
            self.assertEqual(rows[name]["sha256"], digest(self.output / "ready" / name))

        # The reviewed promotion goes; a suspected hook (often a needed sideload fix) stays.
        names = zipfile.ZipFile(self.output / "ready" / "Promo [v1.0].ipa").namelist()
        self.assertNotIn(ROOT + "libobjcpatch.dylib", names)
        self.assertIn(ROOT + "hook.dylib", names)
        plist = plistlib.loads(zipfile.ZipFile(self.output / "ready" / "Метаданные.ipa").read(ROOT + "Info.plist"))
        self.assertEqual((plist["CFBundleIdentifier"], plist["MinimumOSVersion"]), ("com.example.app", "15.0"))

        self.assertIn("rerun with --drop-encrypted", rows["Widget.ipa"]["detail"])
        self.assertIn("Frameworks/Lib.framework/Lib, loaded only by E.appex", rows["Notify.ipa"]["detail"])
        self.assertIn("FairPlay-encrypted", rows["Store.ipa"]["detail"])
        self.assertIn("clean refused: File is not a zip file", rows["Broken.ipa"]["detail"])
        self.assertIn("4 failed", result.stdout)
        self.assertFalse((self.output / ".lock").exists() or (self.output / ".work").exists())

    def test_a_rerun_skips_what_passed_and_drops_encrypted_parts_when_allowed(self):
        self.run_script()
        result = self.run_script("--drop-encrypted")

        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("3 passed in an earlier run", result.stdout)
        self.assertIn("skip    passed in an earlier run: ready/Plain.ipa", result.stdout)
        rows = summary(self.output)
        self.assertEqual([rows[name]["status"] for name in ("Widget.ipa", "Notify.ipa", "Store.ipa")], ["FIXED", "FIXED", "FAILED"])
        for name in ("Widget.ipa", "Notify.ipa"):
            self.assertEqual(zipfile.ZipFile(self.output / "ready" / name).namelist(), [ROOT + "Info.plist", ROOT + "App"])

    def test_succeeds_when_every_selected_app_passes(self):
        dry = self.run_script("--dry-run", "--only", "Plain.ipa")
        self.assertEqual(dry.returncode, 0, dry.stderr)
        self.assertFalse(self.output.exists())

        result = self.run_script("--only", "Plain.ipa", "--only", "Метаданные.ipa")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(sorted(summary(self.output)), ["Plain.ipa", "Метаданные.ipa"])
        self.assertEqual(self.run_script("--only", "Missing.ipa").returncode, 2)

    def test_a_step_that_hangs_is_stopped_after_the_timeout(self):
        tool = self.hanging_tool()
        result = self.run_script("--only", "Plain.ipa", IPA_CLEANER=str(tool), STEP_TIMEOUT="1")

        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertEqual(summary(self.output)["Plain.ipa"]["detail"], "clean timed out after 1s")
        self.assertTrue(gone(int(Path(str(tool) + ".pid").read_text())))

    def test_an_interrupt_stops_the_running_tool_and_releases_the_output(self):
        process, tool = self.start_hanging_run()
        # A second run into the same output is refused while the first one works.
        second = self.run_script("--only", "Plain.ipa")
        self.assertEqual(second.returncode, 2)
        self.assertIn("is writing to", second.stderr)

        process.send_signal(signal.SIGINT)
        output = process.communicate(timeout=30)[0]
        self.assertEqual(process.returncode, 130, output)
        self.assertIn("interrupted (SIGINT)", output)
        self.assertIn("Not processed: 1 (the run was interrupted)", output)
        self.assertTrue(gone(tool))
        self.assertFalse((self.output / ".lock").exists() or (self.output / ".work").exists())
        self.assertTrue((self.output / "summary.tsv").exists())

    @unittest.skipUnless(shutil.which("php") and (REPO / "backend" / "vendor" / "autoload.php").exists(), "needs the backend")
    def test_the_check_includes_the_upload_inspection(self):
        result = self.run_script("--drop-encrypted", "--only", "Plain.ipa", "--only", "Метаданные.ipa", "--only", "Widget.ipa", inspect=True)

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        rows = summary(self.output)
        self.assertEqual([rows[name]["status"] for name in ("Plain.ipa", "Метаданные.ipa", "Widget.ipa")], ["OK", "FIXED", "FIXED"])
        apps = self.output / "apps"
        before = json.loads((apps / "Метаданные.ipa" / "2-inspect.out").read_text())
        after = json.loads((apps / "Метаданные.ipa" / "4-inspect.out").read_text())
        self.assertEqual((before["failure_code"], after["outcome"]), ("INVALID_METADATA", "PROVENANCE_REVIEW"))
        self.assertEqual(json.loads((apps / "Widget.ipa" / "2-inspect.out").read_text())["failure_code"], "ENCRYPTED_BINARY")


if __name__ == "__main__":
    unittest.main()
