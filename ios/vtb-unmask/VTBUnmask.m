// VTBUnmask.dylib — lifts the appbankRU "Altai" cover so the real VTB app shows after re-signing.
//
// The cover↔real switch is gated by a GET to open-api.deceptium.ru that carries a `BundleId`
// header read from Bundle.main.bundleIdentifier. The gate whitelists the ORIGINAL bundle id
// (com.matila.EventPulse); our re-signed id makes the server answer 403 and the cover never lifts.
// Proven on device: same UA/headers, only the bundle id flips 403↔200.
//
// Fix: on every request to *.deceptium.ru (and the altai gate hosts) rewrite the BundleId header
// to the original, so the gate returns 200 and the app reveals the real UI. In-process ObjC
// swizzling only (no Frida), weak-linked, so a load failure never stops the app from launching.
//
// ORIGINAL_BUNDLE_ID is baked at build time; override with the RS_VTB_ORIG_BUNDLE env var for reuse
// on sibling appbankRU masks that whitelist a different id.
#import <Foundation/Foundation.h>
#import <objc/runtime.h>
#import <objc/message.h>

// The vendor bundle id the deceptium gate whitelists for this app. It equals the app's own
// ORIGINAL CFBundleIdentifier (the one it shipped with before we re-signed): the gate was proven
// on device to answer 200 only for that exact id. Baked per build with -DORIGINAL_BUNDLE_ID so one
// source builds a variant per mask (New=com.matila.EventPulse, Old=com.sm.FlowTime); the backend
// injects the variant whose id matches the artifact. Override at runtime with RS_VTB_ORIG_BUNDLE.
#ifndef ORIGINAL_BUNDLE_ID
#define ORIGINAL_BUNDLE_ID @"com.matila.EventPulse"
#endif

static NSString *origBundleId(void) {
    const char *env = getenv("RS_VTB_ORIG_BUNDLE");
    return env ? [NSString stringWithUTF8String:env] : ORIGINAL_BUNDLE_ID;
}

static BOOL gatedHost(NSString *host) {
    if (host.length == 0) return NO;
    return [host containsString:@"deceptium"] || [host containsString:@"altaiapp"];
}

// Return req unchanged, or a mutable copy with BundleId rewritten when it targets the gate host.
static NSURLRequest *rewrite(NSURLRequest *req) {
    if (req == nil || !gatedHost(req.URL.host)) return req;
    NSString *want = origBundleId();
    NSString *have = [req valueForHTTPHeaderField:@"BundleId"];
    if (have != nil && [have isEqualToString:want]) return req;
    NSMutableURLRequest *m = [req mutableCopy];
    [m setValue:want forHTTPHeaderField:@"BundleId"];
    return m;
}

static void swizzle(Class cls, SEL sel, IMP imp, IMP *orig) {
    Method m = class_getInstanceMethod(cls, sel);
    if (!m) return;
    *orig = method_getImplementation(m);
    method_setImplementation(m, imp);
}

typedef void (^DataCB)(NSData *, NSURLResponse *, NSError *);

static id (*orig_dataReqCB)(id, SEL, NSURLRequest *, DataCB);
static id hook_dataReqCB(id self, SEL _cmd, NSURLRequest *req, DataCB cb) {
    return orig_dataReqCB(self, _cmd, rewrite(req), cb);
}
static id (*orig_dataReq)(id, SEL, NSURLRequest *);
static id hook_dataReq(id self, SEL _cmd, NSURLRequest *req) {
    return orig_dataReq(self, _cmd, rewrite(req));
}
static id (*orig_uploadData)(id, SEL, NSURLRequest *, NSData *, DataCB);
static id hook_uploadData(id self, SEL _cmd, NSURLRequest *req, NSData *body, DataCB cb) {
    return orig_uploadData(self, _cmd, rewrite(req), body, cb);
}
static id (*orig_uploadDataNoCB)(id, SEL, NSURLRequest *, NSData *);
static id hook_uploadDataNoCB(id self, SEL _cmd, NSURLRequest *req, NSData *body) {
    return orig_uploadDataNoCB(self, _cmd, rewrite(req), body);
}

__attribute__((constructor)) static void VTBUnmaskInit(void) {
    Class s = NSClassFromString(@"NSURLSession");
    swizzle(s, @selector(dataTaskWithRequest:completionHandler:), (IMP)hook_dataReqCB, (IMP *)&orig_dataReqCB);
    swizzle(s, @selector(dataTaskWithRequest:), (IMP)hook_dataReq, (IMP *)&orig_dataReq);
    swizzle(s, @selector(uploadTaskWithRequest:fromData:completionHandler:), (IMP)hook_uploadData, (IMP *)&orig_uploadData);
    swizzle(s, @selector(uploadTaskWithRequest:fromData:), (IMP)hook_uploadDataNoCB, (IMP *)&orig_uploadDataNoCB);
}
