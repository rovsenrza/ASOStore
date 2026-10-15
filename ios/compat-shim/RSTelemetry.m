// Launch reports for re-signed apps (part of RuStoreCompat).
//
// When an app we installed will not open, the customer cannot tell us why and should not have
// to. This records what happened at the last launch inside the app's own container and sends it
// to ruappstore.com on the next launch:
//   CRASHED       a fatal signal or an uncaught Objective-C exception, with the backtrace;
//   EXITED_EARLY  exit() within the first 30 seconds (jailbreak/sideload checks quit this way),
//                 with the backtrace of whoever called it;
//   LAUNCHED      the app has stayed up for 10 seconds (once per app version).
// Only technical facts are sent: our bundle ID, the vendor's bundle ID, the app version, the
// iOS version and the device model. Previous signal and exception handlers keep running, so
// iOS crash logs and the app's own crash reporter see the same crash.
#import <Foundation/Foundation.h>
#import <execinfo.h>
#import <fcntl.h>
#import <signal.h>
#import <sys/sysctl.h>
#import <unistd.h>

#define RS_TELEMETRY_URL @"https://ruappstore.com/api/v1/telemetry/launch"
#define RS_SHIM_VERSION @"4"

static char gPendingPath[1024];              // Written from a signal handler: a plain C path.
static volatile sig_atomic_t gReported;      // Something was written for this launch already.
static CFAbsoluteTime gLaunchedAt;
static NSUncaughtExceptionHandler *gPreviousExceptionHandler;
static const int gSignals[] = {SIGSEGV, SIGBUS, SIGILL, SIGTRAP, SIGABRT, SIGFPE, SIGSYS};
static struct sigaction gPreviousActions[sizeof(gSignals) / sizeof(gSignals[0])];

static void RSWriteString(int fd, const char *text) {
    size_t length = strlen(text);
    while (length > 0) {
        ssize_t written = write(fd, text, length);
        if (written <= 0) return;
        text += written;
        length -= (size_t)written;
    }
}

// Async-signal-safe: open/write/backtrace only, no allocation, no Objective-C.
static void RSWritePending(const char *kind, const char *detail) {
    if (gPendingPath[0] == '\0') return;
    int fd = open(gPendingPath, O_WRONLY | O_CREAT | O_TRUNC, 0600);
    if (fd < 0) return;
    RSWriteString(fd, kind);
    RSWriteString(fd, "\n");
    RSWriteString(fd, detail);
    RSWriteString(fd, "\n");
    void *frames[48];
    int count = backtrace(frames, 48);
    backtrace_symbols_fd(frames, count, fd);
    close(fd);
}

static const char *RSSignalName(int signal) {
    switch (signal) {
        case SIGSEGV: return "SIGSEGV";
        case SIGBUS: return "SIGBUS";
        case SIGILL: return "SIGILL";
        case SIGTRAP: return "SIGTRAP (Swift runtime error or breakpoint)";
        case SIGABRT: return "SIGABRT";
        case SIGFPE: return "SIGFPE";
        case SIGSYS: return "SIGSYS";
        default: return "signal";
    }
}

static void RSSignalHandler(int signal, siginfo_t *info, void *context) {
    if (!gReported) {
        gReported = 1;
        RSWritePending("CRASHED", RSSignalName(signal));
    }
    // Hand the signal back to whoever had it before, then to the system.
    for (size_t i = 0; i < sizeof(gSignals) / sizeof(gSignals[0]); i++) {
        if (gSignals[i] != signal) continue;
        struct sigaction previous = gPreviousActions[i];
        if ((previous.sa_flags & SA_SIGINFO) && previous.sa_sigaction != NULL) {
            previous.sa_sigaction(signal, info, context);
            return;
        }
        if (previous.sa_handler != SIG_DFL && previous.sa_handler != SIG_IGN && previous.sa_handler != NULL) {
            previous.sa_handler(signal);
            return;
        }
        sigaction(signal, &previous, NULL);
        raise(signal);
        return;
    }
}

static void RSExceptionHandler(NSException *exception) {
    if (!gReported) {
        gReported = 1;
        NSString *detail = [NSString stringWithFormat:@"%@: %@", exception.name, exception.reason ?: @""];
        NSArray *symbols = exception.callStackSymbols ?: @[];
        NSString *text = [NSString stringWithFormat:@"CRASHED\n%@\n%@\n",
                          [detail stringByReplacingOccurrencesOfString:@"\n" withString:@" "],
                          [symbols componentsJoinedByString:@"\n"]];
        [text writeToFile:@(gPendingPath) atomically:YES encoding:NSUTF8StringEncoding error:NULL];
    }
    if (gPreviousExceptionHandler != NULL) gPreviousExceptionHandler(exception);
}

static void RSAtExit(void) {
    if (gReported || CFAbsoluteTimeGetCurrent() - gLaunchedAt > 30) return;
    gReported = 1;
    RSWritePending("EXITED_EARLY", "exit() during launch");
}

#pragma mark - Sending

static NSString *RSDeviceModel(void) {
    char model[64] = {0};
    size_t size = sizeof(model) - 1;
    if (sysctlbyname("hw.machine", model, &size, NULL, 0) != 0) return @"";
    return @(model);
}

static NSString *RSOSVersion(void) {
    NSOperatingSystemVersion v = NSProcessInfo.processInfo.operatingSystemVersion;
    return v.patchVersion > 0 ? [NSString stringWithFormat:@"%ld.%ld.%ld", (long)v.majorVersion, (long)v.minorVersion, (long)v.patchVersion]
                              : [NSString stringWithFormat:@"%ld.%ld", (long)v.majorVersion, (long)v.minorVersion];
}

/// Posts one report. With wait > 0 the caller blocks up to that long (the next launch may die too).
static BOOL RSSend(NSDictionary *base, NSString *kind, NSString *detail, NSArray<NSString *> *backtrace, NSTimeInterval wait) {
    NSMutableDictionary *body = [base mutableCopy];
    body[@"kind"] = kind;
    if (detail.length > 0) body[@"detail"] = [detail substringToIndex:MIN(detail.length, (NSUInteger)1900)];
    if (backtrace.count > 0) {
        NSMutableArray *lines = [NSMutableArray array];
        for (NSString *line in [backtrace subarrayWithRange:NSMakeRange(0, MIN(backtrace.count, (NSUInteger)60))]) {
            NSString *trimmed = [line stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceCharacterSet];
            [lines addObject:[trimmed substringToIndex:MIN(trimmed.length, (NSUInteger)290)]];
        }
        body[@"backtrace"] = lines;
    }
    NSData *json = [NSJSONSerialization dataWithJSONObject:body options:0 error:NULL];
    if (json == nil) return NO;

    NSMutableURLRequest *request = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:RS_TELEMETRY_URL]];
    request.HTTPMethod = @"POST";
    request.HTTPBody = json;
    request.timeoutInterval = 8;
    [request setValue:@"application/json" forHTTPHeaderField:@"Content-Type"];
    [request setValue:@"application/json" forHTTPHeaderField:@"Accept"];
    NSURLSession *session = [NSURLSession sessionWithConfiguration:NSURLSessionConfiguration.ephemeralSessionConfiguration];

    __block BOOL delivered = NO;
    dispatch_semaphore_t done = dispatch_semaphore_create(0);
    [[session dataTaskWithRequest:request completionHandler:^(NSData *data, NSURLResponse *response, NSError *error) {
        NSInteger status = [response isKindOfClass:NSHTTPURLResponse.class] ? ((NSHTTPURLResponse *)response).statusCode : 0;
        delivered = error == nil && status >= 200 && status < 300;
        dispatch_semaphore_signal(done);
    }] resume];
    [session finishTasksAndInvalidate];
    if (wait > 0) dispatch_semaphore_wait(done, dispatch_time(DISPATCH_TIME_NOW, (int64_t)(wait * NSEC_PER_SEC)));
    return delivered;
}

#pragma mark - Start

void RSTelemetryStart(NSString *originalBundleID) {
    NSString *bundleID = NSBundle.mainBundle.bundleIdentifier;
    // Only apps we signed; never report from anything else the shim might end up in.
    if (![bundleID hasPrefix:@"com.ruappstore."]) return;
    gLaunchedAt = CFAbsoluteTimeGetCurrent();

    NSString *folder = [NSHomeDirectory() stringByAppendingPathComponent:@"Library/Caches/RuStoreLaunch"];
    [NSFileManager.defaultManager createDirectoryAtPath:folder withIntermediateDirectories:YES attributes:nil error:NULL];
    NSString *pending = [folder stringByAppendingPathComponent:@"pending.txt"];
    strlcpy(gPendingPath, pending.fileSystemRepresentation, sizeof(gPendingPath));

    NSString *version = [NSBundle.mainBundle objectForInfoDictionaryKey:@"CFBundleShortVersionString"];
    NSMutableDictionary *base = [@{
        @"bundle": bundleID,
        @"os": RSOSVersion(),
        @"model": RSDeviceModel(),
        @"shim": RS_SHIM_VERSION,
    } mutableCopy];
    if ([version isKindOfClass:NSString.class]) base[@"version"] = version;
    if (originalBundleID.length > 0) base[@"original_bundle"] = originalBundleID;

    // What the last launch left behind goes first, and briefly blocks this launch: it may die
    // the same way before an asynchronous request could finish.
    NSString *last = [NSString stringWithContentsOfFile:pending encoding:NSUTF8StringEncoding error:NULL];
    if (last.length > 0) {
        NSArray<NSString *> *lines = [last componentsSeparatedByString:@"\n"];
        NSDictionary *attributes = [NSFileManager.defaultManager attributesOfItemAtPath:pending error:NULL];
        NSMutableDictionary *report = [base mutableCopy];
        NSDate *at = attributes.fileModificationDate;
        if (at != nil) report[@"at"] = @((long long)at.timeIntervalSince1970);
        NSString *kind = lines.count > 0 && [lines[0] isEqualToString:@"EXITED_EARLY"] ? @"EXITED_EARLY" : @"CRASHED";
        NSString *detail = lines.count > 1 ? lines[1] : @"";
        NSArray *trace = lines.count > 2 ? [lines subarrayWithRange:NSMakeRange(2, lines.count - 2)] : @[];
        trace = [trace filteredArrayUsingPredicate:[NSPredicate predicateWithFormat:@"length > 0"]];
        // Drop the report once delivered; keep it for the next launch when the network failed.
        if (RSSend(report, kind, detail, trace, 2.5)) {
            [NSFileManager.defaultManager removeItemAtPath:pending error:NULL];
        }
    }

    for (size_t i = 0; i < sizeof(gSignals) / sizeof(gSignals[0]); i++) {
        struct sigaction action = {0};
        action.sa_sigaction = RSSignalHandler;
        action.sa_flags = SA_SIGINFO | SA_ONSTACK;
        sigemptyset(&action.sa_mask);
        sigaction(gSignals[i], &action, &gPreviousActions[i]);
    }
    gPreviousExceptionHandler = NSGetUncaughtExceptionHandler();
    NSSetUncaughtExceptionHandler(RSExceptionHandler);
    atexit(RSAtExit);

    // Up for ten seconds: the launch held. Once per app version and shim.
    NSString *marker = [folder stringByAppendingPathComponent:[NSString stringWithFormat:@"launched-%@-%@", version ?: @"?", RS_SHIM_VERSION]];
    if (![NSFileManager.defaultManager fileExistsAtPath:marker]) {
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, 10 * NSEC_PER_SEC), dispatch_get_global_queue(QOS_CLASS_UTILITY, 0), ^{
            if (gReported) return;
            if (RSSend(base, @"LAUNCHED", nil, nil, 10)) {
                [NSData.data writeToFile:marker atomically:YES];
            }
        });
    }
}
