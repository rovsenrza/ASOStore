// TGRelay.dylib — production hook injected into re-signed Telegram iOS (ph.telegra.Telegraph).
//
// WHY: Russian ISPs throttle Telegram's direct MTProto connections (TSPU), so a plain install
// barely loads and often can't even sign in. The fix customers normally do by hand is to add an
// MTProxy in Settings. This dylib does it for them: on launch it forces every MTProto connection
// through our own fake-TLS MTProxy (mtg on www.ruappstore.com:443, hidden behind the site), so the
// app works over Russian internet without a VPN and without the customer touching any setting.
//
// HOW (zero-touch, survives restarts, no Postbox writes): Telegram's network stack reads the proxy
// from -[MTApiEnvironment socksProxySettings]. We swizzle that getter to return OUR MTProxy settings
// whenever the user has NOT set a proxy of their own; if they did set one, we return theirs untouched.
// Because every MTTcpTransport reads the getter, all datacenters tunnel through our proxy — login
// included — from the first connection.
//
// INTERNAL LOG: so we can see real-world problems (e.g. the TSPU ~16 KB freeze showing up as read
// errors), the hook logs to the system log (`[TGRelay] ...`, captured by our device-test/syslog
// pipeline) AND appends to <container>/Library/Caches/tgrelay.log, AND POSTs a tiny diagnostics
// line to https://www.ruappstore.com/api/v1/tg-relay/diag (proxy applied + connection open/fail,
// with the provisioned UDID). It never captures message content — only proxy state and transport
// errors.
//
// The MtProtoKit selectors used here are read off the shipped framework's ObjC metadata
// (initWithIp:port:username:password:secret: on MTSocksProxySettings; socksProxySettings on
// MTApiEnvironment; closeAndNotifyWithError: on MTTcpConnection). Every call is guarded and falls
// back to a no-op if a future Telegram build renames them, so the app still launches unchanged.
#import <Foundation/Foundation.h>
#import <UIKit/UIKit.h>
#import <objc/runtime.h>
#import <objc/message.h>
#import <dispatch/dispatch.h>

// Our fake-TLS MTProxy. Host is sent for SNI AND resolved for the TCP connection, so moving the
// proxy to another server only needs a DNS change — no rebuild (ties to [[mtproxy-prod-20261028]]).
static NSString *const kProxyHost   = @"www.ruappstore.com";
static const uint16_t   kProxyPort  = 443;
// fake-TLS secret bytes (0xee + 16 random + "www.ruappstore.com"), hex. Matches /etc/mtg/config.toml.
static NSString *const kProxySecretHex =
    @"eec359562d4ed8af045c7e22e2bbc70a8a7777772e727561707073746f72652e636f6d";
static NSString *const kDiagURL = @"https://www.ruappstore.com/api/v1/tg-relay/diag";

static NSData *HexToData(NSString *hex) {
    const char *c = hex.UTF8String;
    NSUInteger n = strlen(c) / 2;
    NSMutableData *d = [NSMutableData dataWithLength:n];
    unsigned char *b = d.mutableBytes;
    for (NSUInteger i = 0; i < n; i++) {
        unsigned v = 0; sscanf(c + i * 2, "%2x", &v); b[i] = (unsigned char)v;
    }
    return d;
}

static NSString *ReadProvisionedUDID(void) {
    @try {
        NSString *path = [[NSBundle mainBundle] pathForResource:@"embedded" ofType:@"mobileprovision"];
        NSData *data = path ? [NSData dataWithContentsOfFile:path] : nil;
        if (!data) return nil;
        NSString *raw = [[NSString alloc] initWithData:data encoding:NSISOLatin1StringEncoding];
        NSRange s = [raw rangeOfString:@"<?xml"]; NSRange e = [raw rangeOfString:@"</plist>"];
        if (s.location == NSNotFound || e.location == NSNotFound || e.location < s.location) return nil;
        NSData *xml = [[raw substringWithRange:NSMakeRange(s.location, e.location + e.length - s.location)]
                       dataUsingEncoding:NSISOLatin1StringEncoding];
        id plist = [NSPropertyListSerialization propertyListWithData:xml options:0 format:NULL error:nil];
        id devs = [plist isKindOfClass:NSDictionary.class] ? plist[@"ProvisionedDevices"] : nil;
        if ([devs isKindOfClass:NSArray.class] && [devs count] == 1 && [devs[0] isKindOfClass:NSString.class])
            return devs[0];
    } @catch (__unused id e) {}
    return nil;
}

// ---- internal log -----------------------------------------------------------------------------
static dispatch_queue_t gLogQ;
static NSString *gUDID;

static void DiagPost(NSString *event, NSString *detail) {
    @try {
        NSMutableDictionary *body = [NSMutableDictionary dictionary];
        body[@"event"] = event ?: @"";
        if (detail) body[@"detail"] = detail;
        if (gUDID) body[@"udid"] = gUDID;
        body[@"bundleId"] = [[NSBundle mainBundle] bundleIdentifier] ?: @"";
        body[@"appVersion"] = [[NSBundle mainBundle] objectForInfoDictionaryKey:@"CFBundleShortVersionString"] ?: @"";
        NSData *payload = [NSJSONSerialization dataWithJSONObject:body options:0 error:nil];
        if (!payload) return;
        NSMutableURLRequest *req = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:kDiagURL]];
        req.HTTPMethod = @"POST";
        [req setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
        req.timeoutInterval = 15;
        req.HTTPBody = payload;
        [[[NSURLSession sharedSession] dataTaskWithRequest:req completionHandler:^(NSData *d, NSURLResponse *r, NSError *e) {}] resume];
    } @catch (__unused id e) {}
}

static void TGLog(NSString *fmt, ...) {
    va_list ap; va_start(ap, fmt);
    NSString *msg = [[NSString alloc] initWithFormat:fmt arguments:ap];
    va_end(ap);
    NSLog(@"[TGRelay] %@", msg);
    dispatch_async(gLogQ, ^{
        @try {
            NSString *dir = [NSSearchPathForDirectoriesInDomains(NSCachesDirectory, NSUserDomainMask, YES) firstObject];
            if (!dir) return;
            NSString *path = [dir stringByAppendingPathComponent:@"tgrelay.log"];
            NSString *line = [NSString stringWithFormat:@"%@ %@\n", [NSDate date], msg];
            NSFileHandle *fh = [NSFileHandle fileHandleForWritingAtPath:path];
            if (!fh) { [line writeToFile:path atomically:YES encoding:NSUTF8StringEncoding error:nil]; }
            else { @try { [fh seekToEndOfFile]; [fh writeData:[line dataUsingEncoding:NSUTF8StringEncoding]]; [fh closeFile]; } @catch (__unused id e) {} }
        } @catch (__unused id e) {}
    });
}

// ---- build our MTSocksProxySettings via the real init encoding --------------------------------
// Uses NSInvocation so the `port` argument matches whatever integer type the shipped framework
// declares (int32_t / uint16_t / NSInteger), instead of guessing the ABI.
static id BuildProxySettings(void) {
    @try {
        Class cls = objc_getClass("MTSocksProxySettings");
        if (!cls) { TGLog(@"MTSocksProxySettings class missing"); return nil; }
        SEL sel = NSSelectorFromString(@"initWithIp:port:username:password:secret:");
        id obj = [cls alloc];
        NSMethodSignature *sig = [obj methodSignatureForSelector:sel];
        if (!sig) { TGLog(@"init selector missing"); return nil; }
        NSInvocation *inv = [NSInvocation invocationWithMethodSignature:sig];
        inv.selector = sel;
        NSString *ip = kProxyHost;
        NSString *user = nil, *pass = nil;
        NSData *secret = HexToData(kProxySecretHex);
        [inv setArgument:&ip atIndex:2];
        // port: coerce to the declared integer width.
        const char *portType = [sig getArgumentTypeAtIndex:3];
        if (portType && (portType[0] == 'q' || portType[0] == 'Q')) { NSInteger p = kProxyPort; [inv setArgument:&p atIndex:3]; }
        else if (portType && (portType[0] == 's' || portType[0] == 'S')) { uint16_t p = kProxyPort; [inv setArgument:&p atIndex:3]; }
        else { int32_t p = kProxyPort; [inv setArgument:&p atIndex:3]; }
        [inv setArgument:&user atIndex:4];
        [inv setArgument:&pass atIndex:5];
        [inv setArgument:&secret atIndex:6];
        [inv invokeWithTarget:obj];
        id __unsafe_unretained result = nil;
        [inv getReturnValue:&result];
        id settings = result;
        if (settings) TGLog(@"proxy settings built %@:%d (fake-TLS)", kProxyHost, kProxyPort);
        return settings;
    } @catch (id e) { TGLog(@"BuildProxySettings threw %@", e); return nil; }
}

static id gForcedProxy;            // our MTSocksProxySettings (built once)
static dispatch_once_t gProxyOnce;
static BOOL gDiagConnected;        // post "connected" only once

static id ForcedProxy(void) {
    dispatch_once(&gProxyOnce, ^{ gForcedProxy = BuildProxySettings(); });
    return gForcedProxy;
}

// ---- getter swizzle: MTApiEnvironment.socksProxySettings --------------------------------------
typedef id (*ProxyGetterIMP)(id, SEL);
static ProxyGetterIMP gOrigProxyGetter;
static id MyProxyGetter(id self, SEL _cmd) {
    id original = gOrigProxyGetter ? gOrigProxyGetter(self, _cmd) : nil;
    if (original) return original;            // respect a proxy the user set themselves
    id forced = ForcedProxy();
    return forced ?: original;
}

static void HookProxyGetter(void) {
    @try {
        Class env = objc_getClass("MTApiEnvironment");
        if (!env) { TGLog(@"MTApiEnvironment missing — proxy NOT forced"); return; }
        SEL sel = NSSelectorFromString(@"socksProxySettings");
        Method m = class_getInstanceMethod(env, sel);
        if (!m) { TGLog(@"socksProxySettings getter missing — proxy NOT forced"); return; }
        gOrigProxyGetter = (ProxyGetterIMP)method_getImplementation(m);
        method_setImplementation(m, (IMP)MyProxyGetter);
        TGLog(@"proxy getter hooked");
    } @catch (id e) { TGLog(@"HookProxyGetter threw %@", e); }
}

// ---- connection diagnostics: MTTcpConnection -------------------------------------------------
// Log transport failures so we can see the TSPU freeze / blocked-IP symptom in the field. These
// are swizzled best-effort; on a rename they simply don't install and the app is unaffected.
typedef void (*CloseErrIMP)(id, SEL, id);
static CloseErrIMP gOrigClose;
static void MyCloseWithError(id self, SEL _cmd, id error) {
    @try {
        if (error) TGLog(@"tcp close err=%@", error);
    } @catch (__unused id e) {}
    if (gOrigClose) gOrigClose(self, _cmd, error);
}

static void HookConnectionDiag(void) {
    @try {
        Class c = objc_getClass("MTTcpConnection");
        if (!c) return;
        SEL sel = NSSelectorFromString(@"closeAndNotifyWithError:");
        Method m = class_getInstanceMethod(c, sel);
        if (m) {
            gOrigClose = (CloseErrIMP)method_getImplementation(m);
            method_setImplementation(m, (IMP)MyCloseWithError);
        }
    } @catch (__unused id e) {}
}

// Post a "connected"-ish heartbeat once reachability settles, so a working install shows up in the
// diag log even when nothing fails. Cheap: fires once a few seconds after first activation.
static void PostStartupDiag(void) {
    if (gDiagConnected) return; gDiagConnected = YES;
    DiagPost(@"proxy_applied", ForcedProxy() ? @"forced" : @"build_failed");
}

__attribute__((constructor))
static void TGRelayInit(void) {
    gLogQ = dispatch_queue_create("tgrelay.log", DISPATCH_QUEUE_SERIAL);
    gUDID = ReadProvisionedUDID();
    TGLog(@"init (udid=%@)", gUDID ?: @"(multi/none)");
    HookProxyGetter();      // force our MTProxy for every datacenter connection
    HookConnectionDiag();   // capture transport failures for the internal log
    ForcedProxy();          // build eagerly so the first connection already has it

    // A few seconds after the app becomes active, note in the diag log that the proxy is in force.
    [[NSNotificationCenter defaultCenter] addObserverForName:@"UIApplicationDidBecomeActiveNotification"
                                                      object:nil queue:[NSOperationQueue mainQueue]
                                                  usingBlock:^(__unused NSNotification *n) {
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(5 * NSEC_PER_SEC)),
                       dispatch_get_main_queue(), ^{ PostStartupDiag(); });
    }];
}
