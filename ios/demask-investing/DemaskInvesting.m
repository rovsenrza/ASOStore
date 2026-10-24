// 1) Boot the real InvestingLib (Т-Инвест) app delegate (UIApplicationMain hook).
// 2) Swallow anti-tamper brk traps: a SIGTRAP/SIGILL handler advances PC past the
//    4-byte brk so PathFinderBase's deferred integrity checks cannot kill the app.
// Paired with the PathFinderBase method.46 gate patch (forces the real-setup branch).
#import <UIKit/UIKit.h>
#import <Foundation/Foundation.h>
#import "fishhook.h"
#import <signal.h>
#import <sys/ucontext.h>
#import <dlfcn.h>

static int (*orig_UIApplicationMain)(int, char * _Nullable *, NSString * _Nullable, NSString * _Nullable);
static NSString *const kRealDelegate  = @"_TtC12InvestingLib11AppDelegate";
static NSString *const kRealPrincipal = @"_TtC12InvestingLib15MainApplication";

static int demask_UIApplicationMain(int argc, char * _Nullable *argv,
                                    NSString * _Nullable principal, NSString * _Nullable delegate) {
    Class d = NSClassFromString(kRealDelegate);
    Class p = NSClassFromString(kRealPrincipal);
    if (d != nil) {
        NSLog(@"[demask] booting real InvestingLib delegate");
        return orig_UIApplicationMain(argc, argv, p ? kRealPrincipal : nil, kRealDelegate);
    }
    return orig_UIApplicationMain(argc, argv, principal, delegate);
}

static volatile unsigned long g_traps = 0;
static void trap_handler(int sig, siginfo_t *info, void *ucv) {
    ucontext_t *uc = (ucontext_t *)ucv;
    // Advance past the trapping 4-byte instruction (brk) and continue.
    uint64_t pc = (uint64_t)arm_thread_state64_get_pc(uc->uc_mcontext->__ss);
    arm_thread_state64_set_pc_fptr(uc->uc_mcontext->__ss, (void *)(pc + 4));
    g_traps++;
}


// Keep OUR trap handler authoritative: block anyone else (the RuStoreCompat telemetry
// shim) from (re)installing a SIGTRAP/SIGILL handler that would report+terminate.
static int (*orig_sigaction)(int, const struct sigaction * _Nullable, struct sigaction * _Nullable);
static int my_sigaction(int sig, const struct sigaction * _Nullable act, struct sigaction * _Nullable old){
    if (sig == SIGTRAP || sig == SIGILL) { if (old) memset(old, 0, sizeof(*old)); return 0; } // pretend success, don't install
    return orig_sigaction(sig, act, old);
}

static void install_traps(void) {
    int (*sa_fn)(int, const struct sigaction *, struct sigaction *) = orig_sigaction;
    if (!sa_fn) sa_fn = dlsym(RTLD_DEFAULT, "sigaction");
    if (!sa_fn) return;
    struct sigaction sa; memset(&sa, 0, sizeof(sa));
    sa.sa_sigaction = trap_handler;
    sa.sa_flags = SA_SIGINFO;
    sigemptyset(&sa.sa_mask);
    sa_fn(SIGTRAP, &sa, NULL);   // override the shim's handler
    sa_fn(SIGILL,  &sa, NULL);
}

__attribute__((constructor)) static void demask_init(void) {
    struct rebinding rb[] = {
        { "UIApplicationMain", (void*)demask_UIApplicationMain, (void**)&orig_UIApplicationMain },
        { "sigaction", (void*)my_sigaction, (void**)&orig_sigaction },
    };
    rebind_symbols(rb, 2);
    install_traps();
    // Re-install repeatedly so we override the telemetry shim's handler before the
    // deferred anti-tamper check fires.
    for (int t = 0; t <= 12; t++) {
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(t * 0.5 * NSEC_PER_SEC)),
                       dispatch_get_global_queue(0,0), ^{ install_traps(); });
    }
}
