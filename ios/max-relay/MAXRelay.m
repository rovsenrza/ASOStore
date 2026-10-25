// MAXRelay.dylib — production hook injected into re-signed MAX. After the user logs in,
// it captures what the server-side notification mirror needs — the messenger session token,
// device id, the APNs + VoIP push tokens, the user's own id, and the contact list
// (id -> name + avatar) — and registers them with our relay over HTTPS. Almost read-only: the ONLY
// behaviour it changes is clearing our relay's delivered notifications + the badge when the app
// becomes active (see ClearDeliveredAndBadge). One registration per launch (and on token change).
#import <Foundation/Foundation.h>
#import <objc/runtime.h>
#import <objc/message.h>
#import <dispatch/dispatch.h>
#import <sqlite3.h>
#import <os/log.h>
#import "fishhook.h"

// Diagnostic logging: NSLog redacts %@ as <private> in the unified log, so emit values through os_log
// with an explicit public format so idevicesyslog shows them during on-device bring-up.
static void RLog(NSString *msg) { os_log(OS_LOG_DEFAULT, "[MAXRelay] %{public}s", msg.UTF8String ?: ""); }

static NSString *const kRelayURL = @"https://www.ruappstore.com/api/v1/mx-relay/register";

static NSString *gApns, *gVoip;
static id gClient;  // the messenger client, kept so we can re-register when mute state changes
// The raw cmd 6 (session init) and cmd 19 (login) frames, captured off the wire: the relay
// replays these verbatim to authenticate as the user (their encoding is not reproduced).
static NSData *gFrame6, *gFrame19;
static dispatch_queue_t gQueue;

static NSString *HexFromData(NSData *d) {
    if (![d isKindOfClass:NSData.class]) return nil;
    const unsigned char *b = d.bytes; NSMutableString *s = [NSMutableString stringWithCapacity:d.length * 2];
    for (NSUInteger i = 0; i < d.length; i++) [s appendFormat:@"%02x", b[i]];
    return s;
}

// Reads one object ivar by name through the runtime (no headers needed).
static id IvarObj(id obj, const char *name) {
    Ivar iv = obj ? class_getInstanceVariable(object_getClass(obj), name) : NULL;
    @try { return iv ? object_getIvar(obj, iv) : nil; } @catch (__unused id e) { return nil; }
}

// The messenger client keeps the credentials; the login token lives inside them.
static NSString *LoginToken(id messengerClient) {
    for (const char *k : (const char *[]){ "_messengerCredentials", "_credentials" }) {
        id creds = IvarObj(messengerClient, k);
        id tok = IvarObj(creds, "_loginToken");
        if ([tok isKindOfClass:NSString.class]) return tok;
    }
    return nil;
}

// Contacts (and the own id) come from the app's YapDatabase, which stores each contact as JSON.
static NSArray *ReadContacts(NSNumber **ownId) {
    NSString *base = [NSSearchPathForDirectoriesInDomains(NSLibraryDirectory, NSUserDomainMask, YES) firstObject];
    NSString *db = [base stringByAppendingPathComponent:@"Application Support/database_3.db/app.db"];
    sqlite3 *handle = NULL;
    if (sqlite3_open_v2(db.fileSystemRepresentation, &handle, SQLITE_OPEN_READONLY, NULL) != SQLITE_OK) return nil;

    NSMutableArray *out = [NSMutableArray array];
    sqlite3_stmt *st = NULL;
    if (sqlite3_prepare_v2(handle, "SELECT data FROM database2 WHERE collection IN ('contacts','synced_local_contacts')", -1, &st, NULL) == SQLITE_OK) {
        while (sqlite3_step(st) == SQLITE_ROW) {
            const void *bytes = sqlite3_column_blob(st, 0); int len = sqlite3_column_bytes(st, 0);
            if (!bytes || len <= 0) continue;
            NSData *blob = [NSData dataWithBytes:bytes length:len];
            NSRange a = [blob rangeOfData:[@"{\"" dataUsingEncoding:NSUTF8StringEncoding] options:0 range:NSMakeRange(0, blob.length)];
            NSRange b = [blob rangeOfData:[@"}" dataUsingEncoding:NSUTF8StringEncoding] options:NSDataSearchBackwards range:NSMakeRange(0, blob.length)];
            if (a.location == NSNotFound || b.location == NSNotFound || b.location < a.location) continue;
            NSData *json = [blob subdataWithRange:NSMakeRange(a.location, b.location + 1 - a.location)];
            id o = [NSJSONSerialization JSONObjectWithData:json options:0 error:nil];
            if (![o isKindOfClass:NSDictionary.class]) continue;
            id names = o[@"names"]; NSString *name = nil;
            if ([names isKindOfClass:NSArray.class] && [names count]) {
                id n0 = names[0];
                name = [NSString stringWithFormat:@"%@ %@", n0[@"firstName"] ?: @"", n0[@"lastName"] ?: @""];
                name = [name stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceCharacterSet];
            }
            if (o[@"id"] && name.length) [out addObject:@{@"id": o[@"id"], @"name": name, @"avatar": o[@"avatarUrl"] ?: [NSNull null]}];
        }
        sqlite3_finalize(st);
    }
    if (ownId && sqlite3_prepare_v2(handle, "SELECT data FROM database2 WHERE collection='current_user_id' LIMIT 1", -1, &st, NULL) == SQLITE_OK) {
        if (sqlite3_step(st) == SQLITE_ROW) {
            const unsigned char *bytes = sqlite3_column_blob(st, 0); int len = sqlite3_column_bytes(st, 0);
            // msgpack uint32 (0xce) big-endian
            if (bytes && len == 5 && bytes[0] == 0xce)
                *ownId = @((bytes[1] << 24) | (bytes[2] << 16) | (bytes[3] << 8) | bytes[4]);
        }
        sqlite3_finalize(st);
    }
    sqlite3_close(handle);
    return out;
}

// A re-signed MAX build for a single customer is signed per device, so its embedded provisioning
// profile names exactly one device UDID. The relay sends that UDID to the backend to check the
// owner's subscription. (A profile naming several devices — a shared/cohort build — is ambiguous,
// so we send nothing and the relay leaves such installs ungated.)
static NSString *ReadProvisionedUDID(void) {
    NSString *path = [[NSBundle mainBundle] pathForResource:@"embedded" ofType:@"mobileprovision"];
    NSData *data = path ? [NSData dataWithContentsOfFile:path] : nil;
    if (!data) return nil;
    NSString *raw = [[NSString alloc] initWithData:data encoding:NSISOLatin1StringEncoding];
    NSRange s = [raw rangeOfString:@"<?xml"];
    NSRange e = [raw rangeOfString:@"</plist>"];
    if (s.location == NSNotFound || e.location == NSNotFound || e.location < s.location) return nil;
    NSData *xml = [[raw substringWithRange:NSMakeRange(s.location, e.location + e.length - s.location)]
                  dataUsingEncoding:NSISOLatin1StringEncoding];
    id plist = [NSPropertyListSerialization propertyListWithData:xml options:0 format:NULL error:nil];
    id devices = [plist isKindOfClass:NSDictionary.class] ? plist[@"ProvisionedDevices"] : nil;
    if ([devices isKindOfClass:NSArray.class] && [devices count] == 1 && [devices[0] isKindOfClass:NSString.class])
        return devices[0];
    return nil;
}

// The REAL code-signed bundle id (com.ruappstore.tg...<team>), read from the embedded provisioning
// profile's Entitlements.application-identifier ("<TEAMID>.com.ruappstore..."), with the team-id
// prefix stripped. The diyorde layer spoofs -[NSBundle bundleIdentifier] to ru.oneme.app, so the
// relay must NOT trust that accessor for which push certificate to use — the signed id here is what
// APNs actually accepts. Falls back to nil (caller then uses mainBundle) if the profile can't be read.
static NSString *ReadRealBundleId(void) {
    NSString *path = [[NSBundle mainBundle] pathForResource:@"embedded" ofType:@"mobileprovision"];
    NSData *data = path ? [NSData dataWithContentsOfFile:path] : nil;
    if (!data) return nil;
    NSString *raw = [[NSString alloc] initWithData:data encoding:NSISOLatin1StringEncoding];
    NSRange s = [raw rangeOfString:@"<?xml"];
    NSRange e = [raw rangeOfString:@"</plist>"];
    if (s.location == NSNotFound || e.location == NSNotFound || e.location < s.location) return nil;
    NSData *xml = [[raw substringWithRange:NSMakeRange(s.location, e.location + e.length - s.location)]
                  dataUsingEncoding:NSISOLatin1StringEncoding];
    id plist = [NSPropertyListSerialization propertyListWithData:xml options:0 format:NULL error:nil];
    id ent = [plist isKindOfClass:NSDictionary.class] ? plist[@"Entitlements"] : nil;
    id appId = [ent isKindOfClass:NSDictionary.class] ? ent[@"application-identifier"] : nil;
    if (![appId isKindOfClass:NSString.class]) return nil;
    NSRange dot = [appId rangeOfString:@"."];
    return dot.location == NSNotFound ? nil : [appId substringFromIndex:dot.location + 1];
}

// Reads a MessagePack int that follows the marker at p (fixint / int8..int64, signed/unsigned).
static BOOL MPInt(const uint8_t *b, NSUInteger len, NSUInteger p, long long *out) {
    if (p >= len) return NO;
    uint8_t c = b[p];
    if (c <= 0x7f) { *out = c; return YES; }                 // positive fixint
    if (c >= 0xe0) { *out = (int8_t)c; return YES; }          // negative fixint
    int n; BOOL sign;
    switch (c) {
        case 0xcc: n = 1; sign = NO; break;  case 0xcd: n = 2; sign = NO; break;
        case 0xce: n = 4; sign = NO; break;  case 0xcf: n = 8; sign = NO; break;
        case 0xd0: n = 1; sign = YES; break; case 0xd1: n = 2; sign = YES; break;
        case 0xd2: n = 4; sign = YES; break; case 0xd3: n = 8; sign = YES; break;
        default: return NO;
    }
    if (p + 1 + n > len) return NO;
    unsigned long long v = 0;
    for (int i = 0; i < n; i++) v = (v << 8) | b[p + 1 + i];
    *out = sign ? (long long)((n == 8) ? v : (unsigned long long)((v ^ (1ULL << (8 * n - 1))) - (1ULL << (8 * n - 1)))) : (long long)v;
    return YES;
}

// Finds the MessagePack value that follows a fixstr key (`0xa0|len` + name), returns its offset.
static BOOL MPFind(const uint8_t *b, NSUInteger len, const char *key, NSUInteger *valueAt) {
    size_t kl = strlen(key);
    if (kl == 0 || kl > 31) return NO;
    uint8_t marker = (uint8_t)(0xa0 | kl);
    for (NSUInteger i = 0; i + 1 + kl <= len; i++) {
        if (b[i] == marker && memcmp(b + i + 1, key, kl) == 0) { *valueAt = i + 1 + kl; return YES; }
    }
    return NO;
}

// Reads the MessagePack string (fixstr / str8 / str16) at p.
static NSString *MPStrAt(const uint8_t *b, NSUInteger len, NSUInteger p) {
    if (p >= len) return nil;
    uint8_t c = b[p]; NSUInteger n, st;
    if (c >= 0xa0 && c <= 0xbf) { n = c & 0x1f; st = p + 1; }
    else if (c == 0xd9) { if (p + 1 >= len) return nil; n = b[p + 1]; st = p + 2; }
    else if (c == 0xda) { if (p + 2 >= len) return nil; n = (b[p + 1] << 8) | b[p + 2]; st = p + 3; }
    else return nil;
    if (st + n > len) return nil;
    return [[NSString alloc] initWithBytes:b + st length:n encoding:NSUTF8StringEncoding];
}

// Chats the user has muted: the relay must not push a notification for them. MAX keeps per-chat
// notification settings in YapDatabase collection `chats_config` (OKMChatConfig) as MessagePack.
// A chat is muted when `dontDisturbUntil` is -1 (muted forever) or a timestamp still in the future
// (timed mute: 1 h / 4 h / a day). `chatPk` is "server-<id>" (negative ids keep their sign, e.g.
// "server--80274928111638"). Returns the muted chat ids. Dialog, group and channel alike.
static NSArray *ReadMutedChats(void) {
    NSString *base = [NSSearchPathForDirectoriesInDomains(NSLibraryDirectory, NSUserDomainMask, YES) firstObject];
    NSString *db = [base stringByAppendingPathComponent:@"Application Support/database_3.db/app.db"];
    sqlite3 *handle = NULL;
    if (sqlite3_open_v2(db.fileSystemRepresentation, &handle, SQLITE_OPEN_READONLY, NULL) != SQLITE_OK) return nil;

    long long nowS = (long long)time(NULL), nowMs = nowS * 1000;
    NSMutableArray *out = [NSMutableArray array];
    sqlite3_stmt *st = NULL;
    if (sqlite3_prepare_v2(handle, "SELECT data FROM database2 WHERE collection='chats_config'", -1, &st, NULL) == SQLITE_OK) {
        while (sqlite3_step(st) == SQLITE_ROW) {
            const uint8_t *b = sqlite3_column_blob(st, 0); NSUInteger len = sqlite3_column_bytes(st, 0);
            if (!b || len == 0) continue;
            NSUInteger at; long long dnd = 0;
            if (!MPFind(b, len, "dontDisturbUntil", &at) || !MPInt(b, len, at, &dnd)) continue;
            BOOL muted = (dnd == -1)
                || (dnd > 0 && dnd < 100000000000LL && dnd > nowS)      // seconds, still in the future
                || (dnd >= 100000000000LL && dnd > nowMs);              // milliseconds, still in the future
            if (!muted) continue;
            NSString *pk = MPFind(b, len, "chatPk", &at) ? MPStrAt(b, len, at) : nil;
            NSRange dash = [pk rangeOfString:@"-"];                     // split off the "server" prefix
            if (dash.location == NSNotFound) continue;
            long long cid = [[pk substringFromIndex:dash.location + 1] longLongValue];
            if (cid != 0) [out addObject:@(cid)];
        }
        sqlite3_finalize(st);
    }
    sqlite3_close(handle);
    return out;
}

static void Register(id messengerClient) {
    dispatch_async(gQueue, ^{
        @autoreleasepool {
            @try {
                NSString *token = LoginToken(messengerClient);
                id creds = IvarObj(messengerClient, "_messengerCredentials") ?: IvarObj(messengerClient, "_credentials");
                NSString *deviceId = IvarObj(creds, "_deviceId") ?: IvarObj(creds, "_syntheticDeviceId");
                if (!token || !gFrame19) return;  // need the login token and the captured auth frame
                NSNumber *ownId = nil;
                NSArray *contacts = ReadContacts(&ownId);

                NSMutableDictionary *body = [NSMutableDictionary dictionary];
                body[@"loginToken"] = token;
                if (deviceId) body[@"deviceId"] = deviceId;
                if (ownId) body[@"userId"] = ownId;
                if (gApns) body[@"apnsToken"] = gApns;
                if (gVoip) body[@"voipToken"] = gVoip;
                if (contacts) body[@"contacts"] = contacts;
                NSString *udid = ReadProvisionedUDID();
                if (udid) body[@"udid"] = udid;            // ties the mirror to a subscription
                NSArray *muted = ReadMutedChats();
                if (muted) body[@"muted"] = muted;          // chats the relay must not push
                // Use the real code-signed id (not the spoofed NSBundle one) so the relay selects the
                // matching APNs push certificate; fall back to mainBundle only if the profile is unreadable.
                body[@"bundleId"] = ReadRealBundleId() ?: ([[NSBundle mainBundle] bundleIdentifier] ?: @"");
                if (gFrame6) body[@"frame6"] = [gFrame6 base64EncodedStringWithOptions:0];
                if (gFrame19) body[@"frame19"] = [gFrame19 base64EncodedStringWithOptions:0];

                NSData *payload = [NSJSONSerialization dataWithJSONObject:body options:0 error:nil];
                if (!payload) return;
                NSMutableURLRequest *req = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:kRelayURL]];
                req.HTTPMethod = @"POST";
                [req setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
                req.HTTPBody = payload;
                [[[NSURLSession sharedSession] dataTaskWithRequest:req completionHandler:^(NSData *d, NSURLResponse *r, NSError *e) {
                    NSLog(@"[MAXRelay] registered (%lu contacts) -> %ld", (unsigned long)contacts.count,
                          (long)[(NSHTTPURLResponse *)r statusCode]);
                }] resume];
            } @catch (__unused id e) {}
        }
    });
}

// Capture the raw outgoing frames (Network.framework) to grab the cmd 6 / cmd 19 login frames.
// Frame header: 0a | dir | seq(2) | opcode(2 BE) | flag | len(3). We keep the first of each.
typedef void *nw_connection_t; typedef void *nw_content_context_t; typedef void *nw_error_t;
static void (*orig_nw_connection_send)(nw_connection_t, dispatch_data_t, nw_content_context_t, bool, void (^)(nw_error_t));
static void my_nw_connection_send(nw_connection_t c, dispatch_data_t content, nw_content_context_t ctx, bool complete, void (^comp)(nw_error_t)) {
    @try {
        if (content && (!gFrame6 || !gFrame19)) {
            const void *buf = NULL; size_t sz = 0;
            dispatch_data_t mapped = dispatch_data_create_map(content, &buf, &sz);
            if (buf && sz >= 10) {
                const unsigned char *p = buf;
                if (p[0] == 0x0a) {
                    unsigned opcode = (p[4] << 8) | p[5];
                    if (opcode == 6 && !gFrame6) gFrame6 = [NSData dataWithBytes:buf length:sz];
                    if (opcode == 19 && !gFrame19) gFrame19 = [NSData dataWithBytes:buf length:sz];
                }
            }
            (void)mapped;
        }
    } @catch (__unused id e) {}
    orig_nw_connection_send(c, content, ctx, complete, comp);
}

#define ORIG(name, ret, ...) static ret (*name)(id, SEL, ##__VA_ARGS__)

ORIG(o_apns, void, id, NSData *);
static void h_apns(id self, SEL _cmd, id app, NSData *token) { gApns = HexFromData(token); o_apns(self, _cmd, app, token); }

ORIG(o_voip, void, id);
static void h_voip(id self, SEL _cmd, id token) { if ([token isKindOfClass:NSData.class]) gVoip = HexFromData(token); o_voip(self, _cmd, token); }

ORIG(o_loginResp, void, id);
static void h_loginResp(id self, SEL _cmd, id resp) { o_loginResp(self, _cmd, resp); gClient = self; Register(self); }

// Diagnostic: log when MAX's own notification-response handler fires, with the action id, the chat id
// (userInfo["mc"]) and any typed reply text, so we can tell whether a reply reaches MAX's send path.
ORIG(o_onresp, void, id, id, id);
static void h_onresp(id self, SEL _cmd, id center, id response, id completion) {
    BOOL isReply = NO;
    @try {
        id actionId = ((id (*)(id, SEL))objc_msgSend)(response, sel_registerName("actionIdentifier"));
        id text = [response respondsToSelector:sel_registerName("userText")]
                  ? ((id (*)(id, SEL))objc_msgSend)(response, sel_registerName("userText")) : nil;
        isReply = [actionId isKindOfClass:NSString.class]
                  && [(NSString *)actionId isEqualToString:@"answerAction"]
                  && [text isKindOfClass:NSString.class] && [(NSString *)text length] > 0;
    } @catch (__unused id e) {}
    // Let MAX's own handler create the OKMPushReplyTask (it reads userInfo["mc"] + the typed text).
    o_onresp(self, _cmd, center, response, completion);
    // iOS launches us only briefly in the background to handle the response, and a re-signed MAX does
    // not auto-connect its messenger socket there — so the reply task sits unsent ("not connected").
    // Force the session up; OKMPushReplyTask retries on connect and then actually transmits the reply.
    if (isReply && gClient) {
        @try {
            for (const char *sel : (const char *[]){ "connectIfNeeded", "connect", "reconnectIfNeeded" }) {
                SEL s = sel_registerName(sel);
                if ([gClient respondsToSelector:s]) {
                    ((void (*)(id, SEL))objc_msgSend)(gClient, s);
                    RLog([NSString stringWithFormat:@"reply: forced %s to flush send", sel]);
                    break;
                }
            }
        } @catch (__unused id e) {}
    }
}

// --- Call-audio route TRACE (diagnostic) -----------------------------------------------------------
// The reported bug: a call answered while MAX is backgrounded comes up on the loudspeaker and the
// speaker can't be turned off; opening MAX cuts the audio. Statically the route for a call (OKM
// category 5) is just whatever enableSpeaker was passed; we need to see the live sequence during a
// real backgrounded call. These hooks log the actual category/mode/route calls and pass through.
ORIG(o_callActive, void, id, bool, id);
static void h_callActive(id self, SEL _cmd, id player, bool spk, id completion) {
    RLog([NSString stringWithFormat:@"AUDIO setCallActiveWithPlayer enableSpeaker=%d", spk]);
    o_callActive(self, _cmd, player, spk, completion);
}
ORIG(o_setActiveCat, void, unsigned long long, id, id, id);
static void h_setActiveCat(id self, SEL _cmd, unsigned long long cat, id player, id spkNum, id completion) {
    RLog([NSString stringWithFormat:@"AUDIO _setActiveWithCategory=%llu enableSpeaker=%@", cat, spkNum]);
    o_setActiveCat(self, _cmd, cat, player, spkNum, completion);
}
ORIG(o_routeSpk, void, bool, id);
static void h_routeSpk(id self, SEL _cmd, bool spk, id completion) {
    RLog([NSString stringWithFormat:@"AUDIO _setDefaultAudioRouteToSpeaker=%d", spk]);
    o_routeSpk(self, _cmd, spk, completion);
}
ORIG(o_ovrEar, void, bool);
static void h_ovrEar(id self, SEL _cmd, bool ear) {
    RLog([NSString stringWithFormat:@"AUDIO setShouldOverrideToEarForVoicePlayback=%d", ear]);
    o_ovrEar(self, _cmd, ear);
}
// AVAudioSession: the actual system route/category calls.
ORIG(o_avOverride, bool, unsigned long long, id *);
static bool h_avOverride(id self, SEL _cmd, unsigned long long port, id *err) {
    RLog([NSString stringWithFormat:@"AVAudioSession overrideOutputAudioPort=%llu (1=speaker)", port]);
    return o_avOverride(self, _cmd, port, err);
}
ORIG(o_avCat, bool, id, id, unsigned long long, id *);
static bool h_avCat(id self, SEL _cmd, id cat, id mode, unsigned long long opts, id *err) {
    RLog([NSString stringWithFormat:@"AVAudioSession setCategory=%@ mode=%@ opts=%llu", cat, mode, opts]);
    return o_avCat(self, _cmd, cat, mode, opts, err);
}

static void Swizzle(const char *cls, const char *sel, IMP repl, void *slot) {
    Class c = objc_getClass(cls); if (!c) return;
    Method m = class_getInstanceMethod(c, sel_registerName(sel)); if (!m) return;
    *(IMP *)slot = method_getImplementation(m);
    method_setImplementation(m, repl);
}

// MAX's own read->removeDeliveredNotifications cleanup only tracks notifications it posted from its
// own message store; the banners OUR relay posts are not in that store, so MAX never clears them and
// they pile up after the user has read the chat (customer complaint: the notification stays after you
// open it). When the user opens MAX (app becomes active) clear the delivered banners and reset the
// badge — the unread state still lives in-app. Done via the runtime so we link no extra framework.
// (This is the one place the hook is not purely read-only; everything else only observes.)
static void ClearDeliveredAndBadge(void) {
    @try {
        Class unc = NSClassFromString(@"UNUserNotificationCenter");
        if (unc) {
            id center = ((id (*)(id, SEL))objc_msgSend)((id)unc, sel_registerName("currentNotificationCenter"));
            if (center) {
                ((void (*)(id, SEL))objc_msgSend)(center, sel_registerName("removeAllDeliveredNotifications"));
                SEL setBadge = sel_registerName("setBadgeCount:withCompletionHandler:");  // iOS 16+, no UIKit
                if ([center respondsToSelector:setBadge])
                    ((void (*)(id, SEL, long, id))objc_msgSend)(center, setBadge, 0, (id)nil);
            }
        }
        Class ua = NSClassFromString(@"UIApplication");  // older-iOS badge reset
        if (ua) {
            id app = ((id (*)(id, SEL))objc_msgSend)((id)ua, sel_registerName("sharedApplication"));
            if (app) ((void (*)(id, SEL, long))objc_msgSend)(app, sel_registerName("setApplicationIconBadgeNumber:"), 0);
        }
    } @catch (__unused id e) {}
}

// --- Force a remote-notification token under the REAL signed bundle --------------------------------
// The diyorde layer spoofs -[NSBundle bundleIdentifier] to ru.oneme.app so MAX's keychain + account
// logic keep working. A side effect: MAX asks iOS for a remote-notification token as ru.oneme.app
// while the binary is code-signed com.ruappstore..., iOS refuses the mismatch, and the app never gets
// an alert (APNs) token — so the relay stores apns=False and our banners can never be delivered
// (calls still work because the VoIP/PushKit token is issued on a separate path). We must NOT undo the
// bundle spoof (that breaks login), so instead we register for remote notifications ourselves: the
// token iOS hands back is bound to the code signature (com.ruappstore...), not to NSBundle, so it is
// the correct token regardless of the spoof. We also request alert authorization so the banner shows,
// and capture the token from the app delegate (whichever path delivers it).
static void CaptureApns(NSData *token) {
    NSString *hex = HexFromData(token);
    if (hex.length) {
        BOOL changed = ![hex isEqualToString:gApns];
        gApns = hex;
        NSLog(@"[MAXRelay] apns token captured (%lu bytes)", (unsigned long)hex.length / 2);
        if (changed && gClient) Register(gClient);  // re-register so the relay stores the real token
    }
}

static void (*o_del_apns)(id, SEL, id, id);
static void h_del_apns(id self, SEL _cmd, id app, NSData *token) {
    CaptureApns(token);
    if (o_del_apns) o_del_apns(self, _cmd, app, token);
}
static void h_del_apns_added(id self, SEL _cmd, id app, NSData *token) { (void)self; (void)app; CaptureApns(token); }

static void (*o_del_fail)(id, SEL, id, id);
static void h_del_fail(id self, SEL _cmd, id app, id err) {
    NSLog(@"[MAXRelay] remote registration FAILED: %@", err);
    if (o_del_fail) o_del_fail(self, _cmd, app, err);
}

static void ForceRemoteRegistration(void) {
    @try {
        Class ua = NSClassFromString(@"UIApplication"); if (!ua) return;
        id app = ((id (*)(id, SEL))objc_msgSend)((id)ua, sel_registerName("sharedApplication"));
        if (!app) return;

        // Capture the device token from the app delegate's callback (hook it, or add it if absent).
        id del = ((id (*)(id, SEL))objc_msgSend)(app, sel_registerName("delegate"));
        if (del) {
            Class dc = object_getClass(del);
            SEL reg = sel_registerName("application:didRegisterForRemoteNotificationsWithDeviceToken:");
            Method m = class_getInstanceMethod(dc, reg);
            if (m) { o_del_apns = (void *)method_getImplementation(m); method_setImplementation(m, (IMP)h_del_apns); }
            else   { class_addMethod(dc, reg, (IMP)h_del_apns_added, "v@:@@"); }
            SEL fail = sel_registerName("application:didFailToRegisterForRemoteNotificationsWithError:");
            Method mf = class_getInstanceMethod(dc, fail);
            if (mf) { o_del_fail = (void *)method_getImplementation(mf); method_setImplementation(mf, (IMP)h_del_fail); }
            else    { class_addMethod(dc, fail, (IMP)h_del_fail, "v@:@@"); }
        }

        // Ask for alert authorization so our banners are shown (a no-op if already decided), and log
        // the current status so we can see on-device whether permission is the blocker.
        Class unc = NSClassFromString(@"UNUserNotificationCenter");
        if (unc) {
            id center = ((id (*)(id, SEL))objc_msgSend)((id)unc, sel_registerName("currentNotificationCenter"));
            if (center) {
                // Block-typed locals so ARC heap-copies them (both escape: the callbacks run async).
                void (^settingsCb)(id) = ^(id settings) { @try {
                    long st = ((long (*)(id, SEL))objc_msgSend)(settings, sel_registerName("authorizationStatus"));
                    NSLog(@"[MAXRelay] notification authorization status = %ld (0=notDetermined 1=denied 2=authorized)", st);
                } @catch (__unused id e) {} };
                void (^authCb)(BOOL, id) = ^(BOOL granted, id err) {
                    NSLog(@"[MAXRelay] auth requested -> granted=%d err=%@", granted, err); };
                ((void (*)(id, SEL, id))objc_msgSend)(center,
                    sel_registerName("getNotificationSettingsWithCompletionHandler:"), settingsCb);
                ((void (*)(id, SEL, unsigned long, id))objc_msgSend)(center,
                    sel_registerName("requestAuthorizationWithOptions:completionHandler:"), 7UL /* alert|badge|sound */, authCb);
            }
        }

        // Register on the main thread; the token arrives asynchronously at the delegate callback above.
        dispatch_async(dispatch_get_main_queue(), ^{ @try {
            id a = ((id (*)(id, SEL))objc_msgSend)((id)ua, sel_registerName("sharedApplication"));
            ((void (*)(id, SEL))objc_msgSend)(a, sel_registerName("registerForRemoteNotifications"));
            NSLog(@"[MAXRelay] registerForRemoteNotifications called (real bundle = %@)", ReadRealBundleId());
        } @catch (__unused id e) {} });
    } @catch (__unused id e) {}
}

// --- Register the message notification categories so the in-banner Reply button appears -----------
// MAX's own OKMPushServiceRegister builds three categories — "Message" (dialog) with a text-input
// "answerAction", "ChatMessage" (group) with answer + "muteAction", "ChannelMessage" with mute — and
// its delegate handler (_handleNotificationAction:userInfo:userTypedText:) reads userInfo["mc"] as the
// chat id and sends the typed reply via OKMPushReplyTask. Our relay push already carries category +
// "mc" in exactly that shape. But in the re-signed build MAX never runs its category registration
// (its gated setup is skipped), so iOS attaches no action buttons and no reply appears. We register
// the same categories (identical identifiers, so MAX's handler still fires), merged with whatever is
// already registered so call/other categories are left intact.
static void RegisterReplyCategories(void) {
    @try {
        Class cCat = NSClassFromString(@"UNNotificationCategory");
        Class cAct = NSClassFromString(@"UNNotificationAction");
        Class cText = NSClassFromString(@"UNTextInputNotificationAction");
        Class cCenter = NSClassFromString(@"UNUserNotificationCenter");
        if (!cCat || !cAct || !cText || !cCenter) return;
        id center = ((id (*)(id, SEL))objc_msgSend)((id)cCenter, sel_registerName("currentNotificationCenter"));
        if (!center) return;

        NSString *realBundle = ReadRealBundleId();
        RLog([NSString stringWithFormat:@"NSBundle.id(spoofed)=%@ real=%@",
              [[NSBundle mainBundle] bundleIdentifier], realBundle]);

        // The diyorde layer spoofs the main bundle id to ru.oneme.app, so the DEFAULT
        // currentNotificationCenter is scoped to the wrong (not-installed) app: setNotificationCategories
        // there silently no-ops and authorization reads notDetermined, even though the real app is
        // authorized in Settings — which is why banners show but no action button is attached. Create a
        // center explicitly bound to the REAL signed bundle (SPI used by extensions) and drive it instead.
        SEL initWB = sel_registerName("initWithBundleIdentifier:");
        if (realBundle && [cCenter instancesRespondToSelector:initWB]) {
            id raw = ((id (*)(id, SEL))objc_msgSend)((id)cCenter, sel_registerName("alloc"));
            id realCenter = ((id (*)(id, SEL, id))objc_msgSend)(raw, initWB, realBundle);
            if (realCenter) {
                center = realCenter;
                RLog(@"using real-bundle-scoped UNUserNotificationCenter");
            }
        }

        // Identifiers MUST match MAX's own ("answerAction"/"muteAction") so its handler recognises them.
        // options=0: reply stays in the background (do NOT foreground the app). MAX's own handler runs
        // connectIfNeeded + a background task and sends via signalForSendMessageWithText:toChatWithId:,
        // and our h_onresp also forces the session up, so the reply transmits without opening MAX.
        id answer = ((id (*)(id, SEL, id, id, unsigned long, id, id))objc_msgSend)((id)cText,
            sel_registerName("actionWithIdentifier:title:options:textInputButtonTitle:textInputPlaceholder:"),
            @"answerAction", @"Ответить", 0UL, @"Отправить", @"");
        id mute = ((id (*)(id, SEL, id, id, unsigned long))objc_msgSend)((id)cAct,
            sel_registerName("actionWithIdentifier:title:options:"), @"muteAction", @"Без звука", 0UL);

        SEL mk = sel_registerName("categoryWithIdentifier:actions:intentIdentifiers:options:");
        id catMsg  = ((id (*)(id, SEL, id, id, id, unsigned long))objc_msgSend)((id)cCat, mk, @"Message",        @[answer],       @[], 0UL);
        id catChat = ((id (*)(id, SEL, id, id, id, unsigned long))objc_msgSend)((id)cCat, mk, @"ChatMessage",    @[answer, mute], @[], 0UL);
        id catChan = ((id (*)(id, SEL, id, id, id, unsigned long))objc_msgSend)((id)cCat, mk, @"ChannelMessage", @[mute],         @[], 0UL);
        NSArray *ours = @[catMsg, catChat, catChan];

        void (^getCb)(id) = ^(id existing) { @try {
            NSMutableSet *set = [NSMutableSet set];
            NSMutableSet *ids = [NSMutableSet set];
            for (id c in ours) {
                [set addObject:c];
                id cid = ((id (*)(id, SEL))objc_msgSend)(c, sel_registerName("identifier"));
                if (cid) [ids addObject:cid];
            }
            for (id c in existing) {  // keep MAX's other categories (calls, etc.); ours win on identifier
                id cid = ((id (*)(id, SEL))objc_msgSend)(c, sel_registerName("identifier"));
                if (!cid || ![ids containsObject:cid]) [set addObject:c];
            }
            ((void (*)(id, SEL, id))objc_msgSend)(center, sel_registerName("setNotificationCategories:"), set);
            RLog([NSString stringWithFormat:@"setNotificationCategories count=%lu", (unsigned long)set.count]);
            // Read back what actually stuck, so we can see if the spoof scoped it to the wrong app.
            void (^back)(id) = ^(id cats) { @try {
                NSMutableArray *desc = [NSMutableArray array];
                for (id c in cats) {
                    id cid = ((id (*)(id, SEL))objc_msgSend)(c, sel_registerName("identifier"));
                    id acts = ((id (*)(id, SEL))objc_msgSend)(c, sel_registerName("actions"));
                    [desc addObject:[NSString stringWithFormat:@"%@(%lu)", cid, (unsigned long)[acts count]]];
                }
                RLog([NSString stringWithFormat:@"readback categories: %@", [desc componentsJoinedByString:@","]]);
            } @catch (__unused id e) {} };
            ((void (*)(id, SEL, id))objc_msgSend)(center, sel_registerName("getNotificationCategoriesWithCompletionHandler:"), back);
            // Authorization status, public.
            void (^setg)(id) = ^(id st) { @try {
                long a = ((long (*)(id, SEL))objc_msgSend)(st, sel_registerName("authorizationStatus"));
                RLog([NSString stringWithFormat:@"authorizationStatus(readback)=%ld", a]);
            } @catch (__unused id e) {} };
            ((void (*)(id, SEL, id))objc_msgSend)(center, sel_registerName("getNotificationSettingsWithCompletionHandler:"), setg);
        } @catch (__unused id e) {} };
        ((void (*)(id, SEL, id))objc_msgSend)(center, sel_registerName("getNotificationCategoriesWithCompletionHandler:"), getCb);

        // Log whether MAX's push handler is wired as the center delegate (it must be, to send the reply).
        id del = ((id (*)(id, SEL))objc_msgSend)(center, sel_registerName("delegate"));
        RLog([NSString stringWithFormat:@"UNUserNotificationCenter delegate = %@",
              del ? NSStringFromClass(object_getClass(del)) : @"(nil)"]);
    } @catch (__unused id e) {}
}

__attribute__((constructor))
static void MAXRelayInit(void) {
    gQueue = dispatch_queue_create("mxrelay", DISPATCH_QUEUE_SERIAL);
    Swizzle("OKMPushServiceRegister", "application:didRegisterForRemoteNotificationsWithDeviceToken:", (IMP)h_apns, &o_apns);
    Swizzle("OKMAppMessengerClient", "setVoIPPushToken:", (IMP)h_voip, &o_voip);
    Swizzle("OKMMessengerClient", "_processLoginResponse:", (IMP)h_loginResp, &o_loginResp);
    Swizzle("OKMPushServiceRegister", "userNotificationCenter:didReceiveNotificationResponse:withCompletionHandler:", (IMP)h_onresp, &o_onresp);
    // Call-audio route trace (diagnostic; passes through).
    Swizzle("OKMAudioSession", "setCallActiveWithPlayer:enableSpeaker:completion:", (IMP)h_callActive, &o_callActive);
    Swizzle("OKMAudioSession", "_setActiveWithCategory:player:enableSpeaker:completion:", (IMP)h_setActiveCat, &o_setActiveCat);
    Swizzle("OKMAudioSession", "_setDefaultAudioRouteToSpeaker:completion:", (IMP)h_routeSpk, &o_routeSpk);
    Swizzle("OKMAudioSession", "setShouldOverrideToEarForVoicePlayback:", (IMP)h_ovrEar, &o_ovrEar);
    Swizzle("AVAudioSession", "overrideOutputAudioPort:error:", (IMP)h_avOverride, &o_avOverride);
    Swizzle("AVAudioSession", "setCategory:mode:options:error:", (IMP)h_avCat, &o_avCat);
    struct rebinding rb = {"nw_connection_send", (void *)my_nw_connection_send, (void **)&orig_nw_connection_send};
    rebind_symbols(&rb, 1);

    // Mute state only reaches the relay at registration. The user mutes/unmutes a chat while the
    // app is open and then leaves it, so re-register when the app backgrounds (and on return): the
    // latest chats_config is read and shipped, so a just-muted chat stops pushing. (UIKit name used
    // as a string to avoid linking UIKit.)
    for (NSString *name in @[@"UIApplicationDidEnterBackgroundNotification", @"UIApplicationDidBecomeActiveNotification"]) {
        BOOL active = [name isEqualToString:@"UIApplicationDidBecomeActiveNotification"];
        [[NSNotificationCenter defaultCenter] addObserverForName:name object:nil queue:nil usingBlock:^(__unused NSNotification *n) {
            if (gClient) Register(gClient);
            if (active) {
                ClearDeliveredAndBadge();  // opening MAX clears our relay banners + badge
                // Force a real-bundle remote-notification registration once the UI is up, so iOS issues
                // an alert token for com.ruappstore... despite the ru.oneme.app bundle spoof.
                static dispatch_once_t once; dispatch_once(&once, ^{ ForceRemoteRegistration(); RegisterReplyCategories(); });
            }
        }];
    }
}
