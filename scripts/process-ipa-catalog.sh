#!/usr/bin/env bash
# Clean, check and repair every IPA in a catalog folder, with the IPA cleaner and the upload
# inspection.
#
#   scripts/process-ipa-catalog.sh [options] CATALOG_DIR
#
# On the server, run it as the app user, so artisan and the files it writes belong to that user:
#   sudo -u storefront /var/www/storefront/app/scripts/process-ipa-catalog.sh /path/to/ipas
#
# For each *.ipa directly in CATALOG_DIR:
#   1. clean   ipa_clean.py clean --recommended --keep-metadata: removes the reviewed promotions
#              and applies the pinned library patches (tools/ipa-cleaner/README.md).
#   2. check   ipa_clean.py analyze, then `artisan ipa:inspect`, the inspection an upload gets.
#              An app passes when its Info.plist is consistent, none of its code is
#              FairPlay-encrypted, the inspection accepts it and nothing blocks its publication.
#   3. repair  only when the check failed for reasons the cleaner can fix: Info.plist
#              (--fix-metadata) and, with --drop-encrypted, encrypted extensions and components.
#   4. check   the repaired copy, which must now pass.
#
# Sources are never modified. Copies that pass go to OUTPUT/ready/; OUTPUT/apps/<file>/ keeps the
# output of every step, OUTPUT/summary.tsv one row per app and OUTPUT/run.log the whole run. A
# rerun with the same --output skips the apps that passed and retries the others.
#
# Options:
#   -o, --output DIR   results folder (default: CATALOG_DIR-processed-<UTC time>, next to it)
#   --only FILE        process only this IPA of the catalog (repeatable)
#   --opt-in RULE      also remove the optional mods of this cleaner rule, e.g. ymnight-mod
#                      (repeatable)
#   --drop-encrypted   let the repair remove FairPlay-encrypted extensions and components; the
#                      features they provide (widgets, notification extensions…) are lost
#   --no-inspect       check with the cleaner's analysis only, on a host without the backend
#   --force            reprocess apps that already passed in OUTPUT
#   --dry-run          check the setup, list what would be processed, and stop
#   -h, --help         show this help
#
# Environment: PYTHON (python3), IPA_CLEANER (tools/ipa-cleaner/ipa_clean.py), BACKEND (backend/),
# PHP (php), APP_USER (runs artisan; when started as root, the owner of backend/artisan),
# STEP_TIMEOUT (seconds one step may take, 900; 0 = no limit), MIN_FREE_MB (space to leave on the
# output disk, 2048), NICE_LEVEL (CPU priority of the tools, 10).
#
# Exit status: 0 every app passed, 1 an app failed or the run stopped early, 2 bad usage or setup,
# 130 interrupted.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PYTHON="${PYTHON:-python3}"
CLEANER="${IPA_CLEANER:-$ROOT/tools/ipa-cleaner/ipa_clean.py}"
BACKEND="${BACKEND:-$ROOT/backend}"
PHP="${PHP:-php}"
APP_USER="${APP_USER:-}"
STEP_TIMEOUT="${STEP_TIMEOUT:-900}"
MIN_FREE_MB="${MIN_FREE_MB:-2048}"
NICE_LEVEL="${NICE_LEVEL:-10}"
# App names are often Cyrillic: the cleaner prints them as UTF-8 whatever the server's locale is.
export PYTHONUTF8=1

CATALOG="" OUT="" ARGS="$*" INSPECT=1 DROP_ENCRYPTED=0 FORCE=0 DRY_RUN=0 OPT_IN_TEXT=""
ONLY=() OPT_IN=() IPAS=() AS_APP_USER=() NICE=() FAILURES=()
N_OK=0 N_FIXED=0 N_FAILED=0 N_EARLIER=0
STARTED="" STOP_REASON="" EXIT_REASON="" FAILED_AT=""
LOG_FILE="" LOCK_DIR="" WORK_DIR="" STEP_PID="" WATCHDOG_PID=""
SOURCE="" CURRENT="" APP_DIR="" FINGERPRINT="" VERDICT="" SCAN="" CHECK_ERROR=""

if [[ -t 1 && -z ${NO_COLOR:-} ]]; then
  BOLD=$'\033[1m' DIM=$'\033[2m' RED=$'\033[31m' GREEN=$'\033[32m' YELLOW=$'\033[33m' CYAN=$'\033[36m' RESET=$'\033[0m'
else
  BOLD="" DIM="" RED="" GREEN="" YELLOW="" CYAN="" RESET=""
fi

# ---------------------------------------------------------------- Output

# Every console line also goes to run.log once it exists, with a time stamp.
log() { [[ -z $LOG_FILE ]] || printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >> "$LOG_FILE"; }
say() { printf '%s\n' "$*"; log "$*"; }
warn() { printf '%s\n' "${YELLOW}warning:${RESET} $*" >&2; log "warning: $*"; }
die() { printf '%s\n' "${RED}error:${RESET} $*" >&2; log "error: $*"; EXIT_REASON=setup; exit 2; }
usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"; }
usage_error() { printf 'process-ipa-catalog: %s\nSee --help.\n' "$*" >&2; EXIT_REASON=usage; exit 2; }
# step LABEL TEXT [COLOUR]: one line of the current app's progress.
step() { printf '    %s%-7s%s %s\n' "${3:-}" "$1" "${3:+$RESET}" "$2"; log "    $1 $2"; }
human_size() {
  awk -v n="$1" 'BEGIN { split("B KB MB GB TB", u, " "); i = 1; while (n >= 1024 && i < 5) { n /= 1024; i++ }
                         f = i == 1 ? "%d %s\n" : "%.1f %s\n"; printf f, n, u[i] }'
}
duration() {
  if (( $1 >= 3600 )); then printf '%dh %02dm' $(($1 / 3600)) $(($1 % 3600 / 60))
  elif (( $1 >= 60 )); then printf '%dm %02ds' $(($1 / 60)) $(($1 % 60))
  else printf '%ds' "$1"; fi
}

# ---------------------------------------------------------------- Reading the tools' reports

# helper COMMAND ARGS...: file facts and the JSON the tools print (in Python, which the cleaner
# needs anyway).
#   stat FILE        size, then a fingerprint of the file (size and modification time)
#   total FILE...    their total size
#   owner FILE       the owner's user name
#   error OUT ERR    why a step failed: the cleaner's refusal, or the last line the tool printed
#   clean OUT        sha256 of the copy the cleaner wrote, then what it did
#   verdict ANALYSIS INSPECTION DROP_ENCRYPTED ARGS_FILE PROBLEMS_FILE
#                    pass, repairable, blocked or error, then the malware scan status (or the
#                    error); writes the problems found and the cleaner arguments that repair them
# Python's own errors go to run.log once it exists.
helper() {
  if [[ -n $LOG_FILE ]]; then helper_python "$@" 2>>"$LOG_FILE"; else helper_python "$@"; fi
}

helper_python() {
  "$PYTHON" - "$@" <<'PY'
import json
import os
import posixpath
import sys


def load(path):
    """The JSON object a tool printed, skipping lines printed before it (PHP notices)."""
    try:
        with open(path, encoding="utf-8", errors="replace") as stream:
            text = stream.read()
    except OSError:
        return None
    decoder, at = json.JSONDecoder(), 0
    while at >= 0:
        if text.startswith("{", at):
            try:
                value = decoder.raw_decode(text, at)[0]
                if isinstance(value, dict):
                    return value
            except ValueError:
                pass
        at = text.find("\n", at)
        if at >= 0:
            at += 1
    return None


def last_line(*paths):
    for path in paths:
        try:
            with open(path, encoding="utf-8", errors="replace") as stream:
                lines = [line.strip() for line in stream if line.strip()]
        except OSError:
            continue
        if lines:
            return lines[-1]
    return "no output"


def one_line(text, limit=400):
    text = " ".join(str(text).split())
    return text if len(text) <= limit else text[:limit - 1] + "…"


def name(path):
    """A module by its file name (a framework's binary by the framework), an extension by its bundle."""
    folder, base = posixpath.split(path.rstrip("/"))
    return posixpath.basename(folder) if posixpath.basename(folder) == base + ".framework" else base


def listed(word, items):
    return word + ("s " if len(items) > 1 else " ") + ", ".join(items)


def describe(report):
    modules = report.get("removed_modules", [])
    removed = [name(module["path"]) for module in modules if not module.get("orphaned_dependency")]
    orphans = [name(module["path"]) for module in modules if module.get("orphaned_dependency")]
    parts = []
    if removed:
        parts.append("removed " + listed("module", removed))
    if report.get("removed_extensions"):
        parts.append("removed " + listed("extension", [name(path) for path in report["removed_extensions"]]))
    if orphans:
        parts.append("and what only they loaded: " + ", ".join(orphans))
    if report.get("patched_libraries"):
        parts.append("patched " + ", ".join(name(path) for path in report["patched_libraries"]))
    for fix in report.get("metadata_fixes", []):
        parts.append(f"Info.plist {fix['key']} {fix['from']} → {fix['to']}")
    if report.get("rar_converted_to_zip"):
        parts.append("converted from RAR to ZIP")
    return "; ".join(parts) or "nothing to remove"


def stat(path):
    info = os.stat(path)
    print(f"{info.st_size}\t{info.st_size}-{info.st_mtime_ns}")


def total(*paths):
    print(sum(os.stat(path).st_size for path in paths))


def owner(path):
    import pwd
    print(pwd.getpwuid(os.stat(path).st_uid).pw_name)


def error(out, err):
    refusal = (load(out) or {}).get("error")
    message = refusal.get("message") if isinstance(refusal, dict) else None
    print(one_line(message or last_line(err, out), 300))


def clean(out):
    report = load(out)
    if not report or "output_sha256" not in report:
        sys.exit("the cleaner printed no report")
    print(report["output_sha256"] + "\t" + one_line(describe(report)))


def verdict(analysis_path, inspection_path, drop, args_path, problems_path):
    analysis = load(analysis_path)
    if not analysis or "app" not in analysis:
        return print("error\tthe analysis printed no report: " + one_line(last_line(analysis_path[:-4] + ".err", analysis_path), 300))
    app_root, main = analysis["app"]["root"], analysis["app"]["executable"]
    problems, args, blocked = [], [], False

    def short(path):
        return path[len(app_root):] if path.startswith(app_root) else path

    issues = analysis.get("metadata_issues", [])
    for issue in issues:
        problems.append(f"Info.plist {issue['code']} ({issue['value']}, should be {issue['fix']})")
    if issues:
        args.append("--fix-metadata")

    # FairPlay-encrypted code cannot run once re-signed. The cleaner can drop an extension (with what
    # only it loads) and an encrypted component nothing uses; never the app's own executable.
    encrypted = set(analysis.get("encrypted_binaries", []))
    if main in encrypted:
        problems.append("the main executable is FairPlay-encrypted (an App Store copy); only a decrypted IPA can run")
        blocked = True
    roots = sorted((row["path"] for row in analysis.get("extensions", [])), key=len, reverse=True)

    def extension(path):
        return next((root for root in roots if path.startswith(root)), None)

    drop_extensions, drop_components = [], []
    for row in analysis.get("extensions", []):
        if row.get("encrypted"):
            problems.append(f"encrypted extension {short(row['path'])} ({row.get('point') or 'unknown type'})")
            drop_extensions.append(row["path"])
    modules = {row["path"]: row for row in analysis.get("modules", [])}
    for path in sorted(encrypted - {main}):
        if extension(path) in drop_extensions:
            continue
        module = modules.get(path, {})
        loaders = module.get("loaded_by") or []
        users = {extension(loader) for loader in loaders}
        if loaders and None not in users:
            # Only extensions load it (OK's LibverifyExt): it goes when they go.
            problems.append(f"encrypted component {short(path)}, loaded only by " + ", ".join(name(user) for user in sorted(users)))
            drop_extensions += [user for user in sorted(users) if user not in drop_extensions]
        elif module.get("removable"):
            problems.append(f"encrypted component {short(path)}")
            drop_components.append(path)
        else:
            reason = module.get("blocked_reason") or "it is part of the app"
            problems.append(f"encrypted component {short(path)} cannot be removed: {reason}")
            blocked = True
    for root in drop_extensions:
        args += ["--remove-extension", root]
    for path in drop_components:
        args += ["--remove", path]
    needs_drop = drop != "1" and bool(drop_extensions or drop_components)

    scan = ""
    if inspection_path:
        inspection = load(inspection_path)
        if not inspection or "outcome" not in inspection:
            return print("error\tthe inspection printed no report: " + one_line(last_line(inspection_path[:-4] + ".err", inspection_path), 300))
        scan = (inspection.get("malware_scan") or {}).get("status") or ""
        failure = inspection.get("failure") or {}
        code = inspection.get("failure_code") or failure.get("code")
        if inspection["outcome"] != "PROVENANCE_REVIEW":
            keys = set((failure.get("details") or {}).get("keys") or [])
            # Failures the analysis above accounts for.
            covered = (code == "ENCRYPTED_BINARY" and encrypted) or (
                code == "INVALID_METADATA" and keys == {"CFBundleIdentifier"}
                and any(issue["code"] == "BUNDLE_ID_TRAILING_DOT" for issue in issues))
            if not covered:
                details = failure.get("details")
                problems.append(f"inspection {inspection['outcome']} {code}: {failure.get('message', '')}"
                                + (" " + json.dumps(details, ensure_ascii=False) if details else ""))
                blocked = True
        # Findings that block publication (watch apps, App Clips, simulator builds…).
        for issue in inspection.get("compatibility_issues") or []:
            problems.append(f"{issue.get('code')}: {issue.get('message')}" + (f" ({short(issue['path'])})" if issue.get("path") else ""))
            blocked = True

    if needs_drop:
        problems.append("rerun with --drop-encrypted to remove the encrypted parts (their features are lost)")
    state = "pass" if not problems else "blocked" if blocked or needs_drop else "repairable"
    with open(problems_path, "w", encoding="utf-8") as stream:
        stream.writelines(one_line(problem) + "\n" for problem in problems)
    with open(args_path, "wb") as stream:
        stream.write(b"".join(os.fsencode(arg) + b"\0" for arg in args))
    print(f"{state}\t{scan}")


commands = {"stat": stat, "total": total, "owner": owner, "error": error, "clean": clean, "verdict": verdict}
commands[sys.argv[1]](*sys.argv[2:])
PY
}

# ---------------------------------------------------------------- Running a step

# run_step NAME DIR COMMAND...: runs one tool from DIR at low priority, without input; its output
# goes to NAME.out and NAME.err in the app's folder. Returns the tool's exit status, or 124 when it
# ran longer than STEP_TIMEOUT. The tool runs in the background so that Ctrl-C stops it at once.
run_step() {
  local name=$1 dir=$2 rc=0
  shift 2
  rm -f -- "$APP_DIR/$name.timed-out"
  ( cd -- "$dir" && exec ${NICE[@]+"${NICE[@]}"} "$@" ) >"$APP_DIR/$name.out" 2>"$APP_DIR/$name.err" </dev/null &
  STEP_PID=$!
  if (( STEP_TIMEOUT > 0 )); then
    watchdog "$STEP_PID" "$APP_DIR/$name.timed-out" &
    WATCHDOG_PID=$!
  fi
  wait "$STEP_PID" || rc=$?
  STEP_PID=""
  stop_watchdog
  if [[ -e $APP_DIR/$name.timed-out ]]; then rc=124; fi
  return "$rc"
}

# watchdog PID MARKER: ends step PID once it has run STEP_TIMEOUT seconds (TERM, KILL 30 s later).
watchdog() {
  local waited=0
  while kill -0 "$1" 2>/dev/null; do
    if (( waited == STEP_TIMEOUT )); then : > "$2"; kill -TERM "$1" 2>/dev/null || true; fi
    if (( waited == STEP_TIMEOUT + 30 )); then kill -KILL "$1" 2>/dev/null || true; fi
    sleep 1
    waited=$((waited + 1))
  done
}

stop_watchdog() {
  if [[ -n $WATCHDOG_PID ]]; then
    kill "$WATCHDOG_PID" 2>/dev/null || true
    wait "$WATCHDOG_PID" 2>/dev/null || true
    WATCHDOG_PID=""
  fi
}

# stop_step: ends the running step when the run stops (TERM, KILL after 10 s).
stop_step() {
  local waited=0
  if [[ -n $STEP_PID ]]; then
    kill -TERM "$STEP_PID" 2>/dev/null || true
    while kill -0 "$STEP_PID" 2>/dev/null && (( waited < 10 )); do sleep 1; waited=$((waited + 1)); done
    kill -KILL "$STEP_PID" 2>/dev/null || true
    wait "$STEP_PID" 2>/dev/null || true
    STEP_PID=""
  fi
  stop_watchdog
}

# failure NAME STATUS: why step NAME failed.
failure() {
  local message
  message=$(helper error "$APP_DIR/$1.out" "$APP_DIR/$1.err")
  if (( $2 == 124 )); then
    printf 'timed out after %ss' "$STEP_TIMEOUT"
  elif (( $2 == 2 )) && [[ $1 != *inspect ]]; then
    printf 'refused: %s' "$message"
  else
    printf 'failed (exit %s): %s' "$2" "$message"
  fi
}

# ---------------------------------------------------------------- One app

# check N IPA: the cleaner's analysis and the upload inspection of IPA. Sets VERDICT (pass,
# repairable or blocked) and SCAN; N-problems.txt says what is wrong, N-repair.args how the cleaner
# repairs it. Returns 1, with CHECK_ERROR, when a check could not run.
check() {
  local n=$1 ipa=$2 rc=0 inspection="" line
  run_step "$n-analyze" "$ROOT" "$PYTHON" "$CLEANER" analyze "$ipa" || rc=$?
  if (( rc )); then CHECK_ERROR="analysis $(failure "$n-analyze" "$rc")"; return 1; fi
  if (( INSPECT )); then
    run_step "$n-inspect" "$BACKEND" ${AS_APP_USER[@]+"${AS_APP_USER[@]}"} "$PHP" artisan ipa:inspect "$ipa" || rc=$?
    # Exit status 1 is also how ipa:inspect reports an IPA that fails; its report says why.
    if (( rc > 1 )); then CHECK_ERROR="inspection $(failure "$n-inspect" "$rc")"; return 1; fi
    inspection="$APP_DIR/$n-inspect.out"
  fi
  line=$(helper verdict "$APP_DIR/$n-analyze.out" "$inspection" "$DROP_ENCRYPTED" "$APP_DIR/$n-repair.args" "$APP_DIR/$n-problems.txt") \
    || { CHECK_ERROR="its reports could not be read"; return 1; }
  VERDICT=${line%%$'\t'*} SCAN=${line#*$'\t'}
  if [[ $VERDICT == error ]]; then CHECK_ERROR=$SCAN; return 1; fi
}

problems() {
  local line text=""
  while IFS= read -r line; do text+="${text:+; }$line"; done < "$APP_DIR/$1-problems.txt"
  printf '%s' "$text"
}

scan_note() { if [[ -n $SCAN ]]; then printf ' (malware scan: %s)' "$SCAN"; fi; }

# passed_before: the current app passed in an earlier run into this OUT, from the same file.
passed_before() {
  local status fingerprint
  [[ -f $APP_DIR/result && -f $OUT/ready/$CURRENT ]] || return 1
  IFS=$'\t' read -r status fingerprint _ < "$APP_DIR/result" || return 1
  [[ ($status == OK || $status == FIXED) && $fingerprint == "$FINGERPRINT" ]]
}

# finish STATUS DETAIL [IPA SHA256]: records the app's outcome; a copy that passed goes to ready/.
finish() {
  local status=$1 detail=$2 ipa=${3:-} sha=${4:--} colour=$RED line=""
  # What goes to ready/ must come from the bytes that were checked.
  if [[ -n $ipa ]]; then
    line=$(helper stat "$SOURCE") || line=""
    if [[ ${line#*$'\t'} != "$FINGERPRINT" ]]; then
      status=FAILED detail="the source file changed while it was processed" ipa="" sha=-
    fi
  fi
  if [[ -n $ipa ]]; then mv -f -- "$ipa" "$OUT/ready/$CURRENT"; else rm -f -- "$OUT/ready/$CURRENT"; fi
  rm -f -- "$WORK_DIR/cleaned.ipa" "$WORK_DIR/repaired.ipa"
  detail=${detail//$'\t'/ }
  detail=${detail//$'\n'/ }
  printf '%s\t%s\t%s\t%s\n' "$status" "$FINGERPRINT" "$sha" "$detail" > "$APP_DIR/result"
  case $status in
    OK) N_OK=$((N_OK + 1)) colour=$GREEN ;;
    FIXED) N_FIXED=$((N_FIXED + 1)) colour=$CYAN ;;
    *) N_FAILED=$((N_FAILED + 1)); FAILURES+=("$CURRENT: $detail") ;;
  esac
  if [[ -n $ipa ]]; then step "$status" "ready/$CURRENT" "$colour"; else step "$status" "$detail" "$colour"; fi
}

# process_app FILE INDEX: cleans, checks and, when needed, repairs one IPA. A failing app never
# stops the run; a full disk does (STOP_REASON).
process_app() {
  SOURCE=$1 CURRENT=${1##*/} APP_DIR="$OUT/apps/${1##*/}"
  local line size=0 free_kb need_kb rc=0 clean_sha clean_note repair_note arg
  local -a repair=()
  printf '%s[%d/%d] %s%s\n' "$BOLD" "$2" "${#IPAS[@]}" "$CURRENT" "$RESET"
  log "[$2/${#IPAS[@]}] $CURRENT"
  FINGERPRINT=-
  if line=$(helper stat "$SOURCE"); then size=${line%%$'\t'*} FINGERPRINT=${line#*$'\t'}; fi
  if (( ! FORCE )) && passed_before; then
    N_EARLIER=$((N_EARLIER + 1))
    step skip "passed in an earlier run: ready/$CURRENT" "$DIM"
    return 0
  fi
  # Room for the cleaned and the repaired copy, with MIN_FREE_MB left over for the server.
  free_kb=$(df -Pk -- "$OUT" | awk 'NR == 2 { print $4 }') || free_kb=""
  need_kb=$((size * 2 / 1024 + MIN_FREE_MB * 1024))
  if [[ $free_kb =~ ^[0-9]+$ ]] && (( free_kb < need_kb )); then
    STOP_REASON="only $(human_size $((free_kb * 1024))) free for $OUT, and $CURRENT needs $(human_size $((need_kb * 1024))) with the MIN_FREE_MB reserve"
    step stop "$STOP_REASON" "$RED"
    return 0
  fi
  rm -rf -- "$APP_DIR"
  mkdir -p -- "$APP_DIR"
  if [[ $FINGERPRINT == - || ! -r $SOURCE ]]; then finish FAILED "the file cannot be read"; return 0; fi

  # 1. Clean: the reviewed promotions and library patches. Info.plist is left to the check.
  run_step 1-clean "$ROOT" "$PYTHON" "$CLEANER" clean "$SOURCE" "$WORK_DIR/cleaned.ipa" \
    --recommended --keep-metadata ${OPT_IN[@]+"${OPT_IN[@]}"} || rc=$?
  if (( rc )); then finish FAILED "clean $(failure 1-clean "$rc")"; return 0; fi
  if ! line=$(helper clean "$APP_DIR/1-clean.out"); then finish FAILED "the cleaner printed no report"; return 0; fi
  clean_sha=${line%%$'\t'*} clean_note=${line#*$'\t'}
  step clean "$clean_note"

  # 2. Check.
  if ! check 2 "$WORK_DIR/cleaned.ipa"; then finish FAILED "check: $CHECK_ERROR"; return 0; fi
  if [[ $VERDICT == pass ]]; then
    step check "passed$(scan_note)" "$GREEN"
    finish OK "$clean_note" "$WORK_DIR/cleaned.ipa" "$clean_sha"
    return 0
  fi
  if [[ $VERDICT != repairable ]]; then
    step check failed "$YELLOW"
    finish FAILED "$(problems 2)"
    return 0
  fi
  step check "needs repair: $(problems 2)" "$YELLOW"

  # 3. Repair what the check found.
  while IFS= read -r -d '' arg; do repair+=("$arg"); done < "$APP_DIR/2-repair.args"
  run_step 3-repair "$ROOT" "$PYTHON" "$CLEANER" clean "$WORK_DIR/cleaned.ipa" "$WORK_DIR/repaired.ipa" \
    ${repair[@]+"${repair[@]}"} || rc=$?
  if (( rc )); then finish FAILED "repair $(failure 3-repair "$rc")"; return 0; fi
  if ! line=$(helper clean "$APP_DIR/3-repair.out"); then finish FAILED "the repair printed no report"; return 0; fi
  repair_note=${line#*$'\t'}
  step repair "$repair_note" "$CYAN"

  # 4. Check the repaired copy.
  if ! check 4 "$WORK_DIR/repaired.ipa"; then finish FAILED "check after the repair: $CHECK_ERROR"; return 0; fi
  if [[ $VERDICT != pass ]]; then
    step check failed "$YELLOW"
    finish FAILED "still failing after the repair: $(problems 4)"
    return 0
  fi
  step check "passed$(scan_note)" "$GREEN"
  finish FIXED "$clean_note; repair: $repair_note" "$WORK_DIR/repaired.ipa" "${line%%$'\t'*}"
}

# ---------------------------------------------------------------- Setup

parse_args() {
  while (( $# )); do
    case $1 in
      -o|--output) (( $# > 1 )) || usage_error "$1 needs a folder"; OUT=$2; shift ;;
      --output=*) OUT=${1#*=} ;;
      --only) (( $# > 1 )) || usage_error "--only needs an IPA file name"; ONLY+=("$2"); shift ;;
      --opt-in) (( $# > 1 )) || usage_error "--opt-in needs a rule id"; OPT_IN+=(--opt-in "$2"); OPT_IN_TEXT+=" --opt-in $2"; shift ;;
      --drop-encrypted) DROP_ENCRYPTED=1 ;;
      --no-inspect) INSPECT=0 ;;
      --force) FORCE=1 ;;
      --dry-run) DRY_RUN=1 ;;
      -h|--help) usage; exit 0 ;;
      --) shift; break ;;
      -*) usage_error "unknown option $1" ;;
      *) [[ -z $CATALOG ]] || usage_error "pass one catalog folder, not '$CATALOG' and '$1'"; CATALOG=$1 ;;
    esac
    shift
  done
  if (( $# )); then
    [[ -z $CATALOG && $# == 1 ]] || usage_error "pass one catalog folder"
    CATALOG=$1
  fi
  [[ -n $CATALOG ]] || usage_error "pass the catalog folder"
}

preflight() {
  local name
  [[ -d $CATALOG ]] || die "not a folder: $CATALOG"
  CATALOG=$(cd -- "$CATALOG" && pwd -P)
  for name in STEP_TIMEOUT MIN_FREE_MB NICE_LEVEL; do
    [[ ${!name} =~ ^[0-9]+$ ]] || die "$name must be a whole number, not '${!name}'"
  done
  PYTHON=$(command -v "$PYTHON") || die "Python not found (set PYTHON)"
  "$PYTHON" -c 'import sys; sys.exit(sys.version_info < (3, 9))' || die "the cleaner needs Python 3.9 or later, and $PYTHON is older (set PYTHON)"
  [[ -f $CLEANER && -r $CLEANER ]] || die "cleaner not found: $CLEANER (set IPA_CLEANER)"
  if (( INSPECT )); then
    [[ -f $BACKEND/artisan ]] || die "no backend in $BACKEND: set BACKEND, or check with the cleaner only (--no-inspect)"
    PHP=$(command -v "$PHP") || die "PHP not found: set PHP, or check with the cleaner only (--no-inspect)"
    # Started as root, artisan runs as the app's user, so a log file it creates stays writable for the app.
    if [[ -z $APP_USER && $(id -u) == 0 ]]; then APP_USER=$(helper owner "$BACKEND/artisan"); fi
    if [[ -n $APP_USER && $APP_USER != "$(id -un)" ]]; then
      command -v sudo >/dev/null || die "sudo is needed to run artisan as $APP_USER"
      AS_APP_USER=(sudo -n -u "$APP_USER" --)
    fi
    ( cd -- "$BACKEND" && ${AS_APP_USER[@]+"${AS_APP_USER[@]}"} "$PHP" artisan help ipa:inspect ) >/dev/null 2>&1 \
      || die "'artisan ipa:inspect' does not run in $BACKEND${APP_USER:+ as $APP_USER} (or check with the cleaner only: --no-inspect)"
  fi
  if nice -n "$NICE_LEVEL" true 2>/dev/null; then NICE=(nice -n "$NICE_LEVEL"); fi
  if command -v ionice >/dev/null && ionice -c 2 -n 7 true 2>/dev/null; then NICE=(ionice -c 2 -n 7 ${NICE[@]+"${NICE[@]}"}); fi
}

# find_ipas: the *.ipa files directly in CATALOG (any letter case), narrowed by --only.
find_ipas() {
  local file wanted found
  local -a selected=()
  shopt -s nocaseglob
  for file in "$CATALOG"/*.ipa; do
    if [[ -f $file ]]; then IPAS+=("$file"); fi
  done
  shopt -u nocaseglob
  if (( ${#ONLY[@]} )); then
    for wanted in "${ONLY[@]}"; do
      found=""
      for file in ${IPAS[@]+"${IPAS[@]}"}; do
        if [[ ${file##*/} == "${wanted##*/}" ]]; then found=$file; fi
      done
      [[ -n $found ]] || die "--only ${wanted##*/}: no such IPA in $CATALOG"
      selected+=("$found")
    done
    IPAS=("${selected[@]}")
  fi
  (( ${#IPAS[@]} )) || die "no .ipa files in $CATALOG"
}

# prepare_output: creates and locks OUT; a second run into the same OUT stops here.
prepare_output() {
  local pid
  mkdir -p -- "$OUT/ready" "$OUT/apps" || die "cannot create $OUT (choose another --output)"
  OUT=$(cd -- "$OUT" && pwd -P)
  if ! mkdir -- "$OUT/.lock" 2>/dev/null; then
    pid=$(cat -- "$OUT/.lock/pid" 2>/dev/null || true)
    if [[ -z $pid ]]; then sleep 1; pid=$(cat -- "$OUT/.lock/pid" 2>/dev/null || true); fi
    # kill -0 fails for another user's process too, and minimal hosts have no ps.
    if [[ -n $pid ]] && { kill -0 "$pid" 2>/dev/null || [[ -d /proc/$pid ]] || ps -p "$pid" >/dev/null 2>&1; }; then
      die "another run (PID $pid) is writing to $OUT; if no such run exists, delete $OUT/.lock"
    fi
    warn "taking over $OUT from an earlier run that did not finish${pid:+ (PID $pid)}"
    rm -rf -- "$OUT/.lock"
    mkdir -- "$OUT/.lock" || die "cannot lock $OUT"
  fi
  LOCK_DIR="$OUT/.lock"
  echo "$$" > "$LOCK_DIR/pid"
  LOG_FILE="$OUT/run.log"
  WORK_DIR="$OUT/.work"
  rm -rf -- "$WORK_DIR"
  mkdir -- "$WORK_DIR"
  if (( ${#AS_APP_USER[@]} )); then
    : > "$WORK_DIR/probe"
    "${AS_APP_USER[@]}" test -r "$WORK_DIR/probe" 2>/dev/null \
      || die "$APP_USER cannot read files in $OUT: run the script as $APP_USER, or choose an --output it can read"
    rm -f -- "$WORK_DIR/probe"
  fi
}

print_header() {
  local check="ipa_clean.py analyze + artisan ipa:inspect${APP_USER:+ (as $APP_USER)}"
  local encrypted="kept, so such an app fails (--drop-encrypted removes them)" count="${#IPAS[@]} IPAs"
  if (( ! INSPECT )); then check="ipa_clean.py analyze only (--no-inspect)"; fi
  if (( DROP_ENCRYPTED )); then encrypted="removed (--drop-encrypted)"; fi
  if (( ${#IPAS[@]} == 1 )); then count="1 IPA"; fi
  log "started by $(id -un) on $(uname -n): $0 $ARGS"
  say "Catalog  $CATALOG ($count, $(human_size "$(helper total "${IPAS[@]}")"))"
  say "Output   $OUT"
  say "Clean    ipa_clean.py clean --recommended --keep-metadata$OPT_IN_TEXT"
  say "Check    $check"
  say "Repair   Info.plist fixes; encrypted extensions and components are $encrypted"
  say ""
}

dry_run() {
  local file line size status
  say "Catalog  $CATALOG"
  say "Output   $OUT"
  for file in "${IPAS[@]}"; do
    CURRENT=${file##*/} APP_DIR="$OUT/apps/${file##*/}" FINGERPRINT=- size=0 status=process
    if line=$(helper stat "$file"); then size=${line%%$'\t'*} FINGERPRINT=${line#*$'\t'}; fi
    if (( ! FORCE )) && passed_before; then status="skip: passed earlier"; fi
    printf '  %-20s %9s  %s\n' "$status" "$(human_size "$size")" "$CURRENT"
  done
}

# ---------------------------------------------------------------- End of the run

# write_summary: summary.tsv from the result of every app in OUT, earlier runs included.
write_summary() {
  local result name status sha detail output
  {
    printf 'file\tstatus\toutput\tsha256\tdetail\n'
    for result in "$OUT"/apps/*/result; do
      name=${result%/result} name=${name##*/} output=-
      IFS=$'\t' read -r status _ sha detail < "$result" || continue
      if [[ $status == OK || $status == FIXED ]]; then output="ready/$name"; fi
      printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$status" "$output" "$sha" "$detail"
    done
  } > "$OUT/summary.tsv.tmp" && mv -f -- "$OUT/summary.tsv.tmp" "$OUT/summary.tsv"
}

print_summary() {
  local item rest=$((${#IPAS[@]} - N_OK - N_FIXED - N_FAILED - N_EARLIER))
  local counts="$N_OK OK, $N_FIXED fixed, $N_FAILED failed"
  if (( N_EARLIER )); then counts+=", $N_EARLIER passed in an earlier run"; fi
  say ""
  say "$counts ($(duration $((SECONDS - STARTED))))"
  if (( rest > 0 )); then say "Not processed: $rest (${STOP_REASON:-the run was interrupted})"; fi
  if (( N_FAILED )); then
    say "Failed:"
    for item in "${FAILURES[@]}"; do say "  $item"; done
  fi
  say "Ready copies  $OUT/ready"
  say "Summary       $OUT/summary.tsv"
  say "Log           $OUT/run.log"
}

on_signal() {
  EXIT_REASON=interrupted
  printf '\n' >&2
  warn "interrupted (SIG$1)"
  exit "$2"
}

on_exit() {
  local status=$?
  set +e
  trap '' INT TERM HUP
  stop_step
  if [[ -n $LOCK_DIR ]]; then
    rm -rf -- "$WORK_DIR"
    if (( status != 0 )) && [[ -z $EXIT_REASON ]]; then
      printf '%s\n' "${RED}error:${RESET} stopped by an unexpected error (${FAILED_AT:-exit status $status})" >&2
      log "error: stopped by an unexpected error (${FAILED_AT:-exit status $status})"
    fi
    write_summary
    if [[ -n $STARTED ]]; then print_summary; fi
    rm -rf -- "$LOCK_DIR"
  fi
  exit "$status"
}

main() {
  local index=0 file
  parse_args "$@"
  preflight
  find_ipas
  OUT=${OUT:-"$CATALOG-processed-$(date -u +%Y%m%dT%H%M%SZ)"}
  if (( DRY_RUN )); then
    dry_run
    EXIT_REASON=finished
    exit 0
  fi
  prepare_output
  print_header
  STARTED=$SECONDS
  for file in "${IPAS[@]}"; do
    index=$((index + 1))
    process_app "$file" "$index"
    if [[ -n $STOP_REASON ]]; then break; fi
  done
  EXIT_REASON=finished
  if (( N_FAILED )) || [[ -n $STOP_REASON ]]; then exit 1; fi
  exit 0
}

shopt -s nullglob
trap 'FAILED_AT="line $LINENO: $BASH_COMMAND"' ERR
trap on_exit EXIT
trap 'on_signal INT 130' INT
trap 'on_signal TERM 143' TERM
trap 'on_signal HUP 129' HUP
main "$@"
