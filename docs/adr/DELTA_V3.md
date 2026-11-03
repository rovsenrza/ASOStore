# Delta v3: shared core + team patch (pack format `delta-v2`)

Status: accepted 2026-11-03. Extends [DELTA_BUILDS.md](DELTA_BUILDS.md) (`delta-v1`), which stays valid.

## Why

`delta-v1` stores every changed entry whole, so for most apps (the re-signed Mach-O binaries are most of
the app) a team build is as big as a full build. Measured on 31 production apps: two teams' builds of one
artifact differ only in, per Mach-O, **each slice's first 4 KiB page and its code-signature region**, plus a
few small files (profiles, Info.plist, CodeResources, injected dylibs) — 0.1–6 % of the build, median 1.3 %.
Prototype `research/delta-v3/proto.py` composed 15 apps byte-identical per entry, one installed on a device.

So the bytes of a re-signed Mach-O outside those ranges (its **core ranges**) are stored once per artifact in
a **core**, and a team build stores only its **team ranges** plus whole small entries.

## Terms

* **Variable ranges** of a Mach-O file (thin `MH_MAGIC_64` or fat `FAT_MAGIC`, big-endian fat header): for each
  slice at offset `o`: `[o, min(o+4096, len))` and `[o+dataoff, o+dataoff+datasize)` from its
  `LC_CODE_SIGNATURE` (0x1D). Sorted, overlapping/adjacent ranges merged. A slice without
  `LC_CODE_SIGNATURE`, or any other Mach-O magic, means the file has **no** variable ranges (entry kind 1).
* **Segments** of a file of length L: its variable ranges (`team`) and the gaps between/around them (`core`),
  in file order, covering `[0, L)` exactly; no empty segment.
* **Chunk**: the raw DEFLATE encoding (zlib `deflateInit2(level 6, Z_DEFLATED, windowBits -15, memLevel 8,
  Z_DEFAULT_STRATEGY)`, a **fresh** stream per chunk) of one segment's bytes. The entry's **last** segment is
  finished with `Z_FINISH` (final block); every other segment with `Z_FULL_FLUSH` (byte-aligned, not final).
  Concatenating an entry's chunks in order is one valid raw DEFLATE stream of the whole file.

## Core (one per artifact, shared by all teams)

Stored on the `signed` disk at `cores/<sha256>.bin`; a DB row `delta_cores` (`artifact_id`, `sha256` of the
blob, `size_bytes`, `manifest` JSON, `storage_path`, timestamps). Several cores per artifact may exist
(race between two first builds); builds reference one by sha256.

* **Blob**: concatenation of core chunks, in any order.
* **Manifest** (JSON):

```json
{"version":1,"artifact_sha256":"<source IPA sha256>",
 "entries":{"Payload/A.app/A":[[4096,9216000,"<sha256 hex of uncompressed bytes [4096,9216000)>",0,2711004,false],
                               [9300000,9400000,"<hex>",2711004,30111,true]]}}
```

Each item: `[start, end, sha256_hex_of_uncompressed_bytes, blob_offset, blob_length, final]` — one core segment
of that entry; `final` true when it is the entry's last segment (compressed with `Z_FINISH`).

## Pack `delta-v2`

Same ZIP rules as `delta-v1` (no zip64, ≤ 65 534 entries, < 4 GiB). Contains:

* whole entries flagged `1` (as in v1; the runner writes them **deflated**, method 8, no data descriptor);
* `Payload/.delta-team.bin` — **stored** (method 0): concatenation of team chunks;
* `Payload/.delta-manifest.json` — stored, last entry:

```json
{"version":2,"base_sha256":"<source sha256>","core_sha256":"<core blob sha256 or null>","made_by":0,"external":0,
 "entries":[["Payload/A.app/x.png",0],["Payload/A.app/Info.plist",1],
            ["Payload/A.app/A",2,{"crc":3735928559,"size":9400000,"segments":[["t",0,1543],["c",0,2711004],["t",1543,40123],["c",2711004,30111]]}]]}
```

Kind `2` = **segmented** entry: final entry method 8, CRC-32 `crc` and uncompressed `size` of the whole file;
compressed size = sum of segment lengths; `segments` in order, `"t"` = `[offset,length]` inside the data of
`Payload/.delta-team.bin`, `"c"` = `[offset,length]` inside the core blob `core_sha256`. `core_sha256` must be
non-null when any `"c"` segment exists. Neither `.delta-team.bin` nor the manifest is part of the final IPA.

## Runner

When the lease has `delta.enabled` and `delta.version == 2` (sent only when the backend's v2 switch is on):

1. Sign the unpacked tree in place exactly as for `delta-v1` tree packs (`Signer.signDeltaDetached`).
2. Classify every final file as in v1 (kind 0 if identical to the source entry, mandatory entries kind 1).
3. For a changed Mach-O with variable ranges: compute its segments. If the lease's `delta.core.manifest` has
   the entry with **exactly** the same core segment boundaries and every core segment's sha256 matches the
   tree's bytes → kind 2 referencing the core's chunks (`"c"` offsets from the manifest) and new team chunks.
   If the lease has **no** core (`delta.core == null`): build the core from this tree (all core segments of
   all such entries), upload it first (`PUT core_path`, header `X-Core-Sha256`), then reference it.
   Otherwise (layout or hash differs) → kind 1 (whole, deflated).
4. Other changed files → kind 1, deflated.
5. Upload the pack with `X-Build-Format: delta-v2`; result `format: "delta-v2"`. Any failure falls back to the
   v1 tree pack, then to the full IPA (never fails the job because of delta).

Delta v2 is **always** worth it once a core exists: no "pack < half" rule for kind-2 entries; keep the rule only
for the whole pack vs. the tree when the pack ends up with no kind-2 entry.

## Backend

* `signed_builds.format` gains `delta-v2`; `signed_builds.delta_core_id` (nullable FK) records the core used.
* Lease: `delta: {enabled, version: 2, core: null | {sha256, manifest}, core_path}` (core_path = worker
  endpoint `PUT /api/worker/v1/jobs/{job}/core`). The core offered is the newest `delta_cores` row of the artifact.
* Core upload: validates the header sha256 against the body, size ≤ the source IPA size, manifest JSON (sent
  as header-less multipart or a second request — implementer's choice, documented in code), stores
  `cores/<sha>.bin` once (idempotent by sha), creates the row.
  Runner wire format (`WorkerClient.uploadCore`): request 1 is `PUT {core_path}`, body = blob, signed as
  usual (`X-Content-SHA256` = blob sha256), plus `X-Core-Sha256: <hex>` and, when the manifest JSON in
  base64url without padding is shorter than 64 KiB, `X-Core-Manifest: <base64url>`. Otherwise (no
  `X-Core-Manifest`) request 2 follows: `PUT {core_path}?part=manifest`, body = manifest JSON,
  `X-Content-SHA256` = its sha256, `X-Core-Sha256` again; the signed path excludes the query. The body is
  not streamed by `VerifyWorkerSignature` unless `/core` is treated like `/artifact`.
* Verifier (`SignatureVerifier::checkDelta`): for v2 also checks the core exists, every `"c"`/`"t"` range is
  inside its blob, and **streams every kind-2 entry through inflate**: decompressed length == `size` and
  CRC-32 == `crc`. ClamAV is skipped as for v1.
* Composition (`DeltaComposer`/`ComposedPlan`): a kind-2 entry is a local header (method 8, flags 0 or 0x800
  for non-ASCII names, crc/sizes from the manifest, no descriptor, name, no extra) followed by its segments
  read from the team blob (pack file at the stored entry's data offset + t offset) and the core blob (local file,
  fetched once from the `signed` disk into `ArtifactFileCache`); its central record is generated likewise with
  the manifest's record pair. Size known up front → `Content-Length`/`Range` unchanged.
* Janitor: a core is deleted when no live (non-purged) build references it and the artifact is not published.
* Rollout switch `storefront.signing.delta_builds.v2` (env `STOREFRONT_SIGNING_DELTA_V2`, default false). With v2
  on, every shared build of every catalog app is offered delta v2 (the size threshold no longer applies).

## Migration

`signing:migrate-delta-v2 {--apps=all|N} {--dry-run}`: for each published catalog app (least installed first),
queue a v2 team build for every active team's newest eligible device (speculative priority, budget-exempt);
when a v2 build for (artifact, team) becomes DELIVERABLE, the janitor ends the team's older **full / delta-v1**
builds of that artifact whose profile devices are a subset of the new build's (not while in use).
