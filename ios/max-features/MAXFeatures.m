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

__attribute__((constructor))
static void MAXFeaturesInit(void) {
    @autoreleasepool {
        @try {
            unsigned int count = 0;
            Class *classes = objc_copyClassList(&count);
            // The global PMS config object is the class that declares BOTH these flags; only it
            // gets touched, so we never flip an unrelated class's same-named property.
            SEL reactions = sel_registerName("reactionsEnabled");
            SEL stories = sel_registerName("storiesEnabled");
            for (unsigned int i = 0; i < count; i++) {
                Class c = classes[i];
                if (!class_getInstanceMethod(c, reactions) || !class_getInstanceMethod(c, stories)) continue;
                BOOL a = ForceGetter(c, "reactionsEnabled");
                BOOL b = ForceGetter(c, "storiesEnabled");
                ForceGetter(c, "reactionsPollingEnabled");
                NSLog(@"[MAXFeatures] enabled reactions=%d stories=%d on %s", a, b, class_getName(c));
            }
            if (classes) free(classes);
        } @catch (__unused id e) {}
    }
}
