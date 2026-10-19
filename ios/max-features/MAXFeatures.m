// MAXFeatures.dylib — injected into re-signed MAX. MAX already ships full, native support for
// message reactions and for stories ("status"), but both are gated behind server-driven feature
// flags (the PMS config object's @objc BOOL getters reactionsEnabled / storiesEnabled /
// reactionsPollingEnabled) that are off for our users. Because the features are native, forcing
// the flags on makes them work over MAX's own servers — i.e. with every MAX user, official ones
// included. This hook just forces those getters to return YES; it adds no UI and sends nothing.
#import <Foundation/Foundation.h>
#import <objc/runtime.h>

static BOOL ForceYes(__unused id self, __unused SEL _cmd) { return YES; }

// Replace one BOOL getter with a constant-YES implementation, if the class has it.
static BOOL ForceGetter(Class c, const char *name) {
    Method m = class_getInstanceMethod(c, sel_registerName(name));
    if (!m) return NO;
    const char *ret = method_copyReturnType(m);
    BOOL isBool = ret && (ret[0] == 'B' || ret[0] == 'c' || ret[0] == 'i');
    if (ret) free((void *)ret);
    if (!isBool) return NO;
    method_setImplementation(m, (IMP)ForceYes);
    return YES;
}

// Diagnostic breadcrumb to the app's Documents dir (pulled with devicectl), since NSLog from an
// injected dylib is hard to read off-device. Safe to leave in; writes a few lines once at launch.
static void Diag(NSString *line) {
    @try {
        NSString *dir = [NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES) firstObject];
        NSString *path = [dir stringByAppendingPathComponent:@"maxfeatures.txt"];
        NSFileHandle *h = [NSFileHandle fileHandleForWritingAtPath:path];
        if (!h) { [@"" writeToFile:path atomically:YES encoding:NSUTF8StringEncoding error:nil]; h = [NSFileHandle fileHandleForWritingAtPath:path]; }
        [h seekToEndOfFile];
        [h writeData:[[line stringByAppendingString:@"\n"] dataUsingEncoding:NSUTF8StringEncoding]];
        [h closeFile];
    } @catch (__unused id e) {}
}

__attribute__((constructor))
static void MAXFeaturesInit(void) {
    @autoreleasepool {
        @try {
            Diag([NSString stringWithFormat:@"--- launch %@ ---", [NSDate date]]);
            unsigned int count = 0;
            Class *classes = objc_copyClassList(&count);
            SEL reactions = sel_registerName("reactionsEnabled");
            SEL stories = sel_registerName("storiesEnabled");
            SEL comments = sel_registerName("commentsEnabled");
            SEL channelComments = sel_registerName("channelCommentsEnabled");
            int touched = 0;
            for (unsigned int i = 0; i < count; i++) {
                Class c = classes[i];
                BOOL hasR = class_getInstanceMethod(c, reactions) != NULL;
                BOOL hasS = class_getInstanceMethod(c, stories) != NULL;
                if (!hasR && !hasS) continue;
                // Record every class carrying either flag, so the real config object is visible.
                Diag([NSString stringWithFormat:@"found %s reactions=%d stories=%d", class_getName(c), hasR, hasS]);
                // Only MAX's PMS config object (OKMAppMessengerProtocol.OMPMSConfigStorage) — not the
                // unrelated iOS system classes (UIKeyboard…, REMAuxiliary…) that share the name.
                if (hasR && hasS && strstr(class_getName(c), "PMSConfig")) {
                    ForceGetter(c, "reactionsEnabled");
                    ForceGetter(c, "storiesEnabled");
                    ForceGetter(c, "reactionsPollingEnabled");
                    if (class_getInstanceMethod(c, comments)) ForceGetter(c, "commentsEnabled");
                    if (class_getInstanceMethod(c, channelComments)) ForceGetter(c, "channelCommentsEnabled");
                    touched++;
                }
            }
            if (classes) free(classes);
            Diag([NSString stringWithFormat:@"touched %d config class(es)", touched]);

            // Reactions have a SECOND, per-chat gate: MAX fetches OMChatReactionsSettings per chat
            // from its server and the reaction picker only shows when that chat's settings are
            // active. Force the per-chat `isActive` getter on so the picker is offered everywhere;
            // if the picker still does not appear, the gate is server-side (not client-flippable).
            Class rs = objc_getClass("OMChatReactionsSettings");
            Diag([NSString stringWithFormat:@"OMChatReactionsSettings isActive forced=%d", rs ? ForceGetter(rs, "isActive") : -1]);
        } @catch (__unused id e) {}
    }
}
