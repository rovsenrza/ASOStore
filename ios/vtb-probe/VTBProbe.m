// VTBProbe.dylib — diagnostic probe injected (signed, like RuStoreCompat) into the appbankRU VTB
// "Altai" build to find how the cover↔real mask is decided. In-process ObjC swizzling only (no Frida),
// so the app's anti-Frida/anti-tamper is not triggered. Shows a live on-screen overlay + Documents log.
//   - NSURLSession: logs every request URL + status; dumps the deceptium.ru / altaiapp.com response body
//   - UIViewController viewDidAppear:  → logs the presented VC class (cover welcome vs real VTB login)
//   - NSUserDefaults objectForKey:/boolForKey: → logs keys that look mask/cover/onboarding related
#import <UIKit/UIKit.h>
#import <objc/runtime.h>
#import <objc/message.h>

static NSMutableArray<NSString *> *gLines;
static UITextView *gView;

#define RS_TELEMETRY_URL @"https://ruappstore.com/api/v1/telemetry/launch"
// Coalesced POST of the recent diagnostic lines to our launch-report endpoint, so they can be read
// live with `apps:launch-reports`. bundle = the signed bundle (matches a SigningProfile → stored).
static void postLines(void) {
    NSArray *snapshot;
    @synchronized (gLines ?: (gLines = [NSMutableArray array])) {
        NSUInteger n = gLines.count; NSUInteger take = MIN((NSUInteger)60, n);
        snapshot = [gLines subarrayWithRange:NSMakeRange(n-take, take)];
    }
    if (snapshot.count == 0) return;
    NSString *bundle = NSBundle.mainBundle.bundleIdentifier ?: @"com.ruappstore.vtbnewtest";
    NSString *ver = [NSBundle.mainBundle objectForInfoDictionaryKey:@"CFBundleShortVersionString"] ?: @"";
    NSMutableArray *bt = [NSMutableArray array];
    for (NSString *l in snapshot) [bt addObject:[l length] > 299 ? [l substringToIndex:299] : l];
    NSDictionary *body = @{@"kind": @"LAUNCHED", @"bundle": bundle, @"version": ver,
                           @"detail": snapshot.lastObject ?: @"", @"backtrace": bt};
    NSData *json = [NSJSONSerialization dataWithJSONObject:body options:0 error:NULL];
    if (!json) return;
    NSMutableURLRequest *r = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:RS_TELEMETRY_URL]];
    r.HTTPMethod = @"POST"; r.HTTPBody = json;
    [r setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
    NSURLSession *se = [NSURLSession sessionWithConfiguration:NSURLSessionConfiguration.ephemeralSessionConfiguration];
    [[se dataTaskWithRequest:r completionHandler:^(NSData *d, NSURLResponse *rp, NSError *e){}] resume];
}
static void schedulePost(void) {
    static dispatch_source_t timer; static dispatch_once_t once;
    dispatch_once(&once, ^{
        timer = dispatch_source_create(DISPATCH_SOURCE_TYPE_TIMER, 0, 0, dispatch_get_global_queue(0,0));
        dispatch_source_set_timer(timer, dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2*NSEC_PER_SEC)), 2*NSEC_PER_SEC, NSEC_PER_SEC);
        dispatch_source_set_event_handler(timer, ^{ postLines(); });
        dispatch_resume(timer);
    });
}

static void logLine(NSString *s) {
    @synchronized (gLines ?: (gLines = [NSMutableArray array])) {
        NSString *line = [NSString stringWithFormat:@"%@  %@", [@(CFAbsoluteTimeGetCurrent()) stringValue], s];
        [gLines addObject:line];
        if (gLines.count > 200) [gLines removeObjectAtIndex:0];
        // append to Documents/vtbprobe.log
        NSString *path = [NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES).firstObject
                          stringByAppendingPathComponent:@"vtbprobe.log"];
        FILE *f = fopen(path.UTF8String, "a");
        if (f) { fprintf(f, "%s\n", line.UTF8String); fclose(f); }
    }
    dispatch_async(dispatch_get_main_queue(), ^{
        if (gView) {
            gView.text = [[gLines subarrayWithRange:NSMakeRange(MAX(0,(NSInteger)gLines.count-18), MIN(18,gLines.count))] componentsJoinedByString:@"\n"];
        }
    });
}

static void ensureOverlay(void) {
    static dispatch_once_t once; dispatch_once(&once, ^{
        dispatch_async(dispatch_get_main_queue(), ^{
            UIWindowScene *scene = nil;
            for (UIScene *s in UIApplication.sharedApplication.connectedScenes)
                if ([s isKindOfClass:UIWindowScene.class]) { scene = (UIWindowScene *)s; break; }
            UIWindow *w = scene ? [[UIWindow alloc] initWithWindowScene:scene] : [[UIWindow alloc] initWithFrame:UIScreen.mainScreen.bounds];
            w.windowLevel = UIWindowLevelAlert + 1000;
            w.userInteractionEnabled = NO;
            w.backgroundColor = UIColor.clearColor;
            CGRect b = UIScreen.mainScreen.bounds;
            UITextView *tv = [[UITextView alloc] initWithFrame:CGRectMake(4, b.size.height*0.45, b.size.width-8, b.size.height*0.5)];
            tv.backgroundColor = [UIColor colorWithWhite:0 alpha:0.78];
            tv.textColor = UIColor.greenColor;
            tv.font = [UIFont fontWithName:@"Menlo" size:8] ?: [UIFont systemFontOfSize:8];
            tv.editable = NO; tv.userInteractionEnabled = NO;
            [w addSubview:tv];
            w.hidden = NO;
            gView = tv;
            logLine(@"[rsb] VTBProbe overlay up");
        });
    });
}

#pragma mark - swizzle helper
static void swizzle(Class c, SEL s, IMP imp, IMP *orig) {
    Method m = class_getInstanceMethod(c, s);
    if (m) *orig = method_setImplementation(m, imp);
}

#pragma mark - UIViewController viewDidAppear:
static void (*orig_vda)(id, SEL, BOOL);
static void hook_vda(id self, SEL _cmd, BOOL animated) {
    ensureOverlay();
    logLine([NSString stringWithFormat:@"VC: %@", NSStringFromClass([self class])]);
    orig_vda(self, _cmd, animated);
}

#pragma mark - NSUserDefaults
static id (*orig_objForKey)(id, SEL, NSString *);
static id hook_objForKey(id self, SEL _cmd, NSString *key) {
    id v = orig_objForKey(self, _cmd, key);
    NSString *lk = key.lowercaseString;
    if ([lk containsString:@"mask"] || [lk containsString:@"cover"] || [lk containsString:@"altai"] ||
        [lk containsString:@"decept"] || [lk containsString:@"onboard"] || [lk containsString:@"welcome"] ||
        [lk containsString:@"real"] || [lk containsString:@"mode"])
        logLine([NSString stringWithFormat:@"UD[%@] = %@", key, v]);
    return v;
}
static BOOL (*orig_boolForKey)(id, SEL, NSString *);
static BOOL hook_boolForKey(id self, SEL _cmd, NSString *key) {
    BOOL v = orig_boolForKey(self, _cmd, key);
    NSString *lk = key.lowercaseString;
    if ([lk containsString:@"mask"] || [lk containsString:@"cover"] || [lk containsString:@"altai"] ||
        [lk containsString:@"decept"] || [lk containsString:@"onboard"] || [lk containsString:@"welcome"] ||
        [lk containsString:@"real"] || [lk containsString:@"mode"])
        logLine([NSString stringWithFormat:@"UDbool[%@] = %d", key, v]);
    return v;
}

#pragma mark - NSURLSession
static void dumpBody(NSURL *url, NSData *data, NSURLResponse *resp) {
    NSString *host = url.host ?: @"";
    long code = [resp isKindOfClass:NSHTTPURLResponse.class] ? ((NSHTTPURLResponse *)resp).statusCode : -1;
    if ([host containsString:@"ruappstore.com"] && [(url.path?:@"") containsString:@"telemetry"]) return;
    logLine([NSString stringWithFormat:@"NET %ld %@%@", code, host, url.path ?: @""]);
    if (([host containsString:@"deceptium"] || [host containsString:@"altaiapp"]) && data.length) {
        NSString *s = [[NSString alloc] initWithData:[data subdataWithRange:NSMakeRange(0, MIN(600u, (unsigned)data.length))] encoding:NSUTF8StringEncoding];
        logLine([NSString stringWithFormat:@">>> %@ BODY: %@", host, s ?: @"<binary>"]);
    }
}
static void dumpRequest(NSURLRequest *req) {
    NSString *host = req.URL.host ?: @"";
    if (![host containsString:@"deceptium"] && ![host containsString:@"altaiapp"]) return;
    logLine([NSString stringWithFormat:@"REQ %@ %@", req.HTTPMethod ?: @"GET", req.URL.absoluteString ?: @""]);
    [req.allHTTPHeaderFields enumerateKeysAndObjectsUsingBlock:^(NSString *k, NSString *v, BOOL *stop){
        logLine([NSString stringWithFormat:@"HDR %@: %@", k, v]);
    }];
    if (req.HTTPBody.length) {
        NSString *b = [[NSString alloc] initWithData:[req.HTTPBody subdataWithRange:NSMakeRange(0, MIN(400u,(unsigned)req.HTTPBody.length))] encoding:NSUTF8StringEncoding];
        logLine([NSString stringWithFormat:@"BODY-OUT %@", b ?: @"<binary>"]);
    }
}
typedef void (^DataCB)(NSData *, NSURLResponse *, NSError *);
static id (*orig_dataReq)(id, SEL, NSURLRequest *, DataCB);
static id hook_dataReq(id self, SEL _cmd, NSURLRequest *req, DataCB cb) {
    dumpRequest(req);
    DataCB wrap = ^(NSData *d, NSURLResponse *r, NSError *e){ dumpBody(req.URL, d, r); if (cb) cb(d, r, e); };
    return orig_dataReq(self, _cmd, req, wrap);
}
static id (*orig_dataURL)(id, SEL, NSURL *, DataCB);
static id hook_dataURL(id self, SEL _cmd, NSURL *url, DataCB cb) {
    DataCB wrap = ^(NSData *d, NSURLResponse *r, NSError *e){ dumpBody(url, d, r); if (cb) cb(d, r, e); };
    return orig_dataURL(self, _cmd, url, wrap);
}

__attribute__((constructor)) static void VTBProbeInit(void) {
    swizzle(UIViewController.class, @selector(viewDidAppear:), (IMP)hook_vda, (IMP *)&orig_vda);
    swizzle(NSUserDefaults.class, @selector(objectForKey:), (IMP)hook_objForKey, (IMP *)&orig_objForKey);
    swizzle(NSUserDefaults.class, @selector(boolForKey:), (IMP)hook_boolForKey, (IMP *)&orig_boolForKey);
    swizzle(NSURLSession.class, @selector(dataTaskWithRequest:completionHandler:), (IMP)hook_dataReq, (IMP *)&orig_dataReq);
    swizzle(NSURLSession.class, @selector(dataTaskWithURL:completionHandler:), (IMP)hook_dataURL, (IMP *)&orig_dataURL);
    schedulePost();
}
