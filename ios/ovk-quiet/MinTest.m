// Minimal diagnostic: empty-ish constructor that touches nothing ObjC/OVK at load.
// Tests whether OrwellVK's anti-tamper aborts on ANY added image, or only on our earlier
// constructor-time swizzling/class-touching. Writes one marker file post-launch.
#import <Foundation/Foundation.h>
__attribute__((constructor)) static void MinTestInit(void) {
    // Do nothing at load time. Just prove an image can be present without aborting.
}
