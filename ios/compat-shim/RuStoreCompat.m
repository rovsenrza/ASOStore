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
// is genuinely ours) it changes nothing. No ads, no network, no servers.
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
#import <dlfcn.h>
#import "fishhook.h"

static NSArray<NSString *> *gRealGroups;      // App Groups this signature actually holds.
static NSString *gPrimaryGroup;               // The one we map missing groups to.
static NSString *gTeamPrefix;                 // "<TEAMID>." from application-identifier.
static NSSet<NSString *> *gKeychainGroups;    // Keychain groups this signature literally holds.
static NSString *gOriginalBundleID;           // Vendor's bundle ID, when the signer recorded it.
static NSBundle *gMainBundle;
static NSDictionary *gSpoofedInfo;

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

static NSURL *rs_containerURL(id self, SEL _cmd, NSString *group) {
    NSURL *url = orig_containerURL(self, _cmd, group);
    if (url != nil || group == nil || gPrimaryGroup == nil) return url;
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
    if (query == NULL) return NULL;
    NSDictionary *q = (__bridge NSDictionary *)query;
    id group = q[(__bridge id)kSecAttrAccessGroup];
    if (![group isKindOfClass:NSString.class]) return NULL;
    if ([gKeychainGroups containsObject:group]) return NULL;
    RSLog(@"keychain group %@ -> default", group);
    NSMutableDictionary *rewritten = [q mutableCopy];
    [rewritten removeObjectForKey:(__bridge id)kSecAttrAccessGroup];
    return (CFDictionaryRef)CFBridgingRetain(rewritten);
}

static OSStatus (*orig_SecItemAdd)(CFDictionaryRef, CFTypeRef *);
static OSStatus (*orig_SecItemCopyMatching)(CFDictionaryRef, CFTypeRef *);
static OSStatus (*orig_SecItemUpdate)(CFDictionaryRef, CFDictionaryRef);
static OSStatus (*orig_SecItemDelete)(CFDictionaryRef);

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
static OSStatus rs_SecItemCopyMatching(CFDictionaryRef query, CFTypeRef *result) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemCopyMatching(r ?: query, result);
    RSTrace("copy", query, r != NULL, status);
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

#pragma mark - Original bundle ID

static NSString *(*orig_bundleIdentifier)(id, SEL);
static NSDictionary *(*orig_infoDictionary)(id, SEL);
static id (*orig_objectForInfoKey)(id, SEL, NSString *);

static NSString *rs_bundleIdentifier(id self, SEL _cmd) {
    if (self == gMainBundle) return gOriginalBundleID;
    return orig_bundleIdentifier(self, _cmd);
}

static NSDictionary *rs_infoDictionary(id self, SEL _cmd) {
    NSDictionary *info = orig_infoDictionary(self, _cmd);
    if (self != gMainBundle || info == nil) return info;
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
    if (self == gMainBundle && [key isEqualToString:@"CFBundleIdentifier"]) return gOriginalBundleID;
    return orig_objectForInfoKey(self, _cmd, key);
}

// Once the app sees its original ID, it may look its own bundle up by it
// (`Bundle(identifier: Bundle.main.bundleIdentifier!)!`); iOS registered the bundle
// under our ID and would return nil.
static NSBundle *(*orig_bundleWithIdentifier)(id, SEL, NSString *);

static NSBundle *rs_bundleWithIdentifier(id self, SEL _cmd, NSString *identifier) {
    if ([identifier isEqualToString:gOriginalBundleID]) return gMainBundle;
    return orig_bundleWithIdentifier(self, _cmd, identifier);
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
        RSLog(@"groups=%@ primary=%@ team=%@ original=%@", gRealGroups, gPrimaryGroup, gTeamPrefix, gOriginalBundleID);

        if (gOriginalBundleID != nil) {
            RSSwizzle(NSBundle.class, @selector(bundleIdentifier), (IMP)rs_bundleIdentifier, &orig_bundleIdentifier);
            RSSwizzle(NSBundle.class, @selector(infoDictionary), (IMP)rs_infoDictionary, &orig_infoDictionary);
            RSSwizzle(NSBundle.class, @selector(objectForInfoDictionaryKey:), (IMP)rs_objectForInfoKey, &orig_objectForInfoKey);
            RSSwizzle(object_getClass(NSBundle.class), @selector(bundleWithIdentifier:), (IMP)rs_bundleWithIdentifier, &orig_bundleWithIdentifier);
        }

        if (gPrimaryGroup != nil) {
            RSSwizzle(NSFileManager.class, @selector(containerURLForSecurityApplicationGroupIdentifier:),
                      (IMP)rs_containerURL, &orig_containerURL);
            RSSwizzle(NSUserDefaults.class, @selector(initWithSuiteName:),
                      (IMP)rs_initWithSuite, &orig_initWithSuite);
        }

        if (gTeamPrefix != nil) {
            orig_SecItemAdd = dlsym(RTLD_DEFAULT, "SecItemAdd");
            orig_SecItemCopyMatching = dlsym(RTLD_DEFAULT, "SecItemCopyMatching");
            orig_SecItemUpdate = dlsym(RTLD_DEFAULT, "SecItemUpdate");
            orig_SecItemDelete = dlsym(RTLD_DEFAULT, "SecItemDelete");
            if (orig_SecItemAdd && orig_SecItemCopyMatching && orig_SecItemUpdate && orig_SecItemDelete) {
                rebind_symbols((struct rebinding[4]){
                    {"SecItemAdd", (void *)rs_SecItemAdd, (void **)&orig_SecItemAdd},
                    {"SecItemCopyMatching", (void *)rs_SecItemCopyMatching, (void **)&orig_SecItemCopyMatching},
                    {"SecItemUpdate", (void *)rs_SecItemUpdate, (void **)&orig_SecItemUpdate},
                    {"SecItemDelete", (void *)rs_SecItemDelete, (void **)&orig_SecItemDelete},
                }, 4);
            }
        }
    }
}
