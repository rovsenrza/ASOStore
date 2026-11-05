// RuStoreCompat — launch-compatibility shim for re-signed apps (Ru App Store).
//
// When we re-sign a customer-provided IPA for one device, the app keeps its own
// code but receives a new identity: bundle ID com.ruappstore.tg…, one App Group
// (group.com.ruappstore.tg…) and keychain groups under our team. The app's own
// code still asks iOS for the vendor's original App Group and team-prefixed
// keychain groups, which this signature does not own. iOS returns nil / an
// errSecMissingEntitlement, and the app terminates right after its launch screen.
//
// This dylib, injected at signing time, repairs those three calls so the app
// uses the identity it was actually granted. It only ever falls back: when the
// real call already works (the app's own sideload fix handled it, or the group
// is genuinely ours) it changes nothing. No ads. Its only network use is the launch
// report in RSTelemetry.m: whether the app opened, crashed or quit at launch, sent to
// ruappstore.com with technical facts only (bundle IDs, versions, device model).
//
// It reads what iOS granted from the app's own entitlements, so it needs no
// knowledge of any specific app and works for every future one the same way.
//
// For apps whose servers check who is calling (Yandex sign-in refuses to send an
// SMS to an unknown app), the signer may also record the vendor's original bundle
// ID in Info.plist (RuStoreOriginalBundleIdentifier). The app's own main-bundle
// lookups then answer with that ID. iOS itself still sees our real signature.
#import <Foundation/Foundation.h>
#import <objc/runtime.h>
#import <Security/Security.h>
#import <UserNotifications/UserNotifications.h>
#import <dlfcn.h>
#import <mach-o/dyld.h>
#import "fishhook.h"

void RSTelemetryStart(NSString *originalBundleID);

static NSArray<NSString *> *gRealGroups;      // App Groups this signature actually holds.
static NSString *gPrimaryGroup;               // The one we map missing groups to.
static NSString *gTeamPrefix;                 // "<TEAMID>." from application-identifier.
static NSSet<NSString *> *gKeychainGroups;    // Keychain groups this signature literally holds.
static NSString *gOriginalBundleID;           // Vendor's bundle ID, when the signer recorded it.
static NSBundle *gMainBundle;
static NSDictionary *gSpoofedInfo;
static BOOL gIsVK;                            // ВКонтакте process: suppress keychain biometry prompts.

static void RSLog(NSString *format, ...) {
#ifdef RUSTORE_COMPAT_DEBUG
    // Also kept in the app's Library/Caches/RuStoreCompat.log, readable over USB without the system log.
    static FILE *file;
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        NSString *path = [NSHomeDirectory() stringByAppendingPathComponent:@"Library/Caches/RuStoreCompat.log"];
        file = fopen(path.fileSystemRepresentation, "a");
    });
    va_list args; va_start(args, format);
    NSString *line = [[NSString alloc] initWithFormat:format arguments:args];
    va_end(args);
    NSLog(@"[RuStoreCompat] %@", line);
    if (file != NULL) {
        @synchronized (NSFileManager.class) {
            fprintf(file, "%.3f %s\n", CFAbsoluteTimeGetCurrent(), line.UTF8String);
            fflush(file);
        }
    }
#else
    (void)format;
#endif
}

#pragma mark - Reading our own granted entitlements

// SecTaskCopyValueForEntitlement is not in the public iOS SDK, so resolve it at
// runtime rather than link against it.
static id RSEntitlement(NSString *key) {
    typedef struct __SecTask *SecTaskRef;
    static SecTaskRef (*createFromSelf)(CFAllocatorRef);
    static CFTypeRef (*copyValue)(SecTaskRef, CFStringRef, CFErrorRef *);
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        createFromSelf = dlsym(RTLD_DEFAULT, "SecTaskCreateFromSelf");
        copyValue = dlsym(RTLD_DEFAULT, "SecTaskCopyValueForEntitlement");
    });
    if (!createFromSelf || !copyValue) return nil;
    SecTaskRef task = createFromSelf(NULL);
    if (!task) return nil;
    id value = CFBridgingRelease(copyValue(task, (__bridge CFStringRef)key, NULL));
    CFRelease(task);
    return value;
}

#pragma mark - App Group container

static NSURL *(*orig_containerURL)(id, SEL, NSString *);

// A signature that holds no App Group at all (its team could not be given one) must still
// launch: a missing group gets a folder inside the app's own container, laid out like a real
// group container. Nothing is shared with the app's extensions then, but the app opens.
static NSURL *RSPrivateGroupContainer(NSString *group) {
    NSString *name = [group stringByReplacingOccurrencesOfString:@"/" withString:@"_"];
    NSString *path = [NSHomeDirectory() stringByAppendingPathComponent:[@"Library/RuStoreGroups" stringByAppendingPathComponent:name]];
    static NSMutableSet<NSString *> *prepared;
    static dispatch_once_t once;
    dispatch_once(&once, ^{ prepared = [NSMutableSet set]; });
    @synchronized (prepared) {
        if (![prepared containsObject:path]) {
            for (NSString *sub in @[@"Library/Caches", @"Library/Preferences", @"Library/Application Support"]) {
                [NSFileManager.defaultManager createDirectoryAtPath:[path stringByAppendingPathComponent:sub]
                                        withIntermediateDirectories:YES attributes:nil error:NULL];
            }
            [prepared addObject:path];
        }
    }
    return [NSURL fileURLWithPath:path isDirectory:YES];
}

static NSURL *rs_containerURL(id self, SEL _cmd, NSString *group) {
    NSURL *url = orig_containerURL(self, _cmd, group);
    if (url != nil || group == nil) return url;
    if (gPrimaryGroup == nil) {
        NSURL *local = RSPrivateGroupContainer(group);
        RSLog(@"container for %@ -> private %@", group, local.path);
        return local;
    }
    if ([gRealGroups containsObject:group]) return url; // genuinely ours; nil is real
    NSURL *mapped = orig_containerURL(self, _cmd, gPrimaryGroup);
    RSLog(@"container for %@ -> %@", group, mapped.path);
    return mapped ?: url;
}

#pragma mark - Shared NSUserDefaults suite

static id (*orig_initWithSuite)(id, SEL, NSString *);

static id rs_initWithSuite(id self, SEL _cmd, NSString *suite) {
    if (suite != nil && gPrimaryGroup != nil && [suite hasPrefix:@"group."]
        && ![gRealGroups containsObject:suite]) {
        RSLog(@"defaults suite %@ -> %@", suite, gPrimaryGroup);
        return orig_initWithSuite(self, _cmd, gPrimaryGroup);
    }
    return orig_initWithSuite(self, _cmd, suite);
}

#pragma mark - Keychain access groups

// The app shares keychain items under groups the vendor's signature held (its own
// team prefix, or ours plus a name like "<TEAM>.ru.yandex.mobile.auth" that it
// builds at runtime). iOS matches access groups against the entitlement as exact
// strings, and ours is the literal "<TEAM>.*", so any such group fails with
// errSecMissingEntitlement. Drop every group we do not literally hold, so the item
// lands in our default group.
static CFDictionaryRef RSRewriteQuery(CFDictionaryRef query) {
    if (query == NULL || gKeychainGroups == nil) return NULL;
    NSDictionary *q = (__bridge NSDictionary *)query;
    id group = q[(__bridge id)kSecAttrAccessGroup];
    if (![group isKindOfClass:NSString.class]) return NULL;
    if ([gKeychainGroups containsObject:group]) return NULL;
    RSLog(@"keychain group %@ -> default", group);
    NSMutableDictionary *rewritten = [q mutableCopy];
    [rewritten removeObjectForKey:(__bridge id)kSecAttrAccessGroup];
    return (CFDictionaryRef)CFBridgingRetain(rewritten);
}

// The real functions, bound in this image (dyld never interposes the interposer itself).
// volatile keeps them as pointers in __data: without it the compiler calls through this
// image's GOT, and a vendor sideload patch that fishhooks every image (blatantsPatch.dylib
// in Raiffeisen and ~90 other catalog IPAs) points that GOT back at its own hook, which
// calls ours again until the stack overflows at launch.
static OSStatus (* volatile orig_SecItemAdd)(CFDictionaryRef, CFTypeRef *) = SecItemAdd;
static OSStatus (* volatile orig_SecItemCopyMatching)(CFDictionaryRef, CFTypeRef *) = SecItemCopyMatching;
static OSStatus (* volatile orig_SecItemUpdate)(CFDictionaryRef, CFDictionaryRef) = SecItemUpdate;
static OSStatus (* volatile orig_SecItemDelete)(CFDictionaryRef) = SecItemDelete;
// fishhook reports what it replaced here; the values are never used (they may already be interposed).
static void *gRebindUnused[4];

// Two groups the app keeps apart now share our default one, so a second add of
// the same item reports a duplicate. The app means to store it: replace the old one.
static OSStatus RSReplaceDuplicate(CFDictionaryRef rewritten, CFTypeRef *result) {
    NSDictionary *q = (__bridge NSDictionary *)rewritten;
    NSMutableDictionary *match = [NSMutableDictionary dictionary];
    for (id key in @[(__bridge id)kSecClass, (__bridge id)kSecAttrService, (__bridge id)kSecAttrAccount,
                     (__bridge id)kSecAttrServer, (__bridge id)kSecAttrSynchronizable]) {
        if (q[key] != nil) match[key] = q[key];
    }
    if (match.count < 2) return errSecDuplicateItem; // too vague to delete safely
    orig_SecItemDelete((__bridge CFDictionaryRef)match);
    return orig_SecItemAdd(rewritten, result);
}

// Debug builds trace every keychain call: which item, which group, and what iOS answered.
static void RSTrace(const char *call, CFDictionaryRef query, BOOL rewritten, OSStatus status) {
#ifdef RUSTORE_COMPAT_DEBUG
    NSDictionary *q = (__bridge NSDictionary *)query;
    RSLog(@"%s status=%d rewritten=%d class=%@ service=%@ account=%@ group=%@ accessible=%@ token=%@", call, (int)status,
          rewritten, q[(__bridge id)kSecClass], q[(__bridge id)kSecAttrService], q[(__bridge id)kSecAttrAccount],
          q[(__bridge id)kSecAttrAccessGroup], q[(__bridge id)kSecAttrAccessible], q[(__bridge id)kSecAttrTokenID]);
#else
    (void)call; (void)query; (void)rewritten; (void)status;
#endif
}

static OSStatus rs_SecItemAdd(CFDictionaryRef query, CFTypeRef *result) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemAdd(r ?: query, result);
    if (r && status == errSecDuplicateItem) status = RSReplaceDuplicate(r, result);
    RSTrace("add", query, r != NULL, status);
    if (r) CFRelease(r);
    return status;
}
// ВКонтакте never used Face ID, yet a re-signed build can hold a login/app-lock keychain item
// whose access-control still demands biometry; VK reads it directly (not via its biometry provider,
// so the SAKBiometryInfoProvider hooks cannot stop it), and every read makes iOS put up Face ID —
// the endless prompt customers report. For VK ONLY, on READS ONLY, tell the keychain to skip
// auth-requiring items instead of prompting (kSecUseAuthenticationUISkip); VK falls back to normal
// password/SMS login and no Face ID is shown. Two hard limits learned the hard way:
//   1. READS ONLY — never add/update/delete. UISkip on the write path broke VK photo upload.
//   2. NEVER touch the session token (com.vk.passepartout.authorizedUserSession*). It is NOT
//      biometric and VK/photo upload read it constantly; skipping or altering it breaks uploads.
// Every other app is untouched (banking apps keep real biometry). Returns a retained copy, or NULL.
static CFDictionaryRef RSVKSkipAuthUIForRead(CFDictionaryRef base) {
    if (!gIsVK || base == NULL) return NULL;
    NSDictionary *q = (__bridge NSDictionary *)base;
    if (q[(__bridge id)kSecUseAuthenticationUI] != nil) return NULL; // caller was explicit; respect it
    id svc = q[(__bridge id)kSecAttrService];
    if ([svc isKindOfClass:NSString.class] && [svc hasPrefix:@"com.vk.passepartout"]) return NULL; // token: leave alone
    NSMutableDictionary *m = [q mutableCopy];
    m[(__bridge id)kSecUseAuthenticationUI] = (__bridge id)kSecUseAuthenticationUISkip;
    return (CFDictionaryRef)CFBridgingRetain(m);
}

static OSStatus rs_SecItemCopyMatching(CFDictionaryRef query, CFTypeRef *result) {
    CFDictionaryRef r = RSRewriteQuery(query);
    CFDictionaryRef skip = RSVKSkipAuthUIForRead(r ?: query);
    OSStatus status = orig_SecItemCopyMatching(skip ?: r ?: query, result);
    RSTrace("copy", query, r != NULL, status);
    if (skip) CFRelease(skip);
    if (r) CFRelease(r);
    return status;
}
static OSStatus rs_SecItemUpdate(CFDictionaryRef query, CFDictionaryRef attributes) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemUpdate(r ?: query, attributes);
    RSTrace("update", query, r != NULL, status);
    if (r) CFRelease(r);
    return status;
}
static OSStatus rs_SecItemDelete(CFDictionaryRef query) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemDelete(r ?: query);
    RSTrace("delete", query, r != NULL, status);
    if (r) CFRelease(r);
    return status;
}

// Images linked with chained fixups keep their import table in read-only memory on newer
// iOS, which fishhook cannot patch. dyld interposing covers every image in the process.
#define RS_INTERPOSE(replacement, replacee) \
    __attribute__((used)) static const struct { const void *new_fn; const void *old_fn; } _rs_interpose_##replacee \
    __attribute__((section("__DATA,__interpose"))) = { (const void *)(unsigned long)&replacement, (const void *)(unsigned long)&replacee }
RS_INTERPOSE(rs_SecItemAdd, SecItemAdd);
RS_INTERPOSE(rs_SecItemCopyMatching, SecItemCopyMatching);
RS_INTERPOSE(rs_SecItemUpdate, SecItemUpdate);
RS_INTERPOSE(rs_SecItemDelete, SecItemDelete);

#pragma mark - Hide injected images from the app's own anti-tamper

// RASP-hardened apps (VK's Orwell/Analytics) enumerate loaded images with _dyld_image_count /
// _dyld_get_image_name and flag the session as tampered when they spot a dylib we injected
// (RuStoreCompat / VKRelay / libsubstrate). A flagged VK session still *reads* (music, text,
// notifications) but its server blocks *high-risk* actions: sending voice messages, photos and
// video fail with a red "!". OrwellVK — the mod whose uploads work — hooks _dyld_get_image_name
// to make its injected images report an innocuous name.
//
// IMPORTANT: we do NOT touch _dyld_image_count or the per-index header/slide. Collapsing the
// image count (an earlier attempt) crashed VK at launch: the ObjC/Swift runtime and dyld cache
// the real count and then index by it, so a shortened count / shifted indices / a NULL header
// faults. Keeping the count and every index exactly as dyld reports them, and only rewriting the
// *name string* for our own images, leaves every consumer consistent while still defeating a
// name-based scan. Gated behind gHideImages (VK only); a pure pass-through for every other app.
static BOOL gHideImages = NO;
// The real function, captured at static init (dyld never interposes the interposer's own image, so
// this binds to libdyld's _dyld_get_image_name — NOT our rs_dyld_name). Do NOT resolve it with
// dlsym(RTLD_NEXT) at call time: RTLD_NEXT searches images loaded AFTER ours, but libdyld loads
// BEFORE us, so dlsym returns NULL (or, worse, our own interposed symbol) and the first call to
// _dyld_get_image_name faults / recurses — crashing every shimmed app at launch. volatile, like
// orig_SecItemAdd above, keeps it a __data pointer out of a fishhookable GOT slot.
static const char *(* volatile o_dyld_name)(uint32_t) = _dyld_get_image_name;
// A real, innocuous image path handed back in place of ours. libMobileGestalt is always present.
static const char *const kRSDecoyImageName = "/usr/lib/libMobileGestalt.dylib";

static BOOL RSImageHidden(const char *name) {
    if (name == NULL) return NO;
    const char *b = strrchr(name, '/'); b = b ? b + 1 : name;
    return strstr(b, "RuStoreCompat") || strstr(b, "VKRelay")
        || strstr(b, "substrate")     || strstr(b, "Substrate")
        || strstr(b, "libhooker")     || strstr(b, "TweakInject")
        || strstr(name, "/MobileSubstrate");
}

static const char *rs_dyld_name(uint32_t i) {
    const char *name = o_dyld_name(i);
    if (gHideImages && RSImageHidden(name)) return kRSDecoyImageName;
    return name;
}
RS_INTERPOSE(rs_dyld_name, _dyld_get_image_name);

#pragma mark - Original bundle ID

static NSString *(*orig_bundleIdentifier)(id, SEL);
static NSDictionary *(*orig_infoDictionary)(id, SEL);
static id (*orig_objectForInfoKey)(id, SEL, NSString *);

static NSString *rs_bundleIdentifier(id self, SEL _cmd) {
    // For VK, the notification / push system (UNUserNotificationCenter → usernotificationsd) reads
    // the main-bundle id through THIS getter and refuses notifications when it is not the installed
    // app's real id: requestAuthorization returns UNErrorCodeNotificationsNotAllowed(1) and the
    // device drops every alert AND VoIP push (device-confirmed). So return the REAL id from the
    // getter for VK; its music/audio catalog reads the vendor id through CFBundleIdentifier
    // (infoDictionary / objectForInfoDictionaryKey), which stay spoofed below — so music keeps
    // working while notifications and calls start working.
    if (self == gMainBundle && !gIsVK) return gOriginalBundleID;
    return orig_bundleIdentifier(self, _cmd);
}

// For VK, UNUserNotificationCenter resolves the app's bundle id through CFBundleIdentifier (the
// Info.plist dict, not just the getter) and refuses notifications when it is the vendor id. But VK's
// music/audio catalog reads the SAME CFBundleIdentifier and needs the vendor id. They only clash
// during the one-time authorization: so keep CFBundleIdentifier REAL until notification permission
// is decided (gVKUseRealId), then flip to the vendor id for music. Once granted, the authorization
// is stored against the real code-signed id and survives the flip. See RSWatchNotificationAuth.
static BOOL gVKUseRealId = YES;   // VK only: real CFBundleIdentifier until UN auth is determined

static NSDictionary *rs_infoDictionary(id self, SEL _cmd) {
    NSDictionary *info = orig_infoDictionary(self, _cmd);
    if (self != gMainBundle || info == nil) return info;
    if (gIsVK && gVKUseRealId) return info;   // real id while notification auth is pending
    @synchronized (gMainBundle) {
        if (gSpoofedInfo == nil) {
            NSMutableDictionary *copy = [info mutableCopy];
            copy[@"CFBundleIdentifier"] = gOriginalBundleID;
            gSpoofedInfo = [copy copy];
        }
        return gSpoofedInfo;
    }
}

static id rs_objectForInfoKey(id self, SEL _cmd, NSString *key) {
    if (self == gMainBundle && [key isEqualToString:@"CFBundleIdentifier"]) {
        if (gIsVK && gVKUseRealId) return orig_objectForInfoKey(self, _cmd, key);  // real while pending
        return gOriginalBundleID;
    }
    return orig_objectForInfoKey(self, _cmd, key);
}

// Keep CFBundleIdentifier at the REAL id until the user answers the notification prompt (so UN
// registers the app under its installed identity and authorization sticks), then flip to the vendor
// id so VK's music catalog loads. A safety cap flips it anyway so music never stays broken if the
// prompt is ignored. VK only.
static void RSWatchNotificationAuth(void) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        // Safety: never hold the real id longer than this (music would stay broken otherwise).
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(120 * NSEC_PER_SEC)),
                       dispatch_get_main_queue(), ^{ gVKUseRealId = NO; });
        __block void (^check)(void) = nil;
        check = ^{
            if (!gVKUseRealId) return;
            @try {
                [[UNUserNotificationCenter currentNotificationCenter] getNotificationSettingsWithCompletionHandler:^(UNNotificationSettings *s) {
                    if (s.authorizationStatus != UNAuthorizationStatusNotDetermined) {
                        gVKUseRealId = NO;   // decided → music can see the vendor id now
                    } else {
                        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2 * NSEC_PER_SEC)),
                                       dispatch_get_main_queue(), ^{ if (check) check(); });
                    }
                }];
            } @catch (__unused id e) { gVKUseRealId = NO; }
        };
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2 * NSEC_PER_SEC)),
                       dispatch_get_main_queue(), ^{ if (check) check(); });
    });
}

// Once the app sees its original ID, it may look its own bundle up by it
// (`Bundle(identifier: Bundle.main.bundleIdentifier!)!`); iOS registered the bundle
// under our ID and would return nil.
static NSBundle *(*orig_bundleWithIdentifier)(id, SEL, NSString *);

static NSBundle *rs_bundleWithIdentifier(id self, SEL _cmd, NSString *identifier) {
    if ([identifier isEqualToString:gOriginalBundleID]) return gMainBundle;
    return orig_bundleWithIdentifier(self, _cmd, identifier);
}

#pragma mark - CloudKit without an iCloud entitlement

// Apps with their own iCloud sync (VK Video) create a CKContainer while launching.
// A re-signed copy holds no iCloud container, and CloudKit then traps the process.
// Such calls get an inert container that reports "no iCloud account" instead.
static Class gInertContainerClass;
static void RSSwizzle(Class cls, SEL selector, IMP replacement, void *store);

static id rs_inertContainer(void) {
    return class_createInstance(gInertContainerClass, 0);
}

static id rs_defaultContainer(id self, SEL _cmd) { return rs_inertContainer(); }
static id rs_containerWithIdentifier(id self, SEL _cmd, NSString *identifier) { return rs_inertContainer(); }
static id rs_inertNil(id self, SEL _cmd) { return nil; }
static NSString *rs_inertIdentifier(id self, SEL _cmd) { return @""; }

static void rs_inertAccountStatus(id self, SEL _cmd, void (^handler)(NSInteger, NSError *)) {
    if (handler == nil) return;
    dispatch_async(dispatch_get_main_queue(), ^{ handler(3 /* CKAccountStatusNoAccount */, nil); });
}

static void rs_inertFetchUser(id self, SEL _cmd, void (^handler)(id, NSError *)) {
    if (handler == nil) return;
    NSError *error = [NSError errorWithDomain:@"CKErrorDomain" code:9 /* CKErrorNotAuthenticated */ userInfo:nil];
    dispatch_async(dispatch_get_main_queue(), ^{ handler(nil, error); });
}

static void RSInstallInertCloudKit(void) {
    Class container = NSClassFromString(@"CKContainer");
    if (container == Nil) return;
    gInertContainerClass = objc_allocateClassPair(container, "RSInertContainer", 0);
    if (gInertContainerClass == Nil) return;
    class_addMethod(gInertContainerClass, @selector(privateCloudDatabase), (IMP)rs_inertNil, "@@:");
    class_addMethod(gInertContainerClass, @selector(publicCloudDatabase), (IMP)rs_inertNil, "@@:");
    class_addMethod(gInertContainerClass, @selector(sharedCloudDatabase), (IMP)rs_inertNil, "@@:");
    class_addMethod(gInertContainerClass, @selector(containerIdentifier), (IMP)rs_inertIdentifier, "@@:");
    class_addMethod(gInertContainerClass, @selector(accountStatusWithCompletionHandler:), (IMP)rs_inertAccountStatus, "v@:@?");
    class_addMethod(gInertContainerClass, @selector(fetchUserRecordIDWithCompletionHandler:), (IMP)rs_inertFetchUser, "v@:@?");
    objc_registerClassPair(gInertContainerClass);
    static IMP unusedA, unusedB;
    RSSwizzle(object_getClass(container), @selector(defaultContainer), (IMP)rs_defaultContainer, &unusedA);
    RSSwizzle(object_getClass(container), @selector(containerWithIdentifier:), (IMP)rs_containerWithIdentifier, &unusedB);
}

#pragma mark - Roblox forced-upgrade prompt

// Roblox asks its servers whether this client version is still allowed and, when it
// is not, shows a blocking "please upgrade" dialog from handleForceUpgrade:weakSelf:.
// A copy that cannot be updated through the App Store only needs the dialog gone.
static void rs_ignoreForceUpgrade(id self, SEL _cmd, id first, id second) {}
static BOOL rs_refuseForceUpdate(id self, SEL _cmd) { return NO; }

static void RSSuppressForceUpgrade(void) {
    const char *image = _dyld_get_image_name(0);
    if (image == NULL) return;
    unsigned int count = 0;
    const char **names = objc_copyClassNamesForImage(image, &count);
    SEL handle = sel_registerName("handleForceUpgrade:weakSelf:");
    SEL allow = sel_registerName("shouldAllowForceUpdate");
    for (unsigned int i = 0; i < count; i++) {
        Class cls = objc_getClass(names[i]);
        if (cls == Nil) continue;
        Method method = class_getInstanceMethod(cls, handle);
        if (method != NULL && class_getSuperclass(cls) != Nil
            && class_getInstanceMethod(class_getSuperclass(cls), handle) != method) {
            method_setImplementation(method, (IMP)rs_ignoreForceUpgrade);
            RSLog(@"force upgrade dialog disabled in %s", names[i]);
        }
        method = class_getInstanceMethod(cls, allow);
        if (method != NULL && class_getSuperclass(cls) != Nil
            && class_getInstanceMethod(class_getSuperclass(cls), allow) != method) {
            method_setImplementation(method, (IMP)rs_refuseForceUpdate);
        }
    }
    free(names);
}

#pragma mark - Anti-tamper diagnostics (debug builds only)

#ifdef RUSTORE_COMPAT_DEBUG
#include <execinfo.h>
#include <signal.h>
#include <pthread.h>
#include <fcntl.h>
#include <unistd.h>

// Force any jailbreak-detection method to report "not jailbroken". These selectors come from
// ChefPathBase (T-Invest): a re-signed copy must still answer NO so the app does not self-kill.
static BOOL rs_jbFalse(id self, SEL _cmd) { return NO; }
static BOOL rs_jbFalseArg(id self, SEL _cmd, BOOL skip) { (void)skip; return NO; }

static void (*orig_exit)(int);
static void rs_logBacktrace(const char *why) {
    void *frames[64];
    int n = backtrace(frames, 64);
    char **syms = backtrace_symbols(frames, n);
    RSLog(@"=== %s backtrace (%d) ===", why, n);
    for (int i = 0; i < n && syms; i++) RSLog(@"  %s", syms[i]);
    if (syms) free(syms);
}

static void RSBypassJailbreak(void) {
    static const char *zeroArg[] = {"isJailbroken", "isDeviceJailbroken", "isJailbreak", "jailbroken",
                                    "checkJBBySandboxIntegrity", "isJailBroken", "jailbreakStatus", NULL};
    static const char *oneArg[] = {"isJailbrokenWithSkipAdvancedJailbreakValidation:", NULL};
    unsigned int classCount = 0;
    Class *classes = objc_copyClassList(&classCount);
    for (unsigned int c = 0; c < classCount; c++) {
        Class cls = classes[c];
        const char *cn = class_getName(cls);
        if (cn == NULL) continue;
        for (int i = 0; zeroArg[i]; i++) {
            SEL sel = sel_registerName(zeroArg[i]);
            Method m = class_getInstanceMethod(cls, sel);
            if (m != NULL && class_getInstanceMethod(class_getSuperclass(cls), sel) != m) {
                const char *enc = method_getTypeEncoding(m);
                if (enc && (enc[0] == 'B' || enc[0] == 'c' || enc[0] == 'i')) {
                    method_setImplementation(m, (IMP)rs_jbFalse);
                    RSLog(@"jb-bypass %s -%s -> NO", cn, zeroArg[i]);
                }
            }
        }
        for (int i = 0; oneArg[i]; i++) {
            SEL sel = sel_registerName(oneArg[i]);
            Method m = class_getInstanceMethod(cls, sel);
            if (m != NULL && class_getInstanceMethod(class_getSuperclass(cls), sel) != m) {
                method_setImplementation(m, (IMP)rs_jbFalseArg);
                RSLog(@"jb-bypass %s -%s -> NO", cn, oneArg[i]);
            }
        }
    }
    if (classes) free(classes);
}

static void rs_atexitLog(void) { rs_logBacktrace("atexit"); }

// Main-thread sampler: after a delay, signal the main thread to dump its own stack, so a hang on
// the launch screen shows where it is stuck.
static pthread_t gMainThread;
static int gLogFd = -1;

static void rs_sigusr1(int sig) {
    (void)sig;
    const char *hdr = "=== SIGUSR1 main-thread sample ===\n";
    if (gLogFd >= 0) write(gLogFd, hdr, strlen(hdr));
    void *frames[64];
    int n = backtrace(frames, 64);
    if (gLogFd >= 0) backtrace_symbols_fd(frames, n, gLogFd);
}


// Debug: log the anti-tamper's environment probes (NO behavior change yet) with thread id, so we can
// see exactly which C primitive it uses to conclude "tampered", then neutralize only that one.
#include <sys/sysctl.h>
#include <sys/stat.h>
#include <sys/mount.h>
extern int ptrace(int, pid_t, caddr_t, int);
static void rs_probe(const char *fmt, ...) {
    if (gLogFd < 0) return;
    char buf[400]; uint64_t tid = 0; pthread_threadid_np(NULL, &tid);
    int pre = snprintf(buf, sizeof buf, "PROBE t=%llu ", (unsigned long long)tid);
    va_list ap; va_start(ap, fmt); vsnprintf(buf+pre, sizeof buf-pre, fmt, ap); va_end(ap);
    size_t n = strlen(buf); if (n < sizeof buf - 1) { buf[n]='\n'; buf[n+1]=0; }
    write(gLogFd, buf, strlen(buf));
}
static int (*o_sysctl)(int *, u_int, void *, size_t *, void *, size_t);
static int rs_sysctl(int *name, u_int nlen, void *op, size_t *os, void *np, size_t nl) {
    if (!o_sysctl) o_sysctl = (void*)dlsym(RTLD_NEXT, "sysctl");
    int r = o_sysctl(name, nlen, op, os, np, nl);
    if (nlen >= 4 && name[0]==CTL_KERN && name[1]==KERN_PROC) rs_probe("sysctl KERN_PROC pid=%d -> r=%d", name[3], r);
    return r;
}
static int (*o_sysctlbyname)(const char *, void *, size_t *, void *, size_t);
static int rs_sysctlbyname(const char *nm, void *op, size_t *os, void *np, size_t nl) {
    if (!o_sysctlbyname) o_sysctlbyname = (void*)dlsym(RTLD_NEXT, "sysctlbyname");
    rs_probe("sysctlbyname %s", nm ?: "?"); return o_sysctlbyname(nm, op, os, np, nl);
}
static char *(*o_getenv)(const char *);
static char *rs_getenv(const char *k) { if (!o_getenv) o_getenv=(void*)dlsym(RTLD_NEXT,"getenv"); char *v = o_getenv(k); if (k && (strstr(k,"DYLD")||strstr(k,"INSERT")||strstr(k,"_MSSafeMode"))) rs_probe("getenv %s -> %s", k, v?v:"(null)"); return v; }
static int (*o_access)(const char *, int);
static int rs_access(const char *pth, int m) { if (!o_access) o_access=(void*)dlsym(RTLD_NEXT,"access"); int r=o_access(pth,m); if (pth && (strstr(pth,"Cydia")||strstr(pth,"jail")||strstr(pth,"/bin/")||strstr(pth,"substrate")||strstr(pth,"ssh")||strstr(pth,"apt")||strstr(pth,"/private/var/stash")||strstr(pth,"Sileo")||strstr(pth,"Zebra"))) rs_probe("access %s -> %d", pth, r); return r; }
static int (*o_stat)(const char *, void *);
static int rs_stat(const char *pth, void *st) { if (!o_stat) o_stat=(void*)dlsym(RTLD_NEXT,"stat"); int r=o_stat(pth,st); if (pth && (strstr(pth,"Cydia")||strstr(pth,"jail")||strstr(pth,"/bin/")||strstr(pth,"substrate")||strstr(pth,"Sileo")||strstr(pth,"apt"))) rs_probe("stat %s -> %d", pth, r); return r; }
static int (*o_statfs)(const char *, void *);
static int rs_statfs(const char *pth, void *b) { if (!o_statfs) o_statfs=(void*)dlsym(RTLD_NEXT,"statfs"); int r=o_statfs(pth,b); if (pth && (strcmp(pth,"/")==0 || strstr(pth,"/private"))) rs_probe("statfs %s -> %d", pth, r); return r; }
static pid_t (*o_fork)(void);
static pid_t rs_fork(void) { if (!o_fork) o_fork=(void*)dlsym(RTLD_NEXT,"fork"); rs_probe("fork() called"); return o_fork(); }
static long (*o_ptrace)(int, pid_t, void *, int);
static long rs_ptrace(int req, pid_t pid, void *a, int d) { if (!o_ptrace) o_ptrace=(void*)dlsym(RTLD_NEXT,"ptrace"); rs_probe("ptrace req=%d", req); return o_ptrace(req,pid,a,d); }
RS_INTERPOSE(rs_sysctl, sysctl);
RS_INTERPOSE(rs_sysctlbyname, sysctlbyname);
RS_INTERPOSE(rs_getenv, getenv);
RS_INTERPOSE(rs_access, access);
RS_INTERPOSE(rs_stat, stat);
RS_INTERPOSE(rs_statfs, statfs);
RS_INTERPOSE(rs_fork, fork);
RS_INTERPOSE(rs_ptrace, ptrace);

// Debug: catch any process-termination call, log the caller, and PARK the calling thread instead
// of dying. If the app then keeps running, the kill was a background anti-tamper exit we can scope.
static void rs_logFd(const char *why) {
    if (gLogFd >= 0) { write(gLogFd, why, strlen(why)); write(gLogFd, "\n", 1); }
    void *frames[64]; int n = backtrace(frames, 64);
    if (gLogFd >= 0) backtrace_symbols_fd(frames, n, gLogFd);
}
static void rs_park(const char *why) { rs_logFd(why); for (;;) pause(); }
static void rs_exit(int code) { (void)code; rs_park("=== exit() parked ==="); }
static void rs_uexit(int code) { (void)code; rs_park("=== _exit() parked ==="); }
static void rs_Exit(int code) { (void)code; rs_park("=== _Exit() parked ==="); }
static void rs_abort(void) { rs_park("=== abort() parked ==="); }
RS_INTERPOSE(rs_exit, exit);
RS_INTERPOSE(rs_uexit, _exit);
RS_INTERPOSE(rs_Exit, _Exit);
RS_INTERPOSE(rs_abort, abort);

#include <mach/mach.h>
#include <dlfcn.h>

static void rs_fdprint(const char *s) { if (gLogFd >= 0) write(gLogFd, s, strlen(s)); }

// Walk a frame-pointer chain from (pc, fp) and log each return address with its symbol.
static void rs_unwind(uint64_t pc, uint64_t fp) {
    char line[512];
    for (int depth = 0; depth < 40; depth++) {
        Dl_info info; const char *sym = "?"; const char *img = "?";
        if (pc && dladdr((void *)(uintptr_t)pc, &info)) {
            sym = info.dli_sname ? info.dli_sname : "?";
            img = info.dli_fname ? strrchr(info.dli_fname, '/') + 1 : "?";
        }
        snprintf(line, sizeof line, "    0x%016llx %s %s\n", (unsigned long long)pc, img, sym);
        rs_fdprint(line);
        if (!fp) break;
        uint64_t next[2] = {0, 0}; // [0]=saved fp, [1]=saved lr
        vm_size_t got = 0;
        if (vm_read_overwrite(mach_task_self(), (vm_address_t)fp, sizeof next, (vm_address_t)next, &got) != KERN_SUCCESS || got != sizeof next) break;
        if (next[1] == 0 || next[0] <= fp) break;
        pc = next[1]; fp = next[0];
    }
}

static void *rs_samplerThread(void *arg) {
    (void)arg;
    struct timespec ts = {.tv_sec = 14, .tv_nsec = 0};
    nanosleep(&ts, NULL);
    rs_fdprint("=== ALL-THREAD SAMPLE ===\n");
    thread_act_array_t threads; mach_msg_type_number_t count = 0;
    if (task_threads(mach_task_self(), &threads, &count) != KERN_SUCCESS) return NULL;
    thread_t me = mach_thread_self();
    for (mach_msg_type_number_t i = 0; i < count; i++) {
        if (threads[i] == me) continue;
        if (thread_suspend(threads[i]) != KERN_SUCCESS) continue;
        arm_thread_state64_t st; mach_msg_type_number_t sc = ARM_THREAD_STATE64_COUNT;
        if (thread_get_state(threads[i], ARM_THREAD_STATE64, (thread_state_t)&st, &sc) == KERN_SUCCESS) {
            char hdr[64]; snprintf(hdr, sizeof hdr, "-- thread %u --\n", (unsigned)i); rs_fdprint(hdr);
            rs_unwind((uint64_t)arm_thread_state64_get_pc(st), (uint64_t)arm_thread_state64_get_fp(st));
        }
        thread_resume(threads[i]);
    }
    mach_port_deallocate(mach_task_self(), me);
    for (mach_msg_type_number_t i = 0; i < count; i++) mach_port_deallocate(mach_task_self(), threads[i]);
    rs_fdprint("=== END ALL-THREAD SAMPLE ===\n");
    return NULL;
}

static void RSInstallAntiTamperDiag(void) {
    o_sysctl=(void*)dlsym(RTLD_DEFAULT,"sysctl"); o_sysctlbyname=(void*)dlsym(RTLD_DEFAULT,"sysctlbyname");
    o_getenv=(void*)dlsym(RTLD_DEFAULT,"getenv"); o_access=(void*)dlsym(RTLD_DEFAULT,"access");
    o_stat=(void*)dlsym(RTLD_DEFAULT,"stat"); o_statfs=(void*)dlsym(RTLD_DEFAULT,"statfs");
    o_fork=(void*)dlsym(RTLD_DEFAULT,"fork"); o_ptrace=(void*)dlsym(RTLD_DEFAULT,"ptrace");
    RSBypassJailbreak();
    orig_exit = (void (*)(int))dlsym(RTLD_DEFAULT, "exit");
    atexit(rs_atexitLog);
    // The dylib constructor runs on the main thread during dyld load.
    gMainThread = pthread_self();
    NSString *path = [NSHomeDirectory() stringByAppendingPathComponent:@"Library/Caches/RuStoreCompat.log"];
    gLogFd = open(path.fileSystemRepresentation, O_WRONLY | O_APPEND | O_CREAT, 0644);
    struct sigaction sa; memset(&sa, 0, sizeof(sa));
    sa.sa_handler = rs_sigusr1; sa.sa_flags = SA_RESTART; sigemptyset(&sa.sa_mask);
    sigaction(SIGUSR1, &sa, NULL);
    pthread_t t; pthread_create(&t, NULL, rs_samplerThread, NULL); pthread_detach(t);
}
#endif

#pragma mark - VPN tunnel provider bundle ID

// A VPN app names its tunnel extension in the NETunnelProviderProtocol it saves. Many build that
// ID from their original bundle ID (or a sideload patch hands them the original one), so on a
// re-signed copy iOS looks for "<vendor>.PacketTunnelProvider", finds no such extension ("Found 0
// registrations") and the VPN never starts. Point any ID that is not one of our own embedded
// tunnel extensions at the one we do ship: same last name component, else the only one there is.
static NSDictionary<NSString *, NSString *> *gTunnelExtensions; // bundle ID -> extension point
static void (*orig_setProviderBundleIdentifier)(id, SEL, NSString *);

static NSString *RSTunnelProviderFor(NSString *requested) {
    if (![requested isKindOfClass:NSString.class] || gTunnelExtensions[requested] != nil) return requested;
    NSString *name = requested.pathExtension; // after the last dot: "PacketTunnelProvider"
    for (NSString *ident in gTunnelExtensions) {
        if (name.length > 0 && [ident.pathExtension isEqualToString:name]) return ident;
    }
    return gTunnelExtensions.count == 1 ? gTunnelExtensions.allKeys.firstObject : requested;
}

static void rs_setProviderBundleIdentifier(id self, SEL _cmd, NSString *requested) {
    NSString *mapped = RSTunnelProviderFor(requested);
    if (mapped != requested) RSLog(@"tunnel provider %@ -> %@", requested, mapped);
    orig_setProviderBundleIdentifier(self, _cmd, mapped);
}

// NetworkExtension may load after us: try on every image until the class exists.
static void RSTunnelAddImage(const struct mach_header *header, intptr_t slide) {
    if (orig_setProviderBundleIdentifier != NULL) return;
    Class cls = objc_getClass("NETunnelProviderProtocol");
    if (cls != Nil) {
        RSSwizzle(cls, @selector(setProviderBundleIdentifier:), (IMP)rs_setProviderBundleIdentifier, &orig_setProviderBundleIdentifier);
    }
}

static void RSInstallTunnelProviderRemap(void) {
    NSMutableDictionary *found = [NSMutableDictionary dictionary];
    NSString *plugins = NSBundle.mainBundle.builtInPlugInsPath;
    for (NSString *entry in [NSFileManager.defaultManager contentsOfDirectoryAtPath:plugins error:NULL]) {
        if (![entry.pathExtension isEqualToString:@"appex"]) continue;
        // Straight from disk: a sideload patch may rewrite what NSBundle reports.
        NSDictionary *info = [NSDictionary dictionaryWithContentsOfFile:
                              [[plugins stringByAppendingPathComponent:entry] stringByAppendingPathComponent:@"Info.plist"]];
        id ident = info[@"CFBundleIdentifier"];
        id point = [info[@"NSExtension"] isKindOfClass:NSDictionary.class] ? info[@"NSExtension"][@"NSExtensionPointIdentifier"] : nil;
        if ([ident isKindOfClass:NSString.class] && ([point isEqual:@"com.apple.networkextension.packet-tunnel"]
                                                    || [point isEqual:@"com.apple.networkextension.app-proxy"])) {
            found[ident] = point;
        }
    }
    if (found.count == 0) return;
    gTunnelExtensions = [found copy];
    RSLog(@"tunnel extensions %@", gTunnelExtensions.allKeys);
    _dyld_register_func_for_add_image(RSTunnelAddImage);
}

#pragma mark - Keychain hooks

static const char *gAppRoot;

static struct rebinding gRebindings[4];

// Called by dyld for every image, loaded now or later. Only images inside the app bundle are hooked.
static void RSAddImage(const struct mach_header *header, intptr_t slide) {
    Dl_info info;
    if (gAppRoot == NULL || dladdr(header, &info) == 0 || info.dli_fname == NULL) return;
    if (strncmp(info.dli_fname, gAppRoot, strlen(gAppRoot)) != 0) return;
    rebind_symbols_image((void *)header, slide, gRebindings, 4);
}

#pragma mark - Install

static void RSSwizzle(Class cls, SEL selector, IMP replacement, void *store) {
    Method method = class_getInstanceMethod(cls, selector);
    if (method == NULL) return;
    *(IMP *)store = method_getImplementation(method);
    method_setImplementation(method, replacement);
}

__attribute__((constructor))
static void RuStoreCompatInit(void) {
    @autoreleasepool {
        gRealGroups = RSEntitlement(@"com.apple.security.application-groups");
        if (![gRealGroups isKindOfClass:NSArray.class]) gRealGroups = @[];
        gPrimaryGroup = gRealGroups.firstObject;

        NSString *appID = RSEntitlement(@"application-identifier");
        if ([appID isKindOfClass:NSString.class]) {
            NSRange dot = [appID rangeOfString:@"."];
            if (dot.location != NSNotFound) gTeamPrefix = [appID substringToIndex:dot.location + 1];
        }
        NSMutableSet *keychainGroups = [NSMutableSet set];
        id declared = RSEntitlement(@"keychain-access-groups");
        if ([declared isKindOfClass:NSArray.class]) [keychainGroups addObjectsFromArray:declared];
        if ([appID isKindOfClass:NSString.class]) [keychainGroups addObject:appID];
        [keychainGroups addObjectsFromArray:gRealGroups];
        gKeychainGroups = [keychainGroups copy];
        gMainBundle = NSBundle.mainBundle;
        id original = [gMainBundle objectForInfoDictionaryKey:@"RuStoreOriginalBundleIdentifier"];
        if ([original isKindOfClass:NSString.class] && [(NSString *)original length] > 0
            && ![original isEqualToString:gMainBundle.bundleIdentifier]) {
            gOriginalBundleID = [original copy];
        }
        // VK client? Executable is "VKClient" whatever bundle we re-signed under; also honour a
        // recorded vendor bundle com.vk.vkclient*. Used only to skip keychain biometry on reads.
        NSString *exe = [gMainBundle objectForInfoDictionaryKey:@"CFBundleExecutable"];
        gIsVK = ([exe isKindOfClass:NSString.class] && [exe isEqualToString:@"VKClient"])
            || (gOriginalBundleID != nil && [gOriginalBundleID hasPrefix:@"com.vk.vkclient"]);
        RSLog(@"groups=%@ primary=%@ team=%@ original=%@ isVK=%d", gRealGroups, gPrimaryGroup, gTeamPrefix, gOriginalBundleID, gIsVK);
        // VK's anti-tamper scans the loaded-image list and, finding our injected dylibs, blocks
        // high-risk actions server-side (sending voice/photo/video fail with a red "!") while still
        // allowing reads. Hide our images from its _dyld_* enumeration — VK only, like the mod.
        if (gIsVK) { gHideImages = YES; RSWatchNotificationAuth(); }
        // Before the bundle hooks below, so the report names our real bundle ID.
        RSTelemetryStart(gOriginalBundleID);
#ifdef RUSTORE_COMPAT_DEBUG
        RSInstallAntiTamperDiag();
#endif

        if (gOriginalBundleID != nil) {
            RSSwizzle(NSBundle.class, @selector(bundleIdentifier), (IMP)rs_bundleIdentifier, &orig_bundleIdentifier);
            RSSwizzle(NSBundle.class, @selector(infoDictionary), (IMP)rs_infoDictionary, &orig_infoDictionary);
            RSSwizzle(NSBundle.class, @selector(objectForInfoDictionaryKey:), (IMP)rs_objectForInfoKey, &orig_objectForInfoKey);
            RSSwizzle(object_getClass(NSBundle.class), @selector(bundleWithIdentifier:), (IMP)rs_bundleWithIdentifier, &orig_bundleWithIdentifier);
        }

        if ([gOriginalBundleID isEqualToString:@"com.roblox.robloxmobile"]) {
            RSSuppressForceUpgrade();
        }

        id icloudServices = RSEntitlement(@"com.apple.developer.icloud-services");
        id icloudContainers = RSEntitlement(@"com.apple.developer.icloud-container-identifiers");
        if (icloudServices == nil || ![icloudContainers isKindOfClass:NSArray.class] || [icloudContainers count] == 0) {
            RSInstallInertCloudKit();
        }

        RSInstallTunnelProviderRemap();

        // Also without any group of our own: then missing groups get a private folder.
        RSSwizzle(NSFileManager.class, @selector(containerURLForSecurityApplicationGroupIdentifier:),
                  (IMP)rs_containerURL, &orig_containerURL);
        if (gPrimaryGroup != nil) {
            // Without one, a suite named after a group already stays in the app's own preferences.
            RSSwizzle(NSUserDefaults.class, @selector(initWithSuiteName:),
                      (IMP)rs_initWithSuite, &orig_initWithSuite);
        }

        if (gTeamPrefix != nil) {
            {
                // Only the app's own code asks for foreign keychain groups. Hooking every image
                // (Apple's included) is needless and faults on libraries that cannot be written.
                NSString *bundlePath = NSBundle.mainBundle.bundlePath;
                NSRange app = [bundlePath rangeOfString:@".app"];
                NSString *root = app.location == NSNotFound ? bundlePath : [bundlePath substringToIndex:app.location + app.length];
                gAppRoot = strdup([root stringByAppendingString:@"/"].fileSystemRepresentation);
                gRebindings[0] = (struct rebinding){"SecItemAdd", (void *)rs_SecItemAdd, (void **)&gRebindUnused[0]};
                gRebindings[1] = (struct rebinding){"SecItemCopyMatching", (void *)rs_SecItemCopyMatching, (void **)&gRebindUnused[1]};
                gRebindings[2] = (struct rebinding){"SecItemUpdate", (void *)rs_SecItemUpdate, (void **)&gRebindUnused[2]};
                gRebindings[3] = (struct rebinding){"SecItemDelete", (void *)rs_SecItemDelete, (void **)&gRebindUnused[3]};
                _dyld_register_func_for_add_image(RSAddImage);
            }
        }
    }
}
