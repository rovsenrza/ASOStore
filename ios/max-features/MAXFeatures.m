// MAXFeatures.dylib — long-press reaction row for re-signed MAX.
// On long-press MAX calls historyDidRequestContextMenuActionsForMessage:… on its history delegate,
// passing the message (exposing .pk). The same delegate implements
// historyDidDoubleTapMessageWithKey:yourReaction: (how it sends a reaction). We hook the first,
// read message.pk, show our own emoji row above the system menu, and on pick invoke the second.
#import <UIKit/UIKit.h>
#import <QuartzCore/QuartzCore.h>
#import <objc/runtime.h>
#import <objc/message.h>
#import "rlottie/rlottie_capi.h"
static void MxLog(NSString *line);
// Our own channel-post comments UI (thread view wired to MAX's backend over WS). Opened from the
// long-press bar; built here because MAX doesn't serve the native comment UI to this client version.
static void MXOpenComments(NSNumber *channel, NSNumber *post, NSNumber *postTime);
static __weak id gOpenCommentsVC;      // the currently-open comment-thread VC (for live push append)
static NSNumber *gOpenCommentsPost;    // its postId, to match incoming push frames
static long long gMyUserId;            // our own account id (own vs other comment bubble), from any frame

static NSArray<NSString *> *QuickEmojis(void) { return @[@"👍", @"❤️", @"🤣", @"🔥", @"😭", @"😍", @"💯", @"🎉"]; }
// MAX's own popular animated reactions (emoji -> public st.max.ru lottie JSON), captured from the app.
// These render as animated lottie in the quick bar, matching MAX's native reaction picker.
static NSArray<NSDictionary *> *QuickReactions(void) {
    NSString *b = @"https://st.max.ru/lottie_old/picker_";
    return @[
        @{@"e":@"👍", @"u":[b stringByAppendingString:@"thumbsup.json"]},
        @{@"e":@"❤️", @"u":[b stringByAppendingString:@"heart.json"]},
        @{@"e":@"🤣", @"u":[b stringByAppendingString:@"rolling_on_the_floor.json"]},
        @{@"e":@"🔥", @"u":[b stringByAppendingString:@"fire.json"]},
        @{@"e":@"😭", @"u":[b stringByAppendingString:@"cry.json"]},
        @{@"e":@"😍", @"u":[b stringByAppendingString:@"heart_eyes.json"]},
        @{@"e":@"💯", @"u":[b stringByAppendingString:@"100.json"]},
        @{@"e":@"🎉", @"u":[b stringByAppendingString:@"tada.json"]},
    ];
}
// Full set of MAX's animated reactions (emoji -> public lottie), captured from the app. The expand
// panel shows only these (animated), matching MAX's reaction picker rather than the full static grid.
static NSArray<NSDictionary *> *AllReactions(void) {
    return @[
        @{@"e":@"🤣", @"u":@"https://st.max.ru/lottie_old/picker_rolling_on_the_floor.json"},
        @{@"e":@"❤️", @"u":@"https://st.max.ru/lottie_old/picker_heart.json"},
        @{@"e":@"👍", @"u":@"https://st.max.ru/lottie_old/picker_thumbsup.json"},
        @{@"e":@"🔥", @"u":@"https://st.max.ru/lottie_old/picker_fire.json"},
        @{@"e":@"😭", @"u":@"https://st.max.ru/lottie_old/picker_cry.json"},
        @{@"e":@"😍", @"u":@"https://st.max.ru/lottie_old/picker_heart_eyes.json"},
        @{@"e":@"💯", @"u":@"https://st.max.ru/lottie_old/picker_100.json"},
        @{@"e":@"✅", @"u":@"https://st.max.ru/lottie/picker_check_mark.json"},
        @{@"e":@"💩", @"u":@"https://st.max.ru/lottie_old/picker_poo.json"},
        @{@"e":@"😡", @"u":@"https://st.max.ru/lottie_old/picker_rage.json"},
        @{@"e":@"🎉", @"u":@"https://st.max.ru/lottie_old/picker_tada.json"},
        @{@"e":@"👎", @"u":@"https://st.max.ru/lottie_old/picker_thumbs_down.json"},
        @{@"e":@"😱", @"u":@"https://st.max.ru/lottie_old/picker_fear.json"},
        @{@"e":@"🤮", @"u":@"https://st.max.ru/lottie_old/picker_vomiting.json"},
        @{@"e":@"⚡️", @"u":@"https://st.max.ru/lottie_old/picker_lighting.json"},
        @{@"e":@"💔", @"u":@"https://st.max.ru/lottie_old/picker_broken_heart.json"},
        @{@"e":@"🤩", @"u":@"https://st.max.ru/lottie_old/picker_stars.json"},
        @{@"e":@"💀", @"u":@"https://st.max.ru/lottie_old/picker_skull.json"},
        @{@"e":@"🤟", @"u":@"https://st.max.ru/lottie_old/picker_love_you_gesture.json"},
        @{@"e":@"🤡", @"u":@"https://st.max.ru/lottie_old/picker_clown.json"},
        @{@"e":@"😎", @"u":@"https://st.max.ru/lottie_old/picker_smiling_face_with_sunglasses.json"},
        @{@"e":@"🙄", @"u":@"https://st.max.ru/lottie_old/picker_eye_roll.json"},
        @{@"e":@"😐", @"u":@"https://st.max.ru/lottie_old/picker_neutral_face.json"},
        @{@"e":@"🤯", @"u":@"https://st.max.ru/lottie_old/picker_exploding_head.json"},
        @{@"e":@"😁", @"u":@"https://st.max.ru/lottie_old/picker_beaming_face_with_smiling_eyes.json"},
        @{@"e":@"🤪", @"u":@"https://st.max.ru/lottie_old/picker_zany.json"},
        @{@"e":@"😉", @"u":@"https://st.max.ru/lottie_old/picker_winking.json"},
        @{@"e":@"🤤", @"u":@"https://st.max.ru/lottie_old/picker_drooling_face.json"},
        @{@"e":@"😇", @"u":@"https://st.max.ru/lottie_old/picker_halo.json"},
        @{@"e":@"😘", @"u":@"https://st.max.ru/lottie_old/picker_kiss.json"},
        @{@"e":@"🥰", @"u":@"https://st.max.ru/lottie_old/picker_love_face.json"},
        @{@"e":@"🥳", @"u":@"https://st.max.ru/lottie_old/picker_partying_face.json"},
        @{@"e":@"🌚", @"u":@"https://st.max.ru/lottie_v1/picker_new_moon.json"},
        @{@"e":@"🌝", @"u":@"https://st.max.ru/lottie_old/picker_full_moon.json"},
        @{@"e":@"😴", @"u":@"https://st.max.ru/lottie_old/picker_sleeping_face.json"},
        @{@"e":@"😈", @"u":@"https://st.max.ru/lottie_old/picker_devil.json"},
        @{@"e":@"🤬", @"u":@"https://st.max.ru/lottie_old/picker_enraged.json"},
        @{@"e":@"🫠", @"u":@"https://st.max.ru/lottie_old/picker_melting_face.json"},
        @{@"e":@"🤔", @"u":@"https://st.max.ru/lottie_old/picker_thinking.json"},
        @{@"e":@"🫡", @"u":@"https://st.max.ru/lottie_old/picker_saluting_face.json"},
        @{@"e":@"😳", @"u":@"https://st.max.ru/lottie_old/picker_flushed_face.json"},
        @{@"e":@"🥱", @"u":@"https://st.max.ru/lottie_old/picker_yawning_face.json"},
        @{@"e":@"😢", @"u":@"https://st.max.ru/lottie_old/picker_crying_face.json"},
        @{@"e":@"🐱", @"u":@"https://st.max.ru/lottie_old/picker_cat.json"},
        @{@"e":@"🐶", @"u":@"https://st.max.ru/lottie_old/picker_dog.json"},
        @{@"e":@"💪", @"u":@"https://st.max.ru/lottie_old/picker_biceps.json"},
        @{@"e":@"🤞", @"u":@"https://st.max.ru/lottie_old/picker_crossed_fingers.json"},
        @{@"e":@"👋", @"u":@"https://st.max.ru/lottie_old/picker_hello.json"},
        @{@"e":@"👏", @"u":@"https://st.max.ru/lottie_old/picker_clap.json"},
        @{@"e":@"🤝", @"u":@"https://st.max.ru/lottie_old/picker_handshake.json"},
        @{@"e":@"👌", @"u":@"https://st.max.ru/lottie_old/picker_ok.json"},
        @{@"e":@"🙏", @"u":@"https://st.max.ru/lottie_old/picker_folded_hands.json"},
        @{@"e":@"💋", @"u":@"https://st.max.ru/lottie_old/picker_lips.json"},
        @{@"e":@"👑", @"u":@"https://st.max.ru/lottie_old/picker_crown.json"},
        @{@"e":@"🍷", @"u":@"https://st.max.ru/lottie_old/picker_wine.json"},
        @{@"e":@"🍑", @"u":@"https://st.max.ru/lottie_old/picker_peach.json"},
        @{@"e":@"🤷‍♀️", @"u":@"https://st.max.ru/lottie_old/picker_woman_shrugging.json"},
        @{@"e":@"🤷‍♂️", @"u":@"https://st.max.ru/lottie_old/picker_man_shrugging.json"},
        @{@"e":@"👩‍❤️‍👨", @"u":@"https://st.max.ru/lottie_old/picker_cuple.json"},
        @{@"e":@"🦄", @"u":@"https://st.max.ru/lottie_old/picker_unicorn.json"},
        @{@"e":@"👻", @"u":@"https://st.max.ru/lottie_old/picker_gost.json"},
        @{@"e":@"⛄️", @"u":@"https://st.max.ru/lottie_v1/picker_snowman.json"},
        @{@"e":@"🎄", @"u":@"https://st.max.ru/lottie_old/picker_christmas_tree.json"},
        @{@"e":@"🎅", @"u":@"https://st.max.ru/lottie_old/picker_santa.json"},
        @{@"e":@"🗿", @"u":@"https://st.max.ru/lottie_old/picker_moai.json"},
        @{@"e":@"👀", @"u":@"https://st.max.ru/lottie_old/picker_eyes.json"},
        @{@"e":@"👁️", @"u":@"https://st.max.ru/lottie_old/picker_eye.json"},
        @{@"e":@"🖤", @"u":@"https://st.max.ru/lottie_old/picker_black_heart.json"},
        @{@"e":@"❤️‍🩹", @"u":@"https://st.max.ru/lottie_old/picker_heart_sick.json"},
        @{@"e":@"🛑", @"u":@"https://st.max.ru/lottie_old/picker_stop.json"},
        @{@"e":@"❓", @"u":@"https://st.max.ru/lottie_old/picker_question_mark.json"},
        @{@"e":@"❗️", @"u":@"https://st.max.ru/lottie_old/picker_exclamation_mark.json"},
        @{@"e":@"🚀", @"u":@"https://st.max.ru/lottie_old/picker_rocket.json"},
        @{@"e":@"🇷🇺", @"u":@"https://st.max.ru/emoji_old/flag/flag.json"},
        @{@"e":@"📢", @"u":@"https://st.max.ru/emoji_old/megaphone/megaphone.json"},
        @{@"e":@"🍁", @"u":@"https://st.max.ru/lottie_old/picker_leaf.json"},
    ];
}
// Disk cache for downloaded lottie JSON (public st.max.ru assets).
static NSString *LottieCachePath(NSString *url) {
    NSString *dir = [NSTemporaryDirectory() stringByAppendingPathComponent:@"mxlottie"];
    [[NSFileManager defaultManager] createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:nil];
    NSString *name = [[url lastPathComponent] stringByReplacingOccurrencesOfString:@"/" withString:@"_"];
    return [dir stringByAppendingPathComponent:name ?: @"a.json"];
}

// Native animated-lottie view: renders a reaction's lottie via rlottie (MAX's own engine family)
// into a UIImageView, driven by a CADisplayLink. Shows the emoji as a fallback until loaded.
@interface MXLottieView : UIView
@property (nonatomic, strong) UILabel *fallback;
@property (nonatomic, strong) UIImageView *iv;
@property (nonatomic, assign) Lottie_Animation *anim;
@property (nonatomic, strong) CADisplayLink *link;
@property (nonatomic, assign) uint32_t *buf;
@property (nonatomic, assign) CGContextRef ctx;
@property (nonatomic, assign) size_t px, total;
@property (nonatomic, assign) double fps;
@property (nonatomic, assign) CFTimeInterval start;
@property (nonatomic, assign) BOOL playing;
@end
@implementation MXLottieView
- (instancetype)initWithFrame:(CGRect)frame {
    if ((self = [super initWithFrame:frame])) {
        self.backgroundColor = UIColor.clearColor;
        _fallback = [[UILabel alloc] initWithFrame:self.bounds];
        _fallback.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
        _fallback.textAlignment = NSTextAlignmentCenter; _fallback.adjustsFontSizeToFitWidth = YES;
        [self addSubview:_fallback];
        _iv = [[UIImageView alloc] initWithFrame:self.bounds];
        _iv.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
        _iv.contentMode = UIViewContentModeScaleAspectFit;
        [self addSubview:_iv];
    }
    return self;
}
- (void)setEmoji:(NSString *)emoji size:(CGFloat)fontSize {
    self.fallback.text = emoji; self.fallback.font = [UIFont systemFontOfSize:fontSize];
}
- (void)loadURL:(NSString *)url {
    NSString *cache = LottieCachePath(url);
    dispatch_async(dispatch_get_global_queue(QOS_CLASS_UTILITY, 0), ^{
        NSData *data = [NSData dataWithContentsOfFile:cache];
        if (!data.length) {
            data = [NSData dataWithContentsOfURL:[NSURL URLWithString:url]];
            if (data.length) [data writeToFile:cache atomically:YES];
        }
        if (!data.length) return;
        NSString *json = [[NSString alloc] initWithData:data encoding:NSUTF8StringEncoding];
        if (!json) return;
        Lottie_Animation *a = lottie_animation_from_data(json.UTF8String, url.UTF8String, "");
        if (!a) return;
        size_t total = lottie_animation_get_totalframe(a);
        double fps = lottie_animation_get_framerate(a); if (fps < 1) fps = 30;
        dispatch_async(dispatch_get_main_queue(), ^{
            if (!self.superview) { lottie_animation_destroy(a); return; }  // gone already
            self.anim = a; self.total = total; self.fps = fps;
            [self prepareBufferAndStart];
        });
    });
}
- (void)prepareBufferAndStart {
    CGFloat scale = UIScreen.mainScreen.scale; if (scale < 1) scale = 2;
    size_t px = (size_t)(MAX(self.bounds.size.width, 24) * scale); if (px < 48) px = 48; if (px > 180) px = 180;
    self.px = px;
    self.buf = (uint32_t *)calloc(px * px, 4);
    CGColorSpaceRef cs = CGColorSpaceCreateDeviceRGB();
    self.ctx = CGBitmapContextCreate(self.buf, px, px, 8, px * 4, cs, kCGImageAlphaPremultipliedFirst | kCGBitmapByteOrder32Little);
    CGColorSpaceRelease(cs);
    self.start = CACurrentMediaTime(); self.playing = YES;
    self.link = [CADisplayLink displayLinkWithTarget:self selector:@selector(tick)];
    self.link.preferredFramesPerSecond = 30;
    [self.link addToRunLoop:NSRunLoop.mainRunLoop forMode:NSRunLoopCommonModes];
}
- (void)tick {
    if (!self.anim || !self.ctx || !self.playing) return;
    size_t frame = self.total ? (size_t)((CACurrentMediaTime() - self.start) * self.fps) % self.total : 0;
    lottie_animation_render(self.anim, frame, self.buf, self.px, self.px, self.px * 4);
    CGImageRef img = CGBitmapContextCreateImage(self.ctx);
    if (img) { self.iv.image = [UIImage imageWithCGImage:img]; CGImageRelease(img); }
}
- (void)setPlaying:(BOOL)playing { _playing = playing; self.link.paused = !playing; }
- (void)stop {
    [self.link invalidate]; self.link = nil;
    if (self.ctx) { CGContextRelease(self.ctx); self.ctx = NULL; }
    if (self.buf) { free(self.buf); self.buf = NULL; }
    if (self.anim) { lottie_animation_destroy(self.anim); self.anim = NULL; }
}
- (void)dealloc { [self stop]; }
@end
// MAX ships its own ordered emoji catalog (EmojiList-iOS*.plist: array of category arrays whose
// items are either an emoji string or {baseEmoji: [skin-tone variants]}). Load it so our picker
// offers exactly the set MAX supports and renders, matching its native emoji sheet.
static NSArray<NSString *> *AllEmojisFromBundle(void) {
    @try {
        NSOperatingSystemVersion os = NSProcessInfo.processInfo.operatingSystemVersion;
        NSArray *names = @[@"EmojiList-iOS17_4", @"EmojiList-iOS16_0", @"EmojiList-iOS14_5"];
        if (os.majorVersion < 17 || (os.majorVersion == 17 && os.minorVersion < 4)) names = @[@"EmojiList-iOS16_0", @"EmojiList-iOS14_5"];
        if (os.majorVersion < 16) names = @[@"EmojiList-iOS14_5"];
        for (NSString *nm in names) {
            NSString *path = [NSBundle.mainBundle pathForResource:nm ofType:@"plist"];
            if (!path) continue;
            NSArray *cats = [NSArray arrayWithContentsOfFile:path];
            if (![cats isKindOfClass:NSArray.class]) continue;
            NSMutableArray *out = [NSMutableArray array];
            for (id cat in cats) {
                if (![cat isKindOfClass:NSArray.class]) continue;
                for (id item in cat) {
                    if ([item isKindOfClass:NSString.class]) [out addObject:item];
                    else if ([item isKindOfClass:NSDictionary.class]) for (id k in item) if ([k isKindOfClass:NSString.class]) [out addObject:k];
                }
            }
            if (out.count > 50) return out;
        }
    } @catch (__unused id e) {}
    return nil;
}
static NSArray<NSString *> *AllEmojisFallback(void) { return @[@"😀",@"😃",@"😄",@"😁",@"😆",@"😅",@"😂",@"🤣",@"🥲",@"☺️",@"😊",@"😇",@"🙂",@"🙃",@"😉",@"😌",@"😍",@"🥰",@"😘",@"😗",@"😙",@"😚",@"😋",@"😛",@"😝",@"😜",@"🤪",@"🤨",@"🧐",@"🤓",@"😎",@"🥸",@"🤩",@"🥳",@"😏",@"😒",@"😞",@"😔",@"😟",@"😕",@"🙁",@"☹️",@"😣",@"😖",@"😫",@"😩",@"🥺",@"😢",@"😭",@"😤",@"😠",@"😡",@"🤬",@"🤯",@"😳",@"🥵",@"🥶",@"😱",@"😨",@"😰",@"😥",@"😓",@"🤗",@"🤔",@"🤭",@"🤫",@"🤥",@"😶",@"😐",@"😑",@"😬",@"🙄",@"😯",@"😦",@"😧",@"😮",@"😲",@"🥱",@"😴",@"🤤",@"😪",@"😵",@"🤐",@"🥴",@"🤢",@"🤮",@"🤧",@"😷",@"🤒",@"🤕",@"🤑",@"🤠",@"😈",@"👿",@"👹",@"👺",@"🤡",@"💩",@"👻",@"💀",@"☠️",@"👽",@"👾",@"🤖",@"🎃",@"👍",@"👎",@"👌",@"🤌",@"🤏",@"✌️",@"🤞",@"🤟",@"🤘",@"🤙",@"👈",@"👉",@"👆",@"👇",@"☝️",@"✋",@"🤚",@"🖐️",@"🖖",@"👋",@"🤝",@"🙏",@"✍️",@"💅",@"🤳",@"💪",@"🦾",@"🦵",@"🦶",@"👂",@"👃",@"🧠",@"👀",@"👁️",@"👅",@"👄",@"🫶",@"🤲",@"👐",@"🙌",@"👏",@"🤜",@"🤛",@"❤️",@"🧡",@"💛",@"💚",@"💙",@"💜",@"🤎",@"🖤",@"🤍",@"💔",@"❣️",@"💕",@"💞",@"💓",@"💗",@"💖",@"💘",@"💝",@"💟",@"💋",@"💌",@"🐶",@"🐱",@"🐭",@"🐹",@"🐰",@"🦊",@"🐻",@"🐼",@"🐨",@"🐯",@"🦁",@"🐮",@"🐷",@"🐸",@"🐵",@"🙈",@"🙉",@"🙊",@"🐔",@"🐧",@"🐦",@"🐤",@"🦆",@"🦅",@"🦉",@"🦇",@"🐺",@"🐗",@"🐴",@"🦄",@"🐝",@"🐛",@"🦋",@"🐌",@"🐞",@"🐜",@"🪲",@"🐢",@"🐍",@"🦎",@"🦖",@"🐙",@"🦑",@"🦀",@"🦞",@"🐠",@"🐟",@"🐡",@"🐬",@"🐳",@"🐋",@"🦈",@"🐊",@"🐆",@"🦓",@"🦍",@"🦧",@"🐘",@"🦛",@"🦏",@"🐪",@"🐫",@"🦒",@"🦘",@"🦬",@"🐂",@"🐄",@"🐎",@"🐖",@"🐏",@"🐑",@"🦙",@"🐐",@"🦌",@"🐕",@"🐩",@"🦮",@"🐈",@"🐓",@"🦃",@"🦚",@"🦜",@"🦢",@"🦩",@"🕊️",@"🐇",@"🦝",@"🦨",@"🦡",@"🦦",@"🦥",@"🐁",@"🐀",@"🐿️",@"🦔",@"🐾",@"🐉",@"🐲",@"🌵",@"🎄",@"🌲",@"🌳",@"🌴",@"🌱",@"🌿",@"☘️",@"🍀",@"🎍",@"🎋",@"🍃",@"🍂",@"🍁",@"🍄",@"🌾",@"💐",@"🌷",@"🌹",@"🥀",@"🌺",@"🌸",@"🌼",@"🌻",@"🍎",@"🍐",@"🍊",@"🍋",@"🍌",@"🍉",@"🍇",@"🍓",@"🫐",@"🍈",@"🍒",@"🍑",@"🥭",@"🍍",@"🥥",@"🥝",@"🍅",@"🍆",@"🥑",@"🥦",@"🥬",@"🥒",@"🌶️",@"🫑",@"🌽",@"🥕",@"🫒",@"🧄",@"🧅",@"🥔",@"🍠",@"🥐",@"🥯",@"🍞",@"🥖",@"🥨",@"🧀",@"🥚",@"🍳",@"🧈",@"🥞",@"🧇",@"🥓",@"🥩",@"🍗",@"🍖",@"🌭",@"🍔",@"🍟",@"🍕",@"🫓",@"🥪",@"🥙",@"🧆",@"🌮",@"🌯",@"🥗",@"🥘",@"🫕",@"🍝",@"🍜",@"🍲",@"🍛",@"🍣",@"🍱",@"🥟",@"🍤",@"🍙",@"🍚",@"🍘",@"🍥",@"🥠",@"🍢",@"🍡",@"🍧",@"🍨",@"🍦",@"🥧",@"🧁",@"🍰",@"🎂",@"🍮",@"🍭",@"🍬",@"🍫",@"🍿",@"🍩",@"🍪",@"🌰",@"🥜",@"🍯",@"🥛",@"🍼",@"☕",@"🍵",@"🧃",@"🥤",@"🧋",@"🍺",@"🍻",@"🥂",@"🍷",@"🥃",@"🍸",@"🍹",@"🧉",@"🍾",@"⚽",@"🏀",@"🏈",@"⚾",@"🥎",@"🎾",@"🏐",@"🏉",@"🥏",@"🎱",@"🪀",@"🏓",@"🏸",@"🏒",@"🏑",@"🥍",@"🏏",@"🥅",@"⛳",@"🪁",@"🏹",@"🎣",@"🥊",@"🥋",@"🎽",@"🛹",@"⛸️",@"🥌",@"🎿",@"⛷️",@"🏂",@"🏋️",@"🤼",@"🤸",@"⛹️",@"🤺",@"🤾",@"🏌️",@"🏇",@"🧘",@"🏄",@"🏊",@"🤽",@"🚣",@"🧗",@"🚵",@"🚴",@"🏆",@"🥇",@"🥈",@"🥉",@"🏅",@"🎖️",@"🏵️",@"🎗️",@"🎫",@"🎟️",@"🎪",@"🎭",@"🎨",@"🎬",@"🎤",@"🎧",@"🎼",@"🎹",@"🥁",@"🎷",@"🎺",@"🎸",@"🪕",@"🎻",@"🎲",@"♟️",@"🎯",@"🎳",@"🎮",@"🎰",@"🧩",@"⭐",@"🌟",@"✨",@"⚡",@"🔥",@"💥",@"☄️",@"☀️",@"🌤️",@"⛅",@"🌥️",@"☁️",@"🌦️",@"🌧️",@"⛈️",@"🌩️",@"🌨️",@"❄️",@"☃️",@"⛄",@"🌬️",@"💨",@"💧",@"💦",@"🌊",@"🎉",@"🎊",@"🎈",@"🎁",@"🎀",@"🪅",@"🎇",@"🎆",@"🧨",@"💯",@"✅",@"❌",@"⭕",@"❗",@"❓",@"❕",@"❔",@"‼️",@"⁉️",@"💢",@"💫",@"💤",@"♻️",@"✔️",@"☑️",@"🇷🇺",@"🇦🇿",@"🇺🇸",@"🇹🇷",@"🇬🇧",@"🇩🇪",@"🇫🇷",@"🇮🇹",@"🇪🇸",@"🇺🇦",@"🇨🇳",@"🇯🇵",@"🇰🇷",@"🇮🇳",@"🇧🇷",@"🇸🇦",@"🇰🇿",@"🇬🇪",@"🏳️",@"🏴",@"🏁",@"🚩"]; }
static NSArray<NSString *> *AllEmojis(void) {
    static NSArray *cached; static dispatch_once_t once;
    dispatch_once(&once, ^{ cached = AllEmojisFromBundle() ?: AllEmojisFallback(); });
    return cached;
}

@interface MAXReactionPicker : NSObject <UICollectionViewDataSource, UICollectionViewDelegateFlowLayout, UIGestureRecognizerDelegate>
@property (nonatomic, copy) void (^onPick)(NSString *emoji);
@property (nonatomic, strong) UIWindow *win;
@property (nonatomic, strong) UIView *overlay;
@property (nonatomic, strong) UIView *bar;
@property (nonatomic, strong) NSTimer *dismissTimer;
@property (nonatomic, strong) NSArray<NSDictionary *> *gridData;
@property (nonatomic, strong) NSMutableArray<MXLottieView *> *liveViews;
@property (nonatomic, weak) id menuCtx;   // MAX's OMPopoverContext for the long-press menu, to close it after a pick
@property (nonatomic, strong) NSNumber *cChannel;   // long-pressed post's channel chatId (for comments)
@property (nonatomic, strong) NSNumber *cPost;      // long-pressed post's serverId (for comments)
@property (nonatomic, strong) NSNumber *cPostTime;  // long-pressed post's time ms (comment-load anchor)
@end
@implementation MAXReactionPicker
- (UIButton *)emojiButton:(NSString *)e size:(CGFloat)sz {
    UIButton *b = [UIButton buttonWithType:UIButtonTypeSystem];
    [b setTitle:e forState:UIControlStateNormal]; b.titleLabel.font = [UIFont systemFontOfSize:30];
    [b addTarget:self action:@selector(pick:) forControlEvents:UIControlEventTouchUpInside];
    [b.widthAnchor constraintEqualToConstant:sz].active = YES; [b.heightAnchor constraintEqualToConstant:44].active = YES;
    return b;
}
// Try to find the system context-menu platter so we can sit just above it.
- (CGRect)menuFrame {
    CGRect best = CGRectNull; CGFloat sw = self.win ? self.win.bounds.size.width : 400;
    for (UIWindow *w in UIApplication.sharedApplication.windows) {
        for (UIView *v in [self flatten:w]) {
            const char *cn = class_getName(object_getClass(v));
            BOOL isMenu = strstr(cn,"ContextMenu")||strstr(cn,"Platter")||strstr(cn,"_UIMenu")||strstr(cn,"MenuList");
            CGFloat bw = v.bounds.size.width, bh = v.bounds.size.height;
            // the actual menu box: a bounded platter, not the full-screen container or dimming view
            if (isMenu && bw >= 120 && bw <= sw*0.85 && bh >= 140) {
                CGRect f = [v convertRect:v.bounds toView:nil];
                if (CGRectIsNull(best) || f.origin.y < best.origin.y) best = f;
            }
        }
    }
    return best;
}
- (NSArray<UIView *> *)flatten:(UIView *)v {
    NSMutableArray *out = [NSMutableArray arrayWithObject:v];
    for (UIView *s in v.subviews) [out addObjectsFromArray:[self flatten:s]];
    return out;
}
- (void)showFrom:(CGPoint)anchor {
    UIWindow *win = nil;
    for (UIWindow *w in UIApplication.sharedApplication.windows) if (w.isKeyWindow) { win = w; break; }
    if (!win) win = UIApplication.sharedApplication.windows.firstObject;
    if (!win) return; self.win = win;
    UIView *dim = [[UIView alloc] initWithFrame:win.bounds];
    dim.backgroundColor = [UIColor colorWithWhite:0 alpha:0.001];
    UITapGestureRecognizer *tg = [[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(dismiss)];
    tg.delegate = self; tg.cancelsTouchesInView = NO;   // don't swallow taps meant for the row/grid
    [dim addGestureRecognizer:tg];
    self.overlay = dim; [win addSubview:dim];
    [self buildQuickBarAnchor:anchor];
    objc_setAssociatedObject(dim, "picker", self, OBJC_ASSOCIATION_RETAIN);
}
- (void)buildQuickBarAnchor:(CGPoint)anchor {
    self.liveViews = [NSMutableArray array];
    UIView *bar = [[UIView alloc] init];
    bar.backgroundColor = [UIColor colorWithWhite:0.17 alpha:0.98];
    bar.layer.cornerRadius = 24; bar.translatesAutoresizingMaskIntoConstraints = NO;
    bar.layer.shadowColor = UIColor.blackColor.CGColor; bar.layer.shadowOpacity = 0.35; bar.layer.shadowRadius = 12;
    [self.overlay addSubview:bar]; self.bar = bar;
    UIStackView *row = [[UIStackView alloc] init];
    row.axis = UILayoutConstraintAxisHorizontal; row.spacing = 4; row.alignment = UIStackViewAlignmentCenter;
    row.layoutMargins = UIEdgeInsetsMake(5, 10, 5, 8); row.layoutMarginsRelativeArrangement = YES;
    row.translatesAutoresizingMaskIntoConstraints = NO; [bar addSubview:row];
    CGFloat cell = 38;
    for (NSDictionary *o in QuickReactions()) {
        MXLottieView *lv = [[MXLottieView alloc] initWithFrame:CGRectMake(0,0,cell,cell)];
        [lv setEmoji:o[@"e"] size:26]; lv.userInteractionEnabled = YES;
        objc_setAssociatedObject(lv, "emoji", o[@"e"], OBJC_ASSOCIATION_RETAIN_NONATOMIC);
        [lv addGestureRecognizer:[[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(reactionTapped:)]];
        [lv.widthAnchor constraintEqualToConstant:cell].active = YES; [lv.heightAnchor constraintEqualToConstant:cell].active = YES;
        [row addArrangedSubview:lv]; [self.liveViews addObject:lv]; [lv loadURL:o[@"u"]];
    }
    UIButton *more = [UIButton buttonWithType:UIButtonTypeSystem];
    [more setImage:[UIImage systemImageNamed:@"chevron.down"] forState:UIControlStateNormal]; more.tintColor = UIColor.whiteColor;
    more.backgroundColor = [UIColor colorWithWhite:0.28 alpha:1]; more.layer.cornerRadius = 18;
    [more addTarget:self action:@selector(expand) forControlEvents:UIControlEventTouchUpInside];
    [more.widthAnchor constraintEqualToConstant:36].active = YES; [more.heightAnchor constraintEqualToConstant:36].active = YES;
    [row addArrangedSubview:more];
    [NSLayoutConstraint activateConstraints:@[
        [row.topAnchor constraintEqualToAnchor:bar.topAnchor], [row.bottomAnchor constraintEqualToAnchor:bar.bottomAnchor],
        [row.leadingAnchor constraintEqualToAnchor:bar.leadingAnchor], [row.trailingAnchor constraintEqualToAnchor:bar.trailingAnchor]]];
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(0.03 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{
        CGRect mf = [self menuFrame];
        [bar.centerXAnchor constraintEqualToAnchor:self.overlay.centerXAnchor].active = YES;
        CGFloat top = self.win.safeAreaInsets.top + 8;
        CGFloat y;
        if (!CGRectIsNull(mf)) y = MAX(top + 25, mf.origin.y - 33);
        else { y = anchor.y - 80; if (y < top + 25) y = anchor.y + 70; }
        [bar.centerYAnchor constraintEqualToAnchor:self.overlay.topAnchor constant:y].active = YES;
        bar.transform = CGAffineTransformMakeScale(0.6, 0.6); bar.alpha = 0;
        [UIView animateWithDuration:0.2 delay:0 usingSpringWithDamping:0.7 initialSpringVelocity:0.5 options:0
                         animations:^{ bar.transform = CGAffineTransformIdentity; bar.alpha = 1; } completion:nil];
    });
}
- (BOOL)gestureRecognizer:(UIGestureRecognizer *)gr shouldReceiveTouch:(UITouch *)touch {
    if (!self.bar) return YES;
    CGPoint p = [touch locationInView:self.bar];
    return !CGRectContainsPoint(self.bar.bounds, p);
}
- (void)reactionTapped:(UITapGestureRecognizer *)g {
    NSString *e = objc_getAssociatedObject(g.view, "emoji");
    if (e.length && self.onPick) self.onPick(e);
    [self dismiss];
}
// ---- Expand grid (UICollectionView of native animated reactions) ----
- (NSInteger)collectionView:(UICollectionView *)cv numberOfItemsInSection:(NSInteger)section { return self.gridData.count; }
- (UICollectionViewCell *)collectionView:(UICollectionView *)cv cellForItemAtIndexPath:(NSIndexPath *)ip {
    UICollectionViewCell *cell = [cv dequeueReusableCellWithReuseIdentifier:@"c" forIndexPath:ip];
    for (UIView *v in cell.contentView.subviews) { if ([v isKindOfClass:MXLottieView.class]) [(MXLottieView *)v stop]; [v removeFromSuperview]; }
    NSDictionary *o = self.gridData[ip.item];
    MXLottieView *lv = [[MXLottieView alloc] initWithFrame:cell.contentView.bounds];
    lv.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
    lv.userInteractionEnabled = NO;   // let the cell receive the tap (didSelectItemAtIndexPath)
    [lv setEmoji:o[@"e"] size:30]; [cell.contentView addSubview:lv]; [lv loadURL:o[@"u"]];
    return cell;
}
- (void)collectionView:(UICollectionView *)cv didSelectItemAtIndexPath:(NSIndexPath *)ip {
    NSString *e = self.gridData[ip.item][@"e"];
    if (e.length && self.onPick) self.onPick(e);
    [self dismiss];
}
- (void)expand {
    for (MXLottieView *lv in self.liveViews) [lv stop];
    [self.liveViews removeAllObjects];
    [self.bar removeFromSuperview];
    self.gridData = AllReactions();
    int cols = 6; CGFloat cell = 52, pad = 8;
    CGFloat pw = cols * cell + pad * 2;
    int rows = ((int)self.gridData.count + cols - 1) / cols;
    CGFloat contentH = rows * cell + pad * 2;
    CGFloat ph = MIN(contentH, 330);
    UICollectionViewFlowLayout *fl = [[UICollectionViewFlowLayout alloc] init];
    fl.itemSize = CGSizeMake(cell - 6, cell - 6); fl.minimumInteritemSpacing = 6; fl.minimumLineSpacing = 6;
    fl.sectionInset = UIEdgeInsetsMake(pad, pad, pad, pad);
    UICollectionView *cvw = [[UICollectionView alloc] initWithFrame:CGRectMake(0,0,pw,ph) collectionViewLayout:fl];
    cvw.backgroundColor = [UIColor colorWithWhite:0.17 alpha:0.99];
    cvw.layer.cornerRadius = 20; cvw.clipsToBounds = YES;
    cvw.dataSource = self; cvw.delegate = self; cvw.translatesAutoresizingMaskIntoConstraints = NO;
    [cvw registerClass:UICollectionViewCell.class forCellWithReuseIdentifier:@"c"];
    [self.overlay addSubview:cvw]; self.bar = cvw;
    [NSLayoutConstraint activateConstraints:@[
        [cvw.widthAnchor constraintEqualToConstant:pw], [cvw.heightAnchor constraintEqualToConstant:ph],
        [cvw.centerXAnchor constraintEqualToAnchor:self.overlay.centerXAnchor],
        [cvw.centerYAnchor constraintEqualToAnchor:self.overlay.centerYAnchor]]];
    cvw.transform = CGAffineTransformMakeScale(0.8, 0.8); cvw.alpha = 0;
    [UIView animateWithDuration:0.18 animations:^{ cvw.transform = CGAffineTransformIdentity; cvw.alpha = 1; }];
}
- (void)pick:(UIButton *)b { if (self.onPick) self.onPick(b.currentTitle); [self dismiss]; }
- (void)dismiss {
    for (MXLottieView *lv in self.liveViews) [lv stop];
    [self.liveViews removeAllObjects];
    if ([self.bar isKindOfClass:UICollectionView.class]) {
        for (UICollectionViewCell *c in [(UICollectionView *)self.bar visibleCells])
            for (UIView *v in c.contentView.subviews) if ([v isKindOfClass:MXLottieView.class]) [(MXLottieView *)v stop];
    }
    [self.overlay removeFromSuperview]; self.overlay = nil;
    [self closeMenu];
}
// Close MAX's own long-press popover menu (OMPopoverContext) after a reaction is picked.
- (void)closeMenu {
    id ctx = self.menuCtx; if (!ctx) return; self.menuCtx = nil;
    @try {
        for (NSString *sn in @[@"dismissWithAnimated:completion:", @"dismissAnimated:completion:", @"dismissWithCompletion:", @"dismissViewControllerAnimated:completion:", @"dismiss", @"dismissAnimated:", @"close", @"hide"]) {
            SEL s = sel_registerName(sn.UTF8String);
            if (![ctx respondsToSelector:s]) continue;
            MxLog([NSString stringWithFormat:@"MENU closing via %@ on %@", sn, NSStringFromClass([ctx class])]);
            NSUInteger colons = [[sn componentsSeparatedByString:@":"] count] - 1;
            if (colons == 0) ((void(*)(id,SEL))objc_msgSend)(ctx, s);
            else if (colons == 1) ((void(*)(id,SEL,BOOL))objc_msgSend)(ctx, s, YES);
            else ((void(*)(id,SEL,BOOL,id))objc_msgSend)(ctx, s, YES, (id)nil);
            return;
        }
        MxLog([NSString stringWithFormat:@"MENU no dismiss selector on %@", NSStringFromClass([ctx class])]);
    } @catch (__unused id e) {}
}
@end

static CGPoint gLastTouch;
static void (*o_sendEvent)(id, SEL, UIEvent *);
static void h_sendEvent(id self, SEL _cmd, UIEvent *event) {
    @try { UITouch *t = event.allTouches.anyObject; if (t) { CGPoint p = [t locationInView:nil]; if (!CGPointEqualToPoint(p, CGPointZero)) gLastTouch = p; } } @catch (__unused id e) {}
    o_sendEvent(self, _cmd, event);
}

static id MsgVal(id message, NSString *key) {
    @try { id v = [message valueForKey:key]; if ([v isKindOfClass:NSString.class]) return v; } @catch (__unused id e) {}
    SEL s = sel_registerName(key.UTF8String);
    if ([message respondsToSelector:s]) { @try { id v = ((id(*)(id,SEL))objc_msgSend)(message, s); if ([v isKindOfClass:NSString.class]) return v; } @catch (__unused id e) {} }
    return nil;
}
static id MsgPk(id m) { id v = MsgVal(m,@"pk"); if(!v) v=MsgVal(m,@"messagePk"); return v; }

// Live OKMChatService, captured so our picker can send the CHOSEN emoji via addReaction:forMessagePk:chatPk:.
static void MxLog(NSString *line) {
    NSLog(@"MXF: %@", line);
    @try {
        NSString *dir = [NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES) firstObject];
        NSString *path = [dir stringByAppendingPathComponent:@"mxf.log"];
        NSFileHandle *h = [NSFileHandle fileHandleForWritingAtPath:path];
        if (!h) { [@"" writeToFile:path atomically:YES encoding:NSUTF8StringEncoding error:nil]; h = [NSFileHandle fileHandleForWritingAtPath:path]; }
        [h seekToEndOfFile]; [h writeData:[[line stringByAppendingString:@"\n"] dataUsingEncoding:NSUTF8StringEncoding allowLossyConversion:YES]]; [h closeFile];
    } @catch (__unused id e) {}
}
// --- remote-config engine globals + fwd decls (engine defined after ResolveChatService) ---
// Lets us iterate the comment-load request (client/method/body variants) by editing a server-side
// config file and reading a server-side report, with NO rebuild/reinstall per attempt.
static NSNumber *gCapChannel;      // seeded = ПЕСНИ channel chatId; overwritten by any long-pressed post
static NSNumber *gCapPost;         // seeded = a known ПЕСНИ post serverId; overwritten on long-press
static id gRealClient;             // send-client instance seen carrying the largest real payload (= connected socket)
static long long gRealClientLen;
static double gCaptureUntil;       // CFAbsoluteTime until which the parser hook POSTs incoming dicts
static long long gCaptureRun;
static NSMutableArray *gSigKeep;   // retain RACSignals/disposables so async responses aren't torn down
static void Report(NSString *s);

static id gChatService;
static void (*o_addR3)(id, SEL, id, id, id);
static void h_addR3(id self, SEL _cmd, id r, id pk, id cpk) { gChatService = self; o_addR3(self, _cmd, r, pk, cpk); }

// Read an NSNumber-valued field (serverId / chatId) from the OKMMessage via selector then KVC.
static id MsgNum(id message, NSString *key) {
    SEL s = sel_registerName(key.UTF8String);
    if ([message respondsToSelector:s]) { @try { id v = ((id(*)(id,SEL))objc_msgSend)(message, s); if ([v isKindOfClass:NSNumber.class]) return v; } @catch (__unused id e) {} }
    @try { id v = [message valueForKey:key]; if ([v isKindOfClass:NSNumber.class]) return v; } @catch (__unused id e) {}
    return nil;
}

// --- Animoji (animated reaction) DB capture: log every OKMAnimoji's emoji/name -> lottie URL. ---
static NSString *AnimVal(id o, const char *sel) {
    @try { SEL s = sel_registerName(sel); if ([o respondsToSelector:s]) { id v = ((id(*)(id,SEL))objc_msgSend)(o, s); if ([v isKindOfClass:NSString.class]) return v; if (v) return [v description]; } } @catch (__unused id e) {}
    return nil;
}
// Live emoji -> lottie-URL database + the RLottie render service, captured from MAX itself so our
// picker can show MAX's own animated reactions.
static NSMutableDictionary *gAnimojiDB;   // emoji(NSString) -> lottieUrl(NSString)
static id gRenderService;                 // OKTTRLottie.RLottieRenderingService instance

// Hook -[OKMAnimoji lottieUrl]: every animoji in use records its emoji -> lottie URL.
static id (*o_animUrl)(id, SEL);
static id h_animUrl(id self, SEL _cmd) {
    id url = o_animUrl(self, _cmd);
    @try {
        if ([url isKindOfClass:NSString.class] && ((NSString *)url).length) {
            NSString *emoji = AnimVal(self, "emoji");
            if ([emoji isKindOfClass:NSString.class] && emoji.length) {
                if (!gAnimojiDB) gAnimojiDB = [NSMutableDictionary dictionary];
                if (!gAnimojiDB[emoji]) { gAnimojiDB[emoji] = url; MxLog([NSString stringWithFormat:@"ANIMOJI %@ -> %@", emoji, url]); }
            }
        }
    } @catch (__unused id e) {}
    return url;
}

// Hook -[RLottieRenderingService renderLottieWithUrl:preferredSize:useCache:] to capture the service
// instance (so we can call it ourselves) and log URLs actually rendered.
static id (*o_renderLottie)(id, SEL, id, CGSize, BOOL);
static id h_renderLottie(id self, SEL _cmd, id url, CGSize sz, BOOL cache) {
    if (!gRenderService) { gRenderService = self; MxLog([NSString stringWithFormat:@"RENDERSVC captured %@", NSStringFromClass([self class])]); }
    @try { if ([url isKindOfClass:NSString.class]) MxLog([NSString stringWithFormat:@"RENDER url=%@ size=%.0fx%.0f", url, sz.width, sz.height]); } @catch (__unused id e) {}
    return o_renderLottie(self, _cmd, url, sz, cache);
}

// ---- Config feature-flag forcing: enable MAX's native (server-gated) features ----------------
// MAX reads bool feature flags through -[*ConfigStorageComposition boolValueFor:default:] with
// kebab-case string keys. We force a small allow-list to YES to turn on MAX's own native UI.
// Installed LAST in the constructor, in its own @try, so it can never break the reaction hooks.
static NSSet<NSString *> *ForcedBoolFlags(void) {
    static NSSet *s; static dispatch_once_t once;
    dispatch_once(&once, ^{ s = [NSSet setWithArray:@[ @"channel-post-comments-enabled" ]]; });
    return s;
}
// boolValueFor:default: (key, def) and boolForKey:/boolForString: (key) share the (self,_cmd,key,...)
// prefix, so one trampoline family handles both; we force our flag(s) to YES regardless of arity.
static BOOL (*o_boolValueFor)(id, SEL, id, BOOL);
static BOOL ForceCheck(id key, BOOL orig, BOOL def) {
    @try {
        if ([key isKindOfClass:NSString.class]) {
            NSString *k = key;
            if ([k rangeOfString:@"comment" options:NSCaseInsensitiveSearch].location != NSNotFound)
                Report([NSString stringWithFormat:@"CFG read %@ (def=%d orig=%d)", k, def, orig]);
            if ([ForcedBoolFlags() containsObject:k] && !orig) { Report([NSString stringWithFormat:@"CFG force %@ -> 1", k]); return YES; }
        }
    } @catch (__unused id e) {}
    return orig;
}
static BOOL h_boolValueFor(id self, SEL _cmd, id key, BOOL def) { return ForceCheck(key, o_boolValueFor(self, _cmd, key, def), def); }
static BOOL (*o_boolForKey)(id, SEL, id);
static BOOL h_boolForKey(id self, SEL _cmd, id key) { return ForceCheck(key, o_boolForKey(self, _cmd, key), NO); }
static BOOL (*o_boolForStr)(id, SEL, id);
static BOOL h_boolForStr(id self, SEL _cmd, id key) { return ForceCheck(key, o_boolForStr(self, _cmd, key), NO); }
static void HookBoolSel(Class c, const char *selName, IMP newImp, void **orig, int *counter) {
    SEL s = sel_registerName(selName);
    Method m = class_getInstanceMethod(c, s); if (!m) return;
    const char *ret = method_copyReturnType(m);
    BOOL isBool = ret && (ret[0] == 'B' || ret[0] == 'c'); if (ret) free((void *)ret);
    if (!isBool) return;
    if (!*orig) *orig = (void *)method_getImplementation(m);   // all config classes share one IMP family
    method_setImplementation(m, newImp); (*counter)++;
}
static void InstallConfigHook(void) {
    @try {
        unsigned int n = 0; Class *all = objc_copyClassList(&n); int hooked = 0;
        for (unsigned int i = 0; i < n; i++) {
            const char *cn = class_getName(all[i]);
            if (!cn || !strstr(cn, "ConfigStorage")) continue;   // OMPMSConfigStorage + ...Composition + params
            HookBoolSel(all[i], "boolValueFor:default:", (IMP)h_boolValueFor, (void **)&o_boolValueFor, &hooked);
            HookBoolSel(all[i], "boolForKey:", (IMP)h_boolForKey, (void **)&o_boolForKey, &hooked);
            HookBoolSel(all[i], "boolForString:", (IMP)h_boolForStr, (void **)&o_boolForStr, &hooked);
        }
        if (all) free(all);
        MxLog([NSString stringWithFormat:@"CFG hooked %d config storage bool-method(s)", hooked]);
    } @catch (__unused id e) {}
}

// ---- FIX #1: force the REAL native-comment gate getter directly ----
// Decompiled: -[OMPMSConfigStorage isChannelPostCommentsEnabled] reads boolValueFor:default:
// (key "channel-post-comments-enabled", default NO). We force the getter itself to YES on EVERY class
// that implements it (OMPMSConfig witness + storage/composition), so the native HistoryChannelPostCommentsView
// is built. (The data still requires the WEB-identity handshake, handled separately.)
static BOOL h_isChannelPostCommentsEnabled(__unused id self, __unused SEL _cmd) { return YES; }
static void ForceCommentGetter(void) {
    @try {
        SEL g = sel_registerName("isChannelPostCommentsEnabled");
        unsigned int n = 0; Class *all = objc_copyClassList(&n); int forced = 0;
        for (unsigned int i = 0; i < n; i++) {
            Method m = class_getInstanceMethod(all[i], g); if (!m) continue;
            // only classes that DECLARE it themselves (avoid re-forcing inherited copies redundantly)
            unsigned int mc = 0; Method *ml = class_copyMethodList(all[i], &mc); BOOL owns = NO;
            for (unsigned int j = 0; j < mc; j++) if (method_getName(ml[j]) == g) { owns = YES; break; }
            if (ml) free(ml);
            if (!owns) continue;
            const char *ret = method_copyReturnType(m); BOOL isBool = ret && (ret[0]=='B'||ret[0]=='c'); if (ret) free((void*)ret);
            if (!isBool) continue;
            method_setImplementation(m, (IMP)h_isChannelPostCommentsEnabled); forced++;
            Report([NSString stringWithFormat:@"CMTGATE forced isChannelPostCommentsEnabled on %s", class_getName(all[i])]);
        }
        if (all) free(all);
        Report([NSString stringWithFormat:@"CMTGATE total forced = %d", forced]);
    } @catch (__unused id e) {}
}

// ---- Synthesize commentsInfo on channel posts so MAX's native comment button has a thread ref ----
// OKMMessageCommentsInfo is a simple @objc class (only totalCount / setTotalCount:). We construct one
// and store it into each channel post's _commentsInfo so the native tap can proceed (Android confirms
// the thread loads via op49 {chatId,postId} — no composite chat needed, so this should suffice).
static NSMutableDictionary *gCountByPost;   // postId(NSNumber) -> real count (filled lazily by op91)
static id MakeCommentsInfo(long long total) {
    @try {
        Class cls = objc_getClass("OKMMessageCommentsInfo"); if (!cls) return nil;
        id ci = [[cls alloc] init]; if (!ci) return nil;
        SEL s = sel_registerName("setTotalCount:");
        if ([ci respondsToSelector:s]) ((void(*)(id,SEL,long long))objc_msgSend)(ci, s, total);
        return ci;
    } @catch (__unused id e) { return nil; }
}
static int gCIlog;
static id (*o_commentsInfo)(id, SEL);
static id h_commentsInfo(id self, SEL _cmd) {
    id ci = o_commentsInfo(self, _cmd);
    if (ci) return ci;
    @try {
        id type = MsgVal(self, @"type");
        if (![type isKindOfClass:NSString.class] || ![[(NSString *)type uppercaseString] containsString:@"CHANNEL"]) return ci;
        id po = MsgNum(self, @"serverId"); if (!po) po = MsgNum(self, @"id");
        long long total = 0;
        if (gCountByPost && po) { id c = gCountByPost[po]; if (c) total = [c longLongValue]; }
        if (total <= 0) total = 1;   // non-zero so the native button offers the thread
        id made = MakeCommentsInfo(total);
        if (made) { @try { [self setValue:made forKey:@"commentsInfo"]; } @catch (__unused id e) {} // store into _commentsInfo so Swift reads it too
            if (gCIlog < 4) { gCIlog++; Report([NSString stringWithFormat:@"CI synth post=%@ total=%lld stored", po, total]); } }
        return made ?: ci;
    } @catch (__unused id e) {}
    return ci;
}
static void HookCommentsInfo(void) {
    @try {
        Class c = objc_getClass("OKMMessage");
        SEL g = sel_registerName("commentsInfo");
        Method m = c ? class_getInstanceMethod(c, g) : NULL;
        if (m) { o_commentsInfo = (id(*)(id,SEL))method_getImplementation(m); method_setImplementation(m, (IMP)h_commentsInfo); Report(@"CI hooked OKMMessage.commentsInfo"); }
        else Report(@"CI OKMMessage.commentsInfo NOT found");
    } @catch (__unused id e) {}
}

// ---- Locate the postId inside the native comment-button view, so we can hijack its tap ----
// HistoryChannelPostCommentsView is small; its tapAction closure (and maybe ivars) capture the post.
// Dump all ivars (obj class + int value) + scan one level into object ivars for id-looking ints.
static int gHCVlog;
static void MXDumpIdsDeep(id obj, NSString *path, NSMutableString *s, int depth, NSMutableSet *seen) {
    if (!obj || depth < 0) return;
    NSValue *k = [NSValue valueWithNonretainedObject:obj]; if ([seen containsObject:k]) return; [seen addObject:k];
    @try {
        Class c = object_getClass(obj);
        while (c && c != NSObject.class) {
            unsigned int n = 0; Ivar *iv = class_copyIvarList(c, &n);
            for (unsigned int i = 0; i < n; i++) {
                const char *enc = ivar_getTypeEncoding(iv[i]); const char *nm = ivar_getName(iv[i]); if (!enc || !nm) continue;
                if (enc[0]=='q'||enc[0]=='Q'||enc[0]=='l') { @try { long long v = *(long long *)((__bridge void*)obj + ivar_getOffset(iv[i])); if (v>100000000000LL || v<-100000000000LL || v==-76713863894356LL) [s appendFormat:@" %@/%s=%lld", path, nm, v]; } @catch(__unused id e){} }
                else if (enc[0]=='@' && depth>0) { @try { id v = object_getIvar(obj, iv[i]); if (v && ![v isKindOfClass:UIView.class]) { id mc=MsgNum(v,@"chatId"),ms=MsgNum(v,@"serverId"); if(mc&&ms)[s appendFormat:@" %@/%s=OKMMsg(ch=%@ sv=%@)",path,nm,mc,ms]; MXDumpIdsDeep(v,[NSString stringWithFormat:@"%@/%s",path,nm],s,depth-1,seen); } } @catch(__unused id e){} }
            }
            if (iv) free(iv); c = class_getSuperclass(c);
        }
    } @catch (__unused id e) {}
}
// Decompiled: HistoryChannelPostCommentsView.tapAction closure = {fn @ obj+8, ctx @ obj+16}.
// The closure context captures (chatId, postId). Read ctx at the KNOWN offset and dump a BOUNDED
// window of words — we match the known channel id to locate postId precisely. No recursion, no raw
// ivar scan (that crashed); just one validated pointer read + a small bounded read.
static BOOL PlausiblePtr(uintptr_t p) { return p > 0x100000000ULL && p < 0x0001000000000000ULL && (p & 7) == 0; }
// Tap target: when the native comment button is tapped, read its tapAction closure context (view+16)
// and dump a bounded window of words to locate the postId (matched vs the known channel id).
@interface MXHCVTap : NSObject
+ (instancetype)shared;
@end
@implementation MXHCVTap
+ (instancetype)shared { static MXHCVTap *s; static dispatch_once_t o; dispatch_once(&o, ^{ s = [MXHCVTap new]; }); return s; }
- (void)tap:(UITapGestureRecognizer *)g {
    @try {
        UIView *v = g.view; if (!v) return;
        uintptr_t ctx = *(uintptr_t *)((uintptr_t)(__bridge void *)v + 16);   // tapAction closure context
        NSMutableString *s = [NSMutableString stringWithFormat:@"HCVTAP ctx=0x%lx words:", ctx];
        if (PlausiblePtr(ctx)) for (int i = 0; i < 12; i++) { long long w = *(long long *)(ctx + i*8); [s appendFormat:@" [%d]=%lld", i, w]; }
        else [s appendString:@" (ctx not ptr)"];
        Report(s);
    } @catch (__unused id e) {}
}
@end
// Walk up the superview chain to the HistoryChannelPostCommentsView instance, read its tapAction
// closure context (view+16), dump a bounded window of words (to locate postId vs the known channel id).
static void MXDumpHCVCtx(UIView *btn) {
    @try {
        UIView *v = btn;
        for (int i = 0; i < 6 && v; i++) {
            if ([NSStringFromClass([v class]) containsString:@"HistoryChannelPostCommentsView"] &&
                ![NSStringFromClass([v class]) containsString:@"Button"]) break;
            v = v.superview;
        }
        if (!v) { Report(@"HCVTAP: no HCV ancestor"); return; }
        uintptr_t ctx = *(uintptr_t *)((uintptr_t)(__bridge void *)v + 16);
        NSMutableString *s = [NSMutableString stringWithFormat:@"HCVTAP view=%@ ctx=0x%lx words:", NSStringFromClass([v class]), ctx];
        if (PlausiblePtr(ctx)) for (int i = 0; i < 12; i++) { long long w = *(long long *)(ctx + i*8); [s appendFormat:@" [%d]=%lld", i, w]; }
        else [s appendString:@" (ctx not ptr)"];
        Report(s);
    } @catch (__unused id e) {}
}
// applyLayoutAttributes: fires on EVERY cell display/reuse (reliable, unlike initWithFrame). Scoped to
// the comment view, read its tapAction closure ctx (offset 16) and dump words to locate postId.
static void (*o_applyLA)(id, SEL, id);
static void h_applyLA(id self, SEL _cmd, id attrs) {
    o_applyLA(self, _cmd, attrs);
    @try {
        if (gHCVlog >= 4) return;
        NSString *cn = NSStringFromClass([self class]);
        if (![cn containsString:@"HistoryChannelPostCommentsView"] || [cn containsString:@"Button"] || [cn containsString:@"ShapeView"]) return;
        gHCVlog++;
        uintptr_t base = (uintptr_t)(__bridge void *)self;
        // Dump the VIEW's own words (offsets 8..120): tapAction fn@8/ctx@16, containerButton@24, etc.
        // Each object-ivar word: if it's a plausible ptr, also peek a couple words of that object.
        NSMutableString *s = [NSMutableString stringWithFormat:@"HCVVIEW cls=%@ fields:", cn];
        for (int off = 8; off <= 120; off += 8) {
            long long w = *(long long *)(base + off);
            [s appendFormat:@" +%d=%lld", off, w];
        }
        Report(s);
    } @catch (__unused id e) {}
}
static void HookHistoryCommentsView(void) {
    @try {
        Class c = objc_getClass("UICollectionReusableView");   // base; fires for all cells, we scope by class
        SEL sel = sel_registerName("applyLayoutAttributes:");
        Method m = class_getInstanceMethod(c, sel); if (!m) { Report(@"HCV applyLayoutAttributes not found"); return; }
        o_applyLA = (void(*)(id,SEL,id))method_getImplementation(m);
        method_setImplementation(m, (IMP)h_applyLA);
        Report(@"HCV hooked applyLayoutAttributes (ctx probe)");
    } @catch (__unused id e) {}
}

// ---- MAX WebSocket capture: learn the exact request wire format (command <-> opcode, MessagePack) ----
static id gMsgClient;   // OKMMessengerClient instance, for sending our own requests (comments)
static void (*o_sendData)(id, SEL, id, long long, BOOL, id);
static void WSLog(NSString *tag, long long command, id data) {
    @try {
        NSUInteger len = [data isKindOfClass:NSData.class] ? [(NSData *)data length] : 0;
        NSString *b64 = len ? [[data subdataWithRange:NSMakeRange(0, MIN(len, (NSUInteger)300))] base64EncodedStringWithOptions:0] : @"";
        MxLog([NSString stringWithFormat:@"%@ cmd=%lld len=%lu b64=%@", tag, command, (unsigned long)len, b64]);
    } @catch (__unused id e) {}
}
static void h_sendData(id self, SEL _cmd, id data, long long command, BOOL retry, id callback) {
    if (!gMsgClient) gMsgClient = self;
    if ([data isKindOfClass:NSData.class] && (long long)[(NSData *)data length] > gRealClientLen) { gRealClientLen = [(NSData *)data length]; gRealClient = self; }
    if (CFAbsoluteTimeGetCurrent() < gCaptureUntil) {
        NSUInteger len = [data isKindOfClass:NSData.class] ? [(NSData *)data length] : 0;
        NSString *b64 = len ? [[(NSData *)data subdataWithRange:NSMakeRange(0, MIN(len,(NSUInteger)160))] base64EncodedStringWithOptions:0] : @"";
        Report([NSString stringWithFormat:@"WSOUT op=%lld len=%lu b64=%@", command, (unsigned long)len, b64]);
    }
    o_sendData(self, _cmd, data, command, retry, callback);
}
// request/response variant: returns a RACSignal (id); mapResultTo is a Class. Carries the real payload.
static id (*o_sendMap)(id, SEL, id, unsigned short, BOOL, Class);
static id h_sendMap(id self, SEL _cmd, id data, unsigned short command, BOOL retry, Class mapTo) {
    if (!gMsgClient) gMsgClient = self;
    if ([data isKindOfClass:NSData.class] && (long long)[(NSData *)data length] > gRealClientLen) { gRealClientLen = [(NSData *)data length]; gRealClient = self; }
    if (CFAbsoluteTimeGetCurrent() < gCaptureUntil) {
        NSUInteger len = [data isKindOfClass:NSData.class] ? [(NSData *)data length] : 0;
        NSString *b64 = len ? [[(NSData *)data subdataWithRange:NSMakeRange(0, MIN(len,(NSUInteger)160))] base64EncodedStringWithOptions:0] : @"";
        Report([NSString stringWithFormat:@"WSMAPOUT op=%u len=%lu mapTo=%@ b64=%@", command, (unsigned long)len, mapTo ? NSStringFromClass(mapTo) : @"nil", b64]);
    }
    return o_sendMap(self, _cmd, data, command, retry, mapTo);
}
// ---- Readable response logging: strip heavy media keys, summarize message arrays ----
static NSSet *HeavyKeys(void) {
    static NSSet *s; static dispatch_once_t once;
    dispatch_once(&once, ^{ s = [NSSet setWithArray:@[@"attaches", @"previewData", @"thumbnail", @"token", @"photoToken",
        @"videoToken", @"baseUrl", @"baseRawUrl", @"url", @"previewUrl", @"lottieUrl", @"iconUrl", @"elements"]]; });
    return s;
}
static id Slim(id o, int depth) {
    if (depth > 6) return @"…";
    if ([o isKindOfClass:NSDictionary.class]) {
        NSMutableDictionary *out = [NSMutableDictionary dictionary];
        for (id k in (NSDictionary *)o) {
            id v = ((NSDictionary *)o)[k];
            if ([k isKindOfClass:NSString.class] && [HeavyKeys() containsObject:k]) {
                out[k] = [v isKindOfClass:NSArray.class] ? [NSString stringWithFormat:@"<%lu stripped>", (unsigned long)[(NSArray *)v count]] : @"<stripped>";
            } else out[k] = Slim(v, depth + 1);
        }
        return out;
    }
    if ([o isKindOfClass:NSArray.class]) {
        NSMutableArray *out = [NSMutableArray array];
        for (id e in (NSArray *)o) [out addObject:Slim(e, depth + 1)];
        return out;
    }
    if ([o isKindOfClass:NSString.class] && [(NSString *)o length] > 160) return [[(NSString *)o substringWithRange:[(NSString *)o rangeOfComposedCharacterSequencesForRange:NSMakeRange(0, 160)]] stringByAppendingString:@"…"];
    if ([o isKindOfClass:NSData.class]) return [NSString stringWithFormat:@"<data %lu>", (unsigned long)[(NSData *)o length]];
    return o ?: [NSNull null];
}
// One line per message: id / time / sender / type / text / attach types — enough to tell a comment
// thread (many senders, user text) from plain channel history (one sender, posts).
static NSString *MsgLines(NSArray *msgs) {
    NSMutableString *s = [NSMutableString stringWithFormat:@"%lu messages:\n", (unsigned long)msgs.count];
    for (id m in msgs) {
        if (![m isKindOfClass:NSDictionary.class]) { [s appendFormat:@"  ? %@\n", NSStringFromClass([m class])]; continue; }
        NSDictionary *d = m;
        NSMutableArray *at = [NSMutableArray array];
        if ([d[@"attaches"] isKindOfClass:NSArray.class]) for (id a in d[@"attaches"]) if ([a isKindOfClass:NSDictionary.class]) [at addObject:a[@"_type"] ?: a[@"type"] ?: @"?"];
        NSString *txt = [d[@"text"] isKindOfClass:NSString.class] ? d[@"text"] : @"";
        if (txt.length > 120) txt = [[txt substringWithRange:[txt rangeOfComposedCharacterSequencesForRange:NSMakeRange(0, 120)]] stringByAppendingString:@"…"];
        txt = [txt stringByReplacingOccurrencesOfString:@"\n" withString:@" "];
        NSMutableArray *other = [NSMutableArray array];
        for (id k in d) if (![@[@"id", @"time", @"sender", @"type", @"text", @"attaches", @"elements", @"cid"] containsObject:k]) [other addObject:k];
        [s appendFormat:@"  id=%@ t=%@ from=%@ type=%@ att=%@ text=\"%@\" +keys=%@\n", d[@"id"], d[@"time"], d[@"sender"], d[@"type"],
            [at componentsJoinedByString:@","], txt, [other componentsJoinedByString:@","]];
    }
    return s;
}
static NSString *Describe(id o) {
    if (!o) return @"nil";
    @try {
        if ([o isKindOfClass:NSDictionary.class]) {
            NSDictionary *d = o;
            NSMutableString *s = [NSMutableString string];
            NSMutableDictionary *rest = [NSMutableDictionary dictionary];
            for (id k in d) {
                if ([k isEqual:@"messages"] && [d[k] isKindOfClass:NSArray.class]) [s appendString:MsgLines(d[k])];
                else rest[k] = d[k];
            }
            if ([d[@"message"] isKindOfClass:NSDictionary.class]) [s appendFormat:@"message: %@", MsgLines(@[d[@"message"]])];
            [s appendFormat:@"rest=%@", Slim(rest, 0)];
            return s;
        }
        return [Slim(o, 0) description];
    } @catch (__unused id e) { return [o description]; }
}
static void ReportBig(NSString *s) {   // split into <=7000-char POSTs so nothing important is cut off
    const NSUInteger kChunk = 7000, kMax = 42000;
    if (s.length > kMax) s = [[s substringToIndex:kMax] stringByAppendingString:@"\n…(capped)"];
    // split on composed-character boundaries: cutting a surrogate pair makes the UTF-8 body nil (empty POST)
    NSUInteger i = 0, part = 0;
    while (i < s.length) {
        NSRange r = [s rangeOfComposedCharacterSequencesForRange:NSMakeRange(i, MIN(kChunk, s.length - i))];
        if (NSMaxRange(r) <= i) break;
        NSString *p = [s substringWithRange:NSMakeRange(i, NSMaxRange(r) - i)];
        Report(part == 0 ? p : [NSString stringWithFormat:@"…cont[%lu] %@", (unsigned long)part, p]);
        i = NSMaxRange(r); part++;
    }
}

static BOOL gWantCmtResp;   // set when we've sent an op-49 and want the parser hook to log message responses
// -[NSData mpo_parseMessagePackData] parses an incoming frame. Hook it to catch our op-49 response
// (a dict/obj containing "messages") since the response arrives via the client's incoming dispatch.
static id (*o_mpoParse)(id, SEL);
// TEST (direction 1): give every channel post a commentsInfo so MAX's native comment button has a
// thread reference to open. Fixed count for now; if the native tap then fires op49 we wire real counts.
static int gInjCount;
static void InjectCI(id o, int depth) {
    if (!o || depth > 7) return;
    if ([o isKindOfClass:NSArray.class]) { for (id e in (NSArray *)o) InjectCI(e, depth + 1); return; }
    if (![o isKindOfClass:NSDictionary.class]) return;
    NSDictionary *d = o;
    @try {
        id type = d[@"type"]; id mid = d[@"id"];
        if (mid && [type isEqual:@"CHANNEL"] && !d[@"commentsInfo"] && [d respondsToSelector:@selector(setObject:forKey:)]) {
            ((NSMutableDictionary *)d)[@"commentsInfo"] = [@{@"totalCount": @(7), @"counters": @[]} mutableCopy];
            if (gInjCount < 5) { gInjCount++; Report([NSString stringWithFormat:@"INJECT commentsInfo into post id=%@ (mutable=%d)", mid, [d isKindOfClass:NSMutableDictionary.class]]); }
        } else if (mid && [type isEqual:@"CHANNEL"] && !d[@"commentsInfo"] && gInjCount < 5) {
            gInjCount++; Report([NSString stringWithFormat:@"INJECT SKIP post id=%@ immutable class=%@", mid, NSStringFromClass([d class])]);
        }
    } @catch (__unused id e) {}
    for (id k in [d allKeys]) InjectCI(d[k], depth + 1);
}
static id h_mpoParse(id self, SEL _cmd) {
    id obj = o_mpoParse(self, _cmd);
    @try {
        if ([obj isKindOfClass:NSDictionary.class]) {
            NSDictionary *o0 = obj;
            if (o0[@"messages"] || o0[@"message"] || o0[@"chat"] || o0[@"chats"] || ([o0[@"type"] isEqual:@"CHANNEL"] && o0[@"id"]))
                InjectCI(obj, 0);
        } else if ([obj isKindOfClass:NSArray.class]) InjectCI(obj, 0);
    } @catch (__unused id e) {}
    @try {
        // Capture our own account id (for own-vs-other comment bubble alignment) from any frame.
        if (!gMyUserId && [obj isKindOfClass:NSDictionary.class]) {
            id uid = ((NSDictionary *)obj)[@"userId"];
            if ([uid isKindOfClass:NSNumber.class] && [uid longLongValue] > 0) gMyUserId = [uid longLongValue];
        }
        // Live new-comment push (op 128): a parsed frame carrying a `message` + a `postId` that matches
        // the open thread. Append it to our comments VC on the main thread.
        if (gOpenCommentsVC && gOpenCommentsPost && [obj isKindOfClass:NSDictionary.class]) {
            NSDictionary *o = obj;
            id po = o[@"postId"]; id msg = o[@"message"];
            if ([po isKindOfClass:NSNumber.class] && [po isEqualToNumber:gOpenCommentsPost] && [msg isKindOfClass:NSDictionary.class]) {
                id vc = gOpenCommentsVC; NSDictionary *m = msg;
                dispatch_async(dispatch_get_main_queue(), ^{ @try {
                    SEL ap = sel_registerName("appendPushed:");
                    if ([vc respondsToSelector:ap]) ((void(*)(id,SEL,id))objc_msgSend)(vc, ap, m);
                } @catch (__unused id e) {} });
            }
        }
        // Server config (login reply / push 134) — report any comment/story flags it carries (first few times).
        static int cfgSeen = 0;
        if (cfgSeen < 6 && [obj isKindOfClass:NSDictionary.class] && !((NSDictionary *)obj)[@"__class"]) {
            NSMutableDictionary *hits = [NSMutableDictionary dictionary];
            void (^scan)(NSDictionary *, NSString *) = ^(NSDictionary *dd, NSString *pre) {
                for (id k in dd) if ([k isKindOfClass:NSString.class]) {
                    NSString *ks = k; NSString *lk = ks.lowercaseString;
                    if ([lk containsString:@"comment"] || [lk containsString:@"stor"]) hits[[pre stringByAppendingString:ks]] = Slim(dd[k], 3);
                }
            };
            NSDictionary *od = obj; scan(od, @"");
            for (id k in od) if ([od[k] isKindOfClass:NSDictionary.class] && [od[k] count] > 3) scan(od[k], [NSString stringWithFormat:@"%@.", k]);
            if (hits.count) { cfgSeen++; ReportBig([NSString stringWithFormat:@"CFGFLAGS keys=%@ hits=%@", [[od allKeys] componentsJoinedByString:@","], hits]); }
        }
        // During a capture window (opened by a remote-config run), POST every incoming frame that
        // looks like a response/error so we see exactly what comes back after each request variant.
        if (CFAbsoluteTimeGetCurrent() < gCaptureUntil && [obj isKindOfClass:NSDictionary.class]) {
            NSDictionary *o = (NSDictionary *)obj;
            BOOL noise = o[@"__class"] != nil;   // __class dicts are MAX's local-DB records, not WS frames
            if (!noise && (o[@"messages"] || o[@"message"] || o[@"comments"] || o[@"commentsInfo"] ||
                o[@"error"] || o[@"reason"] || o[@"chatId"] || o[@"chat"] || o[@"title"] ||
                o[@"localizedMessage"] || o[@"code"] || o[@"status"] || o[@"postId"])) {
                ReportBig([NSString stringWithFormat:@"RESP keys=%@ %@",
                    [[o allKeys] componentsJoinedByString:@","], Describe(o)]);
            }
        }
    } @catch (__unused id e) {}
    return obj;
}
// ---- Minimal MessagePack encoder matching web.max.ru's wire format ----
// The web client sends every id as msgpack EXT type 1 wrapping an int64 (d3) / uint64 (cf); iOS's
// mpo_messagePackData only emits plain ints, which the server silently ignores for postId. MXExtId
// marks a value to be emitted as ext-1.
@interface MXExtId : NSObject
@property (nonatomic) long long v;
+ (instancetype)with:(long long)v;
@end
@implementation MXExtId
+ (instancetype)with:(long long)v { MXExtId *e = [MXExtId new]; e.v = v; return e; }
- (NSString *)description { return [NSString stringWithFormat:@"ext1(%lld)", self.v]; }
@end
static void PackBE(NSMutableData *o, uint64_t v, int n) { for (int i = n - 1; i >= 0; i--) { uint8_t b = (uint8_t)(v >> (8 * i)); [o appendBytes:&b length:1]; } }
static void PackByte(NSMutableData *o, uint8_t b) { [o appendBytes:&b length:1]; }
static void PackInt(NSMutableData *o, long long v) {
    if (v >= 0 && v <= 127) PackByte(o, (uint8_t)v);
    else if (v < 0 && v >= -32) PackByte(o, (uint8_t)(int8_t)v);
    else if (v >= 0 && v <= 0xff) { PackByte(o, 0xcc); PackBE(o, v, 1); }
    else if (v >= 0 && v <= 0xffff) { PackByte(o, 0xcd); PackBE(o, v, 2); }
    else if (v >= 0 && v <= 0xffffffffLL) { PackByte(o, 0xce); PackBE(o, v, 4); }
    else if (v >= 0) { PackByte(o, 0xcf); PackBE(o, v, 8); }
    else if (v >= -128) { PackByte(o, 0xd0); PackBE(o, (uint64_t)v, 1); }
    else if (v >= -32768) { PackByte(o, 0xd1); PackBE(o, (uint64_t)v, 2); }
    else if (v >= -2147483648LL) { PackByte(o, 0xd2); PackBE(o, (uint64_t)v, 4); }
    else { PackByte(o, 0xd3); PackBE(o, (uint64_t)v, 8); }
}
static void PackLen(NSMutableData *o, NSUInteger n, uint8_t fix, NSUInteger fixMax, uint8_t b8, uint8_t b16, uint8_t b32) {
    if (n <= fixMax) PackByte(o, fix | (uint8_t)n);
    else if (b8 && n <= 0xff) { PackByte(o, b8); PackBE(o, n, 1); }
    else if (n <= 0xffff) { PackByte(o, b16); PackBE(o, n, 2); }
    else { PackByte(o, b32); PackBE(o, n, 4); }
}
static void MXPackInto(NSMutableData *o, id v) {
    if (!v || v == [NSNull null]) { PackByte(o, 0xc0); return; }
    if ([v isKindOfClass:MXExtId.class]) {   // ext8 len 9 type 1 + int64/uint64, exactly like the web bundle
        long long x = ((MXExtId *)v).v;
        PackByte(o, 0xc7); PackByte(o, 0x09); PackByte(o, 0x01);
        PackByte(o, x < 0 ? 0xd3 : 0xcf); PackBE(o, (uint64_t)x, 8); return;
    }
    if ([v isKindOfClass:NSString.class]) {
        NSData *u = [(NSString *)v dataUsingEncoding:NSUTF8StringEncoding];
        PackLen(o, u.length, 0xa0, 31, 0xd9, 0xda, 0xdb); [o appendData:u]; return;
    }
    if ([v isKindOfClass:NSNumber.class]) {
        NSNumber *n = v;
        if (CFGetTypeID((__bridge CFTypeRef)n) == CFBooleanGetTypeID()) { PackByte(o, n.boolValue ? 0xc3 : 0xc2); return; }
        const char *t = n.objCType;
        if (t[0] == 'f' || t[0] == 'd') { double d = n.doubleValue; uint64_t bits; memcpy(&bits, &d, 8); PackByte(o, 0xcb); PackBE(o, bits, 8); return; }
        PackInt(o, n.longLongValue); return;
    }
    if ([v isKindOfClass:NSArray.class]) {
        PackLen(o, [(NSArray *)v count], 0x90, 15, 0, 0xdc, 0xdd);
        for (id e in (NSArray *)v) MXPackInto(o, e);
        return;
    }
    if ([v isKindOfClass:NSDictionary.class]) {
        NSDictionary *d = v;
        NSArray *keys = d[@"__order"] ?: d.allKeys;   // optional explicit key order (cosmetic; msgpack maps are unordered)
        NSUInteger cnt = 0; for (id k in keys) if (![k isEqual:@"__order"] && d[k]) cnt++;
        PackLen(o, cnt, 0x80, 15, 0, 0xde, 0xdf);
        for (id k in keys) { if ([k isEqual:@"__order"] || !d[k]) continue; MXPackInto(o, k); MXPackInto(o, d[k]); }
        return;
    }
    if ([v isKindOfClass:NSData.class]) { PackLen(o, [(NSData *)v length], 0, 0, 0xc4, 0xc5, 0xc6); [o appendData:v]; return; }
    PackByte(o, 0xc0);
}
static NSData *MXPack(id v) { NSMutableData *o = [NSMutableData data]; MXPackInto(o, v); return o; }
// Marker dict: { __mxraw: <NSData> } passed to sendData:withCommand:retry:callback: as the object.
// MAX serializes it via mpo_messagePackData (hooked below → we return our raw bytes) and then adds the
// protocol envelope itself — so the frame is correct AND the ids are ext-1 like the web.
static NSString *const kMXRaw = @"__mxraw";

// MAX's serializer: -[id mpo_messagePackData] turns a request dict into the MessagePack NSData.
// Hook it to capture the exact request DICT (field names + id object classes) MAX builds for op49/64.
static id (*o_mpoData)(id, SEL);
static id h_mpoData(id self, SEL _cmd) {
    @try {
        if ([self isKindOfClass:NSDictionary.class]) {
            NSDictionary *d = (NSDictionary *)self;
            if (d.count == 1 && [d[kMXRaw] isKindOfClass:NSData.class]) return d[kMXRaw];
            // During a capture window log EVERY outgoing request dict MAX builds (the request bodies),
            // so we see exactly what the native comment-thread open sends.
            if (CFAbsoluteTimeGetCurrent() < gCaptureUntil && d.count && !d[@"userAgent"])
                ReportBig([NSString stringWithFormat:@"REQOUT keys=%@ %@", [[d allKeys] componentsJoinedByString:@","], Describe(d)]);
            // Session handshake (op 6) carries userAgent{deviceType, appVersion, ...}: the server gates
            // features (comments) per client. Log it, and apply a persisted override set via remote config.
            if ([d[@"userAgent"] isKindOfClass:NSDictionary.class]) {
                NSDictionary *ov = [[NSUserDefaults standardUserDefaults] dictionaryForKey:@"mxf.ua"];
                NSMutableDictionary *nd = nil;
                if (ov.count) {
                    nd = [d mutableCopy];
                    NSMutableDictionary *ua = [d[@"userAgent"] mutableCopy];
                    [ua addEntriesFromDictionary:ov];
                    nd[@"userAgent"] = ua;
                }
                ReportBig([NSString stringWithFormat:@"HANDSHAKE orig=%@ override=%@", Describe(d), ov ?: @"none"]);
                if (nd) return o_mpoData(nd, _cmd);
            }
            if (d[@"token"] && (d[@"chatsSync"] || d[@"configHash"] || d[@"interactive"])) {   // login (op 19); token stripped by Slim
                ReportBig([NSString stringWithFormat:@"LOGIN req=%@", Describe(d)]);
            }
            if (d[@"getMessages"] || d[@"chatId"] || d[@"postId"] || d[@"message"]) {
                NSMutableString *types = [NSMutableString string];
                for (id k in d) [types appendFormat:@"%@=(%@) ", k, NSStringFromClass([d[k] class])];
                MxLog([NSString stringWithFormat:@"MPO dict=%@ || types: %@", d, types]);
            }
        }
    } @catch (__unused id e) {}
    return o_mpoData(self, _cmd);
}

// Resolve a live OKMChatService (responds to addReaction:forMessagePk:chatPk:) from the chat
// presenter via its public getters — no ivar scanning. Lets the picker work on first use,
// before the user has triggered any native reaction.
static id ScanIvarsForService(id obj, SEL add3, int depth) {
    if (!obj || depth < 0) return nil;
    @try {
        Class c = object_getClass(obj);
        while (c && c != NSObject.class) {
            unsigned int n = 0; Ivar *ivars = class_copyIvarList(c, &n);
            for (unsigned int i = 0; i < n; i++) {
                const char *enc = ivar_getTypeEncoding(ivars[i]);
                if (!enc || enc[0] != '@') continue;              // object ivars only (safe for object_getIvar)
                @try {
                    id v = object_getIvar(obj, ivars[i]);
                    if (!v) continue;
                    if ([v respondsToSelector:add3]) { free(ivars); return v; }
                    if (depth > 0) { id deep = ScanIvarsForService(v, add3, depth - 1); if (deep) { free(ivars); return deep; } }
                } @catch (__unused id e) {}
            }
            if (ivars) free(ivars);
            c = class_getSuperclass(c);
        }
    } @catch (__unused id e) {}
    return nil;
}
static id ResolveChatService(id presenter) {
    SEL add3 = sel_registerName("addReaction:forMessagePk:chatPk:");
    for (NSString *g in @[@"chatService", @"chatsService", @"service"]) {
        @try {
            SEL s = sel_registerName(g.UTF8String);
            if ([presenter respondsToSelector:s]) {
                id v = ((id(*)(id,SEL))objc_msgSend)(presenter, s);
                if (v && [v respondsToSelector:add3]) return v;
            }
        } @catch (__unused id e) {}
    }
    // presenter exposes no getter: safely walk its object ivars (2 levels) for the chat service.
    return ScanIvarsForService(presenter, add3, 2);
}

// ================= REMOTE-CONFIG ENGINE (single-build comment-protocol iteration) =================
// Polls https://ruappstore.com/mxcfg.json every few seconds; on a new "run" id it builds + sends a WS
// request per the config (client/method/body variants) and POSTs every result + any matching incoming
// frame to https://ruappstore.com/mxreport.php. Lets us iterate request formats server-side, no rebuild.
static NSString *const kCfgURL = @"https://ruappstore.com/mxcfg.json";
static NSString *const kRepURL = @"https://ruappstore.com/mxreport.php";

static void Report(NSString *s) {
    @try {
        NSMutableURLRequest *r = [NSMutableURLRequest requestWithURL:[NSURL URLWithString:kRepURL]];
        r.HTTPMethod = @"POST";
        r.HTTPBody = [s dataUsingEncoding:NSUTF8StringEncoding allowLossyConversion:YES];
        r.timeoutInterval = 8;
        [[[NSURLSession sharedSession] dataTaskWithRequest:r] resume];
    } @catch (__unused id e) {}
    MxLog(s);   // also keep the on-device log as a fallback
}

static NSString *HexFromI64(long long v) { return [NSString stringWithFormat:@"%016llx", (unsigned long long)(uint64_t)v]; }
static NSData *DataFromHex(NSString *hex) {
    hex = [[hex stringByReplacingOccurrencesOfString:@" " withString:@""] lowercaseString];
    NSMutableData *d = [NSMutableData data];
    for (NSUInteger i = 0; i + 1 < hex.length; i += 2) {
        unsigned x = 0; sscanf([[hex substringWithRange:NSMakeRange(i, 2)] UTF8String], "%x", &x);
        unsigned char b = (unsigned char)x; [d appendBytes:&b length:1];
    }
    return d;
}
// Resolve a JSON config value into the object mpo_messagePackData expects. {"id":"channel"|"post"} ->
// the captured NSNumber (encoded as the ext-type-1 id). Nested dicts recurse. Scalars pass through.
static id ResolveVal(id v) {
    if ([v isKindOfClass:NSDictionary.class]) {
        NSDictionary *dv = (NSDictionary *)v;
        id idtok = dv[@"id"];
        if (dv.count == 1 && [idtok isKindOfClass:NSString.class]) {
            if ([idtok isEqualToString:@"channel"]) return gCapChannel ?: @(0);
            if ([idtok isEqualToString:@"post"])    return gCapPost ?: @(0);
        }
        NSMutableDictionary *out = [NSMutableDictionary dictionary];
        for (id k in dv) out[k] = ResolveVal(dv[k]);
        return out;
    }
    if ([v isKindOfClass:NSArray.class]) {
        NSMutableArray *out = [NSMutableArray array];
        for (id e in (NSArray *)v) [out addObject:ResolveVal(e)];
        return out;
    }
    return v;
}
// Like ResolveVal but for our own encoder: {"id":"channel"|"post"} and {"ext":N} -> ext-1 ids,
// {"cid":"now"} -> ext-1(-now_ms) (the web's client message id), {"plain":"channel"|"post"} -> plain int.
static id ResolveRaw(id v) {
    if ([v isKindOfClass:NSDictionary.class]) {
        NSDictionary *dv = (NSDictionary *)v;
        if (dv.count == 1) {
            id t = dv[@"id"];
            if ([t isEqual:@"channel"]) return [MXExtId with:gCapChannel.longLongValue];
            if ([t isEqual:@"post"])    return [MXExtId with:gCapPost.longLongValue];
            if ([dv[@"ext"] isKindOfClass:NSNumber.class]) return [MXExtId with:[dv[@"ext"] longLongValue]];
            if ([dv[@"ext"] isKindOfClass:NSString.class]) return [MXExtId with:[dv[@"ext"] longLongValue]];
            if ([dv[@"cid"] isEqual:@"now"]) return [MXExtId with:-(long long)([[NSDate date] timeIntervalSince1970] * 1000)];
            if ([dv[@"plain"] isEqual:@"channel"]) return gCapChannel ?: @0;
            if ([dv[@"plain"] isEqual:@"post"])    return gCapPost ?: @0;
        }
        NSMutableDictionary *out = [NSMutableDictionary dictionary];
        for (id k in dv) out[k] = ResolveRaw(dv[k]);
        return out;
    }
    if ([v isKindOfClass:NSArray.class]) {
        NSMutableArray *out = [NSMutableArray array];
        for (id e in (NSArray *)v) [out addObject:ResolveRaw(e)];
        return out;
    }
    return v;
}
static NSData *BuildBody(NSDictionary *cfg) {
    NSString *mode = cfg[@"bodyMode"] ?: @"dict";
    if ([mode isEqualToString:@"hex"]) {
        NSString *h = cfg[@"hex"] ?: @"";
        h = [h stringByReplacingOccurrencesOfString:@"{CH}" withString:HexFromI64(gCapChannel.longLongValue)];
        h = [h stringByReplacingOccurrencesOfString:@"{PO}" withString:HexFromI64(gCapPost.longLongValue)];
        return DataFromHex(h);
    }
    NSDictionary *fields = cfg[@"fields"] ?: @{};
    NSMutableDictionary *req = [NSMutableDictionary dictionary];
    for (id k in fields) req[k] = ResolveVal(fields[k]);
    SEL mpo = sel_registerName("mpo_messagePackData");
    return [req respondsToSelector:mpo] ? ((id(*)(id,SEL))objc_msgSend)(req, mpo) : nil;
}
static id ClientForToken(NSString *tok) {
    SEL sendSel = sel_registerName("sendData:withCommand:retry:callback:");
    if ([tok isEqualToString:@"msgclient"]) return gMsgClient;
    if ([tok isEqualToString:@"real"])      return gRealClient;
    if ([tok isEqualToString:@"chatservice"]) return gChatService;
    if ([tok isEqualToString:@"chatservice-scan"]) return gChatService ? ScanIvarsForService(gChatService, sendSel, 3) : nil;
    return gMsgClient;
}
// Dump a class's (and superclasses') instance methods whose selector contains `match`, with arg count.
static void DumpSel(NSString *clsName, NSString *match) {
    @try {
        Class c = objc_getClass(clsName.UTF8String);
        if (!c) { Report([NSString stringWithFormat:@"DUMPSEL class %@ NOT found", clsName]); return; }
        NSMutableString *out = [NSMutableString stringWithFormat:@"DUMPSEL %@ ~%@:\n", clsName, match];
        while (c && c != [NSObject class]) {
            unsigned int mc = 0; Method *ml = class_copyMethodList(c, &mc);
            for (unsigned int j = 0; j < mc; j++) {
                NSString *sn = NSStringFromSelector(method_getName(ml[j]));
                if (match.length == 0 || [sn rangeOfString:match options:NSCaseInsensitiveSearch].location != NSNotFound)
                    [out appendFormat:@"  [%@] %@\n", NSStringFromClass(c), sn];
            }
            if (ml) free(ml);
            c = class_getSuperclass(c);
        }
        Report(out);
    } @catch (__unused id e) {}
}
// Flexible invoker: send `arg` (NSData body or the request dict) to `client` via the selector/shape
// named in the config, so any send method/arg-shape can be tried purely from mxcfg.json (no rebuild).
static void SendFlex(id client, NSDictionary *cfg, NSData *body, NSDictionary *reqDict, int op, long long run, NSString *ctok) {
    NSString *cls = client ? NSStringFromClass([client class]) : @"nil";
    if (!client) { Report([NSString stringWithFormat:@"RUN %lld client=%@ nil-client", run, ctok]); return; }
    BOOL passObj = [cfg[@"passObj"] boolValue];
    id arg = passObj ? (id)reqDict : (id)body;
    if (!arg || (!passObj && !body.length)) { Report([NSString stringWithFormat:@"RUN %lld client=%@ empty-arg", run, ctok]); return; }
    NSString *method = cfg[@"method"] ?: @"callback";
    NSString *shape = cfg[@"shape"];
    if (!shape) shape = [method isEqualToString:@"map"] ? @"map" : ([method isEqualToString:@"mapnil"] ? @"mapnil" : @"cb");
    void(^logResp)(NSString*,id) = ^(NSString *tag, id resp){ ReportBig([NSString stringWithFormat:@"RUN %lld[%@] %@ %@", run, ctok, tag, Describe(resp)]); };
    @try {
        if ([shape isEqualToString:@"cb"]) {
            SEL s = sel_registerName(((NSString *)(cfg[@"sel"] ?: @"sendData:withCommand:retry:callback:")).UTF8String);
            if (![client respondsToSelector:s]) { Report([NSString stringWithFormat:@"RUN %lld[%@] no sel %@", run, ctok, NSStringFromSelector(s)]); return; }
            void(^cb)(id) = ^(id resp){ logResp(@"CB", resp); };
            ((void(*)(id,SEL,id,unsigned short,BOOL,id))objc_msgSend)(client, s, arg, (unsigned short)op, NO, cb);
            Report([NSString stringWithFormat:@"RUN %lld client=%@(%@) shape=cb passObj=%d op=%d sent", run, ctok, cls, passObj, op]);
        } else if ([shape isEqualToString:@"map"] || [shape isEqualToString:@"mapnil"]) {
            SEL s = sel_registerName(((NSString *)(cfg[@"sel"] ?: @"sendData:withCommand:retry:mapResultTo:")).UTF8String);
            if (![client respondsToSelector:s]) { Report([NSString stringWithFormat:@"RUN %lld[%@] no sel %@", run, ctok, NSStringFromSelector(s)]); return; }
            Class mapTo = [shape isEqualToString:@"mapnil"] ? nil : [NSDictionary class];
            id sig = ((id(*)(id,SEL,id,unsigned short,BOOL,Class))objc_msgSend)(client, s, arg, (unsigned short)op, NO, mapTo);
            Report([NSString stringWithFormat:@"RUN %lld client=%@(%@) shape=%@ passObj=%d op=%d sig=%@", run, ctok, cls, shape, passObj, op, sig ? NSStringFromClass([sig class]) : @"nil"]);
            if (sig) { if (!gSigKeep) gSigKeep = [NSMutableArray array]; [gSigKeep addObject:sig];
                SEL sub = sel_registerName("subscribeNext:error:completed:");
                if ([sig respondsToSelector:sub]) {
                    void(^n)(id) = ^(id x){ logResp(@"NEXT", x); }; void(^e)(NSError*) = ^(NSError *er){ logResp(@"ERR", er.userInfo); }; void(^c2)(void) = ^{ logResp(@"DONE", nil); };
                    id disp = ((id(*)(id,SEL,id,id,id))objc_msgSend)(sig, sub, n, e, c2); if (disp) [gSigKeep addObject:disp];
                }
            }
        } else if ([shape isEqualToString:@"ack"]) {   // sendCommand:withData:retry:ack: (cmd, data, retry, ack)
            SEL s = sel_registerName(((NSString *)(cfg[@"sel"] ?: @"sendCommand:withData:retry:ack:")).UTF8String);
            if (![client respondsToSelector:s]) { Report([NSString stringWithFormat:@"RUN %lld[%@] no sel %@", run, ctok, NSStringFromSelector(s)]); return; }
            void(^ack)(id) = ^(id resp){ logResp(@"ACK", resp); };
            ((void(*)(id,SEL,unsigned short,id,BOOL,id))objc_msgSend)(client, s, (unsigned short)op, arg, NO, ack);
            Report([NSString stringWithFormat:@"RUN %lld client=%@(%@) shape=ack passObj=%d op=%d sent", run, ctok, cls, passObj, op]);
        } else if ([shape isEqualToString:@"obj1"]) {   // one-arg selector: sel(arg) -> id
            SEL s = sel_registerName(((NSString *)(cfg[@"sel"] ?: @"sendRequest:")).UTF8String);
            if (![client respondsToSelector:s]) { Report([NSString stringWithFormat:@"RUN %lld[%@] no sel %@", run, ctok, NSStringFromSelector(s)]); return; }
            id r = ((id(*)(id,SEL,id))objc_msgSend)(client, s, arg);
            Report([NSString stringWithFormat:@"RUN %lld client=%@(%@) shape=obj1 sel=%@ passObj=%d ret=%@", run, ctok, cls, NSStringFromSelector(s), passObj, r ? NSStringFromClass([r class]) : @"nil"]);
            if (r) { if (!gSigKeep) gSigKeep = [NSMutableArray array]; [gSigKeep addObject:r];
                SEL sub = sel_registerName("subscribeNext:error:completed:");
                if ([r respondsToSelector:sub]) { void(^n)(id) = ^(id x){ logResp(@"NEXT", x); }; void(^e)(NSError*) = ^(NSError *er){ logResp(@"ERR", er.userInfo); }; void(^c2)(void) = ^{ logResp(@"DONE", nil); };
                    id disp = ((id(*)(id,SEL,id,id,id))objc_msgSend)(r, sub, n, e, c2); if (disp) [gSigKeep addObject:disp]; }
            }
        } else if ([shape isEqualToString:@"serblk"]) {   // sendData:withCommand:serializeBlock:resultBlock:
            // Pass RAW pre-encoded msgpack (with ext-type-1 ids); the identity serializeBlock stops the
            // default block from re-wrapping our NSData as a bin blob.
            SEL s = sel_registerName(((NSString *)(cfg[@"sel"] ?: @"sendData:withCommand:serializeBlock:resultBlock:")).UTF8String);
            if (![client respondsToSelector:s]) { Report([NSString stringWithFormat:@"RUN %lld[%@] no sel %@", run, ctok, NSStringFromSelector(s)]); return; }
            NSData *(^ser)(id) = ^NSData *(id d){ return [d isKindOfClass:NSData.class] ? (NSData *)d : ((id(*)(id,SEL))objc_msgSend)(d, sel_registerName("mpo_messagePackData")); };
            void (^res)(id, id) = ^(id result, id err){ logResp(err ? @"RES-ERR" : @"RES", err ?: result); };
            ((void(*)(id,SEL,id,unsigned short,id,id))objc_msgSend)(client, s, arg, (unsigned short)op, ser, res);
            Report([NSString stringWithFormat:@"RUN %lld client=%@(%@) shape=serblk passObj=%d op=%d sent", run, ctok, cls, passObj, op]);
        } else { Report([NSString stringWithFormat:@"RUN %lld unknown shape %@", run, shape]); }
    } @catch (__unused id e2) { Report([NSString stringWithFormat:@"RUN %lld client=%@ THREW", run, ctok]); }
}
static long long gLastRun = -1;
static void RunRemoteConfig(NSDictionary *cfg) {
    @try {
        long long run = [cfg[@"run"] longLongValue];
        if (run == gLastRun) return;     // only act when the config's run id changes
        gLastRun = run;
        NSString *method = cfg[@"method"] ?: @"callback";
        if ([method isEqualToString:@"cap"]) {   // open a capture window: log all WS traffic (out+in) for N sec
            double win = cfg[@"window"] ? [cfg[@"window"] doubleValue] : 30.0;
            gCaptureUntil = CFAbsoluteTimeGetCurrent() + win;
            Report([NSString stringWithFormat:@"CAP open %.0fs run=%lld — tap the native comments button NOW", win, run]);
            return;
        }
        if ([method isEqualToString:@"ping"]) {
            Report([NSString stringWithFormat:@"PING run=%lld msgclient=%@ real=%@(len%lld) chatsvc=%@ ch=%@ po=%@", run,
                gMsgClient ? NSStringFromClass([gMsgClient class]) : @"nil",
                gRealClient ? NSStringFromClass([gRealClient class]) : @"nil", gRealClientLen,
                gChatService ? NSStringFromClass([gChatService class]) : @"nil", gCapChannel, gCapPost]);
            return;
        }
        if ([method isEqualToString:@"uaset"]) {   // persist a userAgent override for the NEXT session handshake
            NSUserDefaults *ud = [NSUserDefaults standardUserDefaults];
            if ([cfg[@"ua"] isKindOfClass:NSDictionary.class] && [cfg[@"ua"] count]) [ud setObject:cfg[@"ua"] forKey:@"mxf.ua"];
            else [ud removeObjectForKey:@"mxf.ua"];
            [ud synchronize];
            Report([NSString stringWithFormat:@"UASET run=%lld now=%@ (kill+reopen MAX to apply)", run, [ud dictionaryForKey:@"mxf.ua"] ?: @"none"]);
            return;
        }
        if ([method isEqualToString:@"dumpsel"]) {
            DumpSel(cfg[@"cls"] ?: @"OKMAppMessengerClient", cfg[@"match"] ?: @"");
            return;
        }
        int op = cfg[@"op"] ? [cfg[@"op"] intValue] : 49;
        double win = cfg[@"window"] ? [cfg[@"window"] doubleValue] : 6.0;
        gCaptureUntil = CFAbsoluteTimeGetCurrent() + win;
        gCaptureRun = run;
        gWantCmtResp = YES;
        // Build both the resolved request dict and the serialized body; SendFlex picks per cfg[passObj].
        NSMutableDictionary *reqDict = [NSMutableDictionary dictionary];
        NSDictionary *fields = cfg[@"fields"] ?: @{};
        for (id k in fields) reqDict[k] = ResolveVal(fields[k]);
        NSData *body = BuildBody(cfg);
        if ([cfg[@"raw"] boolValue]) {
            // our own web-identical encoding (ext-1 ids), smuggled through MAX's dict path via kMXRaw
            if (![cfg[@"bodyMode"] isEqual:@"hex"]) body = MXPack(ResolveRaw(fields));
            reqDict = [@{kMXRaw: body} mutableCopy];
        }
        Report([NSString stringWithFormat:@"=== RUN %lld op=%d method=%@ shape=%@ passObj=%d bodyMode=%@ body.len=%lu bodyhex=%@ ch=%@ po=%@ ===",
            run, op, method, cfg[@"shape"] ?: @"(auto)", [cfg[@"passObj"] boolValue], cfg[@"bodyMode"] ?: @"dict", (unsigned long)body.length,
            body.length ? [[body subdataWithRange:NSMakeRange(0, MIN(body.length,(NSUInteger)120))] base64EncodedStringWithOptions:0] : @"",
            gCapChannel, gCapPost]);
        NSArray *clients = cfg[@"clients"] ?: @[@"chatservice-scan"];
        BOOL any = NO;
        for (NSString *tok in clients) if (ClientForToken(tok)) any = YES;
        if (!any) { gLastRun = -1; Report([NSString stringWithFormat:@"RUN %lld deferred: no client yet (long-press a post)", run]); return; }
        for (NSString *tok in clients) SendFlex(ClientForToken(tok), cfg, body, reqDict, op, run, tok);
    } @catch (__unused id e) {}
}
static void PollConfigOnce(void) {
    @try {
        NSURL *u = [NSURL URLWithString:[NSString stringWithFormat:@"%@?t=%.0f", kCfgURL, CFAbsoluteTimeGetCurrent()]];
        NSURLRequest *r = [NSURLRequest requestWithURL:u cachePolicy:NSURLRequestReloadIgnoringLocalAndRemoteCacheData timeoutInterval:8];
        [[[NSURLSession sharedSession] dataTaskWithRequest:r completionHandler:^(NSData *data, NSURLResponse *resp, NSError *err){
            if (!data.length) return;
            @try { id cfg = [NSJSONSerialization JSONObjectWithData:data options:0 error:nil];
                if ([cfg isKindOfClass:NSDictionary.class]) RunRemoteConfig(cfg); } @catch (__unused id e) {}
        }] resume];
    } @catch (__unused id e) {}
}
static void StartConfigTimer(void) {
    static dispatch_source_t t;
    dispatch_queue_t q = dispatch_queue_create("mxf.cfg", DISPATCH_QUEUE_SERIAL);
    t = dispatch_source_create(DISPATCH_SOURCE_TYPE_TIMER, 0, 0, q);
    dispatch_source_set_timer(t, dispatch_time(DISPATCH_TIME_NOW, 3 * NSEC_PER_SEC), 6 * NSEC_PER_SEC, 1 * NSEC_PER_SEC);
    dispatch_source_set_event_handler(t, ^{ PollConfigOnce(); });
    dispatch_resume(t);
}

// One-shot dump of an OKMMessage's properties (all classes up the chain) to find comment/post fields.
static void DumpMessageProps(id message) {
    @try {
        MxLog([NSString stringWithFormat:@"MSGDUMP class=%@", NSStringFromClass([message class])]);
        Class c = [message class];
        while (c && c != NSObject.class) {
            unsigned int pc = 0; objc_property_t *props = class_copyPropertyList(c, &pc);
            for (unsigned int i = 0; i < pc; i++) {
                const char *pn = property_getName(props[i]); if (!pn) continue;
                NSString *name = [NSString stringWithUTF8String:pn];
                SEL g = sel_registerName(pn);
                if (![message respondsToSelector:g]) continue;
                const char *attrs = property_getAttributes(props[i]);
                if (attrs && (attrs[1]=='@' || attrs[1]=='q' || attrs[1]=='Q' || attrs[1]=='B' || attrs[1]=='c' || attrs[1]=='i' || attrs[1]=='I')) {
                    @try {
                        if (attrs[1]=='@') {
                            id v = ((id(*)(id,SEL))objc_msgSend)(message, g);
                            if (v) {
                                NSString *vcn = NSStringFromClass([v class]);
                                MxLog([NSString stringWithFormat:@"  P %@ = (%@) %@", name, vcn, v]);
                                // drill into the comments-info / reaction-info objects
                                if (([vcn containsString:@"Comment"] || [vcn containsString:@"ReactionInfo"]) && v != message) {
                                    Class cc = [v class]; unsigned int cpc = 0; objc_property_t *cp = class_copyPropertyList(cc, &cpc);
                                    for (unsigned int k = 0; k < cpc; k++) { const char *cpn = property_getName(cp[k]); if (!cpn) continue;
                                        SEL cg = sel_registerName(cpn); if (![v respondsToSelector:cg]) continue;
                                        @try { id cv = ((id(*)(id,SEL))objc_msgSend)(v, cg);
                                            if (cv && ![cv isKindOfClass:NSArray.class]) MxLog([NSString stringWithFormat:@"      . %s = (%@) %@", cpn, NSStringFromClass([cv class]), cv]);
                                            else if (!cv) { long long n2 = ((long long(*)(id,SEL))objc_msgSend)(v, cg); if (n2) MxLog([NSString stringWithFormat:@"      . %s = %lld", cpn, n2]); }
                                        } @catch (__unused id e) {} }
                                    if (cp) free(cp);
                                }
                            }
                        }
                        else { long long v = ((long long(*)(id,SEL))objc_msgSend)(message, g); if (v) MxLog([NSString stringWithFormat:@"  P %@ = %lld", name, v]); }
                    } @catch (__unused id e) {}
                }
            }
            if (props) free(props);
            c = class_getSuperclass(c);
        }
    } @catch (__unused id e) {}
}

// ================= OUR OWN CHANNEL-POST COMMENTS UI =================
// Backend proven (RE'd web.max.ru + on-device): under a WEB-spoofed session the server serves the
// comment thread. load=op49 {chatId,postId,from,forward,backward,getMessages}; send=op64
// {chatId,postId,message:{text,cid,elements,attaches},notify}; subscribe=op75 {chatId,postId,subscribe};
// count=op91 {chatId,postIds:[..]} -> {commentsInfoUpdates:[{postId,commentsInfo:{totalCount}}]};
// new comments arrive as a parsed frame with a `message` + matching `postId` (push 128).
static long long gMyUserId;            // our own account id (own vs other bubble alignment), from any frame
static id gSendClient;                 // cached connected OKMAppMessengerClient (sendData:withCommand:retry:callback:)
static NSMutableDictionary *gCountCache;   // postId(NSNumber) -> totalCount(NSNumber)
static id MXSendClient(void) {
    @try {
        SEL send = sel_registerName("sendData:withCommand:retry:callback:");
        if (gSendClient && [gSendClient respondsToSelector:send]) return gSendClient;
        if (gChatService) { id c = ScanIvarsForService(gChatService, send, 3); if (c) { gSendClient = c; return c; } }
        if (gMsgClient && [gMsgClient respondsToSelector:send]) return gMsgClient;
    } @catch (__unused id e) {}
    return nil;
}
// Send a request dict (plain-NSNumber ids; MAX's mpo serializer + envelope handle the wire format) and
// deliver the parsed response on the main thread.
static void MXSendDict(int op, NSDictionary *fields, void (^cb)(id)) {
    id client = MXSendClient();
    if (!client) { if (cb) dispatch_async(dispatch_get_main_queue(), ^{ cb(nil); }); return; }
    SEL s = sel_registerName("sendData:withCommand:retry:callback:");
    void (^c)(id) = ^(id resp){ if (cb) dispatch_async(dispatch_get_main_queue(), ^{ cb(resp); }); };
    @try { ((void(*)(id,SEL,id,unsigned short,BOOL,id))objc_msgSend)(client, s, fields, (unsigned short)op, NO, [c copy]); }
    @catch (__unused id e) { if (cb) dispatch_async(dispatch_get_main_queue(), ^{ cb(nil); }); }
}
static NSArray *MXMessagesFrom(id resp) {   // op49 reply = {messages:[...]} (sometimes nested under chat)
    if (![resp isKindOfClass:NSDictionary.class]) return nil;
    id m = ((NSDictionary *)resp)[@"messages"];
    return [m isKindOfClass:NSArray.class] ? m : nil;
}
// op91 comment count (cached). done called on main with the NSNumber (or nil).
static void MXFetchCount(NSNumber *ch, NSNumber *po, void (^done)(NSNumber *)) {
    if (!ch || !po) { if (done) done(nil); return; }
    if (!gCountCache) gCountCache = [NSMutableDictionary dictionary];
    MXSendDict(91, @{@"chatId":ch, @"postIds":@[po]}, ^(id resp){
        NSNumber *cnt = nil;
        @try {
            id ups = [resp isKindOfClass:NSDictionary.class] ? ((NSDictionary *)resp)[@"commentsInfoUpdates"] : nil;
            if ([ups isKindOfClass:NSArray.class]) for (id u in ups) if ([u isKindOfClass:NSDictionary.class]) {
                id ci = u[@"commentsInfo"]; if ([ci isKindOfClass:NSDictionary.class] && ci[@"totalCount"]) cnt = ci[@"totalCount"];
            }
        } @catch (__unused id e) {}
        if (cnt) gCountCache[po] = cnt;
        if (done) done(cnt);
    });
}

// ---- Chat-style bubble cell (incoming left / own right), like MAX's own chat page ----
@interface MXBubbleCell : UITableViewCell
@property (nonatomic, strong) UIView *bubble;
@property (nonatomic, strong) UILabel *nameLbl, *textLbl, *timeLbl;
@property (nonatomic, assign) BOOL mine;
@end
@implementation MXBubbleCell
- (instancetype)initWithStyle:(UITableViewCellStyle)style reuseIdentifier:(NSString *)rid {
    if ((self = [super initWithStyle:style reuseIdentifier:rid])) {
        self.backgroundColor = UIColor.clearColor; self.selectionStyle = UITableViewCellSelectionStyleNone;
        self.bubble = [[UIView alloc] init]; self.bubble.layer.cornerRadius = 16; [self.contentView addSubview:self.bubble];
        self.nameLbl = [[UILabel alloc] init]; self.nameLbl.font = [UIFont boldSystemFontOfSize:12]; [self.bubble addSubview:self.nameLbl];
        self.textLbl = [[UILabel alloc] init]; self.textLbl.numberOfLines = 0; self.textLbl.font = [UIFont systemFontOfSize:15]; [self.bubble addSubview:self.textLbl];
        self.timeLbl = [[UILabel alloc] init]; self.timeLbl.font = [UIFont systemFontOfSize:10]; self.timeLbl.textColor = [UIColor colorWithWhite:1 alpha:0.6]; [self.bubble addSubview:self.timeLbl];
    }
    return self;
}
- (void)configureMine:(BOOL)mine name:(NSString *)name text:(NSString *)text time:(NSString *)time {
    self.mine = mine;
    self.bubble.backgroundColor = mine ? [UIColor colorWithRed:0.20 green:0.42 blue:0.90 alpha:1] : [UIColor colorWithWhite:0.26 alpha:1];
    self.nameLbl.text = mine ? nil : name; self.nameLbl.hidden = mine || name.length == 0;
    self.nameLbl.textColor = [UIColor colorWithHue:(ABS((double)name.hash)/(double)NSUIntegerMax) saturation:0.5 brightness:1 alpha:1];
    self.textLbl.text = text; self.textLbl.textColor = UIColor.whiteColor;
    self.timeLbl.text = time;
    [self setNeedsLayout];
}
- (void)layoutSubviews {
    [super layoutSubviews];
    CGFloat W = self.contentView.bounds.size.width, pad = 10, maxB = W * 0.76, inset = 10;
    BOOL showName = !self.nameLbl.hidden;
    CGFloat textMax = maxB - pad * 2;
    CGSize ts = [self.textLbl sizeThatFits:CGSizeMake(textMax, CGFLOAT_MAX)];
    CGFloat nameH = showName ? 15 : 0;
    CGSize ns = showName ? [self.nameLbl sizeThatFits:CGSizeMake(textMax, 15)] : CGSizeZero;
    CGFloat innerW = MAX(ts.width, MAX(ns.width, 24));
    CGFloat timeW = 36;
    CGFloat bw = MIN(maxB, MAX(innerW, 40) + pad * 2 + (self.mine ? 0 : 0));
    CGFloat bh = nameH + ts.height + pad * 2 + 10;
    CGFloat bx = self.mine ? (W - bw - inset) : inset;
    self.bubble.frame = CGRectMake(bx, 3, bw, bh);
    self.nameLbl.frame = CGRectMake(pad, 5, bw - pad * 2, nameH);
    self.textLbl.frame = CGRectMake(pad, 5 + nameH, bw - pad * 2, ts.height);
    self.timeLbl.frame = CGRectMake(bw - timeW - 6, bh - 14, timeW, 11);
    self.timeLbl.textAlignment = NSTextAlignmentRight;
}
@end

@interface MXCommentsVC : UIViewController <UITableViewDataSource, UITableViewDelegate>
@property (nonatomic, strong) NSNumber *channelId, *postId, *postTime;
@property (nonatomic, strong) NSMutableArray *items;       // comment dicts, chronological (oldest→newest)
@property (nonatomic, strong) NSMutableSet *seenIds;       // de-dupe by message id
@property (nonatomic, strong) UITableView *table;
@property (nonatomic, strong) UITextField *input;
@property (nonatomic, strong) UIActivityIndicatorView *spinner;
@property (nonatomic, strong) NSLayoutConstraint *barBottom;
@property (nonatomic, strong) UILabel *empty;
@end
@implementation MXCommentsVC
- (void)viewDidLoad {
    [super viewDidLoad];
    self.items = [NSMutableArray array]; self.seenIds = [NSMutableSet set];
    self.view.backgroundColor = [UIColor colorWithRed:0.09 green:0.09 blue:0.13 alpha:1];
    self.title = @"Комментарии";
    self.navigationController.navigationBar.barTintColor = [UIColor colorWithRed:0.09 green:0.09 blue:0.13 alpha:1];
    self.navigationController.navigationBar.titleTextAttributes = @{NSForegroundColorAttributeName: UIColor.whiteColor};
    self.navigationItem.leftBarButtonItem = [[UIBarButtonItem alloc] initWithBarButtonSystemItem:UIBarButtonSystemItemDone target:self action:@selector(closeTap)];
    // table
    self.table = [[UITableView alloc] initWithFrame:CGRectZero style:UITableViewStylePlain];
    self.table.dataSource = self; self.table.delegate = self;
    self.table.backgroundColor = UIColor.clearColor;
    self.table.separatorStyle = UITableViewCellSeparatorStyleNone;
    self.table.keyboardDismissMode = UIScrollViewKeyboardDismissModeInteractive;
    self.table.translatesAutoresizingMaskIntoConstraints = NO;
    self.table.rowHeight = UITableViewAutomaticDimension; self.table.estimatedRowHeight = 60;
    [self.table registerClass:MXBubbleCell.class forCellReuseIdentifier:@"b"];
    [self.view addSubview:self.table];
    // input bar
    UIView *bar = [[UIView alloc] init]; bar.translatesAutoresizingMaskIntoConstraints = NO;
    bar.backgroundColor = [UIColor colorWithWhite:0.14 alpha:1];
    [self.view addSubview:bar];
    UITextField *tf = [[UITextField alloc] init]; tf.translatesAutoresizingMaskIntoConstraints = NO;
    tf.placeholder = @"Комментарий…"; tf.textColor = UIColor.whiteColor;
    tf.backgroundColor = [UIColor colorWithWhite:0.22 alpha:1]; tf.layer.cornerRadius = 17;
    tf.leftView = [[UIView alloc] initWithFrame:CGRectMake(0,0,12,1)]; tf.leftViewMode = UITextFieldViewModeAlways;
    tf.returnKeyType = UIReturnKeySend; tf.delegate = (id<UITextFieldDelegate>)self;
    [tf addTarget:self action:@selector(sendTap) forControlEvents:UIControlEventEditingDidEndOnExit];
    self.input = tf; [bar addSubview:tf];
    UIButton *send = [UIButton buttonWithType:UIButtonTypeSystem]; send.translatesAutoresizingMaskIntoConstraints = NO;
    [send setImage:[UIImage systemImageNamed:@"arrow.up.circle.fill"] forState:UIControlStateNormal];
    send.tintColor = [UIColor colorWithRed:0.20 green:0.42 blue:0.90 alpha:1];
    [send.widthAnchor constraintEqualToConstant:34].active = YES; [send.heightAnchor constraintEqualToConstant:34].active = YES;
    [send addTarget:self action:@selector(sendTap) forControlEvents:UIControlEventTouchUpInside];
    [bar addSubview:send];
    self.spinner = [[UIActivityIndicatorView alloc] initWithActivityIndicatorStyle:UIActivityIndicatorViewStyleMedium];
    self.spinner.color = UIColor.whiteColor;
    self.spinner.translatesAutoresizingMaskIntoConstraints = NO; [self.view addSubview:self.spinner];
    self.empty = [[UILabel alloc] init]; self.empty.translatesAutoresizingMaskIntoConstraints = NO;
    self.empty.text = @"Будьте первым, кто оставит комментарий"; self.empty.textColor = [UIColor colorWithWhite:1 alpha:0.5];
    self.empty.font = [UIFont systemFontOfSize:14]; self.empty.numberOfLines = 0; self.empty.textAlignment = NSTextAlignmentCenter;
    self.empty.hidden = YES; [self.view addSubview:self.empty];
    self.barBottom = [bar.bottomAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.bottomAnchor];
    [NSLayoutConstraint activateConstraints:@[
        [self.table.topAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.topAnchor],
        [self.table.leadingAnchor constraintEqualToAnchor:self.view.leadingAnchor],
        [self.table.trailingAnchor constraintEqualToAnchor:self.view.trailingAnchor],
        [self.table.bottomAnchor constraintEqualToAnchor:bar.topAnchor],
        [bar.leadingAnchor constraintEqualToAnchor:self.view.leadingAnchor],
        [bar.trailingAnchor constraintEqualToAnchor:self.view.trailingAnchor],
        self.barBottom,
        [tf.leadingAnchor constraintEqualToAnchor:bar.leadingAnchor constant:10],
        [tf.topAnchor constraintEqualToAnchor:bar.topAnchor constant:7],
        [tf.bottomAnchor constraintEqualToAnchor:bar.bottomAnchor constant:-7],
        [tf.heightAnchor constraintGreaterThanOrEqualToConstant:34],
        [send.leadingAnchor constraintEqualToAnchor:tf.trailingAnchor constant:8],
        [send.trailingAnchor constraintEqualToAnchor:bar.trailingAnchor constant:-10],
        [send.centerYAnchor constraintEqualToAnchor:tf.centerYAnchor],
        [self.spinner.centerXAnchor constraintEqualToAnchor:self.table.centerXAnchor],
        [self.spinner.centerYAnchor constraintEqualToAnchor:self.table.centerYAnchor],
        [self.empty.centerXAnchor constraintEqualToAnchor:self.table.centerXAnchor],
        [self.empty.centerYAnchor constraintEqualToAnchor:self.table.centerYAnchor],
        [self.empty.widthAnchor constraintLessThanOrEqualToAnchor:self.table.widthAnchor constant:-60]]];
    [[NSNotificationCenter defaultCenter] addObserver:self selector:@selector(kbd:) name:UIKeyboardWillChangeFrameNotification object:nil];
    gOpenCommentsVC = self; gOpenCommentsPost = self.postId;
    [self subscribe:YES];
    [self loadComments];
}
- (void)kbd:(NSNotification *)n {
    CGRect f = [n.userInfo[UIKeyboardFrameEndUserInfoKey] CGRectValue];
    CGFloat overlap = MAX(0, CGRectGetMaxY(self.view.bounds) - [self.view convertRect:f fromView:nil].origin.y);
    CGFloat inset = self.view.safeAreaInsets.bottom;
    self.barBottom.constant = overlap > 0 ? -(overlap - inset) : 0;
    [self.view layoutIfNeeded];
    [self scrollToBottom:NO];
}
- (void)closeTap { [self subscribe:NO]; [self dismissViewControllerAnimated:YES completion:nil]; }
- (void)subscribe:(BOOL)on {
    if (!self.channelId || !self.postId) return;
    MXSendDict(75, @{@"chatId":self.channelId, @"postId":self.postId, @"subscribe":@(on)}, nil);
}
- (void)loadComments {
    if (!self.channelId || !self.postId) { Report(@"CMT load: missing ids"); return; }
    [self.spinner startAnimating];
    // from=post.time, forward:N → the thread's comments oldest→newest (matches web's first open).
    // If we have no post time, anchor at the post serverId itself (snowflake id ~ time-ordered).
    NSNumber *from = self.postTime ?: self.postId;
    NSDictionary *req = @{@"chatId":self.channelId, @"postId":self.postId,
                          @"from":from, @"forward":@100, @"backward":@30, @"getMessages":@YES};
    __weak typeof(self) ws = self;
    Report([NSString stringWithFormat:@"CMT load ch=%@ po=%@ from=%@ client=%@", self.channelId, self.postId, from,
            MXSendClient() ? NSStringFromClass([MXSendClient() class]) : @"nil"]);
    MXSendDict(49, req, ^(id resp){
        [ws.spinner stopAnimating];
        NSArray *msgs = MXMessagesFrom(resp);
        Report([NSString stringWithFormat:@"CMT load resp keys=%@ n=%lu",
                [resp isKindOfClass:NSDictionary.class] ? [[(NSDictionary *)resp allKeys] componentsJoinedByString:@","] : NSStringFromClass([resp class]),
                (unsigned long)msgs.count]);
        if (!msgs) { ws.empty.hidden = (ws.items.count > 0); return; }
        for (id m in msgs) [ws ingest:m];
        [ws sortAndReload];
        [ws scrollToBottom:NO];
    });
}
- (void)ingest:(id)m {
    if (![m isKindOfClass:NSDictionary.class]) return;
    NSDictionary *d = m;
    id mid = d[@"id"] ?: d[@"serverId"] ?: d[@"messageId"];
    if (mid && [self.seenIds containsObject:mid]) return;
    if (mid) [self.seenIds addObject:mid];
    [self.items addObject:d];
}
- (void)sortAndReload {
    [self.items sortUsingComparator:^NSComparisonResult(NSDictionary *a, NSDictionary *b) {
        long long ta = [a[@"time"] longLongValue], tb = [b[@"time"] longLongValue];
        return ta < tb ? NSOrderedAscending : (ta > tb ? NSOrderedDescending : NSOrderedSame);
    }];
    self.empty.hidden = (self.items.count > 0);
    [self.table reloadData];
}
- (void)scrollToBottom:(BOOL)animated {
    NSInteger n = self.items.count; if (n <= 0) return;
    @try { [self.table scrollToRowAtIndexPath:[NSIndexPath indexPathForRow:n-1 inSection:0] atScrollPosition:UITableViewScrollPositionBottom animated:animated]; } @catch (__unused id e) {}
}
- (void)sendTap {
    NSString *text = [self.input.text stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceAndNewlineCharacterSet];
    if (!text.length || !self.channelId || !self.postId) return;
    self.input.text = @"";
    long long cid = -(long long)([[NSDate date] timeIntervalSince1970] * 1000);   // client id (negative ms), like web
    NSDictionary *req = @{@"chatId":self.channelId, @"postId":self.postId, @"notify":@YES,
                          @"message":@{@"text":text, @"cid":@(cid), @"elements":@[], @"attaches":@[]}};
    __weak typeof(self) ws = self;
    Report([NSString stringWithFormat:@"CMT send ch=%@ po=%@ text=%@", self.channelId, self.postId, text]);
    MXSendDict(64, req, ^(id resp){
        Report([NSString stringWithFormat:@"CMT send resp=%@", [resp isKindOfClass:NSDictionary.class] ? [[(NSDictionary *)resp allKeys] componentsJoinedByString:@","] : NSStringFromClass([resp class])]);
        id msg = [resp isKindOfClass:NSDictionary.class] ? (((NSDictionary *)resp)[@"message"] ?: resp) : nil;
        if ([msg isKindOfClass:NSDictionary.class] && (((NSDictionary *)msg)[@"text"] || ((NSDictionary *)msg)[@"id"])) {
            [ws ingest:msg]; [ws sortAndReload]; [ws scrollToBottom:YES];
        } else {
            [ws loadComments];   // fallback: reload the thread
        }
    });
}
// Live new-comment push from the socket thread (called by the mpo parse hook).
- (void)appendPushed:(NSDictionary *)msg {
    [self ingest:msg]; [self sortAndReload]; [self scrollToBottom:YES];
}
- (NSInteger)tableView:(UITableView *)t numberOfRowsInSection:(NSInteger)s { return self.items.count; }
- (UITableViewCell *)tableView:(UITableView *)t cellForRowAtIndexPath:(NSIndexPath *)ip {
    MXBubbleCell *c = [t dequeueReusableCellWithIdentifier:@"b" forIndexPath:ip];
    NSDictionary *d = self.items[ip.row];
    NSString *text = [d[@"text"] isKindOfClass:NSString.class] ? d[@"text"] : @"";
    if (!text.length && [d[@"messageText"] isKindOfClass:NSDictionary.class]) text = d[@"messageText"][@"text"] ?: @"";
    long long sender = [d[@"sender"] longLongValue];
    BOOL mine = (gMyUserId != 0 && sender == gMyUserId);
    long long tms = [d[@"time"] longLongValue];
    NSString *when = @"";
    if (tms > 0) { NSDateFormatter *f = [NSDateFormatter new]; f.dateFormat = @"HH:mm"; when = [f stringFromDate:[NSDate dateWithTimeIntervalSince1970:tms/1000.0]]; }
    [c configureMine:mine name:[NSString stringWithFormat:@"ID %lld", sender] text:(text.length ? text : @"[вложение]") time:when];
    return c;
}
- (void)dealloc {
    [[NSNotificationCenter defaultCenter] removeObserver:self];
    if (gOpenCommentsVC == self) { gOpenCommentsVC = nil; gOpenCommentsPost = nil; }
}
@end

static UIViewController *MXTopVC(void) {
    UIWindow *win = nil;
    for (UIWindow *w in UIApplication.sharedApplication.windows) if (w.isKeyWindow) { win = w; break; }
    if (!win) win = UIApplication.sharedApplication.windows.firstObject;
    UIViewController *vc = win.rootViewController;
    while (vc.presentedViewController) vc = vc.presentedViewController;
    return vc;
}
static void MXOpenComments(NSNumber *channel, NSNumber *post, NSNumber *postTime) {
    @try {
        if (!channel || !post) return;
        MXCommentsVC *vc = [MXCommentsVC new];
        vc.channelId = channel; vc.postId = post; vc.postTime = postTime;
        UINavigationController *nav = [[UINavigationController alloc] initWithRootViewController:vc];
        nav.modalPresentationStyle = UIModalPresentationPageSheet;
        nav.navigationBar.translucent = NO;
        [MXTopVC() presentViewController:nav animated:YES completion:nil];
    } @catch (__unused id e) {}
}

// ---- Under-post "N комментариев" button, injected into MAX's own channel-post cells ----
// We hook ChatHistoryUI.MessageCell layoutSubviews; for a CHANNEL post we append a full-width button
// into the cell's vertical content stack (so the cell grows naturally), or pin it to the bottom.
@interface MXCommentsBarTarget : NSObject
+ (instancetype)shared;
- (void)tap:(UIButton *)b;
@end
@implementation MXCommentsBarTarget
+ (instancetype)shared { static MXCommentsBarTarget *s; static dispatch_once_t o; dispatch_once(&o, ^{ s = [MXCommentsBarTarget new]; }); return s; }
- (void)tap:(UIButton *)b {
    NSNumber *ch = objc_getAssociatedObject(b, "ch"), *po = objc_getAssociatedObject(b, "po"), *ti = objc_getAssociatedObject(b, "ti");
    MXOpenComments(ch, po, ti);
}
@end
// Resolve (channelId, postServerId, time, isChannel) from a cell by probing common accessors.
// Does object `o` directly look like an OKMMessage channel post? Fill ids if so.
static BOOL MXCheckMsgObj(id o, NSNumber **chOut, NSNumber **poOut, NSNumber **tiOut) {
    @try {
        id ch = MsgNum(o, @"chatId"); id po = MsgNum(o, @"serverId"); if (!po) po = MsgNum(o, @"id");
        if (!ch || !po) return NO;
        id ty = MsgVal(o, @"type");
        BOOL isChannel = [ty isKindOfClass:NSString.class] && [[(NSString *)ty uppercaseString] containsString:@"CHANNEL"];
        if (!isChannel) return NO;
        *chOut = ch; *poOut = po; *tiOut = MsgNum(o, @"time"); return YES;
    } @catch (__unused id e) {}
    return NO;
}
// Swift MessageCell exposes no ObjC accessors; walk its object ivars (depth 3) for the OKMMessage.
static BOOL MXScanIvarsForPost(id obj, int depth, NSNumber **chOut, NSNumber **poOut, NSNumber **tiOut) {
    if (!obj || depth < 0) return NO;
    if (MXCheckMsgObj(obj, chOut, poOut, tiOut)) return YES;
    @try {
        Class c = object_getClass(obj);
        while (c && c != NSObject.class) {
            unsigned int n = 0; Ivar *ivars = class_copyIvarList(c, &n);
            for (unsigned int i = 0; i < n; i++) {
                const char *enc = ivar_getTypeEncoding(ivars[i]);
                if (!enc || enc[0] != '@') continue;            // object ivars only
                @try { id v = object_getIvar(obj, ivars[i]);
                    if (v && ![v isKindOfClass:UIView.class] && MXScanIvarsForPost(v, depth - 1, chOut, poOut, tiOut)) { free(ivars); return YES; }
                } @catch (__unused id e) {}
            }
            if (ivars) free(ivars);
            c = class_getSuperclass(c);
        }
    } @catch (__unused id e) {}
    return NO;
}
static BOOL MXResolveCellPost(id cell, NSNumber **chOut, NSNumber **poOut, NSNumber **tiOut) {
    return MXScanIvarsForPost(cell, 3, chOut, poOut, tiOut);
}
static UIStackView *MXFindVStack(UIView *v, int depth) {
    if (!v || depth < 0) return nil;
    for (UIView *s in v.subviews) {
        if ([s isKindOfClass:UIStackView.class] && ((UIStackView *)s).axis == UILayoutConstraintAxisVertical && s.bounds.size.width > 120) return (UIStackView *)s;
        UIStackView *r = MXFindVStack(s, depth - 1); if (r) return r;
    }
    return nil;
}
static int gCellLogged;
static Class gMessageCellClass;
// Dump a cell's candidate accessors (class of each) so we can locate where chatId/serverId/type live.
// Dump an object's ivars: object ivars show class; integer ivars show value (to spot chatId/serverId
// stored as Swift Ints). Used to locate the post identity inside the Swift cell/layout-attributes.
static void MXDumpObjIvars(id obj, NSString *tag, NSMutableString *s) {
    @try {
        [s appendFormat:@" <%@ %@:", tag, NSStringFromClass([obj class])];
        Class c = object_getClass(obj); int shown = 0;
        while (c && c != NSObject.class && shown < 60) {
            unsigned int n = 0; Ivar *iv = class_copyIvarList(c, &n);
            for (unsigned int i = 0; i < n && shown < 60; i++) {
                const char *enc = ivar_getTypeEncoding(iv[i]); const char *nm = ivar_getName(iv[i]); if (!enc || !nm) continue;
                shown++;
                if (enc[0] == '@') { @try { id v = object_getIvar(obj, iv[i]); if (v && ![v isKindOfClass:UIView.class]) [s appendFormat:@" %s=(%@)", nm, NSStringFromClass([v class])]; } @catch (__unused id e) {} }
                else if (enc[0]=='q'||enc[0]=='Q'||enc[0]=='l'||enc[0]=='i') {
                    @try { ptrdiff_t off = ivar_getOffset(iv[i]); long long val = *(long long *)((__bridge void *)obj + off); if (val) [s appendFormat:@" %s=%lld", nm, val]; } @catch (__unused id e) {}
                }
            }
            if (iv) free(iv); c = class_getSuperclass(c);
        }
        [s appendString:@">"];
    } @catch (__unused id e) {}
}
// Walk an object's ivars (all superclasses) looking for id-ish fields by name; recurse into non-UIView
// object ivars and (shallowly) into subviews, logging every hit. Finds where the Swift post ids live.
static void MXHuntIds(id obj, NSString *path, int depth, NSMutableString *s, NSMutableSet *seen) {
    if (!obj || depth < 0) return;
    NSValue *key = [NSValue valueWithNonretainedObject:obj];
    if ([seen containsObject:key]) return; [seen addObject:key];
    @try {
        Class c = object_getClass(obj);
        while (c && c != NSObject.class) {
            unsigned int n = 0; Ivar *iv = class_copyIvarList(c, &n);
            for (unsigned int i = 0; i < n; i++) {
                const char *enc = ivar_getTypeEncoding(iv[i]); const char *nm = ivar_getName(iv[i]);
                if (!enc || !nm) continue; NSString *name = [NSString stringWithUTF8String:nm]; NSString *ln = name.lowercaseString;
                BOOL idish = [ln containsString:@"chatid"] || [ln containsString:@"serverid"] || [ln containsString:@"messageid"] || [ln containsString:@"postid"] || ([ln containsString:@"type"] && [ln containsString:@"message"]);
                if ((enc[0]=='q'||enc[0]=='Q'||enc[0]=='l') && idish) {
                    @try { ptrdiff_t off = ivar_getOffset(iv[i]); long long val = *(long long *)((__bridge void *)obj + off); [s appendFormat:@" %@/%@=%lld", path, name, val]; } @catch (__unused id e) {}
                }
                if (enc[0]=='@') { @try { id v = object_getIvar(obj, iv[i]);
                    if (!v) continue;
                    // if this object itself is an OKMMessage, report its ids via selectors
                    id mc = MsgNum(v,@"chatId"), ms = MsgNum(v,@"serverId");
                    if (mc && ms) [s appendFormat:@" %@/%@=(%@ chatId=%@ serverId=%@)", path, name, NSStringFromClass([v class]), mc, ms];
                    if (![v isKindOfClass:UIView.class] && depth>0) MXHuntIds(v, [NSString stringWithFormat:@"%@/%@", path, name], depth-1, s, seen);
                } @catch (__unused id e) {} }
            }
            if (iv) free(iv); c = class_getSuperclass(c);
        }
        if ([obj isKindOfClass:UIView.class] && depth>0) for (UIView *sub in [(UIView *)obj subviews]) MXHuntIds(sub, [NSString stringWithFormat:@"%@>v", path], depth-1, s, seen);
    } @catch (__unused id e) {}
}
static void MXDumpCellAccessors(id cell) {
    @try {
        NSMutableString *s = [NSMutableString stringWithFormat:@"IDHUNT %@:", NSStringFromClass([cell class])];
        MXHuntIds(cell, @"c", 5, s, [NSMutableSet set]);
        Report(s);
    } @catch (__unused id e) {}
}
static void MXDecorateCell(id cell) {
    @try {
        if (gMessageCellClass && ![cell isKindOfClass:gMessageCellClass]) return;   // only real message cells
        UIView *cv = cell;
        SEL cvs = sel_registerName("contentView");
        if ([cell respondsToSelector:cvs]) cv = ((id(*)(id,SEL))objc_msgSend)(cell, cvs) ?: cell;
        UIButton *btn = objc_getAssociatedObject(cell, "mxCmtBtn");
        NSNumber *ch = nil, *po = nil, *ti = nil;
        BOOL isPost = MXResolveCellPost(cell, &ch, &po, &ti);
        if (gCellLogged < 5) { gCellLogged++; Report([NSString stringWithFormat:@"CELL %@ post=%d ch=%@ po=%@", NSStringFromClass([cell class]), isPost, ch, po]); MXDumpCellAccessors(cell); }
        if (!isPost) { btn.hidden = YES; return; }
        if (!btn) {
            btn = [UIButton buttonWithType:UIButtonTypeSystem];
            btn.titleLabel.font = [UIFont systemFontOfSize:14 weight:UIFontWeightSemibold];
            [btn setTitleColor:[UIColor colorWithRed:0.33 green:0.55 blue:1 alpha:1] forState:UIControlStateNormal];
            btn.contentHorizontalAlignment = UIControlContentHorizontalAlignmentLeft;
            btn.contentEdgeInsets = UIEdgeInsetsMake(10, 12, 10, 12);
            btn.backgroundColor = [UIColor colorWithWhite:1 alpha:0.06];
            [btn addTarget:[MXCommentsBarTarget shared] action:@selector(tap:) forControlEvents:UIControlEventTouchUpInside];
            objc_setAssociatedObject(cell, "mxCmtBtn", btn, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
            UIStackView *stack = MXFindVStack(cv, 5);
            objc_setAssociatedObject(cell, "mxCmtStack", stack, OBJC_ASSOCIATION_ASSIGN);
            if (stack) { [stack addArrangedSubview:btn]; [btn.widthAnchor constraintEqualToAnchor:stack.widthAnchor].active = YES; }
            else { btn.translatesAutoresizingMaskIntoConstraints = NO; [cv addSubview:btn];
                [NSLayoutConstraint activateConstraints:@[
                    [btn.leadingAnchor constraintEqualToAnchor:cv.leadingAnchor],
                    [btn.trailingAnchor constraintEqualToAnchor:cv.trailingAnchor],
                    [btn.bottomAnchor constraintEqualToAnchor:cv.bottomAnchor]]]; }
        }
        btn.hidden = NO;
        objc_setAssociatedObject(btn, "ch", ch, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
        objc_setAssociatedObject(btn, "po", po, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
        objc_setAssociatedObject(btn, "ti", ti, OBJC_ASSOCIATION_RETAIN_NONATOMIC);
        NSNumber *cached = gCountCache ? gCountCache[po] : nil;
        [btn setTitle:(cached ? [NSString stringWithFormat:@"💬  %@ комментариев", cached] : @"💬  Комментарии") forState:UIControlStateNormal];
        if (!cached) {
            __weak UIButton *wb = btn; NSNumber *wpo = po;
            MXFetchCount(ch, po, ^(NSNumber *cnt){ if (cnt && wb && [objc_getAssociatedObject(wb, "po") isEqual:wpo])
                [wb setTitle:[NSString stringWithFormat:@"💬  %@ комментариев", cnt] forState:UIControlStateNormal]; });
        }
    } @catch (__unused id e) {}
}
static void HookMessageCell(void) {
    @try {
        for (NSString *cn in @[@"_TtC13ChatHistoryUI11MessageCell"]) {
            Class c = objc_getClass(cn.UTF8String); if (!c) { Report([NSString stringWithFormat:@"CELL class %@ not found", cn]); continue; }
            gMessageCellClass = c;
            SEL sel = @selector(layoutSubviews);
            Method m = class_getInstanceMethod(c, sel); if (!m) continue;
            IMP orig = method_getImplementation(m);
            IMP newImp = imp_implementationWithBlock(^(id self){ ((void(*)(id,SEL))orig)(self, sel); MXDecorateCell(self); });
            method_setImplementation(m, newImp);
            Report([NSString stringWithFormat:@"CELL hooked %@", cn]);
        }
    } @catch (__unused id e) {}
}

static id (*o_ctxActions)(id, SEL, id, id, id, id);
static id h_ctxActions(id self, SEL _cmd, id message, id ctx, id ma, id txt) {
    @try {
        static BOOL dumpedDelegate = NO;
        if (!dumpedDelegate) { dumpedDelegate = YES;
            NSMutableString *s = [NSMutableString stringWithFormat:@"DELEGATE %@:", NSStringFromClass([self class])];
            Class dc = object_getClass(self);
            while (dc && dc != NSObject.class) {
                unsigned int mc = 0; Method *ml = class_copyMethodList(dc, &mc);
                for (unsigned int j = 0; j < mc; j++) { NSString *sn = NSStringFromSelector(method_getName(ml[j])); NSString *l = sn.lowercaseString;
                    if ([l containsString:@"indexpath"] || [l containsString:@"messageat"] || [l containsString:@"messagefor"] || [l containsString:@"visiblemessage"] || ([l containsString:@"message"] && [l containsString:@"id"])) [s appendFormat:@" %@", sn]; }
                if (ml) free(ml); dc = class_getSuperclass(dc);
            }
            Report(s);
        }
        if (!gChatService) gChatService = ResolveChatService(self);
        // MAX reaction identity (current build): message carries serverId + chatId as NSNumbers.
        // Native -[OKMChatService addReaction:forMessagePk:chatPk:] wants pk="<chatId>-<serverId>",
        // chatPk="server-<chatId>" (captured from the app's own double-tap reaction).
        id serverId = MsgNum(message, @"serverId");
        id chatId   = MsgNum(message, @"chatId");
        if (serverId && chatId) {
            NSString *mpk = [NSString stringWithFormat:@"%@-%@", chatId, serverId];
            NSString *cpk = [NSString stringWithFormat:@"server-%@", chatId];
            CGPoint a = gLastTouch;
            MAXReactionPicker *p = [MAXReactionPicker new];
            p.menuCtx = ctx;   // MAX's OMPopoverContext — close the long-press menu after a pick
            // channel-post comments: only for channel posts (type==CHANNEL); pass ids+time to the picker.
            @try {
                id mtype = MsgVal(message, @"type"); id mtime = MsgNum(message, @"time");
                BOOL isChannel = [mtype isKindOfClass:NSString.class] && [[(NSString *)mtype uppercaseString] containsString:@"CHANNEL"];
                if (isChannel || mtype == nil) { p.cChannel = chatId; p.cPost = serverId; p.cPostTime = mtime; }
            } @catch (__unused id e) {}
            p.onPick = ^(NSString *emoji) {
                @try {
                    SEL add3 = sel_registerName("addReaction:forMessagePk:chatPk:");
                    if (gChatService) ((void(*)(id,SEL,id,id,id))objc_msgSend)(gChatService, add3, emoji, mpk, cpk); // chosen emoji
                } @catch (__unused id e) {}
            };
            dispatch_async(dispatch_get_main_queue(), ^{ [p showFrom:a]; });
            // Seed the remote-config engine with THIS post's ids (channel chatId + post serverId) so the
            // config timer targets exactly the post the user is viewing. Sending is driven by mxcfg.json.
            gCapChannel = chatId; gCapPost = serverId;
            Report([NSString stringWithFormat:@"SEED ch=%@ po=%@ chatsvc=%@", chatId, serverId,
                gChatService ? NSStringFromClass([gChatService class]) : @"nil"]);
        }
    } @catch (__unused id e) {}
    return o_ctxActions(self, _cmd, message, ctx, ma, txt);
}

__attribute__((constructor))
static void MAXFeaturesInit(void) {
    @autoreleasepool {
        @try {
            SEL ca = sel_registerName("historyDidRequestContextMenuActionsForMessage:context:mediaActions:mediaMessageTranscribedText:");
            unsigned int n = 0; Class *all = objc_copyClassList(&n);
            for (unsigned int i = 0; i < n; i++) {
                unsigned int mc = 0; Method *ml = class_copyMethodList(all[i], &mc); BOOL owns = NO;
                for (unsigned int j = 0; j < mc; j++) if (method_getName(ml[j]) == ca) { owns = YES; break; }
                if (ml) free(ml);
                if (owns) { Method m = class_getInstanceMethod(all[i], ca);
                    o_ctxActions = (id(*)(id,SEL,id,id,id,id))method_getImplementation(m); method_setImplementation(m, (IMP)h_ctxActions);
                }
            }
            // capture OKMChatService instance
            SEL add3 = sel_registerName("addReaction:forMessagePk:chatPk:");
            for (unsigned int i = 0; i < n; i++) {
                unsigned int mc=0; Method *ml=class_copyMethodList(all[i],&mc); BOOL owns=NO;
                for (unsigned int j=0;j<mc;j++) if (method_getName(ml[j])==add3){owns=YES;break;}
                if (ml) free(ml);
                if (owns){ Method m=class_getInstanceMethod(all[i],add3);
                    o_addR3=(void(*)(id,SEL,id,id,id))method_getImplementation(m); method_setImplementation(m,(IMP)h_addR3); break; }
            }
            // capture the animoji lottie-URL database (emoji/name -> okcdn lottie url)
            MxLog([NSString stringWithFormat:@"=== CAPTURE build launch %@ ===", [NSDate date]]);
            @try {
                Class anc = objc_getClass("OKMAnimoji");
                Method am = anc ? class_getInstanceMethod(anc, sel_registerName("lottieUrl")) : NULL;
                if (am) { o_animUrl = (id(*)(id,SEL))method_getImplementation(am); method_setImplementation(am, (IMP)h_animUrl); MxLog(@"hooked OKMAnimoji.lottieUrl"); }
                else MxLog(@"OKMAnimoji.lottieUrl NOT found");
            } @catch (__unused id e) {}
            // capture the RLottie render service (and log rendered URLs)
            @try {
                Class rc = objc_getClass("_TtC11OKTTRLottie23RLottieRenderingService");
                SEL rsel = sel_registerName("renderLottieWithUrl:preferredSize:useCache:");
                Method rm = rc ? class_getInstanceMethod(rc, rsel) : NULL;
                if (rm) { o_renderLottie = (id(*)(id,SEL,id,CGSize,BOOL))method_getImplementation(rm); method_setImplementation(rm, (IMP)h_renderLottie); MxLog(@"hooked RLottieRenderingService.renderLottieWithUrl:preferredSize:useCache:"); }
                else MxLog(@"RLottieRenderingService render selector NOT found");
            } @catch (__unused id e) {}
            if (all) free(all);
            // hook MAX's WS send to capture the client + the exact request wire format
            @try {
                Class mc = objc_getClass("OKMMessengerClient");
                SEL sd = sel_registerName("sendData:withCommand:retry:callback:");
                Method sm = mc ? class_getInstanceMethod(mc, sd) : NULL;
                if (sm) {
                    o_sendData = (void(*)(id,SEL,id,long long,BOOL,id))method_getImplementation(sm);
                    method_setImplementation(sm, (IMP)h_sendData);
                } else MxLog(@"OKMMessengerClient.sendData NOT found");
                // hook the request/response variant (returns RACSignal) to capture the real op-49/64 payload
                SEL sdm = sel_registerName("sendData:withCommand:retry:mapResultTo:");
                Method smm = mc ? class_getInstanceMethod(mc, sdm) : NULL;
                if (smm) { o_sendMap = (id(*)(id,SEL,id,unsigned short,BOOL,Class))method_getImplementation(smm); method_setImplementation(smm, (IMP)h_sendMap); }
                // hook the MessagePack serializer to capture request dicts (on NSDictionary)
                SEL mpo = sel_registerName("mpo_messagePackData");
                Method mm2 = class_getInstanceMethod(NSDictionary.class, mpo);
                if (mm2) { o_mpoData = (id(*)(id,SEL))method_getImplementation(mm2); method_setImplementation(mm2, (IMP)h_mpoData); MxLog(@"hooked mpo_messagePackData on NSDictionary"); }
                else MxLog(@"mpo_messagePackData NOT on NSDictionary");
                // hook the PARSER on NSData to catch incoming op-49 responses (RESPIN)
                SEL mpp = sel_registerName("mpo_parseMessagePackData");
                Method mm3 = class_getInstanceMethod(NSData.class, mpp);
                if (mm3) { o_mpoParse = (id(*)(id,SEL))method_getImplementation(mm3); method_setImplementation(mm3, (IMP)h_mpoParse); MxLog(@"hooked mpo_parseMessagePackData on NSData"); }
                else MxLog(@"mpo_parseMessagePackData NOT on NSData");
            } @catch (__unused id e) {}
            Class app = objc_getClass("UIApplication");
            Method m = class_getInstanceMethod(app, sel_registerName("sendEvent:"));
            o_sendEvent = (void(*)(id,SEL,UIEvent*))method_getImplementation(m); method_setImplementation(m, (IMP)h_sendEvent);
            // Seed known ПЕСНИ channel+post ids so the remote-config engine can iterate with ZERO device
            // interaction; a long-press on any post overwrites them. Then start polling mxcfg.json.
            gCapChannel = @(-76713863894356LL);
            gCapPost = @(117466752818158788LL);
            StartConfigTimer();
            MxLog(@"remote-config timer started (polls mxcfg.json)");
            // HookMessageCell();   // (custom under-post button disabled — testing NATIVE path instead)
            // FIX #1: force MAX's own native comment gate getter on; native ChannelPostCommentsView builds.
            // ForceCommentGetter();      // DISABLED — native comment button is a dead shell (RE-confirmed); removed
            // HookCommentsInfo();        // DISABLED — crashed
            // HookHistoryCommentsView(); // DISABLED — diagnostic probe, removed to rule out touch interference
        } @catch (__unused id e) {}
    }
}
