# Quota reconciliation mismatch

**Trigger.** Alert / audit event `quota.mismatch` from the nightly reconciliation (or «Сверить с
Apple»): Apple lists a different number of devices for a family than the local counters.

The system **never corrects this automatically** (FULL_PLAN §6.2 step 7).

1. Admin → Команды Apple: note `registered` (local) vs «По данным Apple» per family.
2. **Apple > local** (most common): someone registered devices directly in the Apple developer portal,
   or a registration succeeded at Apple but the job crashed before recording it.
   - Compare the Apple device list with Admin → Устройства for that team.
   - Devices registered by hand still use slots for the whole membership year. Record them in the
     ticket; if slots are nearly gone, see the blocking banner and plan a team assignment.
   - A crashed registration fixes itself: the job looks the UDID up at Apple before registering.
     Admin → Устройства → device → «Повторить синхронизацию».
3. **Local > Apple**: a device we count was removed at Apple, or Apple is still processing it.
   Wait one day and reconcile again; if it persists, check `APPLE_PENDING` registrations older than a day.
4. Never edit `team_quotas` or registrations in SQL. If a real correction is needed, write it up
   with evidence and have a second admin review it.
