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
#import <AVFoundation/AVFoundation.h>
#import <BackgroundTasks/BackgroundTasks.h>
#import <LocalAuthentication/LocalAuthentication.h>
#import <UserNotifications/UserNotifications.h>

static void RSDiag(NSString *event);   // diagnostic beacon (defined below), usable everywhere

#import <dlfcn.h>
// The app's REAL signed bundle id, read from the code-signing entitlement (NOT swizzled by the
// keep-bundle-id shim, which rewrites NSBundle.bundleIdentifier to the vendor id). Shared by both
// builds. Mirrors RuStoreCompat's RSEntitlement.
static NSString *RealBundleId(void) {
    NSString *fallback = [[NSBundle mainBundle] bundleIdentifier] ?: @"";
    typedef struct __SecTask *SecTaskRef;
    static SecTaskRef (*createFromSelf)(CFAllocatorRef);
    static CFTypeRef (*copyValue)(SecTaskRef, CFStringRef, CFErrorRef *);
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        createFromSelf = dlsym(RTLD_DEFAULT, "SecTaskCreateFromSelf");
        copyValue = dlsym(RTLD_DEFAULT, "SecTaskCopyValueForEntitlement");
    });
    if (!createFromSelf || !copyValue) return fallback;
    SecTaskRef task = createFromSelf(NULL);
    if (!task) return fallback;
    id appId = CFBridgingRelease(copyValue(task, CFSTR("application-identifier"), NULL));
    CFRelease(task);
    if (![appId isKindOfClass:NSString.class] || [(NSString *)appId length] == 0) return fallback;
    NSRange dot = [(NSString *)appId rangeOfString:@"."];      // strip "<TEAMID>."
    return dot.location != NSNotFound ? [(NSString *)appId substringFromIndex:dot.location + 1] : (NSString *)appId;
}
#import <objc/runtime.h>
#import <objc/message.h>
#import <dispatch/dispatch.h>
#import <dlfcn.h>

// Everything from here down to the Face-ID fix is the server-side notification/call mirror:
// VK session capture, APNs/VoIP token registration, and the CallKit incoming-call path. The
// general (non-test) build is compiled with -DVK_FACEID_ONLY, which omits ALL of it — those
// customers get only the launch-crash fix and the Face-ID-loop fix, with no notifications or
// calls, because the relay is not in full production yet. (The relay also gates by UDID
// server-side, so this compile split is defence in depth, not the only gate.)
#ifndef VK_FACEID_ONLY
static NSString *const kRelayURL = @"https://www.ruappstore.com/api/v1/vk-relay/register";

#ifdef VKDUMP
// One-shot FairPlay dump for the call-join RE (VKDump.m). Compiled in only under -DVKDUMP
// (Makefile `make dump`) and gated to a single device inside the routine — production builds of
// VKRelay.dylib do not define VKDUMP and contain none of this.
extern void VKDumpDecryptedExecutable(void);
#endif

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

// VKID/SAK store the device_id (bound to the refresh token) in the keychain, either as a plain
// UUID string or NSKeyedArchiver-wrapped. Decode to the uuid string so the mirror can refresh.
static NSString *DecodeDeviceId(NSData *data) {
    if (![data isKindOfClass:NSData.class] || data.length == 0) return nil;
    NSString *s = [[NSString alloc] initWithData:data encoding:NSUTF8StringEncoding];
    NSString *trimmed = [s stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceAndNewlineCharacterSet];
    if (trimmed.length && [[NSUUID alloc] initWithUUIDString:trimmed]) return trimmed;
    id obj = nil;
    @try { obj = [NSKeyedUnarchiver unarchiveTopLevelObjectWithData:data error:nil]; }
    @catch (__unused id e) {}
    if ([obj isKindOfClass:NSUUID.class]) return [(NSUUID *)obj UUIDString];
    if ([obj isKindOfClass:NSString.class] && [(NSString *)obj length]) return obj;
    if ([obj isKindOfClass:NSData.class]) {
        NSString *s2 = [[NSString alloc] initWithData:(NSData *)obj encoding:NSUTF8StringEncoding];
        if (s2.length) return s2;
    }
    return trimmed.length ? trimmed : nil;
}

// The keychain services VK/VKID/SAK use for the device_id (any may be present on a given build).
static BOOL IsDeviceIdService(NSString *svc) {
    return [svc isEqualToString:@"com.vk.ios.deviceid"] ||
           [svc isEqualToString:@"com.vk.sak.deviceid"] ||
           [svc isEqualToString:@"com.vkid.storage.deviceID"];
}

// Reads the VK session (access + refresh token, user id, device_id candidates) from the keychain.
// Returns nil until the user has logged in. MatchLimitAll + no access-group constraint so we see
// VK's items under the shared re-sign group; we pick the one whose service is the 3140623 session.
static NSDictionary *ReadVKSession(void) {
    // kSecUseAuthenticationUISkip: this is a broad MatchLimitAll + ReturnData read, so if any item
    // in the shared group carries a biometric access-control it would make iOS show Face ID on every
    // foreground. Skip auth-requiring items instead of prompting — VK never legitimately needs them.
    NSDictionary *query = @{
        (__bridge id)kSecClass:             (__bridge id)kSecClassGenericPassword,
        (__bridge id)kSecMatchLimit:        (__bridge id)kSecMatchLimitAll,
        (__bridge id)kSecReturnAttributes:  @YES,
        (__bridge id)kSecReturnData:        @YES,
        (__bridge id)kSecUseAuthenticationUI: (__bridge id)kSecUseAuthenticationUISkip,
    };
    CFTypeRef result = NULL;
    OSStatus st = SecItemCopyMatching((__bridge CFDictionaryRef)query, &result);
    if (st != errSecSuccess || !result) return nil;
    NSArray *items = (__bridge_transfer NSArray *)result;
    if (![items isKindOfClass:NSArray.class]) return nil;

    NSMutableDictionary *out = nil;
    NSMutableArray<NSString *> *deviceIds = [NSMutableArray array];
    for (id it in items) {
        if (![it isKindOfClass:NSDictionary.class]) continue;
        NSString *svc = it[(__bridge id)kSecAttrService];
        if (![svc isKindOfClass:NSString.class]) continue;
        NSData *data = it[(__bridge id)kSecValueData];

        if (IsDeviceIdService(svc)) {
            NSString *dv = DecodeDeviceId(data);
            if (dv.length && ![deviceIds containsObject:dv]) [deviceIds addObject:dv];
            continue;
        }
        if (out || ![svc isEqualToString:kVKSessionService]) continue;  // session already found / other item
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

        out = [NSMutableDictionary dictionary];
        out[@"accessToken"] = access;
        if ([refresh isKindOfClass:NSString.class]) out[@"refreshToken"] = refresh;
        if ([userID isKindOfClass:NSNumber.class]) out[@"userId"] = userID;
        else if ([userID isKindOfClass:NSString.class]) out[@"userId"] = @([userID longLongValue]);
        if (expiry) out[@"accessTokenExpiry"] = expiry;
    }
    if (!out) return nil;
    if (deviceIds.count) out[@"deviceIds"] = deviceIds;  // keychain-stored candidates
    return out;
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

// The REAL signed bundle id (com.ruappstore.tg…), read from the application-identifier
// entitlement. We must NOT use [[NSBundle mainBundle] bundleIdentifier] here: the compat shim
// swizzles it to the vendor id (com.vk.vkclient) for the keep-bundle-id apps, and the relay uses
// this value as the APNs topic and the certs/<bundle>/ key — a vendor id there yields HTTP 400
// TopicDisallowed (no cert, wrong topic) and kills every push and call. The entitlement is not
// swizzled, so it still carries our real identity. (RealBundleId is defined once near the top.)

static void Register(BOOL active) {
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

                // device_id candidates for the mirror's VKID token refresh. The refresh token is
                // bound to the exact device_id used at auth; we don't know which store VK used, so we
                // send every candidate we can read on our build (keychain + IDFV + VKID UserDefaults)
                // and the mirror tries each. IDFV is this (re-signed) build's vendor id — stable and
                // exactly what VKID would have used if the user logged in on our build.
                NSMutableArray<NSString *> *devIds = [NSMutableArray array];
                void (^addDev)(NSString *) = ^(NSString *v) {
                    if ([v isKindOfClass:NSString.class] && v.length && ![devIds containsObject:v])
                        [devIds addObject:v];
                };
                for (NSString *v in session[@"deviceIds"]) addDev(v);
                addDev([UIDevice currentDevice].identifierForVendor.UUIDString);
                addDev([[NSUserDefaults standardUserDefaults] stringForKey:@"com.vkid.core.deviceId"]);
                if (devIds.count) body[@"deviceIds"] = devIds;
                if (gApns) body[@"apnsToken"] = gApns;
                if (gVoip) body[@"voipToken"] = gVoip;
                NSString *udid = ReadProvisionedUDID();
                if (udid) body[@"udid"] = udid;            // ties the mirror to a subscription
                body[@"bundleId"] = RealBundleId();
                // active=YES marks a foreground (didBecomeActive) register: the relay resets this
                // user's unread icon-badge counter to 0 (the user is reading).
                body[@"active"] = @(active);

                // Nothing changed since the last successful POST → skip (foreground churn). But an
                // active register must ALWAYS reach the relay (it carries the badge-reset signal), so
                // the dedup is bypassed when active.
                NSString *fingerprint = [NSString stringWithFormat:@"%@|%@|%@", access, gApns ?: @"", gVoip ?: @""];
                if (!active && gLastToken && [gLastToken isEqualToString:fingerprint]) return;

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
    Register(NO);

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
    if ([type isEqualToString:PKPushTypeVoIP] && [creds.token isKindOfClass:NSData.class]) {
        gVoip = HexFromData(creds.token);
        RSDiag([NSString stringWithFormat:@"vk-voiptoken %@", gVoip.length >= 12 ? [gVoip substringToIndex:12] : gVoip]);
        Register(NO);   // push the VoIP token to the relay the instant VK obtains it (so incoming
                      // calls can ring right after launch, not only after the next foreground/APNs)
    }
    if (gOrigVoip) gOrigVoip(self, _cmd, registry, creds, type);
    Register(NO);

}

// ---- CallKit: present the incoming call ourselves ---------------------------------------------
// VK's own PushKit handler decodes the vendor's VoIP payload and crashes on anything else (its
// Swift decoder feeds a nil into +[NSSet setWithObject:] — device-confirmed). We never reproduce
// that payload: our relay sends a VoIP push carrying OUR own "ruas_call" dict, this hook catches
// it before VK's handler runs, and reports the incoming call straight to CallKit. iOS rings and
// shows the caller; answering brings VK to the foreground (we are the VK process). The vendor's
// call/audio stack is not involved — this is a ring + caller banner, not a connected VK call.
//
// ANSWER->AUDIO handoff: our CallKit ring carries no media, so answering it cannot by itself make
// audio flow. When the user answers we must get VK's OWN call stack to join the still-live call.
// The relay sends VK's own VoIP payload, so VK normally reports the call itself and handles answer,
// end and audio through its own CXProvider. gProvider below only rings if VK did not report in time,
// so iOS does not kill the app. That ring cannot be answered: CallKit gives its actions to VK's
// provider, which looks up a session by the call's UUID and finds none.
static CXProvider *gProvider;
@class VKRCallDelegate;
static VKRCallDelegate *gCallDelegate;
static NSMutableDictionary *gCalls;  // CallKit uuidString -> {call_id, conversation_params, caller, video}

// 1-to-1 calls: -[VKCCallSession fullName] returns "calls_group_title" until participants load, so
// CallKit would show "group call" first. Show the caller's name from the push instead.
static NSString *gCallerDisplayName;
static void (*gOrigVKReport)(id, SEL, id, id, id, BOOL, id);
static void MyVKReport(id self, SEL _cmd, id call, id handle, id name, BOOL video, id completion) {
    if (gCallerDisplayName.length) name = gCallerDisplayName;
    gOrigVKReport(self, _cmd, call, handle, name, video, completion);
}

static void (*gOrigSMIncoming)(id, SEL, id, id, id);
static void MySMIncoming(id self, SEL _cmd, id pl, id cfg, id comp) {
    gCallerDisplayName = nil;
    @try {
        id chat = [pl respondsToSelector:@selector(chatInfo)] ? [pl performSelector:@selector(chatInfo)] : nil;
        id nm = !chat && [pl respondsToSelector:@selector(callerDisplayName)] ? [pl performSelector:@selector(callerDisplayName)] : nil;
        if ([nm isKindOfClass:NSString.class]) gCallerDisplayName = nm;
    } @catch (__unused id e) {}
    gOrigSMIncoming(self, _cmd, pl, cfg, comp);
}

// VK's own call queue (-[VKCallsService onQueueEvent:], source 0, only while the app runs) delivers the
// call we already rang from the VoIP push (source 2) seconds later. Handing it on creates a second session
// for the same call_id, so a declined or answered call rings again for a second. Queue events are not
// bound by PushKit's report rule, so dropping the repeat is safe.
static void (*gOrigHandleVoIP)(id, SEL, id, id, long, id, id);
static NSMutableSet *gVoipCallIds;
static void MyHandleVoIP(id self, SEL _cmd, id payload, id toId, long source, id session, id comp) {
    id callId = [payload isKindOfClass:NSDictionary.class] ? payload[@"call_id"] : nil;
    if ([callId isKindOfClass:NSString.class]) {
        @synchronized(self) {
            if (!gVoipCallIds) gVoipCallIds = [NSMutableSet set];
            if (source == 2) [gVoipCallIds addObject:callId];
            else if ([gVoipCallIds containsObject:callId]) {
                NSLog(@"[VKRelay] queue repeat dropped");
                if (comp) ((void (^)(id))comp)(nil);
                return;
            }
        }
    }
    gOrigHandleVoIP(self, _cmd, payload, toId, source, session, comp);
}

static void HookVKCallEntryPoints(void) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        Class svc = objc_getClass("VKCallsService");
        Method hv = svc ? class_getClassMethod(svc, @selector(handleVoIPPushPayload:toUserId:source:session:completion:)) : NULL;
        if (hv) { gOrigHandleVoIP = (void (*)(id, SEL, id, id, long, id, id))method_getImplementation(hv); method_setImplementation(hv, (IMP)MyHandleVoIP); }
        Class sm = objc_getClass("VKCCallSessionManager");
        Method in = sm ? class_getInstanceMethod(sm, @selector(incomingCallWithPayload:config:completion:)) : NULL;
        if (in) { gOrigSMIncoming = (void (*)(id, SEL, id, id, id))method_getImplementation(in); method_setImplementation(in, (IMP)MySMIncoming); }
        Class ck = objc_getClass("VKCCallKitController");
        Method rp = ck ? class_getInstanceMethod(ck, @selector(reportIncomingCall:handle:displayName:video:completion:)) : NULL;
        if (rp) { gOrigVKReport = (void (*)(id, SEL, id, id, id, BOOL, id))method_getImplementation(rp); method_setImplementation(rp, (IMP)MyVKReport); }
    });
}

static CXCallObserver *gCallObserver;

// Forward declarations (defined further down) so the delegate methods below can use them.
static void SetupCallProvider(void);
static void ReportIncomingCall(NSString *caller, BOOL video, NSString *callId, NSString *convParams, void (^pushCompletion)(void));
static void ParseVoipCall(PKPushPayload *payload, NSString **caller, BOOL *video, NSString **callId, NSString **convParams, BOOL *isCall);

// True when the system currently has a non-outgoing, non-ended call — i.e. VK (or we) reported an
// incoming call. Used to tell whether VK's own handler reported the call before our watchdog fires.
static BOOL AnyIncomingCallActive(void) {
    @try {
        for (CXCall *c in gCallObserver.calls) {
            if (!c.hasEnded && !c.outgoing) return YES;
        }
    } @catch (__unused id e) {}
    return NO;
}

@interface VKRCallDelegate : NSObject <CXProviderDelegate, CXCallObserverDelegate, PKPushRegistryDelegate>
@end
// Incoming-call audio for a CLOSED/backgrounded app (mirrors MAXRelay's device-confirmed fix): a
// cold/background launch from a VoIP push never starts its voice I/O unit until the app is
// foregrounded, so the call connects silently. When a non-outgoing call connects, force the app's own
// AVAudioSession into the call configuration and activate it, retried to win against VK's own resets.
static void VKForceCallAudioActive(void) {
    @try {
        AVAudioSession *s = [AVAudioSession sharedInstance];
        [s setCategory:AVAudioSessionCategoryPlayAndRecord mode:AVAudioSessionModeVoiceChat
           options:AVAudioSessionCategoryOptionAllowBluetooth error:nil];
        [s setActive:YES withOptions:0 error:nil];
    } @catch (__unused id e) {}
}

@implementation VKRCallDelegate
- (void)callObserver:(CXCallObserver *)observer callChanged:(CXCall *)call {
    @try {
        if (call.isOutgoing || call.hasEnded || !call.hasConnected) return;
        NSLog(@"[VKRelay] AUDIO incoming call connected -> forcing call audio active");
        for (int i = 0; i < 10; i++) {
            dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(i * 0.4 * NSEC_PER_SEC)),
                           dispatch_get_main_queue(), ^{ VKForceCallAudioActive(); });
        }
    } @catch (__unused id e) {}
}
// ---- Own PKPushRegistry (fallback) delegate -------------------------------------------------
// Used only by the registry we create ourselves when VK never creates one (so the app would
// otherwise never register for VoIP pushes → no incoming calls). This delegate has no VK handler,
// so it always reports our own CallKit ring (guaranteed, no kill). Token updates go to the relay.
- (void)pushRegistry:(PKPushRegistry *)registry didUpdatePushCredentials:(PKPushCredentials *)credentials forType:(PKPushType)type {
    if ([type isEqualToString:PKPushTypeVoIP] && [credentials.token isKindOfClass:NSData.class]) {
        gVoip = HexFromData(credentials.token);
        NSLog(@"[VKRelay] own PKPushRegistry got VoIP token; registering with relay");
        RSDiag(@"own-voiptoken");
        Register(NO);

    }
}
- (void)pushRegistry:(PKPushRegistry *)registry didReceiveIncomingPushWithPayload:(PKPushPayload *)payload forType:(PKPushType)type withCompletionHandler:(void (^)(void))completion {
    SetupCallProvider();
    NSString *caller = nil, *callId = nil, *convParams = nil; BOOL video = NO, isCall = NO;
    ParseVoipCall(payload, &caller, &video, &callId, &convParams, &isCall);
    NSLog(@"[VKRelay] own registry VoIP push -> CallKit ring (isCall=%d call_id=%@)", isCall, callId);
    RSDiag(@"own-voippush");
    __block BOOL rel = NO;
    void (^release)(void) = ^{ if (rel) return; rel = YES; if (completion) completion(); };
    @try { ReportIncomingCall(caller, video, callId, convParams, release); }
    @catch (__unused id e) { release(); }
}
- (void)providerDidReset:(CXProvider *)provider {}
- (void)provider:(CXProvider *)provider performAnswerCallAction:(CXAnswerCallAction *)action {
    @try {
        NSString *uuid = action.callUUID.UUIDString;
        NSDictionary *ctx = nil;
        @synchronized(gCalls) { ctx = gCalls[uuid]; }
        // CallKit expects an answered call to own the audio session; set it up before handing off.
        @try {
            AVAudioSession *s = [AVAudioSession sharedInstance];
            [s setCategory:AVAudioSessionCategoryPlayAndRecord mode:AVAudioSessionModeVoiceChat
               options:0 error:nil];
        } @catch (__unused id e) {}
    } @catch (__unused id e) {}
    [action fulfill];
}
- (void)provider:(CXProvider *)provider performEndCallAction:(CXEndCallAction *)action {
    @try {
        NSString *uuid = action.callUUID.UUIDString;
        @synchronized(gCalls) { [gCalls removeObjectForKey:uuid]; }
    } @catch (__unused id e) {}
    [action fulfill];
}
- (void)provider:(CXProvider *)provider didActivateAudioSession:(AVAudioSession *)audioSession {
    NSLog(@"[VKRelay] CallKit audio session activated");
}
- (void)provider:(CXProvider *)provider didDeactivateAudioSession:(AVAudioSession *)audioSession {}
@end

// Builds the CallKit provider once. Safe to call from the constructor, so the provider exists
// before the first VoIP push arrives on a cold launch (no lazy setup inside the push handler).
static void SetupCallProvider(void) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        gCalls = [NSMutableDictionary dictionary];
        CXProviderConfiguration *cfg = [[CXProviderConfiguration alloc] initWithLocalizedName:@"ВКонтакте"];
        cfg.supportsVideo = YES;
        cfg.maximumCallsPerCallGroup = 1;
        cfg.supportedHandleTypes = [NSSet setWithObject:@(CXHandleTypeGeneric)];
        gProvider = [[CXProvider alloc] initWithConfiguration:cfg];
        gCallDelegate = [VKRCallDelegate new];
        [gProvider setDelegate:gCallDelegate queue:nil];
        gCallObserver = [[CXCallObserver alloc] init];
        [gCallObserver setDelegate:gCallDelegate queue:dispatch_get_main_queue()];
    });
}

// Reports the incoming call to CallKit and calls the PushKit completion handler ONLY inside the
// report's own completion block. iOS kills the app if the push completion runs before the call is
// actually registered, so the two must be ordered — calling completion() right after the report
// (as before) is a race that still triggers the "never posted an incoming call" kill. A watchdog
// releases the push if CallKit never calls back, so the completion is always invoked exactly once.
static void ReportIncomingCall(NSString *caller, BOOL video, NSString *callId, NSString *convParams,
                               void (^pushCompletion)(void)) {
    SetupCallProvider();
    NSUUID *uuid = [NSUUID UUID];
    NSMutableDictionary *ctx = [NSMutableDictionary dictionary];
    if ([caller isKindOfClass:NSString.class]) ctx[@"caller"] = caller;
    ctx[@"video"] = @(video);
    if ([callId isKindOfClass:NSString.class]) ctx[@"call_id"] = callId;
    if ([convParams isKindOfClass:NSString.class]) ctx[@"conversation_params"] = convParams;
    @synchronized(gCalls) { gCalls[uuid.UUIDString] = ctx; }
    CXCallUpdate *update = [CXCallUpdate new];
    update.remoteHandle = [[CXHandle alloc] initWithType:CXHandleTypeGeneric value:caller ?: @"VK"];
    update.hasVideo = video;
    update.localizedCallerName = caller ?: @"ВКонтакте";
    __block BOOL released = NO;
    void (^release)(void) = ^{
        if (released) return; released = YES;
        if (pushCompletion) pushCompletion();
    };
    [gProvider reportNewIncomingCallWithUUID:uuid update:update completion:^(NSError *e) {
        NSLog(@"[VKRelay] CallKit reportNewIncomingCall err=%@", e ?: @"none");
        release();
    }];
    // Watchdog: never leave the VoIP push completion un-called (that itself causes the iOS kill).
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(4 * NSEC_PER_SEC)),
                   dispatch_get_main_queue(), release);
}

// iOS 13+ requires every VoIP push to report a CallKit call before the completion handler, or it
// kills the app. The relay now sends VK's OWN native VoIP payload ({"data":{call_id,caller_info,
// conversation_params,answer_from,...}}): we pass that straight to VK's own handler, which builds a
// VKCPushPayload (+[VKCPushPayload payloadFromDictionary:]) and runs +[VKCallsService
// handleVoIPPushPayload:...] — native CallKit + WebRTC join + audio. (RE: the data keys and the
// handler chain are in research/vk-native-push-formats-2026-10-23.md.) We no longer build our own
// CallKit for calls; the legacy `ruas_call` branch stays only as a fallback for an older relay.
typedef void (*IncomingIMP)(id, SEL, id, PKPushPayload *, PKPushType, void (^)(void));
static IncomingIMP gOrigIncoming;
static BOOL gVKRegistrySeen = NO;   // set when VK creates its own PKPushRegistry (so we don't add ours)

// Parses a VoIP push into caller/call_id/conversation_params, accepting the native shape flat OR
// under "context", plus our legacy "ruas_call". Sets *isCall when it is a recognisable call push.
static void ParseVoipCall(PKPushPayload *payload, NSString **caller, BOOL *video,
                          NSString **callId, NSString **convParams, BOOL *isCall) {
    *caller = nil; *callId = nil; *convParams = nil; *video = NO; *isCall = NO;
    @try {
        NSDictionary *d = [payload respondsToSelector:@selector(dictionaryPayload)] ? payload.dictionaryPayload : nil;
        NSDictionary *data = [d[@"data"] isKindOfClass:NSDictionary.class] ? d[@"data"] : nil;
        NSDictionary *ctx = data && [data[@"context"] isKindOfClass:NSDictionary.class] ? data[@"context"] : data;
        if ([ctx isKindOfClass:NSDictionary.class] && ctx[@"call_id"]) {
            *isCall = YES;
            id ci = ctx[@"caller_info"];
            NSString *nm = [ci isKindOfClass:NSDictionary.class]
                ? [NSString stringWithFormat:@"%@ %@", ci[@"first_name"] ?: @"", ci[@"last_name"] ?: @""] : @"";
            *caller = [nm stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceCharacterSet];
            *callId = [ctx[@"call_id"] isKindOfClass:NSString.class] ? ctx[@"call_id"] : nil;
            *convParams = [ctx[@"conversation_params"] isKindOfClass:NSString.class] ? ctx[@"conversation_params"] : nil;
        } else if ([d[@"ruas_call"] isKindOfClass:NSDictionary.class]) {
            NSDictionary *call = d[@"ruas_call"];
            *caller = [call[@"caller"] isKindOfClass:NSString.class] ? call[@"caller"] : nil;
            *video = [call[@"video"] boolValue];
            *callId = [call[@"call_id"] isKindOfClass:NSString.class] ? call[@"call_id"] : nil;
            *convParams = [call[@"conversation_params"] isKindOfClass:NSString.class] ? call[@"conversation_params"] : nil;
        }
    } @catch (__unused id e) {}
    if (!(*caller).length) *caller = @"ВКонтакте";
}

static void MyDidReceiveIncoming(id self, SEL _cmd, id registry, PKPushPayload *payload,
                                 PKPushType type, void (^completion)(void)) {
    // iOS 13+ hard rule: EVERY VoIP push must report a CallKit incoming call before the completion
    // handler returns, or the system kills the app ("never posted an incoming call …") and then
    // stops delivering VoIP pushes — breaking all future calls.
    //
    // Best path (ring + JOIN + AUDIO): let VK's own -pushRegistry:didReceiveIncomingPush run; it
    // builds a VKCPushPayload and runs +[VKCallsService handleVoIPPushPayload:], which reports the
    // CallKit call and joins the real WebRTC call. But VK only does that if it can parse the payload
    // AND its call service is ready (not always true on a push-triggered cold launch). So we hand the
    // push to VK, then WATCH: if a CallKit call appears within the deadline, VK handled it (audio).
    // If not, WE report our own CallKit ring as a fail-safe so iOS never kills the app (ring only,
    // no audio). The push completion is released only once a call is actually registered, or by the
    // watchdog — never before, which is what previously tripped the kill.
    SetupCallProvider();
    __block BOOL released = NO;
    void (^release)(void) = ^{ if (released) return; released = YES; if (completion) completion(); };

    NSString *caller = nil, *callId = nil, *convParams = nil; BOOL video = NO, isCall = NO;
    ParseVoipCall(payload, &caller, &video, &callId, &convParams, &isCall);
    RSDiag([NSString stringWithFormat:@"voippush-received isCall=%d hasVKHandler=%d", isCall, gOrigIncoming != NULL]);

    if (gOrigIncoming && isCall) {
        // Hand the original payload to VK (may ring + join + audio). Give it a no-op completion; we
        // own releasing the push once a call is actually reported.
        NSLog(@"[VKRelay] VoIP push -> VK handler, watching for CallKit report (call_id=%@)", callId);
        HookVKCallEntryPoints();
        @try { gOrigIncoming(self, _cmd, registry, payload, type, ^{}); }
        @catch (__unused id e) {}
        // Poll for VK's reported call; release as soon as one appears, else fall back to our own ring
        // well before iOS's VoIP kill deadline.
        __block int tries = 0;
        __block void (^poll)(void) = nil;
        poll = ^{
            if (released) return;
            if (AnyIncomingCallActive()) { NSLog(@"[VKRelay] VK reported the call (audio path)"); release(); return; }
            if (++tries >= 17) {   // ~0.4 + 17*0.25 = 4.6 s, under iOS's ~5 s deadline
                NSLog(@"[VKRelay] VK did not report in time -> fail-safe CallKit ring (ring only)");
                ReportIncomingCall(caller, video, callId, convParams, release);
                return;
            }
            dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(0.25 * NSEC_PER_SEC)), dispatch_get_main_queue(), poll);
        };
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(0.4 * NSEC_PER_SEC)), dispatch_get_main_queue(), poll);
        return;
    }

    // No VK handler captured, or not a call payload we recognise: ring ourselves so iOS doesn't kill us.
    NSLog(@"[VKRelay] VoIP push -> own CallKit ring (isCall=%d)", isCall);
    @try { ReportIncomingCall(caller, video, callId, convParams, release); }
    @catch (__unused id e) { release(); }
}

typedef void (*SetDelegateIMP)(id, SEL, id);
static SetDelegateIMP gOrigSetDelegate;
static void MySetRegistryDelegate(id self, SEL _cmd, id delegate) {
    if (gOrigSetDelegate) gOrigSetDelegate(self, _cmd, delegate);
    @try {
        // Our own fallback registry (created in EnsureOwnPushRegistry) uses gCallDelegate — do not
        // swizzle it onto itself; it already implements the delegate methods natively.
        if (delegate != nil && delegate == gCallDelegate) return;
        if (!gVKRegistrySeen) RSDiag(@"vk-registry-seen");
        gVKRegistrySeen = YES;   // a real VK registry exists, so we don't need to add our own
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
        // Do NOT call setDesiredPushTypes: on VK's registry here. VK's PKPushManager already sets it.
        // Forcing it again made SpringBoard lose the app's remote-notification registration on most
        // launches ("without push registration"), so alert pushes never reached the device and the
        // APNs token kept rotating. Toggled on device 2026-11-06: 1/6 delivered with it, 22/22 without.
    } @catch (__unused id e) {}
}

// If VK never created a PKPushRegistry (its call module didn't initialise on this re-signed build),
// the app is NOT registered for VoIP pushes, so iOS delivers no incoming calls and the VoIP token
// the relay holds is stale (observed on device: a VoIP push to the stored token does not wake the
// app). We then create our OWN registry so iOS issues a fresh VoIP token (sent to the relay) and
// delivers VoIP pushes to us → we report the CallKit ring. Only when VK has none, to avoid two
// registries. gCallDelegate handles its callbacks (see the PKPushRegistryDelegate methods above).
static id gOwnRegistry;
static void EnsureOwnPushRegistry(void) {
    if (gVKRegistrySeen) { RSDiag(@"ownreg-skip-vkseen"); return; }
    if (gOwnRegistry != nil) return;
    @try {
        SetupCallProvider();
        PKPushRegistry *reg = [[PKPushRegistry alloc] initWithQueue:dispatch_get_main_queue()];
        reg.delegate = gCallDelegate;
        reg.desiredPushTypes = [NSSet setWithObject:PKPushTypeVoIP];
        gOwnRegistry = reg;
        NSLog(@"[VKRelay] VK created no PKPushRegistry -> created our own to force VoIP registration");
        RSDiag(@"ownreg-created");
    } @catch (id e) { NSLog(@"[VKRelay] own registry create threw %@", e); RSDiag(@"ownreg-threw"); }
}
#endif  // VK_FACEID_ONLY (end of notification/call relay machinery)

// ---- Face-ID loop fix (login + app-lock) ------------------------------------------------------
// VK uses Face ID for both its app-lock AND its saved-account login ("Продолжить"): the credential/
// unlock token lives in a biometric-protected keychain item under VK's ORIGINAL access group, which
// a re-signed build can't read back, so every biometry attempt fails and VK re-prompts Face ID
// forever (login never completes; the app-lock never unlocks). Report biometry as UNAVAILABLE for
// the whole VK process, so VK skips Face ID and uses normal password/SMS login and no lock — no loop.
// The device's own Face ID/passcode still protects the phone.
// Fail every biometry EVALUATION instantly (reply NO, biometryNotAvailable) without ever showing
// Face ID. We hook evaluatePolicy rather than canEvaluatePolicy because VK's login helper, when
// canEvaluatePolicy is NO, returns without calling its completion → the login spinner would hang;
// failing the evaluation instead delivers a result, so VK cleanly falls back to normal login.
typedef void (*EvalIMP)(id, SEL, LAPolicy, NSString *, void (^)(BOOL, NSError *));
static void MyEvaluatePolicy(__unused id self, __unused SEL _cmd, __unused LAPolicy policy,
                             __unused NSString *reason, void (^reply)(BOOL, NSError *)) {
    if (!reply) return;
    NSError *e = [NSError errorWithDomain:@"com.apple.LocalAuthentication" code:-6 /* LAErrorBiometryNotAvailable */ userInfo:nil];
    dispatch_async(dispatch_get_global_queue(QOS_CLASS_USER_INITIATED, 0), ^{ reply(NO, e); });
}
static BOOL MyReturnNO(__unused id self, __unused SEL _cmd) { return NO; }

static void HookBiometrics(void) {
    @try {
        Class la = objc_getClass("LAContext");
        if (la) {
            Method m = class_getInstanceMethod(la, @selector(evaluatePolicy:localizedReason:reply:));
            if (m) method_setImplementation(m, (IMP)MyEvaluatePolicy);
        }
        // VK's own biometry-availability checks: make VK believe biometry is unavailable/not set up,
        // so it never offers or attempts the biometric saved-login or app-lock and stores its session
        // in a NORMAL keychain item (which the compat shim handles), instead of a biometric-ACL item
        // it can't read back on a re-signed build → the login Face-ID loop goes away.
        Class bp = objc_getClass("SAKBiometryInfoProvider");
        if (bp) {
            for (NSString *name in @[@"isBiometryAccessAllowed", @"isBiometryAccessRequested"]) {
                Method m = class_getInstanceMethod(bp, NSSelectorFromString(name));
                if (m) method_setImplementation(m, (IMP)MyReturnNO);
            }
            Method cm = class_getClassMethod(bp, NSSelectorFromString(@"isBiometryAvailable"));
            if (cm) method_setImplementation(cm, (IMP)MyReturnNO);
        }
    } @catch (__unused id e) {}
}

#ifndef VK_FACEID_ONLY
static void HookVoip(void) {
    Method m = class_getInstanceMethod([PKPushRegistry class], @selector(setDelegate:));
    if (!m) return;
    gOrigSetDelegate = (SetDelegateIMP)method_getImplementation(m);
    method_setImplementation(m, (IMP)MySetRegistryDelegate);
}
#endif

// Diagnostic beacon: POST a short event to the relay so we can see the dylib's runtime state on
// device even though iOS 27 redacts app logs and get-task-allow=false blocks house-arrest file pull.
// The relay logs any POST carrying "diag" (no userId needed). Fire-and-forget; works in both builds.
static void RSDiag(NSString *event) {
    @try {
        NSMutableDictionary *b = [NSMutableDictionary dictionary];
        b[@"diag"] = event ?: @"?";
        b[@"build"] = @"diag-20261105";
        b[@"exe"] = [[NSBundle mainBundle] objectForInfoDictionaryKey:@"CFBundleExecutable"] ?: @"?";
        NSString *idfv = [UIDevice currentDevice].identifierForVendor.UUIDString;
        if (idfv) b[@"udid"] = idfv;
        NSData *p = [NSJSONSerialization dataWithJSONObject:b options:0 error:nil];
        if (!p) return;
        NSMutableURLRequest *req = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:@"https://www.ruappstore.com/api/v1/vk-relay/register"]];
        req.HTTPMethod = @"POST";
        [req setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
        req.HTTPBody = p;
        [[[NSURLSession sharedSession] dataTaskWithRequest:req completionHandler:^(NSData *d, NSURLResponse *r, NSError *e) {}] resume];
    } @catch (__unused id e) {}
}

// ---- Notification permission (re-signed VK never prompts) --------------------------------------
// On the re-signed build VK does not request user-notification authorization, so iOS shows no
// permission prompt, no entry appears under Settings → ВКонтакте → Уведомления, and every alert
// push the relay sends is silently dropped (APNs returns 200 but iOS has no authorization to show
// it). We request authorization ourselves once per launch and register for remote notifications so
// the token/registration exists. If VK ever requests too, iOS returns the existing status — no
// double prompt. Runs in both the full and Face-ID-only builds, so every VK customer gets alerts.
// A UNUserNotificationCenter scoped to the REAL signed bundle via the private
// -[UNUserNotificationCenter initWithBundleIdentifier:] SPI (the same one notification extensions
// use). The default currentNotificationCenter resolves the app id through CFBundleIdentifier, which
// the keep-bundle-id shim answers with the vendor id for VK's own callers, so the default center is
// bound to a bundle that is not the installed app — requests/queries there no-op. A real-scoped
// center talks to the installed app's record, so authorization and settings actually take effect.
// Mirrors MAXRelay.RealScopedCenter. Falls back to the default center if the SPI is unavailable.
static id VKRealScopedCenter(void) {
    Class cls = objc_getClass("UNUserNotificationCenter");
    if (!cls) return nil;
    id def = [cls performSelector:@selector(currentNotificationCenter)];
    NSString *realId = RealBundleId();
    SEL initWB = NSSelectorFromString(@"initWithBundleIdentifier:");
    if (realId.length && [cls instancesRespondToSelector:initWB]) {
        @try {
            id raw = [cls alloc];
            id rc = ((id (*)(id, SEL, id))objc_msgSend)(raw, initWB, realId);
            if (rc) return rc;
        } @catch (__unused id e) {}
    }
    return def;
}

// Clear the app-icon unread badge + delivered banners when VK comes to the foreground. The re-signed
// VK no longer drives its own icon badge (its notification pipeline is broken by re-signing — the
// badge froze on whatever it last set, observed stuck on "1" and never changing), so the relay owns
// it: pushes carry aps.badge to raise the number and this clears it on open. Scoped to the REAL
// signed bundle (keep-bundle-id builds answer CFBundleIdentifier with the vendor id, so the default
// center would clear the wrong bundle and leave the installed app's icon frozen). iOS 16+ path with
// a UIApplication fallback for older systems.
static void VKClearBadge(void) {
    @try {
        id center = VKRealScopedCenter();
        if (center) {
            ((void (*)(id, SEL))objc_msgSend)(center, sel_registerName("removeAllDeliveredNotifications"));
            SEL setBadge = sel_registerName("setBadgeCount:withCompletionHandler:");  // iOS 16+, no UIKit
            if ([center respondsToSelector:setBadge])
                ((void (*)(id, SEL, long, id))objc_msgSend)(center, setBadge, 0, (id)nil);
        }
        Class ua = objc_getClass("UIApplication");  // older-iOS badge reset
        if (ua) {
            id app = ((id (*)(id, SEL))objc_msgSend)((id)ua, sel_registerName("sharedApplication"));
            if (app) ((void (*)(id, SEL, long))objc_msgSend)(app, sel_registerName("setApplicationIconBadgeNumber:"), 0);
        }
    } @catch (__unused id e) {}
}

// VK reads its own notification authorization through the DEFAULT center (vendor-bound) and so always
// sees notDetermined — it then shows "enable notifications" prompts and, observed on device, delivers
// notifications only on alternate launches. Answer every getNotificationSettings from a REAL-scoped
// center (authorized) by invoking the original IMP on it (never the swizzled selector → no recursion).
static id gVKRealCenterForSettings;
static void (*gOrigGetSettings)(id, SEL, id);
static void MyGetNotificationSettings(id self, SEL _cmd, id handler) {
    @try {
        if (!gVKRealCenterForSettings) {
            id rc = VKRealScopedCenter();
            Class cls = objc_getClass("UNUserNotificationCenter");
            id def = cls ? [cls performSelector:@selector(currentNotificationCenter)] : nil;
            if (rc && rc != def) gVKRealCenterForSettings = rc;
        }
        if (gVKRealCenterForSettings && gOrigGetSettings) { gOrigGetSettings(gVKRealCenterForSettings, _cmd, handler); return; }
    } @catch (__unused id e) {}
    if (gOrigGetSettings) gOrigGetSettings(self, _cmd, handler);
}

static void HookNotificationSettings(void) {
    @try {
        Class cls = objc_getClass("UNUserNotificationCenter");
        if (!cls) return;
        Method m = class_getInstanceMethod(cls, @selector(getNotificationSettingsWithCompletionHandler:));
        if (m && !gOrigGetSettings) {
            gOrigGetSettings = (void (*)(id, SEL, id))method_getImplementation(m);
            method_setImplementation(m, (IMP)MyGetNotificationSettings);
        }
    } @catch (__unused id e) {}
}

static void RequestNotificationAuthorization(void) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        @try {
            // Request on a REAL-bundle-scoped center so authorization reaches the installed app (the
            // default center is vendor-bound via the keep-bundle-id shim). Mirrors MAXRelay.
            id c = VKRealScopedCenter();
            if (!c) c = (id)[UNUserNotificationCenter currentNotificationCenter];
            UNAuthorizationOptions opts = UNAuthorizationOptionAlert | UNAuthorizationOptionBadge | UNAuthorizationOptionSound;
            [c requestAuthorizationWithOptions:opts completionHandler:^(BOOL granted, NSError *e) {
                NSLog(@"[VKRelay] notification authorization granted=%d err=%@", granted, e ?: @"none");
                RSDiag([NSString stringWithFormat:@"notif-request granted=%d code=%ld dom=%@", granted, (long)e.code, e.domain ?: @"-"]);
                dispatch_async(dispatch_get_main_queue(), ^{
                    @try {
                        BOOL reg = [[UIApplication sharedApplication] isRegisteredForRemoteNotifications];
                        [[UIApplication sharedApplication] registerForRemoteNotifications];
                        RSDiag([NSString stringWithFormat:@"notif-remote wasRegistered=%d", reg]);
                    } @catch (__unused id x) {}
                });
                [c getNotificationSettingsWithCompletionHandler:^(UNNotificationSettings *s) {
                    RSDiag([NSString stringWithFormat:@"notif-after auth=%ld alert=%ld sound=%ld badge=%ld lock=%ld",
                            (long)s.authorizationStatus, (long)s.alertSetting, (long)s.soundSetting, (long)s.badgeSetting, (long)s.lockScreenSetting]);
                }];
            }];
        } @catch (__unused id e) { RSDiag(@"notif-threw"); }
    });
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
    HookBiometrics();      // re-apply: VK's biometry classes are definitely loaded by now
    RequestNotificationAuthorization();  // re-signed VK never prompts; ensure alerts can be shown
    return r;
}

// Disable VK's in-app Face-ID/PIN app-lock on re-signed builds. Its PIN/biometry secret lives in
// the keychain under VK's ORIGINAL access group; a re-signed build can't read it back, so the lock
// is never satisfied and -[AppDelegate showBiometricsIfNeeded] re-presents the BiometricsKit overlay
// (biometry_setup_failure) → Face ID loops endlessly (customer complaints). The phone's own Face ID/
// passcode still protects the device; we only stop the unusable in-app lock loop. No-op the trigger.
static void MyNoShowBiometrics(__unused id self, __unused SEL _cmd) { }

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
            // Kill the re-sign Face-ID app-lock loop (see MyNoShowBiometrics).
            Method bm = class_getInstanceMethod(cls, @selector(showBiometricsIfNeeded));
            if (bm) { method_setImplementation(bm, (IMP)MyNoShowBiometrics); NSLog(@"[VKRelay] app-lock biometrics loop disabled"); }
        }
    } @catch (__unused id e) {}
    if (gOrigSetAppDel) gOrigSetAppDel(self, _cmd, delegate);
}

// The identifiers VK leaves unregistered on re-signed builds (device-confirmed: com.vk.pushes).
// Pre-registered in the constructor so a handler always exists, independent of when/whether VK's
// own registration runs — the crash fires on BACKGROUND launches where VK's init is skipped.
static NSArray *MissingBGTaskIds(void) { return @[@"com.vk.pushes"]; }

static void BridgeBGTasks(void) {
    gBGRegistered = [NSMutableSet set];
    Class sched = objc_getClass("BGTaskScheduler");
    if (sched) {
        Method m = class_getInstanceMethod(sched, @selector(registerForTaskWithIdentifier:usingQueue:launchHandler:));
        if (m) { gOrigBGReg = (BGRegIMP)method_getImplementation(m); method_setImplementation(m, (IMP)MyBGRegister); }
    }
    // Primary fix: register the no-op handler now, before UIApplicationMain. Our register swizzle
    // dedupes, so if VK later registers the same id its call is skipped (no "already registered"
    // throw); VK's other tasks (vkm.refresh) register normally and keep working.
    @try {
        BGTaskScheduler *s = [BGTaskScheduler sharedScheduler];
        for (NSString *tid in MissingBGTaskIds()) {
            BOOL ok = [s registerForTaskWithIdentifier:tid usingQueue:nil launchHandler:^(BGTask *task) {
                [task setTaskCompletedWithSuccess:YES];
            }];
            NSLog(@"[VKRelay] pre-registered BGTask no-op %@ ok=%d", tid, ok);
        }
    } @catch (id e) { NSLog(@"[VKRelay] BGTask pre-register threw %@", e); }
    // Secondary / future-proof: also fill any other permitted id VK skips, at end of launch.
    Method sd = class_getInstanceMethod([UIApplication class], @selector(setDelegate:));
    if (sd) { gOrigSetAppDel = (SetAppDelIMP)method_getImplementation(sd); method_setImplementation(sd, (IMP)MySetAppDelegate); }
}

// Our pipeline injects this dylib into the app's extensions too (zsign -P) so the compat shim that
// rides alongside it can remap App Groups there; the relay hooks themselves (BG tasks, biometrics,
// VoIP, APNs) are for the container app only and would be wrong in a Share/Notification/Broadcast
// extension. Bail out there and let the shim do its work.
static BOOL VKRunningInAppExtension(void) {
    return [[[NSBundle mainBundle] bundlePath] hasSuffix:@".appex"]
        || [[NSBundle mainBundle] objectForInfoDictionaryKey:@"NSExtension"] != nil;
}

__attribute__((constructor))
static void VKRelayInit(void) {
    if (VKRunningInAppExtension()) return;
    RSDiag(@"ctor");
#ifndef VK_FACEID_ONLY
    gQueue = dispatch_queue_create("vkrelay", DISPATCH_QUEUE_SERIAL);
#endif
    BridgeBGTasks();  // must run before UIApplicationMain sets the delegate / VK registers its tasks
    HookBiometrics();  // stop the re-sign Face-ID loop in VK login + app-lock
    HookNotificationSettings();  // answer VK's own notification-status checks from the real-bundle center

    // Notification permission: VK is a UIScene app (SceneDelegate), so the AppDelegate
    // didFinishLaunching hook is unreliable for this — ask on the first didBecomeActive instead,
    // which VK's AppDelegate definitely drives. RequestNotificationAuthorization is dispatch_once,
    // so repeated activations prompt at most once. Shared by both builds, so every VK user is asked.
    [[NSNotificationCenter defaultCenter] addObserverForName:@"UIApplicationDidBecomeActiveNotification"
                                                      object:nil queue:[NSOperationQueue mainQueue]
                                                  usingBlock:^(__unused NSNotification *n) {
        RequestNotificationAuthorization();
        VKClearBadge();  // opening VK clears our relay's unread icon badge + delivered banners
    }];
    // Also try shortly after launch in case the app is already active (cold launch into foreground).
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(3 * NSEC_PER_SEC)),
                   dispatch_get_main_queue(), ^{ RequestNotificationAuthorization(); });

#ifndef VK_FACEID_ONLY
    SetupCallProvider();  // provider ready before the first VoIP push on a cold launch
    HookVoip();
    // A few seconds after the app is up, if VK still has not created a PKPushRegistry, create ours so
    // the app is actually registered for VoIP pushes (otherwise no incoming calls ever arrive).
    [[NSNotificationCenter defaultCenter] addObserverForName:@"UIApplicationDidBecomeActiveNotification"
                                                      object:nil queue:[NSOperationQueue mainQueue]
                                                  usingBlock:^(__unused NSNotification *n) {
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(6 * NSEC_PER_SEC)),
                       dispatch_get_main_queue(), ^{ EnsureOwnPushRegistry(); });
    }];
#endif

#ifdef VKDUMP
    // Dump the decrypted executable once the app has settled (test device only; see VKDump.m).
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(6 * NSEC_PER_SEC)),
                   dispatch_get_global_queue(QOS_CLASS_UTILITY, 0), ^{ VKDumpDecryptedExecutable(); });
#endif

#ifndef VK_FACEID_ONLY
    // The delegate and the login state are only ready after the app finishes launching. Hook the
    // APNs delegate once active, then register; re-register on background/return and token rotation.
    __block BOOL hooked = NO;
    for (NSString *name in @[@"UIApplicationDidBecomeActiveNotification", @"UIApplicationDidEnterBackgroundNotification"]) {
        BOOL isActive = [name isEqualToString:@"UIApplicationDidBecomeActiveNotification"];
        [[NSNotificationCenter defaultCenter] addObserverForName:name object:nil queue:[NSOperationQueue mainQueue]
                                                      usingBlock:^(__unused NSNotification *n) {
            if (!hooked) { HookDelegateApns(); hooked = YES; }
            // Give VK a moment to settle the session into the keychain after a cold launch. A
            // foreground register carries active=YES so the relay clears this user's unread badge.
            dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2 * NSEC_PER_SEC)), gQueue, ^{ Register(isActive); });
        }];
    }
#endif
}
