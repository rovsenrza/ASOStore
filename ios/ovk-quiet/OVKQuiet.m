// OVKQuiet: silences OrwellVK's (com.dvntm.ovk) own promo UI in the VK mod we ship.
//
// OrwellVK adds its Objective-C methods at runtime (its classes carry only +initialize), so this is
// done at runtime too, not by patching the tweak binary:
//   * -showDonationReminder: and -checkForUpdates: (the periodic "buy me a coffee" sheet and the update
//     check that sends people to other repos) become no-ops on whatever class owns them;
//   * the screens it shows by itself (WelcomeVC on first launch, ChangelogVC after an update) are not
//     presented. Its settings menu, and everything the user opens from there, stays.
// Built per ios/ovk-quiet/Makefile and injected with scripts/inject-dylib.py.
#import <Foundation/Foundation.h>
#import <UIKit/UIKit.h>
#import <objc/runtime.h>

static void OVKQuietNoop(id self, SEL _cmd, id arg) {}

static BOOL OVKQuietBlocked(UIViewController *controller) {
    static NSArray<NSString *> *names;
    static dispatch_once_t once;
    dispatch_once(&once, ^{ names = @[@"WelcomeVC", @"ChangelogVC"]; });
    UIViewController *shown = controller;
    if ([controller isKindOfClass:[UINavigationController class]]) {
        shown = ((UINavigationController *)controller).viewControllers.firstObject ?: controller;
    }
    for (NSString *name in names) {
        Class cls = NSClassFromString(name);
        if (cls && [shown isKindOfClass:cls]) return YES;
    }
    return NO;
}

static void (*OVKQuietOriginalPresent)(UIViewController *, SEL, UIViewController *, BOOL, void (^)(void));

static void OVKQuietPresent(UIViewController *self, SEL _cmd, UIViewController *controller, BOOL animated, void (^completion)(void)) {
    if (controller && OVKQuietBlocked(controller)) {
        NSLog(@"[OVKQuiet] not presenting %@", NSStringFromClass([controller class]));
        if (completion) completion();
        return;
    }
    OVKQuietOriginalPresent(self, _cmd, controller, animated, completion);
}

// Lets the tweak's classes run their +initialize (where it adds its methods), then turns the two
// selectors into no-ops on every class that has them, instance or class side.
static void OVKQuietSilence(void) {
    static const char *const promoClasses[] = {"OVK", "OVKSettingsVC", "OVKUserDefaults", "OVKApiRequest", "WelcomeVC", "ChangelogVC"};
    for (size_t i = 0; i < sizeof(promoClasses) / sizeof(promoClasses[0]); i++) {
        Class cls = objc_getClass(promoClasses[i]);
        if (cls) (void)[cls class];
    }
    SEL selectors[] = {sel_registerName("showDonationReminder:"), sel_registerName("checkForUpdates:")};
    unsigned int count = 0;
    Class *classes = objc_copyClassList(&count);
    unsigned int silenced = 0;
    for (unsigned int i = 0; i < count; i++) {
        Class sides[] = {classes[i], object_getClass((id)classes[i])};
        for (int s = 0; s < 2; s++) {
            unsigned int methodCount = 0;
            Method *methods = class_copyMethodList(sides[s], &methodCount);
            for (unsigned int m = 0; m < methodCount; m++) {
                SEL name = method_getName(methods[m]);
                for (size_t k = 0; k < 2; k++) {
                    if (name == selectors[k] && method_getImplementation(methods[m]) != (IMP)OVKQuietNoop) {
                        method_setImplementation(methods[m], (IMP)OVKQuietNoop);
                        silenced++;
                    }
                }
            }
            free(methods);
        }
    }
    free(classes);
    if (silenced) NSLog(@"[OVKQuiet] silenced %u promo method(s)", silenced);
}

__attribute__((constructor)) static void OVKQuietInit(void) {
    Method present = class_getInstanceMethod([UIViewController class], @selector(presentViewController:animated:completion:));
    OVKQuietOriginalPresent = (void *)method_setImplementation(present, (IMP)OVKQuietPresent);

    // The tweak may still be setting itself up when this image loads: silence again once the app is up.
    OVKQuietSilence();
    [[NSNotificationCenter defaultCenter] addObserverForName:UIApplicationDidFinishLaunchingNotification object:nil
                                                       queue:[NSOperationQueue mainQueue] usingBlock:^(NSNotification *note) {
        OVKQuietSilence();
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, 2 * NSEC_PER_SEC), dispatch_get_main_queue(), ^{ OVKQuietSilence(); });
    }];
}
