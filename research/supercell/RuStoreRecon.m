// RuStoreRecon — investigation-only dylib to locate Supercell's anti-tamper check.
//
// Built to be dropped in at Frameworks/FridaGadget.dylib (the first LC_LOAD_DYLIB in the
// re-signed laser binary), so its constructor runs FIRST — before Supercell's integrity
// check in an early framework initializer. It fishhooks the detection primitives the guard
// is likely to use (csops, ptrace, sysctl, syscall, getppid, dlopen, open/stat, exit/abort)
// across every image in the app bundle, and logs each call with a backtrace to
// Library/Caches/RuStoreRecon/recon.log. The last entries before the process dies point at
// the check routine. No network. NOT shipped — a diagnostic build only.
#import <Foundation/Foundation.h>
#import <dlfcn.h>
#import <execinfo.h>
#import <mach-o/dyld.h>
#import <pthread.h>
#import <signal.h>
#import <stdio.h>
#import <string.h>
#import "fishhook.h"

static FILE *gLog;
static pthread_mutex_t gLock = PTHREAD_MUTEX_INITIALIZER;
static const char *gAppRoot;

static void RLog(const char *tag, const char *extra, void *const *bt, int btn) {
    if (gLog == NULL) return;
    pthread_mutex_lock(&gLock);
    fprintf(gLog, "[%s] %s\n", tag, extra ? extra : "");
    if (bt != NULL && btn > 0) {
        char **syms = backtrace_symbols(bt, btn);
        for (int i = 2; i < btn && i < 16; i++) {  // skip our wrapper + backtrace frame
            Dl_info info;
            if (dladdr(bt[i], &info) && info.dli_fname) {
                const char *base = strrchr(info.dli_fname, '/');
                fprintf(gLog, "    %s+0x%lx\n", base ? base + 1 : info.dli_fname,
                        (unsigned long)((char *)bt[i] - (char *)info.dli_fbase));
            } else if (syms) {
                fprintf(gLog, "    %s\n", syms[i]);
            }
        }
        if (syms) free(syms);
    }
    fflush(gLog);
    pthread_mutex_unlock(&gLock);
}

#define CAP() void *bt[24]; int btn = backtrace(bt, 24)

// --- wrappers ---
static int (*o_csops)(pid_t, unsigned int, void *, size_t);
static int w_csops(pid_t pid, unsigned int ops, void *blob, size_t size) {
    CAP(); char e[64]; snprintf(e, sizeof e, "pid=%d ops=%u", pid, ops); RLog("csops", e, bt, btn);
    return o_csops(pid, ops, blob, size);
}
static int (*o_csops_audittoken)(pid_t, unsigned int, void *, size_t, void *);
static int w_csops_audittoken(pid_t pid, unsigned int ops, void *blob, size_t size, void *tok) {
    CAP(); char e[64]; snprintf(e, sizeof e, "ops=%u", ops); RLog("csops_audittoken", e, bt, btn);
    return o_csops_audittoken(pid, ops, blob, size, tok);
}
static int (*o_ptrace)(int, pid_t, caddr_t, int);
static int w_ptrace(int req, pid_t pid, caddr_t addr, int data) {
    CAP(); char e[48]; snprintf(e, sizeof e, "req=%d", req); RLog("ptrace", e, bt, btn);
    return o_ptrace(req, pid, addr, data);
}
static long (*o_syscall)(long, ...);
// Cannot forward variadic cleanly; only log ptrace(26)/csops(169) via syscall, then call through
// with up to 6 args which covers these.
static long w_syscall(long n, long a1, long a2, long a3, long a4, long a5, long a6) {
    if (n == 26 || n == 169) { CAP(); char e[32]; snprintf(e, sizeof e, "n=%ld", n); RLog("syscall", e, bt, btn); }
    return o_syscall(n, a1, a2, a3, a4, a5, a6);
}
static int (*o_sysctl)(int *, unsigned int, void *, size_t *, void *, size_t);
static int w_sysctl(int *name, unsigned int namelen, void *oldp, size_t *oldlenp, void *newp, size_t newlen) {
    CAP(); char e[64]; snprintf(e, sizeof e, "mib0=%d mib1=%d len=%u", namelen > 0 ? name[0] : -1, namelen > 1 ? name[1] : -1, namelen); RLog("sysctl", e, bt, btn);
    return o_sysctl(name, namelen, oldp, oldlenp, newp, newlen);
}
static int (*o_sysctlbyname)(const char *, void *, size_t *, void *, size_t);
static int w_sysctlbyname(const char *nm, void *oldp, size_t *oldlenp, void *newp, size_t newlen) {
    CAP(); RLog("sysctlbyname", nm, bt, btn);
    return o_sysctlbyname(nm, oldp, oldlenp, newp, newlen);
}
static pid_t (*o_getppid)(void);
static pid_t w_getppid(void) { CAP(); RLog("getppid", "", bt, btn); return o_getppid(); }
static void *(*o_dlopen)(const char *, int);
static void *w_dlopen(const char *path, int mode) { CAP(); RLog("dlopen", path ? path : "(null)", bt, btn); return o_dlopen(path, mode); }
static int (*o_open)(const char *, int, ...);
static int w_open(const char *path, int flags, mode_t mode) {
    if (path && (strstr(path, "frida") || strstr(path, "cydia") || strstr(path, "substrate") || strstr(path, "jailbreak") || strstr(path, "/var/jb") || strstr(path, "cycript") || strstr(path, "/bin/"))) { CAP(); RLog("open", path, bt, btn); }
    return o_open(path, flags, mode);
}
static int (*o_stat)(const char *, void *);
static int w_stat(const char *path, void *buf) {
    if (path && (strstr(path, "frida") || strstr(path, "cydia") || strstr(path, "substrate") || strstr(path, "/var/jb") || strstr(path, "cycript"))) { CAP(); RLog("stat", path, bt, btn); }
    return o_stat(path, buf);
}
static void (*o_exit)(int);
static void w_exit(int c) { CAP(); char e[16]; snprintf(e, sizeof e, "code=%d", c); RLog("KILL:exit", e, bt, btn); o_exit(c); }
static void (*o_abort)(void);
static void w_abort(void) { CAP(); RLog("KILL:abort", "", bt, btn); o_abort(); }
static int (*o_kill)(pid_t, int);
static int w_kill(pid_t pid, int sig) { CAP(); char e[32]; snprintf(e, sizeof e, "pid=%d sig=%d", pid, sig); RLog("KILL:kill", e, bt, btn); return o_kill(pid, sig); }
static int (*o_pthread_kill)(pthread_t, int);
static int w_pthread_kill(pthread_t t, int sig) { CAP(); char e[16]; snprintf(e, sizeof e, "sig=%d", sig); RLog("KILL:pthread_kill", e, bt, btn); return o_pthread_kill(t, sig); }

static struct rebinding gRebind[] = {
    {"csops", (void *)w_csops, (void **)&o_csops},
    {"csops_audittoken", (void *)w_csops_audittoken, (void **)&o_csops_audittoken},
    {"ptrace", (void *)w_ptrace, (void **)&o_ptrace},
    {"syscall", (void *)w_syscall, (void **)&o_syscall},
    {"sysctl", (void *)w_sysctl, (void **)&o_sysctl},
    {"sysctlbyname", (void *)w_sysctlbyname, (void **)&o_sysctlbyname},
    {"getppid", (void *)w_getppid, (void **)&o_getppid},
    {"dlopen", (void *)w_dlopen, (void **)&o_dlopen},
    {"open", (void *)w_open, (void **)&o_open},
    {"stat", (void *)w_stat, (void **)&o_stat},
    {"exit", (void *)w_exit, (void **)&o_exit},
    {"abort", (void *)w_abort, (void **)&o_abort},
    {"kill", (void *)w_kill, (void **)&o_kill},
    {"pthread_kill", (void *)w_pthread_kill, (void **)&o_pthread_kill},
};
static const int gRebindCount = sizeof(gRebind) / sizeof(gRebind[0]);

// Rebind every image inside the app bundle (now and as they load), so Scid/laserx are covered.
static void RAddImage(const struct mach_header *header, intptr_t slide) {
    Dl_info info;
    if (gAppRoot == NULL || dladdr(header, &info) == 0 || info.dli_fname == NULL) return;
    if (strncmp(info.dli_fname, gAppRoot, strlen(gAppRoot)) != 0) return;
    rebind_symbols_image((void *)header, slide, gRebind, gRebindCount);
}

static void RSignal(int sig, siginfo_t *info, void *uap) {
    char e[96];
    snprintf(e, sizeof e, "signal=%d addr=%p", sig, info ? info->si_addr : NULL);
    void *bt[24]; int btn = backtrace(bt, 24);
    RLog("FAULT", e, bt, btn);   // sp may be poisoned; backtrace is best-effort
    signal(sig, SIG_DFL);
    raise(sig);
}

__attribute__((constructor))
static void RuStoreReconInit(void) {
    @autoreleasepool {
        NSString *dir = [NSHomeDirectory() stringByAppendingPathComponent:@"Library/Caches/RuStoreRecon"];
        [NSFileManager.defaultManager createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:NULL];
        NSString *path = [dir stringByAppendingPathComponent:@"recon.log"];
        gLog = fopen(path.fileSystemRepresentation, "a");

        NSString *bundlePath = NSBundle.mainBundle.bundlePath;
        NSRange app = [bundlePath rangeOfString:@".app"];
        NSString *root = app.location == NSNotFound ? bundlePath : [bundlePath substringToIndex:app.location + app.length];
        gAppRoot = strdup([root stringByAppendingString:@"/"].fileSystemRepresentation);

        RLog("START", [[NSDate date].description UTF8String], NULL, 0);
        RLog("APPROOT", gAppRoot, NULL, 0);

        for (int s = 0; s < 3; s++) {
            int sigs[] = {SIGSEGV, SIGBUS, SIGTRAP};
            struct sigaction sa = {0};
            sa.sa_sigaction = RSignal;
            sa.sa_flags = SA_SIGINFO;
            sigemptyset(&sa.sa_mask);
            sigaction(sigs[s], &sa, NULL);
        }

        _dyld_register_func_for_add_image(RAddImage);  // rebinds current + future app images
        RLog("READY", "hooks armed", NULL, 0);
    }
}
