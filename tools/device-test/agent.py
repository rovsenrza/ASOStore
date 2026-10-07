#!/usr/bin/env python3
"""Device-test agent: tests catalog apps on the iPhone plugged into this Mac, at night.

For every listing of the configured categories it installs the live build over USB,
launches it and watches it for a minute (process alive, screenshots, on-screen text).
A build that fails gets a cleaned copy from the server; a copy that passes replaces the
live build at once. If that is not enough the app is tried once more keeping its original
bundle ID. Apps nothing helps are reported, never hidden. All catalog changes happen on the
server through `artisan catalog:device-test`, called over SSH.

Runs under launchd (install.sh); state survives restarts. Commands:

    agent.py run          the loop launchd keeps alive
    agent.py status       progress and the last results
    agent.py start-now    ignore the night window until the queue is done (stop-now undoes it)
    agent.py pause        stop after the current step (resume undoes it)
    agent.py retest ID    test one listing again from the start
    agent.py report       write report.html with the screenshots
"""

import datetime as dt
import html
import json
import os
import shlex
import signal
import subprocess
import sys
import threading
import time
from pathlib import Path

HOME = Path(__file__).resolve().parent
CONFIG = HOME / "config.json"
STATE = HOME / "state.json"
RESULTS = HOME / "results.jsonl"
WORK = HOME / "work"
EVIDENCE = HOME / "evidence"
PAUSE = HOME / "PAUSE"
RUN_NOW = HOME / "RUN_NOW"

FINAL = ("OK", "REPLACED", "KEPT_ID", "BROKEN", "UNSURE", "ERROR")
# On-screen text that means the app is not really usable (lower case).
ALERTS = (
    "jailbreak", "jailbroken", "джейлбрейк", "please update", "update required", "please upgrade", "upgrade required", "new version is required",
    "обновите приложение", "требуется обновление", "необходимо обновить",
    "something went wrong", "что-то пошло не так", "unable to verify", "не удалось проверить",
    "t.me/", "подпишитесь на канал", "subscribe to our channel", "недостаточно памяти", "no free storage",
)


class DeviceUnavailable(Exception):
    """The phone is not usable right now (unplugged, locked, out of space): wait, do not blame the app."""


class StepFailed(Exception):
    """This attempt failed for a reason outside the app (network, signing); retry later."""


def log(message):
    print(f"{dt.datetime.now():%Y-%m-%d %H:%M:%S} {message}", flush=True)


def load_json(path, default):
    try:
        return json.loads(path.read_text())
    except (FileNotFoundError, json.JSONDecodeError):
        return default


def save_json(path, data):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(data, ensure_ascii=False, indent=1))
    temporary.replace(path)


class Agent:
    def __init__(self):
        self.config = load_json(CONFIG, None)
        if self.config is None:
            sys.exit(f"missing {CONFIG}")
        self.state = load_json(STATE, {"apps": {}, "order": []})
        self.udid = self.config["device_udid"]
        self.active = False
        self.notified_unavailable = False
        for directory in (WORK, EVIDENCE):
            directory.mkdir(exist_ok=True)

    # ---------------------------------------------------------------- server

    def server(self, action, target=None, timeout=300, **options):
        command = self.config["artisan"] + " " + shlex.quote(action)
        if target is not None:
            command += " " + shlex.quote(str(target))
        options.setdefault("device", self.config["device_id"])
        options.setdefault("user", self.config["user_id"])
        for key, value in options.items():
            values = value if isinstance(value, list) else [value]
            for item in values:
                if item is True:
                    command += f" --{key}"
                elif item not in (None, False):
                    command += f" --{key}={shlex.quote(str(item))}"
        ssh = ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=20", "-o", "ServerAliveInterval=30",
               "-o", "ServerAliveCountMax=6", self.config["ssh"], command + " < /dev/null"]
        for attempt in range(3):
            try:
                done = subprocess.run(ssh, capture_output=True, text=True, timeout=timeout)
            except subprocess.TimeoutExpired:
                raise StepFailed(f"server {action} timed out")
            lines = [line for line in done.stdout.splitlines() if line.startswith("{")]
            if lines:
                result = json.loads(lines[-1])
                if not result.get("ok"):
                    raise StepFailed(f"server {action}: {result.get('error')}")
                return result
            log(f"server {action} gave no answer (exit {done.returncode}): {done.stderr.strip()[-300:]}")
            time.sleep(20 * (attempt + 1))
        raise StepFailed(f"server {action} unreachable")

    def notify(self, message):
        try:
            self.server("notify", message=message, timeout=60)
        except StepFailed as error:
            log(f"notify failed: {error}")

    # ---------------------------------------------------------------- device

    def devicectl(self, *arguments, timeout=120, json_output=False):
        command = ["xcrun", "devicectl", *arguments]
        output = None
        if json_output:
            output = WORK / "devicectl.json"
            output.unlink(missing_ok=True)
            command += ["--json-output", str(output), "-q"]
        try:
            done = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise StepFailed(f"devicectl {arguments[:3]} timed out")
        text = (done.stdout + done.stderr).strip()
        lowered = text.lower()
        if done.returncode != 0:
            if "locked" in lowered:
                raise DeviceUnavailable("the iPhone is locked")
            if "not connected" in lowered or "unable to locate" in lowered or "no such device" in lowered:
                raise DeviceUnavailable("the iPhone is not connected")
            if any(phrase in lowered for phrase in ("not enough space", "not enough storage", "insufficient storage", "no space", "out of space", "nospace")):
                raise DeviceUnavailable("the iPhone is out of storage")
        if json_output:
            data = load_json(output, {})
            output.unlink(missing_ok=True)
            return done.returncode, data.get("result", {}), text
        return done.returncode, None, text

    def device_ready(self):
        """Connected and unlocked; raises DeviceUnavailable otherwise."""
        _, result, _ = self.devicectl("list", "devices", json_output=True)
        connected = any(
            device.get("hardwareProperties", {}).get("udid") == self.udid
            and device.get("connectionProperties", {}).get("pairingState") == "paired"
            and device.get("connectionProperties", {}).get("transportType") in ("wired", "localNetwork")
            for device in result.get("devices", []))
        if not connected:
            raise DeviceUnavailable("the iPhone is not connected")
        _, lock, _ = self.devicectl("device", "info", "lockState", "--device", self.udid, json_output=True)
        if lock.get("passcodeRequired"):
            raise DeviceUnavailable("the iPhone is locked")
        return True

    def installed_apps(self):
        code, result, text = self.devicectl("device", "info", "apps", "--device", self.udid, json_output=True)
        if code != 0:
            raise DeviceUnavailable(f"cannot list apps: {text[-200:]}")
        return {app["bundleIdentifier"]: app.get("url", "") for app in result.get("apps", [])}

    def running(self, app_url):
        code, result, _ = self.devicectl("device", "info", "processes", "--device", self.udid, json_output=True)
        if code != 0 or not app_url:
            return None
        return any(process.get("executable", "").startswith(app_url) for process in result.get("runningProcesses", []))

    def screenshot(self, path):
        code, _, _ = self.devicectl("device", "capture", "screenshot", "--device", self.udid, "--destination", str(path), timeout=60)
        if code != 0 or not path.exists():
            return None
        checked = subprocess.run([str(HOME / "screencheck"), str(path)], capture_output=True, text=True, timeout=60)
        result = json.loads(checked.stdout) if checked.returncode == 0 else {"uniform": 0, "text": ""}
        small = path.with_suffix(".jpg")
        subprocess.run(["sips", "-s", "format", "jpeg", "-Z", "900", str(path), "--out", str(small)], capture_output=True)
        path.unlink(missing_ok=True)
        result["file"] = str(small.relative_to(HOME))
        return result

    def watch(self, bundle, app_url, evidence):
        """Launch, keep it running for `watch_seconds`, look at the screen three times."""
        seconds = int(self.config.get("watch_seconds", 60))
        process = subprocess.Popen(["xcrun", "devicectl", "device", "process", "launch", "--device", self.udid,
                                    "--terminate-existing", "--console", bundle],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, errors="replace")
        console = []
        reader = threading.Thread(target=lambda: console.extend(process.stdout), daemon=True)
        reader.start()
        started = time.monotonic()
        shots, exited_after = [], None
        for moment in (8, seconds // 2, seconds - 3):
            while time.monotonic() - started < moment and process.poll() is None:
                time.sleep(0.5)
            if process.poll() is not None:
                exited_after = round(time.monotonic() - started)
                break
            shot = self.screenshot(evidence.with_name(f"{evidence.name}-{moment}s.png"))
            if shot:
                shots.append(shot)
        alive = None
        if exited_after is None:
            alive = self.running(app_url)
            process.send_signal(signal.SIGINT)
        try:
            process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            process.kill()
        reader.join(timeout=5)
        text = "".join(console)
        if exited_after is not None and ("locked" in text.lower() and "launch" in text.lower()):
            raise DeviceUnavailable("the iPhone is locked")
        evidence.with_name(f"{evidence.name}-console.txt").write_text(text[-20000:])
        return {"exited_after": exited_after, "alive": alive, "shots": shots, "console_tail": text[-600:]}

    @staticmethod
    def judge(run):
        if run["exited_after"] is not None:
            return "FAIL", f"quit after {run['exited_after']} s"
        if run["alive"] is False:
            return "FAIL", "not running at the end"
        shots = run["shots"]
        if len(shots) >= 2 and min(shot["uniform"] for shot in shots) >= 0.95:
            return "SUSPECT", "blank screen the whole time"
        seen = "\n".join(shot["text"] for shot in shots).lower()
        alerts = [word for word in ALERTS if word in seen]
        if alerts:
            return "SUSPECT", "on screen: " + ", ".join(alerts)
        return "PASS", "running, screen shows content"

    # ---------------------------------------------------------------- one test

    def test(self, app, artifact_id, variant, require_original_id=False):
        """Sign, download, install, watch, clean up. Returns the recorded result."""
        name = app["name"]
        log(f"[{app['app_id']}] {name}: {variant} (artifact {artifact_id})")
        build = self.server("sign", artifact_id)
        deadline = time.monotonic() + 60 * int(self.config.get("sign_timeout_minutes", 60))
        while build["status"] != "DELIVERABLE":
            if build["status"] in ("SIGNING_FAILED", "VALIDATION_FAILED", "EXPIRED", "REVOKED"):
                raise StepFailed(f"signing ended {build['status']} {build.get('status_reason') or ''}")
            if time.monotonic() > deadline:
                raise StepFailed("signing took too long")
            time.sleep(30)
            build = self.server("build", build["build_id"])
        if "url" not in build:
            build = self.server("build", build["build_id"])

        ipa = WORK / f"build-{build['build_id']}.ipa"
        try:
            self.download(build["url"], ipa, build["size_bytes"])
            info = self.info_plist(ipa)
            bundle = info.get("CFBundleIdentifier") or build["bundle_identifier"]
            if require_original_id and not info.get("RuStoreOriginalBundleIdentifier"):
                # A build signed before the switch was reused: retire it so the retry signs anew.
                self.server("keep", app["app_id"])
                raise StepFailed("build was signed without the original bundle ID")
            self.device_ready()
            self.remove_leftovers()
            # An app the owner already had keeps its data: it is updated, never uninstalled.
            preexisting = bundle in self.installed_apps()
            if not preexisting:
                self.state.setdefault("installed", []).append(bundle)
                save_json(STATE, self.state)
            code, _, text = self.devicectl("device", "install", "app", "--device", self.udid, str(ipa), timeout=1800)
            if code != 0:
                raise StepFailed(f"install failed: {text[-300:]}")
        except BaseException:
            self.cleanup()
            raise
        finally:
            ipa.unlink(missing_ok=True)

        try:
            app_url = self.installed_apps().get(bundle, "")
            folder = EVIDENCE / str(app["app_id"])
            folder.mkdir(exist_ok=True)
            stamp = dt.datetime.now().strftime("%m%d-%H%M%S")
            run = self.watch(bundle, app_url, folder / f"{variant}-{stamp}")
            verdict, reason = self.judge(run)
            if verdict == "FAIL" and run["exited_after"] is not None and run["exited_after"] < 15:
                # The first launch straight after an install sometimes quits once; judge the second.
                log(f"[{app['app_id']}] quit after {run['exited_after']} s, launching once more")
                run = self.watch(bundle, app_url, folder / f"{variant}-{stamp}-again")
                verdict, reason = self.judge(run)
        finally:
            # Whatever happened, a tested app leaves the phone so its storage never fills up.
            self.cleanup()

        result = {"at": dt.datetime.now().isoformat(timespec="seconds"), "app_id": app["app_id"], "name": name,
                  "variant": variant, "artifact_id": artifact_id, "build_id": build["build_id"], "bundle": bundle,
                  "verdict": verdict, "reason": reason, "shots": [shot["file"] for shot in run["shots"]],
                  "text": "\n".join(shot["text"] for shot in run["shots"])[:1500], "console_tail": run["console_tail"],
                  "kept_on_phone": preexisting}
        with RESULTS.open("a") as file:
            file.write(json.dumps(result, ensure_ascii=False) + "\n")
        log(f"[{app['app_id']}] {name}: {variant} → {verdict} ({reason})")
        return result

    def cleanup(self):
        try:
            self.remove_leftovers()
        except Exception as error:  # the phone may be gone; the next start removes them
            log(f"cleanup postponed: {error}")

    def remove_leftovers(self):
        """Uninstall every app this agent put on the phone (also ones a crash or restart left behind)."""
        installed = self.state.get("installed", [])
        if not installed:
            return
        present = self.installed_apps()
        for bundle in list(installed):
            if bundle in present:
                code, _, text = self.devicectl("device", "uninstall", "app", "--device", self.udid, bundle, timeout=300)
                if code != 0:
                    log(f"uninstall {bundle} failed: {text[-200:]}")
                    continue
                log(f"removed {bundle} from the phone")
            installed.remove(bundle)
        save_json(STATE, self.state)

    def download(self, url, path, size):
        # Generous for ~1 MB/s; curl resumes across retries.
        timeout = 600 + (size or 0) // 150_000
        done = subprocess.run(["curl", "-fsSL", "--retry", "5", "--retry-delay", "15", "--retry-all-errors", "-C", "-",
                               "-o", str(path), url], capture_output=True, text=True, timeout=timeout)
        if done.returncode != 0 or (size and path.stat().st_size != size):
            path.unlink(missing_ok=True)
            raise StepFailed(f"download failed ({done.returncode}): {done.stderr.strip()[-200:]}")

    @staticmethod
    def info_plist(ipa):
        listing = subprocess.run(["unzip", "-Z1", str(ipa)], capture_output=True, text=True).stdout.splitlines()
        names = [name for name in listing if name.count("/") == 2 and name.startswith("Payload/") and name.endswith(".app/Info.plist")]
        if not names:
            return {}
        raw = subprocess.run(["unzip", "-p", str(ipa), names[0]], capture_output=True).stdout
        converted = subprocess.run(["plutil", "-convert", "json", "-o", "-", "-"], input=raw, capture_output=True)
        try:
            return json.loads(converted.stdout)
        except json.JSONDecodeError:
            return {}

    # ---------------------------------------------------------------- one listing

    def process(self, app):
        key = str(app["app_id"])
        entry = self.state["apps"].setdefault(key, {"stage": "live", "tests": []})
        save = lambda: save_json(STATE, self.state)  # noqa: E731

        def record(result):
            entry["tests"].append({k: result[k] for k in ("variant", "verdict", "reason", "artifact_id")})
            save()
            return result["verdict"]

        if entry["stage"] == "live":
            if record(self.test(app, app["artifact_id"], "live")) == "PASS":
                return self.finish(app, entry, "OK")
            entry["stage"] = "candidate"
            save()

        if entry["stage"] == "candidate":
            log(f"[{key}] {app['name']}: asking for a cleaned copy")
            copy = self.server("candidate", app["app_id"], timeout=2400, wait=1800)
            entry["candidate_result"] = copy["result"]
            entry["candidate"] = copy["artifact_id"] if copy["result"] == "READY" else None
            entry["removed"] = copy.get("removed", [])
            entry["stage"] = "candidate_test" if entry["candidate"] else "keep"
            save()

        if entry["stage"] == "candidate_test":
            if record(self.test(app, entry["candidate"], "cleaned")) == "PASS":
                self.server("publish", entry["candidate"])
                entry["published"] = entry["candidate"]
                return self.finish(app, entry, "REPLACED")
            entry["stage"] = "keep"
            save()

        if entry["stage"] == "keep":
            if app.get("kept_bundle_id"):
                entry["stage"] = "give_up"
            else:
                self.server("keep", app["app_id"])
                entry["keep_on"] = True
                entry["stage"] = "keep_test"
            save()

        if entry["stage"] == "keep_test":
            target = entry.get("candidate") or app["artifact_id"]
            if record(self.test(app, target, "kept-id", require_original_id=True)) == "PASS":
                if entry.get("candidate"):
                    self.server("publish", entry["candidate"])
                    entry["published"] = entry["candidate"]
                return self.finish(app, entry, "KEPT_ID")
            self.server("keep", app["app_id"], off=True)
            entry["keep_on"] = False
            entry["stage"] = "give_up"
            save()

        if entry["stage"] == "give_up":
            if entry.get("candidate"):
                self.server("discard", entry["candidate"])
            last = entry["tests"][-1]["verdict"] if entry["tests"] else "FAIL"
            return self.finish(app, entry, "UNSURE" if last == "SUSPECT" else "BROKEN")

    def abandon(self, app, entry):
        """Too many errors: undo what this listing's run changed, keep the live build as it is."""
        try:
            if entry.get("keep_on"):
                self.server("keep", app["app_id"], off=True)
                entry["keep_on"] = False
            if entry.get("candidate") and not entry.get("published"):
                self.server("discard", entry["candidate"])
        except StepFailed as error:
            log(f"[{app['app_id']}] cleanup failed: {error}")
        self.finish(app, entry, "ERROR")

    def finish(self, app, entry, outcome):
        entry["stage"] = "done"
        entry["outcome"] = outcome
        entry["finished_at"] = dt.datetime.now().isoformat(timespec="seconds")
        save_json(STATE, self.state)
        log(f"[{app['app_id']}] {app['name']}: {outcome}")
        last = entry["tests"][-1] if entry["tests"] else {}
        messages = {
            "REPLACED": f"«{app['name']}»: исходная сборка не работала ({entry['tests'][0]['reason']}); очищенная копия прошла тест на iPhone и опубликована вместо неё. Удалено: {', '.join(entry.get('removed') or []) or '—'}.",
            "KEPT_ID": f"«{app['name']}»: заработала, только сохранив исходный Bundle ID; добавлено в список keep-bundle-id" + (" и опубликована очищенная копия." if entry.get("published") else "."),
            "BROKEN": f"«{app['name']}»: не работает ни в одном варианте (последний: {last.get('reason', '—')}). Решите: оставить или скрыть.",
            "UNSURE": f"«{app['name']}»: запускается, но экран вызывает сомнения ({last.get('reason', '—')}). Посмотрите скриншоты в отчёте.",
            "ERROR": f"«{app['name']}»: тест не удалось провести ({entry.get('last_error', '—')}); сборка в каталоге не менялась.",
        }
        if outcome in messages:
            self.notify(messages[outcome])

    # ---------------------------------------------------------------- loop

    def in_window(self):
        if RUN_NOW.exists():
            return True
        start, end = (dt.time.fromisoformat(value) for value in self.config.get("window", ["00:30", "08:00"]))
        now = dt.datetime.now().time()
        return start <= now < end if start < end else (now >= start or now < end)

    def load_queue(self):
        apps = self.server("queue", category=self.config["categories"], timeout=300)["apps"]
        known = {str(app["app_id"]) for app in self.state.get("queue", [])}
        for app in apps:
            # A listing whose live build changed since (a new upload, not our own copy) is tested again.
            entry = self.state["apps"].get(str(app["app_id"]))
            if entry and entry.get("stage") == "done":
                seen = {test["artifact_id"] for test in entry["tests"]} | {entry.get("published")}
                if app["artifact_id"] not in seen:
                    del self.state["apps"][str(app["app_id"])]
        self.state["queue"] = apps
        self.state["queue_loaded_at"] = dt.datetime.now().isoformat(timespec="seconds")
        save_json(STATE, self.state)
        log(f"queue: {len(apps)} listings ({len({str(a['app_id']) for a in apps} - known)} new)")

    def pending(self):
        return [app for app in self.state.get("queue", []) if self.state["apps"].get(str(app["app_id"]), {}).get("stage") != "done"]

    def summary(self):
        outcomes = {}
        for app in self.state.get("queue", []):
            outcome = self.state["apps"].get(str(app["app_id"]), {}).get("outcome", "PENDING")
            outcomes[outcome] = outcomes.get(outcome, 0) + 1
        return outcomes

    def run(self):
        log(f"agent started, device {self.udid}")
        while True:
            try:
                if PAUSE.exists() or not self.in_window():
                    if self.active:
                        self.active = False
                        self.notify(f"Тест на iPhone: пауза до следующей ночи. Итог: {self.format_summary()}")
                    time.sleep(60)
                    continue
                if not self.active:
                    if not self.state.get("queue") or self.queue_stale():
                        self.load_queue()
                    if not self.pending():
                        RUN_NOW.unlink(missing_ok=True)
                        time.sleep(600)
                        continue
                    self.device_ready()
                    self.remove_leftovers()
                    self.active = True
                    self.notified_unavailable = False
                    self.notify(f"Тест на iPhone начат: осталось {len(self.pending())} из {len(self.state['queue'])}.")
                self.device_ready()
                apps = self.pending()
                if not apps:
                    RUN_NOW.unlink(missing_ok=True)
                    self.active = False
                    self.notify(f"Тест на iPhone завершён. Итог: {self.format_summary()}")
                    continue
                app = apps[0]
                self.prefetch(apps[1:4])
                try:
                    self.process(app)
                except StepFailed as error:
                    entry = self.state["apps"].setdefault(str(app["app_id"]), {"stage": "live", "tests": []})
                    entry["errors"] = entry.get("errors", 0) + 1
                    entry["last_error"] = str(error)
                    log(f"[{app['app_id']}] {app['name']}: {error} (attempt {entry['errors']})")
                    if entry["errors"] >= 3:
                        self.abandon(app, entry)
                    else:
                        # Try the others first; this one comes back at the end of the queue.
                        self.state["queue"].remove(app)
                        self.state["queue"].append(app)
                        save_json(STATE, self.state)
            except DeviceUnavailable as reason:
                log(f"waiting: {reason}")
                if not self.notified_unavailable:
                    self.notified_unavailable = True
                    hints = {
                        "the iPhone is locked": "iPhone заблокирован. Разблокируйте его и поставьте автоблокировку «Никогда».",
                        "the iPhone is not connected": "iPhone не подключён. Подключите его по USB.",
                        "the iPhone is out of storage": "на iPhone не хватает места. Освободите место (нужно 3–5 ГБ).",
                    }
                    self.notify(f"Тест на iPhone ждёт: {hints.get(str(reason), str(reason))}")
                time.sleep(300)
            except Exception as error:  # keep the daemon alive; launchd restarts it otherwise
                log(f"unexpected: {type(error).__name__}: {error}")
                time.sleep(120)

    def queue_stale(self):
        loaded = self.state.get("queue_loaded_at")
        return not loaded or dt.datetime.fromisoformat(loaded) < dt.datetime.now() - dt.timedelta(hours=12)

    def prefetch(self, apps):
        """Ask for the next live builds now so they are signed by the time their turn comes."""
        for app in apps:
            if self.state["apps"].get(str(app["app_id"]), {}).get("stage", "live") == "live":
                try:
                    self.server("sign", app["artifact_id"], timeout=120)
                except StepFailed as error:
                    log(f"prefetch {app['name']}: {error}")

    def format_summary(self):
        names = {"OK": "работают", "REPLACED": "заменены", "KEPT_ID": "с исходным ID", "BROKEN": "не работают",
                 "UNSURE": "под вопросом", "ERROR": "ошибки", "PENDING": "в очереди"}
        return ", ".join(f"{names.get(key, key)} {value}" for key, value in self.summary().items())


def report(agent):
    rows = []
    results = [json.loads(line) for line in RESULTS.read_text().splitlines()] if RESULTS.exists() else []
    for app in agent.state.get("queue", []):
        entry = agent.state["apps"].get(str(app["app_id"]), {})
        tests = [result for result in results if result["app_id"] == app["app_id"]]
        cells = "".join(
            f"<div class=t><b>{html.escape(t['variant'])}: {t['verdict']}</b> {html.escape(t['reason'])}<br>"
            + "".join(f"<img src='{html.escape(shot)}' loading=lazy>" for shot in t["shots"]) + "</div>" for t in tests)
        rows.append(f"<tr><td>{html.escape(app['name'])}<br><small>#{app['app_id']} · {app['size_bytes'] // 1_000_000} MB</small></td>"
                    f"<td class={entry.get('outcome', 'PENDING')}>{entry.get('outcome', entry.get('stage', 'PENDING'))}</td><td>{cells}</td></tr>")
    page = ("<!doctype html><meta charset=utf-8><title>Device test</title><style>body{font:14px system-ui;margin:16px}"
            "td{border-top:1px solid #ddd;padding:8px;vertical-align:top}img{height:220px;margin:4px;border:1px solid #ccc}"
            ".OK,.REPLACED,.KEPT_ID{color:#0a7d32}.BROKEN,.ERROR{color:#b3261e}.UNSURE{color:#9a6700}.t{margin-bottom:8px}</style>"
            f"<h1>Device test</h1><p>{html.escape(agent.format_summary())}</p><table>{''.join(rows)}</table>")
    (HOME / "report.html").write_text(page)
    print(HOME / "report.html")


def main():
    command = sys.argv[1] if len(sys.argv) > 1 else "status"
    agent = Agent()
    if command == "run":
        agent.run()
    elif command == "status":
        print(f"window {agent.config.get('window')} · in window now: {agent.in_window()} · paused: {PAUSE.exists()} · run-now: {RUN_NOW.exists()}")
        print(f"queue {len(agent.state.get('queue', []))}, pending {len(agent.pending())}: {agent.format_summary()}")
        for app in agent.state.get("queue", []):
            entry = agent.state["apps"].get(str(app["app_id"]))
            if entry:
                print(f"  #{app['app_id']:<4} {app['name'][:30]:<30} {entry.get('outcome', entry['stage']):<10} "
                      + " | ".join(f"{t['variant']}:{t['verdict']}" for t in entry["tests"]) + (f"  !{entry['last_error']}" if entry.get("last_error") and entry["stage"] != "done" else ""))
    elif command == "start-now":
        RUN_NOW.write_text("run until the queue is done\n")
        PAUSE.unlink(missing_ok=True)
        print("running now (outside the night window too)")
    elif command == "stop-now":
        RUN_NOW.unlink(missing_ok=True)
        print("back to the night window")
    elif command == "pause":
        PAUSE.write_text("paused by hand\n")
        print("paused after the current step")
    elif command == "resume":
        PAUSE.unlink(missing_ok=True)
        print("resumed")
    elif command == "retest":
        agent.state["apps"].pop(sys.argv[2], None)
        save_json(STATE, agent.state)
        PAUSE.unlink(missing_ok=True)
        print(f"listing {sys.argv[2]} will be tested again")
    elif command == "report":
        report(agent)
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
