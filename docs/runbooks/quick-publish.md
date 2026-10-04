# Quick publish (admin «Быстрая публикация»)

One upload takes an IPA all the way into the catalog: inspection, cleaning of injected libraries,
finding (or creating) the listing, approval, publication. Admin page: `/admin/publish.html`
(needs both `artifacts.manage` and `catalog.manage`: catalog manager or admin).

## What happens to each file

1. **Upload** in chunks (resumable). The file waits in a draft *staging* listing (`quick-…` slug).
2. **Inspection** and malware scan, as for any upload.
3. **Cleaning** (tools/ipa-cleaner): reviewed promotions, pinned patches, Info.plist fixes and,
   unless switched off, every other injected library the cleaner can remove safely. The cleaned
   copy is inspected again; the original upload stays as evidence (`PROVENANCE_FAILED`,
   reason `REPLACED_BY_CLEANED_COPY`).
4. **Listing.** The bundle ID decides: a catalog listing that already carries it gets a new build
   (an artifact cannot change listing, so a copy is created there and the staged one retired,
   reason `MOVED_TO_LISTING`); otherwise the staging listing becomes a new listing
   (`tg-<hash>`, signing ID `com.ruappstore.tg<hash>`) with name, developer, category and icon
   from Apple's public lookup, falling back to the IPA's own name and largest loose icon.
   A new listing with an unknown category lands in «Импортировано»: set it on the app card.
5. **Approval and publication** through the ordinary review service, with the uploader as
   reviewer and a full checklist. The previous live build is superseded.

Every step is audited (`quick_publish.*`, `artifact.*`) against the operator's account and IP.

## When it stops (stage `HELD`)

The message on the page says why. Nothing is published by itself in these cases:

| `hold_code` | Meaning | What to do |
|---|---|---|
| `DOWNGRADE` | Version/build is older than the live one | «Разрешить и продолжить» if intended |
| `OTHER_SOURCE` | The listing came from another source (iOSBoom, partner…) | «Разрешить и продолжить», or update it by hand |
| `REVIEW` | Four-eyes review is on (`STOREFRONT_INDEPENDENT_REVIEW=true`), the scan was not `CLEAN`, or no Apple team is approved for the bundle ID | Approve in **Артефакты → Проверка происхождения** (second person / after fixing the cause) |
| `CLEANING` | The cleaner cannot remove an injected library safely, or is not installed | Look at the artifact's cleaning section; clean by hand or publish with «Убирать сторонние библиотеки» off |
| `LISTING_EXISTS` | A deleted/hidden listing already uses this bundle ID | Restore it in **Приложения**, upload again |

Other final stages: `REJECTED` (failed inspection, quarantined, FairPlay-encrypted code,
incompatible), `DUPLICATE` (this build is already in the listing), `FAILED` (internal error: retry
the job on **Задачи**, quote the request ID).

## Switches

- `STOREFRONT_QUICK_PUBLISH_ENABLED=false` turns the feature off (starting an upload is refused).
- `STOREFRONT_QUICK_PUBLISH_LOOKUP=false` skips Apple's lookup (names/icons then come from the IPA).
- The jobs run on the `files` queue (`storefront-queue-files@`); they wait for inspection and
  cleaning by releasing themselves, so a stuck file never blocks a worker. A run can be retried on
  **Задачи** (type `QuickPublishJob`).

## Things to know

- A customer's private import of the same file never blocks an upload, and is never reused: the
  catalog gets its own artifact (the stored file is shared by path).
- This feature publishes without a second person. If that is not acceptable, set
  `STOREFRONT_INDEPENDENT_REVIEW=true`: every run then ends in `HELD / REVIEW`.
