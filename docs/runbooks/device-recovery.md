# User device recovery

For support staff. Customers can do most of this themselves on `/install.html#recovery`, which works
without the app.

**Storefront disappeared or «не открывается»**
1. Ask for the request ID if an error was shown; Admin → Задачи → Установки, search by email.
2. If the latest installation is `DELIVERED`: the IPA was downloaded; the install itself happens on the
   phone. Ask the customer to check «Настройки → Основные → VPN и управление устройством» and trust the developer.
3. If the app was deleted: the customer opens `/install.html` in Safari on the iPhone and taps
   «Установить Storefront» — a new link is issued; the build is reused if still valid.
4. `FAILED` with a reason: `NO_SIGNING_CERTIFICATE` / `TEAM_NOT_ELIGIBLE` → escalate to an admin;
   `DEVICE_NOT_IN_PROFILE` → escalate (signing problem), do not ask the customer to retry repeatedly.

**New iPhone**
1. One device per account by default. The old device's Apple slot stays used for the membership year.
2. The customer enrols the new iPhone on `/activate.html`. If the account limit blocks it, an admin
   decides (product decision on device limits, IMPLEMENTATION_PLAN §10).

**«Регистрация временно недоступна»** (`QUOTA_BLOCKED` / `NO_ELIGIBLE_TEAM`): tell the customer no action
is needed from them; an admin checks Команды Apple → Назначения. Never promise a time.

**Account deletion**: the customer requests it on `/account.html`; an admin erases the account from
Пользователи (types the email to confirm). Explain that the device identifier is kept until Apple's
membership year ends, because Apple only allows disabling a device within the year.
