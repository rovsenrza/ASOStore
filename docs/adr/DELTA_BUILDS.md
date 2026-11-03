# Delta builds (pack format `delta-v1`)

A signed build used to be a full re-zipped copy of the IPA for every Apple team. A 2.7 GB game
changes only ~55 MB when it is re-signed (the Mach-O binaries, `embedded.mobileprovision`,
`Info.plist`, `_CodeSignature/CodeResources`), so a **delta build** stores only those entries (the
*pack*). The delivered IPA is composed on the fly from the artifact's original IPA (the *base*) and
the pack. Full builds stay as the fallback for everything this document does not cover.

## Pack

A pack is an ordinary ZIP file (no zip64, at most 65 535 entries, under 4 GiB) holding:

* every **changed or new** entry of the signed IPA, copied *raw* from the runner's `signed.ipa`
  (local header + compressed bytes + data descriptor, never recompressed), under its own name;
* one last entry `Payload/.delta-manifest.json` (stored, method 0; the name sits under `Payload/` so the
  ordinary IPA inspector accepts a pack as it is):

```json
{"version":1,"base_sha256":"<sha256 of the source IPA>","made_by":0,"external":0,"entries":[["Payload/A.app/Info.plist",1],["Payload/A.app/data/x.rpf",0]]}
```

`entries` is the **final IPA's entry list in final order**; the second value says where the bytes
come from: `0` = the base IPA, `1` = the pack. Every name flagged `1` is present in the pack; the
manifest entry itself is never part of the final IPA.

### Which entries come from the base (`0`)

An entry of the signed IPA is taken from the base only when **all** hold:

1. the source IPA has an entry with the same name (names compared byte for byte; duplicates in
   either archive disable delta);
2. same CRC-32 and same uncompressed size, and its central record in the signed IPA carries the archive's
   *record pair* `(made_by, external)` (below);
3. the name is pure ASCII (UTF-8 flags would otherwise differ, `ZipNames.markUTF8`);
4. the source entry uses method 0 or 8, is not encrypted (bit 0) and carries no zip64 extra field;
5. the name is **not** in the mandatory set below.

Everything else is `1`. **Mandatory pack entries** (always `1`, so a pack can be inspected alone):
any entry whose path inside `Payload/` ends in `/Info.plist`, `/embedded.mobileprovision` or
`/_CodeSignature/CodeResources`, at any depth.

### The record pair

zsign writes every entry with `version made by` 0 and external attributes 0, while the source IPA carries Unix
modes (and symlink bits) and other flags. The final IPA must look as zsign's output did, so the manifest
declares the pair `made_by` (the 16-bit *version made by* field) and `external` (the 32-bit external
attributes) taken from the **first non-directory entry of the signed IPA**; an otherwise identical entry
whose own pair differs from it goes to the pack. On composition the server writes this pair into the central
record of every entry it takes from the base. Entries from the pack keep their own record. (The local
headers, flags and data descriptors of base entries stay as the source has them: flag bits do not change
how an installer reads an entry.)

### When delta is not used (runner uploads a full IPA as before)

The lease has no `delta.enabled`; the source or the signed IPA needs zip64 (any EOCD/CD field at
its 16/32-bit maximum, or a zip64 locator), has more than 65 535 entries, duplicate names, or a
size of 4 GiB or more; the pack would not be smaller than half of the signed IPA; any parsing step
fails. A delta attempt never fails the job: it falls back to the full upload.

## Wire

Upload (`PUT upload_path`): body is the pack, header `X-Build-Format: delta-v1`; the request hash
header stays the sha256 of the **uploaded file** (the pack). Result (`POST result_path`) adds
`"format": "delta-v1"`; its `sha256` is the pack's. Without the header the body is a full IPA.

## Composition (server)

For manifest order `e_1 … e_n`:

* a **raw extent** of an archive entry is `[local header offset, next local header offset)`, taking
  the next larger offset in that archive's central directory, or the start of the central
  directory for the last entry (so data descriptors and padding travel with the entry);
* the final IPA is the concatenation of each entry's raw extent (from the base or the pack file),
  then the central directory records, then an end-of-central-directory record;
* a central directory record is copied from the archive the entry comes from with the *relative offset of
  local header* replaced by the entry's offset in the final IPA and, for entries from the base, the *version made by*
  and *external attributes* replaced by the manifest's record pair;
* the end record: disk numbers 0, entry count n, central directory size and offset, no comment.

Total size and every segment are known before the first byte is sent, so `Content-Length` and
`Range` work exactly as for a stored file.
