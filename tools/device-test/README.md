# Device test

Tests catalog apps on the iPhone plugged into this Mac, at night, and repairs what it can. For each listing of the configured categories (smallest build first):

1. The live build is signed for the test device, installed over USB, launched and watched for a minute: the process must stay alive, and three screenshots are read (Vision OCR, Russian and English) for a blank screen or alerts such as jailbreak warnings, forced updates or Telegram channel gates.
2. If it fails, the server makes a cleaned copy (`catalog:clean-batch` rules, not published) and the copy is tested. A copy that passes replaces the live build at once; the old original is deleted by the storage janitor a day later.
3. If that is not enough, the app is tried once more keeping its original bundle ID (`KeptBundleIds` file list). If that works it stays on the list.
4. Apps nothing helps are reported as BROKEN (or UNSURE when they run but the screen looks wrong) and left as they are.

Each result goes to the Telegram admins.

Phone storage: every app the agent installs is uninstalled right after its test, also when the test breaks off, and anything a crash or restart left behind is removed before the next install. So at most one test app (up to ~2.5 GB) is on the phone at a time; the test phone had about 20 GB free. If an install still runs out of space, the run pauses and says so in Telegram. Apps that were already on the phone before are updated, never uninstalled, so their data stays. Every catalog change happens on the server through `php artisan catalog:device-test` (JSON over SSH), with the same services as the admin panel.

## Running it

```sh
tools/device-test/install.sh                # install/update and start under launchd (runs in the background)
tools/device-test/install.sh --uninstall    # stop it; state and results stay
```

The agent lives in `~/Library/Application Support/RuAppStoreDeviceTest` (`config.json`, `state.json`, `results.jsonl`, `evidence/`, `logs/agent.log`). Its commands:

```sh
A="$HOME/Library/Application Support/RuAppStoreDeviceTest/agent.py"
python3 "$A" status       # progress and every listing's tests
python3 "$A" start-now    # run now, outside the night window, until the queue is done (stop-now undoes it)
python3 "$A" pause        # stop after the current step (resume undoes it)
python3 "$A" retest 123   # test listing 123 again from the start
python3 "$A" report       # report.html with the screenshots
```

## What it needs

- The iPhone on USB, charging, unlocked, Auto-Lock set to Never, not used during the window (`window` in `config.json`, 00:30–08:00 by default). A locked or unplugged phone pauses the run and sends one Telegram message.
- The Mac on power with the lid open; the job holds `caffeinate -is`, so the Mac does not idle-sleep while it is installed.
- SSH access to the server without a passphrase, and Xcode (for `devicectl` and `swiftc`).

Downloads run at about 1 MB/s, so the 72 games (~23 GB) take one to two nights. Only one build is on disk at a time.
