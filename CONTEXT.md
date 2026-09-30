# Ru App Store domain language

The product enrolls test devices, assigns them to an eligible Apple Developer team, and delivers authorized IPA builds.

## Language

**Apple team**:
An Apple Developer Program team with its own membership year, registered-device capacity, credentials, and signing identity.
_Avoid_: Account, user account

**Device assignment**:
The durable association of one enrolled device with an Apple team for a membership year. It does not move an existing installation between teams.
_Avoid_: Account switch

**Ru App Store variant**:
A separately built Ru App Store listing and IPA for one Apple team, with a distinct Bundle ID and that team's distribution eligibility.
_Avoid_: A renamed copy of the same IPA

**Available device slot**:
Remaining registration capacity for one Apple team, device family, and membership year, considering registered devices and in-flight reservations.
_Avoid_: Free user seat
