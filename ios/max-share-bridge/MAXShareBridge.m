// MAXShareBridge.dylib — lets MAX's share extension (diyorde's DiyShareViewController) hand files to the
// app without an App Group. Overflow teams have no App Groups, so the extension found no shared container
// and showed "MAX can't receive files in this install (no shared app group)".
//
// Loaded by both binaries (LC_LOAD_WEAK_DYLIB added by scripts/patch-max-share-bridge.py):
//  - share.appex: the extension gets a fake application-groups entitlement and a private container in
//    its own tmp, so diyorde writes the files + manifest.json there as usual. When it opens
//    max://diyorde-share?dir=<folder>, the folder is packed into a named pasteboard (shared between
//    processes of one team, no App Group needed) and dir becomes "rsb:<token>".
//  - MAX.app: diyorde's ShareFolderFromURL reads the dir query item; when it is "rsb:<token>" the
//    package is unpacked into the app's tmp and the real path is returned. With no shared container
//    diyorde's ReadManifest accepts any absolute path, so the rest of its flow is unchanged.
#import <UIKit/UIKit.h>
#import <objc/runtime.h>
#import <objc/message.h>
#import <Security/Security.h>
#import "fishhook.h"

static NSString *const kPasteboard = @"ru.appstore.maxshare";
static NSString *const kType = @"ru.appstore.maxshare.package";
static NSString *const kPrefix = @"rsb:";
static NSString *const kFakeGroup = @"group.ru.appstore.maxshare";
// Named pasteboards hold the data in memory; larger shares are refused with a clear message.
static const unsigned long long kMaxBytes = 300ull * 1024 * 1024;

static BOOL IsExtension(void) {
    return [[[NSBundle mainBundle] bundlePath] hasSuffix:@".appex"];
}

static NSString *BridgeRoot(void) {
    NSString *root = [NSTemporaryDirectory() stringByAppendingPathComponent:@"RuShareBridge"];
    [[NSFileManager defaultManager] createDirectoryAtPath:root withIntermediateDirectories:YES attributes:nil error:nil];
    return root;
}

#pragma mark - Extension side

typedef void *SecTaskRef; // private on iOS
typedef CFTypeRef (*SecTaskCopyValueFn)(SecTaskRef, CFStringRef, CFErrorRef *);
static SecTaskCopyValueFn orig_SecTaskCopyValueForEntitlement;

static CFTypeRef hook_SecTaskCopyValueForEntitlement(SecTaskRef task, CFStringRef entitlement, CFErrorRef *error) {
    CFTypeRef value = orig_SecTaskCopyValueForEntitlement(task, entitlement, error);
    if (entitlement && CFEqual(entitlement, CFSTR("com.apple.security.application-groups"))) {
        NSArray *groups = (__bridge NSArray *)value;
        if (![groups isKindOfClass:[NSArray class]] || groups.count == 0) {
            if (value) CFRelease(value);
            return CFBridgingRetain(@[kFakeGroup]);
        }
    }
    return value;
}

static IMP orig_containerURL;
static NSURL *hook_containerURL(id self, SEL _cmd, NSString *group) {
    NSURL *url = ((NSURL *(*)(id, SEL, NSString *))orig_containerURL)(self, _cmd, group);
    if (url == nil && [group isEqualToString:kFakeGroup]) {
        url = [NSURL fileURLWithPath:BridgeRoot() isDirectory:YES];
    }
    return url;
}

static void FinishWithMessage(UIViewController *controller, NSString *message) {
    SEL finish = NSSelectorFromString(@"finishWithError:");
    if ([controller respondsToSelector:finish]) {
        ((void (*)(id, SEL, id))objc_msgSend)(controller, finish, message);
    }
}

// The folder's regular files, keyed by their name (manifest.json included). Nil when too large.
static NSDictionary<NSString *, NSData *> *PackFolder(NSString *folder, BOOL *tooLarge) {
    NSFileManager *fm = [NSFileManager defaultManager];
    NSMutableDictionary *files = [NSMutableDictionary dictionary];
    unsigned long long total = 0;
    for (NSString *name in [fm contentsOfDirectoryAtPath:folder error:nil]) {
        NSString *path = [folder stringByAppendingPathComponent:name];
        BOOL dir = NO;
        if (![fm fileExistsAtPath:path isDirectory:&dir] || dir) continue;
        total += [[fm attributesOfItemAtPath:path error:nil] fileSize];
        if (total > kMaxBytes) {
            *tooLarge = YES;
            return nil;
        }
        NSData *data = [NSData dataWithContentsOfFile:path options:NSDataReadingMappedIfSafe error:nil];
        if (data) files[name] = data;
    }
    return files;
}

static IMP orig_openMAXWithFolder;
static void hook_openMAXWithFolder(UIViewController *self, SEL _cmd, NSString *folder) {
    // A real shared container (team with App Groups): leave diyorde's flow untouched.
    if (![folder hasPrefix:BridgeRoot()]) {
        ((void (*)(id, SEL, NSString *))orig_openMAXWithFolder)(self, _cmd, folder);
        return;
    }
    BOOL tooLarge = NO;
    NSDictionary *files = PackFolder(folder, &tooLarge);
    [[NSFileManager defaultManager] removeItemAtPath:folder error:nil];
    if (tooLarge) {
        FinishWithMessage(self, @"Файл слишком большой для отправки через «Поделиться» (до 300 МБ). Отправьте его из чата MAX.");
        return;
    }
    if (files.count == 0) {
        FinishWithMessage(self, @"Nothing to share.");
        return;
    }
    NSString *token = [[NSUUID UUID] UUIDString];
    NSData *package = [NSKeyedArchiver archivedDataWithRootObject:@{@"token": token, @"files": files}
                                            requiringSecureCoding:NO error:nil];
    UIPasteboard *board = [UIPasteboard pasteboardWithName:kPasteboard create:YES];
    [board setData:package forPasteboardType:kType];
    ((void (*)(id, SEL, NSString *))orig_openMAXWithFolder)(self, _cmd, [kPrefix stringByAppendingString:token]);
}

#pragma mark - App side

// Unpacks the package for this token into tmp/RuShareBridge/<token>; returns the folder or nil.
static NSString *Unpack(NSString *token) {
    static NSMutableDictionary<NSString *, NSString *> *done;
    static dispatch_once_t once;
    dispatch_once(&once, ^{ done = [NSMutableDictionary dictionary]; });
    @synchronized (done) {
        if (done[token]) return done[token];
        UIPasteboard *board = [UIPasteboard pasteboardWithName:kPasteboard create:NO];
        NSData *package = [board dataForPasteboardType:kType];
        if (package == nil) return nil;
        NSKeyedUnarchiver *unarchiver = [[NSKeyedUnarchiver alloc] initForReadingFromData:package error:nil];
        unarchiver.requiresSecureCoding = NO;
        NSDictionary *root = [unarchiver decodeObjectForKey:NSKeyedArchiveRootObjectKey];
        [unarchiver finishDecoding];
        if (![root isKindOfClass:[NSDictionary class]] || ![root[@"token"] isEqual:token]) return nil;

        NSString *folder = [BridgeRoot() stringByAppendingPathComponent:token];
        [[NSFileManager defaultManager] createDirectoryAtPath:folder withIntermediateDirectories:YES attributes:nil error:nil];
        NSDictionary *files = root[@"files"];
        for (NSString *name in files) {
            if ([name containsString:@"/"] || [name containsString:@".."]) continue;
            [files[name] writeToFile:[folder stringByAppendingPathComponent:name] atomically:YES];
        }
        board.items = @[];
        done[token] = folder;
        return folder;
    }
}

static IMP orig_queryItemValue;
static NSString *hook_queryItemValue(NSURLQueryItem *self, SEL _cmd) {
    NSString *value = ((NSString *(*)(id, SEL))orig_queryItemValue)(self, _cmd);
    if ([value hasPrefix:kPrefix] && [self.name isEqualToString:@"dir"]) {
        NSString *folder = Unpack([value substringFromIndex:kPrefix.length]);
        return folder ?: @"";
    }
    return value;
}

#pragma mark -

static void Swizzle(Class cls, SEL sel, IMP replacement, IMP *original) {
    Method method = class_getInstanceMethod(cls, sel);
    if (method) *original = method_setImplementation(method, replacement);
}

__attribute__((constructor)) static void MAXShareBridgeInit(void) {
    if (IsExtension()) {
        rebind_symbols((struct rebinding[1]){{"SecTaskCopyValueForEntitlement", (void *)hook_SecTaskCopyValueForEntitlement, (void **)&orig_SecTaskCopyValueForEntitlement}}, 1);
        Swizzle([NSFileManager class], @selector(containerURLForSecurityApplicationGroupIdentifier:), (IMP)hook_containerURL, &orig_containerURL);
        Class controller = NSClassFromString(@"DiyShareViewController");
        if (controller) {
            Swizzle(controller, NSSelectorFromString(@"openMAXWithFolder:"), (IMP)hook_openMAXWithFolder, &orig_openMAXWithFolder);
        }
    } else {
        // NSURLComponents may hand out a private subclass; hook the class its items really have.
        Class item = [[NSURLQueryItem queryItemWithName:@"dir" value:@""] class];
        Swizzle(item, @selector(value), (IMP)hook_queryItemValue, &orig_queryItemValue);
    }
}
