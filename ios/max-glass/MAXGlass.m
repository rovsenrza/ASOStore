// MAXGlass.dylib — injected alongside MAXRelay. MAX's OMUIKit.TabBarController keeps the stock
// UITabBar (which on iOS 26 renders the authentic floating Liquid Glass platter) at alpha 0 and draws
// its own full-width OMUIKit.TabBar on top. Needs artifact 697 (SDK 26.2, UIDesignRequiresCompatibility
// = false). We show the stock bar and hide MAX's, so UIKit itself draws the glass. No-op below iOS 26.
#import <UIKit/UIKit.h>
#import <objc/runtime.h>

#define MX_DIAG 0
#define MX_SCREEN_DIAG 0   // per-screen dump of nav/search/list classes, to plan more glass adoption   // on-screen overlay with the tab bar state (idevicesyslog does not work here)


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

#if MX_SCREEN_DIAG
static BOOL Interesting(NSString *n) {
    for (NSString *k in @[@"NavigationBar", @"Search", @"Table", @"Collection", @"Header", @"Button", @"TextField", @"Segment", @"ASDisplay", @"Node", @"Control"])
        if ([n containsString:k]) return YES;
    return NO;
}
static void Walk(UIView *v, UIView *root, int depth, NSMutableString *out, int *lines) {
    if (*lines > 60 || depth > 40 || v.hidden || v.alpha < 0.01) return;
    NSString *n = NSStringFromClass([v class]);
    if ([v isKindOfClass:[UITabBar class]] || [n containsString:@"TabBarContainerWrapper"] || v.tag == 0x6D79 || v.tag == 0x6D78) return;
    if (Interesting(n)) {
        CGRect r = [v convertRect:v.bounds toView:root];
        NSString *extra = @"";
        if ([v isKindOfClass:[UITableView class]]) extra = [NSString stringWithFormat:@" style=%ld", (long)((UITableView *)v).style];
        if ([v isKindOfClass:[UICollectionView class]]) extra = [NSString stringWithFormat:@" lay=%@", NSStringFromClass([((UICollectionView *)v).collectionViewLayout class])];
        [out appendFormat:@"%d %@ [%.0f,%.0f,%.0f,%.0f]%@\n", depth, n, r.origin.x, r.origin.y, r.size.width, r.size.height, extra];
        (*lines)++;
        if ([v isKindOfClass:[UITableViewCell class]] || [v isKindOfClass:[UICollectionViewCell class]]) return;
    }
    for (UIView *c in v.subviews) Walk(c, root, depth + 1, out, lines);
}
static void ScreenDiag(UIViewController *vc) {
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(0.6 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{
        UIWindow *w = vc.view.window;
        if (!w || vc.childViewControllers.count || [NSStringFromClass([vc class]) hasPrefix:@"App.Decorati"]) return;   // leaf screens only
        UINavigationController *nc = vc.navigationController;
        NSMutableString *s = [NSMutableString stringWithFormat:@"SCREEN %@\nnc=%@ navBarHidden=%d bar=%@ a=%.1f\nnavItem title=%@ L=%lu R=%lu search=%@\n",
            NSStringFromClass([vc class]), NSStringFromClass([nc class]), nc.navigationBarHidden,
            NSStringFromClass([nc.navigationBar class]), nc.navigationBar.alpha, vc.navigationItem.title,
            (unsigned long)vc.navigationItem.leftBarButtonItems.count, (unsigned long)vc.navigationItem.rightBarButtonItems.count,
            NSStringFromClass([vc.navigationItem.searchController class])];
        int lines = 0;
        Walk(w, w, 0, s, &lines);
        UILabel *l = (UILabel *)[w viewWithTag:0x6D79];
        if (!l) {
            l = [[UILabel alloc] init];
            l.tag = 0x6D79; l.numberOfLines = 0; l.userInteractionEnabled = NO;
            l.font = [UIFont monospacedSystemFontOfSize:7.5 weight:UIFontWeightRegular];
            l.textColor = UIColor.yellowColor; l.backgroundColor = [UIColor colorWithWhite:0 alpha:0.82];
            [w addSubview:l];
        }
        l.frame = CGRectMake(2, 50, CGRectGetWidth(w.bounds) - 4, CGRectGetHeight(w.bounds) * 0.78);
        l.text = s;
        [w bringSubviewToFront:l];
    });
}
static void (*o_viewDidAppear)(id, SEL, BOOL);
static void h_viewDidAppear(id self, SEL _cmd, BOOL animated) {
    o_viewDidAppear(self, _cmd, animated);
    @try { ScreenDiag(self); } @catch (__unused id e) {}
}
#endif

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

// --- Glass controls in MAX's own (custom) navigation header and search field ---------------------
// MAX hides UINavigationBar and draws OMUIKit.NavigationBar, so there is no system bar to reveal.
// Instead we give its header buttons and search field the iOS 26 glass treatment directly.
static const void *kGlassKey = &kGlassKey;

static UIVisualEffectView *GlassBackground(UIView *host, UIColor *tint) {
    UIVisualEffectView *g = objc_getAssociatedObject(host, kGlassKey);
    if (g) return g;
    UIVisualEffect *fx = nil;
    Class ge = objc_getClass("UIGlassEffect");
    if (ge) {
        id e = [ge new];
        if (tint && [e respondsToSelector:@selector(setTintColor:)]) [e setValue:tint forKey:@"tintColor"];
        if ([e respondsToSelector:@selector(setInteractive:)]) [e setValue:@YES forKey:@"interactive"];
        fx = e;
    }
    if (!fx) return nil;
    g = [[UIVisualEffectView alloc] initWithEffect:fx];
    g.userInteractionEnabled = NO;
    g.layer.cornerCurve = kCACornerCurveContinuous;
    [host insertSubview:g atIndex:0];
    objc_setAssociatedObject(host, kGlassKey, g, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
    return g;
}
static BOOL HasAncestorNamed(UIView *v, NSString *name) {
    for (UIView *p = v.superview; p; p = p.superview)
        if ([NSStringFromClass([p class]) isEqualToString:name]) return YES;
    return NO;
}

// MAX paints a button's filled circle (grey "…", blue "+") on an inner layer; clear it and return its
// colour. Only a visible, roughly circular fill of >= 28pt counts, so 24pt icons and hidden highlight
// views are never picked up.
static BOOL IsCircleFill(CALayer *l) {
    CGSize z = l.bounds.size;
    return !l.hidden && l.opacity > 0.5 && z.width >= 28.0 && fabs(z.width - z.height) < 4.0;
}
static UIColor *TakeInnerFill(UIView *btn, CALayer *skip) {
    NSMutableArray<CALayer *> *queue = [NSMutableArray arrayWithObject:btn.layer];
    for (NSUInteger i = 0; i < queue.count && i < 40; i++) {
        CALayer *l = queue[i];
        if (l == skip) continue;
        if (IsCircleFill(l)) {
            if ([l isKindOfClass:[CAShapeLayer class]]) {
                CGColorRef f = ((CAShapeLayer *)l).fillColor;
                if (f && CGColorGetAlpha(f) > 0.5) {
                    UIColor *c = [UIColor colorWithCGColor:f];
                    ((CAShapeLayer *)l).fillColor = UIColor.clearColor.CGColor;
                    return c;
                }
            }
            CGColorRef bg = l.backgroundColor;
            if (bg && CGColorGetAlpha(bg) > 0.5) {
                UIColor *c = [UIColor colorWithCGColor:bg];
                l.backgroundColor = UIColor.clearColor.CGColor;
                UIView *owner = (UIView *)l.delegate;
                if ([owner isKindOfClass:[UIView class]]) owner.backgroundColor = UIColor.clearColor;
                return c;
            }
        }
        if (l.sublayers) [queue addObjectsFromArray:l.sublayers];
    }
    return nil;
}
static UIViewController *OwningVC(UIView *v) {
    for (UIResponder *r = v; r; r = r.nextResponder)
        if ([r isKindOfClass:[UIViewController class]]) return (UIViewController *)r;
    return nil;
}
// The leftmost header button of a pushed screen is MAX's back button.
static BOOL IsBackButton(UIView *btn) {
    if ([btn convertPoint:CGPointZero toView:nil].x > 30.0) return NO;
    UIViewController *vc = OwningVC(btn);
    for (UIViewController *p = vc; p; p = p.parentViewController) {
        UINavigationController *nc = p.navigationController;
        if (nc && p.parentViewController == nc) return [nc.viewControllers indexOfObject:p] > 0;
    }
    return NO;
}
static const void *kChevronKey = &kChevronKey;

// Neighbouring header buttons sit ~40pt apart, so the glass circle must shrink to leave a gap.
static CGFloat GlassSide(UIView *btn) {
    CGFloat side = 44.0;
    CGPoint me = [btn convertPoint:CGPointMake(CGRectGetMidX(btn.bounds), CGRectGetMidY(btn.bounds)) toView:nil];
    for (UIView *o in btn.superview.subviews) {
        if (o == btn || o.hidden || [o class] != [btn class]) continue;
        CGPoint c = [o convertPoint:CGPointMake(CGRectGetMidX(o.bounds), CGRectGetMidY(o.bounds)) toView:nil];
        if (fabs(c.y - me.y) > 10) continue;
        side = MIN(side, fabs(c.x - me.x) - 6.0);
    }
    return MAX(side, 34.0);
}

static void (*o_btnLayout)(id, SEL);
static void h_btnLayout(UIView *self, SEL _cmd) {
    o_btnLayout(self, _cmd);
    @try {
        if (!HasAncestorNamed(self, @"OMUIKit.NavigationBarHeaderView")) return;   // nav header only, not list rows
        BOOL back = IsBackButton(self);
        UIColor *tint = nil;
        if (!objc_getAssociatedObject(self, kGlassKey) && !back) tint = TakeInnerFill(self, nil);
        UIVisualEffectView *g = GlassBackground(self, tint);   // the fill's colour tints the whole glass button
        if (!g) return;
        self.clipsToBounds = NO;
        CGFloat side = back ? 44.0 : GlassSide(self);
        CGRect r = CGRectMake(CGRectGetMidX(self.bounds) - side / 2, CGRectGetMidY(self.bounds) - side / 2, side, side);
        // Keep edge buttons (back, QR, edit) 8pt inside the screen: move the whole button, icon included.
        CGFloat cx = [self.superview convertPoint:self.center toView:nil].x, sw = CGRectGetWidth(self.window.bounds);
        CGFloat dx = MAX(0.0, 8.0 - (cx - side / 2));
        if (sw > 0) dx = MIN(dx, 0.0) + dx + MIN(0.0, (sw - 8.0) - (cx + side / 2 + dx));
        self.transform = CGAffineTransformMakeTranslation(dx, 0);
        g.frame = r;
        g.layer.cornerRadius = side / 2;
        [self sendSubviewToBack:g];
        if (back) {
            // System back button look: SF chevron.backward centred in the glass, MAX's own icon hidden.
            UIImageView *chev = objc_getAssociatedObject(self, kChevronKey);
            if (!chev) {
                UIImageSymbolConfiguration *cfg = [UIImageSymbolConfiguration configurationWithPointSize:19 weight:UIImageSymbolWeightSemibold];
                chev = [[UIImageView alloc] initWithImage:[UIImage systemImageNamed:@"chevron.backward" withConfiguration:cfg]];
                chev.tintColor = UIColor.labelColor;
                chev.contentMode = UIViewContentModeCenter;
                chev.userInteractionEnabled = NO;
                [self addSubview:chev];
                objc_setAssociatedObject(self, kChevronKey, chev, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
            }
            for (UIView *sv in self.subviews) if (sv != g && sv != chev) sv.alpha = 0.0;
            chev.frame = r;
            [self bringSubviewToFront:chev];
        }
    } @catch (__unused id e) {}
}

static void (*o_searchLayout)(id, SEL);
static void h_searchLayout(UIView *self, SEL _cmd) {
    o_searchLayout(self, _cmd);
    @try {
        UIVisualEffectView *g = GlassBackground(self, nil);
        if (!g) return;
        self.backgroundColor = nil;
        if ([self isKindOfClass:[UITextField class]]) {
            ((UITextField *)self).background = nil;
            ((UITextField *)self).borderStyle = UITextBorderStyleNone;
        }
        self.layer.backgroundColor = NULL;
        g.frame = self.bounds;
        g.layer.cornerRadius = CGRectGetHeight(self.bounds) / 2;
        [self sendSubviewToBack:g];
    } @catch (__unused id e) {}
}

// MAX's Swift UI classes live in its own frameworks/executable; resolve them once the app is up.
static void InstallControlHooks(void) {
    static BOOL done;
    if (done) return;
    Class btn = objc_getClass("OMUIShareKit.ButtonIconComponentView");
    Class search = objc_getClass("OMUIKit.SearchTextField");
    if (btn) Hook(btn, @selector(layoutSubviews), (IMP)h_btnLayout, (IMP *)&o_btnLayout);
    if (search) Hook(search, @selector(layoutSubviews), (IMP)h_searchLayout, (IMP *)&o_searchLayout);
    done = btn || search;
}

// --- Comment-send crash guard -------------------------------------------------------------------
// MAX 26.35 changed a send-response payload from a dictionary to a plain string. diyordeMax's comment
// send-response handler still does response[@"key"] on it, so it sends objectForKeyedSubscript: to an
// __NSCFString and aborts with "unrecognized selector". A string is never a dictionary, so answering
// that selector with nil instead of crashing is safe: the native send already happened, diyorde just
// skips its post-processing. This affects only the (always-wrong) pattern of subscripting a string.
@interface NSString (MAXGlassCommentGuard)
@end
@implementation NSString (MAXGlassCommentGuard)
- (id)objectForKeyedSubscript:(__unused id)key { return nil; }
@end

__attribute__((constructor))
static void MAXGlassInit(void) {
    if (@available(iOS 26.0, *)) {} else return;
    Class c = objc_getClass("UITabBar");
    if (!c) return;
    Hook(c, @selector(layoutSubviews), (IMP)h_layout, (IMP *)&o_layout);
    Hook(c, @selector(setAlpha:), (IMP)h_setAlpha, (IMP *)&o_setAlpha);
    Hook(c, @selector(setHidden:), (IMP)h_setHidden, (IMP *)&o_setHidden);
    InstallControlHooks();
    [[NSNotificationCenter defaultCenter] addObserverForName:UIApplicationDidFinishLaunchingNotification object:nil
        queue:NSOperationQueue.mainQueue usingBlock:^(__unused NSNotification *n) { InstallControlHooks(); }];
#if MX_SCREEN_DIAG
    Method vm = class_getInstanceMethod([UIViewController class], @selector(viewDidAppear:));
    o_viewDidAppear = (void (*)(id, SEL, BOOL))method_setImplementation(vm, (IMP)h_viewDidAppear);
#endif
}
