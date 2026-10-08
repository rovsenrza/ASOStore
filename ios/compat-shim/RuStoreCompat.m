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
#import <mach-o/dyld.h>
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
static OSStatus (*orig_SecItemAdd)(CFDictionaryRef, CFTypeRef *) = SecItemAdd;
static OSStatus (*orig_SecItemCopyMatching)(CFDictionaryRef, CFTypeRef *) = SecItemCopyMatching;
static OSStatus (*orig_SecItemUpdate)(CFDictionaryRef, CFDictionaryRef) = SecItemUpdate;
static OSStatus (*orig_SecItemDelete)(CFDictionaryRef) = SecItemDelete;
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

// Images linked with chained fixups keep their import table in read-only memory on newer
// iOS, which fishhook cannot patch. dyld interposing covers every image in the process.
#define RS_INTERPOSE(replacement, replacee) \
    __attribute__((used)) static const struct { const void *new_fn; const void *old_fn; } _rs_interpose_##replacee \
    __attribute__((section("__DATA,__interpose"))) = { (const void *)(unsigned long)&replacement, (const void *)(unsigned long)&replacee }
RS_INTERPOSE(rs_SecItemAdd, SecItemAdd);
RS_INTERPOSE(rs_SecItemCopyMatching, SecItemCopyMatching);
RS_INTERPOSE(rs_SecItemUpdate, SecItemUpdate);
RS_INTERPOSE(rs_SecItemDelete, SecItemDelete);

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
        RSLog(@"groups=%@ primary=%@ team=%@ original=%@", gRealGroups, gPrimaryGroup, gTeamPrefix, gOriginalBundleID);

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
