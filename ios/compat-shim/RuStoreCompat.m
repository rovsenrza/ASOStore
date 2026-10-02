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
#import <Foundation/Foundation.h>
#import <objc/runtime.h>
#import <Security/Security.h>
#import <dlfcn.h>
#import "fishhook.h"

static NSArray<NSString *> *gRealGroups;      // App Groups this signature actually holds.
static NSString *gPrimaryGroup;               // The one we map missing groups to.
static NSString *gTeamPrefix;                 // "<TEAMID>." from application-identifier.

static void RSLog(NSString *format, ...) {
#ifdef RUSTORE_COMPAT_DEBUG
    va_list args; va_start(args, format);
    NSLogv([@"[RuStoreCompat] " stringByAppendingString:format], args);
    va_end(args);
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

// The app shares keychain items under the vendor's team prefix, which we do not
// own. Drop a foreign access group from the query so the item uses our default
// group; keep it when it is already under our team.
static CFDictionaryRef RSRewriteQuery(CFDictionaryRef query) {
    if (query == NULL) return NULL;
    NSDictionary *q = (__bridge NSDictionary *)query;
    id group = q[(__bridge id)kSecAttrAccessGroup];
    if (![group isKindOfClass:NSString.class]) return NULL;
    if (gTeamPrefix != nil && [(NSString *)group hasPrefix:gTeamPrefix]) return NULL;
    NSMutableDictionary *rewritten = [q mutableCopy];
    [rewritten removeObjectForKey:(__bridge id)kSecAttrAccessGroup];
    return (CFDictionaryRef)CFBridgingRetain(rewritten);
}

static OSStatus (*orig_SecItemAdd)(CFDictionaryRef, CFTypeRef *);
static OSStatus (*orig_SecItemCopyMatching)(CFDictionaryRef, CFTypeRef *);
static OSStatus (*orig_SecItemUpdate)(CFDictionaryRef, CFDictionaryRef);
static OSStatus (*orig_SecItemDelete)(CFDictionaryRef);

static OSStatus rs_SecItemAdd(CFDictionaryRef query, CFTypeRef *result) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemAdd(r ?: query, result);
    if (r) CFRelease(r);
    return status;
}
static OSStatus rs_SecItemCopyMatching(CFDictionaryRef query, CFTypeRef *result) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemCopyMatching(r ?: query, result);
    if (r) CFRelease(r);
    return status;
}
static OSStatus rs_SecItemUpdate(CFDictionaryRef query, CFDictionaryRef attributes) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemUpdate(r ?: query, attributes);
    if (r) CFRelease(r);
    return status;
}
static OSStatus rs_SecItemDelete(CFDictionaryRef query) {
    CFDictionaryRef r = RSRewriteQuery(query);
    OSStatus status = orig_SecItemDelete(r ?: query);
    if (r) CFRelease(r);
    return status;
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
        RSLog(@"groups=%@ primary=%@ team=%@", gRealGroups, gPrimaryGroup, gTeamPrefix);

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
