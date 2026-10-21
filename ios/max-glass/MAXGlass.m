// MAXGlass.dylib — injected alongside MAXRelay. MAX's OMUIKit.TabBarController keeps the stock
// UITabBar (which on iOS 26 renders the authentic floating Liquid Glass platter) at alpha 0 and draws
// its own full-width OMUIKit.TabBar on top. Needs artifact 697 (SDK 26.2, UIDesignRequiresCompatibility
// = false). We show the stock bar and hide MAX's, so UIKit itself draws the glass. No-op below iOS 26.
#import <UIKit/UIKit.h>
#import <objc/runtime.h>

#define MX_DIAG 0   // on-screen overlay with the tab bar state (idevicesyslog does not work here)


#if MX_DIAG
static NSString *Desc(UIColor *c) { return c ? c.description : @"nil"; }
static void Collect(UIView *v, NSMutableArray *out) {
    if ([v isKindOfClass:[UITabBar class]]) [out addObject:v];
    for (UIView *c in v.subviews) Collect(c, out);
}
static void ShowDiag(UITabBar *tb) {
    static CFAbsoluteTime last;
    CFAbsoluteTime now = CFAbsoluteTimeGetCurrent();
    if (now - last < 1.0) return;
    last = now;
    dispatch_async(dispatch_get_main_queue(), ^{
        UIWindow *w = tb.window;
        if (!w) return;
        NSMutableString *s = [NSMutableString stringWithString:@"MAXGlass 5290 diag\n"];
        NSMutableArray *bars = [NSMutableArray array];
        Collect(w, bars);
        int n = 0;
        for (UITabBar *b in bars) {
            if ([b isDescendantOfView:[w viewWithTag:0x6D78]]) continue;
            CGRect r = [b convertRect:b.bounds toView:w];
            [s appendFormat:@"TB%d %@ %@ h=%d a=%.1f sup=%@ std.bg=%@\n", n++, NSStringFromClass([b class]),
             NSStringFromCGRect(r), b.hidden, b.alpha, NSStringFromClass([b.superview class]),
             Desc(b.standardAppearance.backgroundColor)];
            int i = 0;
            for (UIView *sv in b.subviews) {
                [s appendFormat:@"  S%d %@ %@", i++, NSStringFromClass([sv class]), NSStringFromCGRect(sv.frame)];
                if ([sv isKindOfClass:[UIVisualEffectView class]])
                    [s appendFormat:@" fx=%@", NSStringFromClass([((UIVisualEffectView *)sv).effect class])];
                [s appendString:@"\n"];
            }
        }
        CGPoint p = CGPointMake(CGRectGetMidX(w.bounds) - 60, CGRectGetMaxY(w.bounds) - 50);
        UIView *hit = [w hitTest:p withEvent:nil];
        [s appendString:@"HIT bottom:\n"];
        for (UIView *v = hit; v && v != w; v = v.superview)
            [s appendFormat:@"  %@ %@\n", NSStringFromClass([v class]), NSStringFromCGRect([v convertRect:v.bounds toView:w])];
        UILabel *l = (UILabel *)[w viewWithTag:0x6D78];
        if (!l) {
            l = [[UILabel alloc] init];
            l.tag = 0x6D78; l.numberOfLines = 0; l.userInteractionEnabled = NO;
            l.font = [UIFont monospacedSystemFontOfSize:8 weight:UIFontWeightRegular];
            l.textColor = UIColor.yellowColor; l.backgroundColor = [UIColor colorWithWhite:0 alpha:0.8];
            [w addSubview:l];
        }
        l.frame = CGRectMake(4, 60, CGRectGetWidth(w.bounds) - 8, CGRectGetHeight(w.bounds) * 0.6);
        l.text = s;
        [w bringSubviewToFront:l];
    });
}
#endif

// MAX keeps UITabBarController's stock bar (TB0, holding the real iOS 26 glass platter) at alpha 0
// and draws its own full-width OMUIKit.TabBar (TB1) on top. We flip that: show the stock bar, hide MAX's.
static BOOL IsStockBar(id v) {
    return [v class] == [UITabBar class]
        && [NSStringFromClass([[v superview] class]) containsString:@"TabBarContainer"];
}
static BOOL IsMAXBar(id v) { return [NSStringFromClass([v class]) isEqualToString:@"OMUIKit.TabBar"]; }

static void (*o_setAlpha)(id, SEL, CGFloat);
static void h_setAlpha(id self, SEL _cmd, CGFloat a) { o_setAlpha(self, _cmd, IsStockBar(self) ? 1.0 : a); }
static void (*o_setHidden)(id, SEL, BOOL);
static void h_setHidden(id self, SEL _cmd, BOOL h) {
    o_setHidden(self, _cmd, IsStockBar(self) ? NO : (IsMAXBar(self) ? YES : h));
}

static void (*o_layout)(id, SEL);
static void h_layout(id self, SEL _cmd) {
    if (o_layout) o_layout(self, _cmd);
    @try {
        UITabBar *tb = (UITabBar *)self;
        if (IsStockBar(tb)) {
            if (tb.alpha < 1.0) tb.alpha = 1.0;
            if (tb.hidden) tb.hidden = NO;
            // MAX also drops its own capsule background (a direct UIVisualEffectView) into the stock
            // bar; it shows as a second, larger pill behind the system platter, so hide it.
            for (UIView *sv in tb.subviews)
                if ([sv isMemberOfClass:[UIVisualEffectView class]] && !sv.hidden) sv.hidden = YES;
        }
        else if (IsMAXBar(tb) && !tb.hidden) tb.hidden = YES;
#if MX_DIAG
        ShowDiag(tb);
#endif
    } @catch (__unused id e) {}
}

static void Hook(Class c, SEL sel, IMP imp, IMP *orig) {
    Method m = class_getInstanceMethod(c, sel);
    if (!m) return;
    // add on UITabBar itself so we don't rewrite UIView's shared implementation
    if (class_addMethod(c, sel, imp, method_getTypeEncoding(m))) *orig = method_getImplementation(m);
    else *orig = method_setImplementation(m, imp);
}

__attribute__((constructor))
static void MAXGlassInit(void) {
    if (@available(iOS 26.0, *)) {} else return;
    Class c = objc_getClass("UITabBar");
    if (!c) return;
    Hook(c, @selector(layoutSubviews), (IMP)h_layout, (IMP *)&o_layout);
    Hook(c, @selector(setAlpha:), (IMP)h_setAlpha, (IMP *)&o_setAlpha);
    Hook(c, @selector(setHidden:), (IMP)h_setHidden, (IMP *)&o_setHidden);
}
