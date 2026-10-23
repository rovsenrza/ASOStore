// VKDump.m — one-shot FairPlay decryptor for the VK call-join RE (answer->audio handoff).
//
// The device is not jailbroken, but we control signing, so we inject this into a VK build the same
// way VKRelay.dylib is injected. It is compiled into VKRelay.dylib ONLY when built with -DVKDUMP
// (Makefile `make dump`); the production dylib never contains this code. At runtime, on the ONE
// test device, it reads the running (kernel-decrypted) VKClient slice from memory, rebuilds a thin
// cryptid=0 Mach-O, and writes it to the app's Documents dir so it can be pulled off the device and
// statically RE'd for VK's call-join/accept entry point.
//
// Dump the running slice only (arm64e on the test iPhone) — that is exactly what a decompiler wants,
// and it keeps the file layout valid: we copy the on-disk slice and overwrite just the encrypted
// __TEXT region with the decrypted bytes the kernel mapped at (header + cryptoff).
#import <Foundation/Foundation.h>
#import <mach-o/dyld.h>
#import <mach-o/loader.h>
#import <mach-o/fat.h>
#import <mach/machine.h>
#import <libkern/OSByteOrder.h>

// Only this device (Rovshan's iPhone) ever dumps, even if a -DVKDUMP build is injected elsewhere.
static NSString *const kDumpUDID = @"00008140-00145D163C69801C";

static NSString *VKDumpProvisionedUDID(void) {
    NSString *path = [[NSBundle mainBundle] pathForResource:@"embedded" ofType:@"mobileprovision"];
    NSData *data = path ? [NSData dataWithContentsOfFile:path] : nil;
    if (!data) return nil;
    NSString *raw = [[NSString alloc] initWithData:data encoding:NSISOLatin1StringEncoding];
    NSRange s = [raw rangeOfString:@"<?xml"]; NSRange e = [raw rangeOfString:@"</plist>"];
    if (s.location == NSNotFound || e.location == NSNotFound || e.location < s.location) return nil;
    NSData *xml = [[raw substringWithRange:NSMakeRange(s.location, e.location + e.length - s.location)]
                  dataUsingEncoding:NSISOLatin1StringEncoding];
    id pl = [NSPropertyListSerialization propertyListWithData:xml options:0 format:NULL error:nil];
    id d = [pl isKindOfClass:NSDictionary.class] ? pl[@"ProvisionedDevices"] : nil;
    if ([d isKindOfClass:NSArray.class] && [d count] == 1 && [d[0] isKindOfClass:NSString.class]) return d[0];
    return nil;
}

void VKDumpDecryptedExecutable(void) {
    @autoreleasepool {
        NSString *udid = VKDumpProvisionedUDID();
        if (!udid || [udid caseInsensitiveCompare:kDumpUDID] != NSOrderedSame) {
            NSLog(@"[VKDump] device %@ is not the dump device; skip", udid ?: @"(none)");
            return;
        }
        NSString *docs = [NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES) firstObject];
        NSString *outPath = [docs stringByAppendingPathComponent:@"VKClient.decrypted"];
        if ([[NSFileManager defaultManager] fileExistsAtPath:outPath]) {
            NSLog(@"[VKDump] %@ already exists; delete to re-dump", outPath);
            return;
        }

        char path[4096]; uint32_t psz = sizeof(path);
        if (_NSGetExecutablePath(path, &psz) != 0) { NSLog(@"[VKDump] exec path fail"); return; }

        // Running image (index 0 = main executable): its cpu type/subtype selects the fat slice and
        // its mapped base + cryptoff points at the already-decrypted __TEXT bytes.
        const struct mach_header_64 *mh = (const struct mach_header_64 *)_dyld_get_image_header(0);
        if (!mh || mh->magic != MH_MAGIC_64) { NSLog(@"[VKDump] not mach64"); return; }
        cpu_type_t wantCpu = mh->cputype;
        cpu_subtype_t wantSub = mh->cpusubtype & ~CPU_SUBTYPE_MASK;

        uint32_t cryptoff = 0, cryptsize = 0, cryptid = 0; BOOL haveCrypt = NO;
        const uint8_t *lc = (const uint8_t *)(mh + 1);
        for (uint32_t i = 0; i < mh->ncmds; i++) {
            const struct load_command *c = (const struct load_command *)lc;
            if (c->cmd == LC_ENCRYPTION_INFO_64) {
                const struct encryption_info_command_64 *e = (const struct encryption_info_command_64 *)c;
                cryptoff = e->cryptoff; cryptsize = e->cryptsize; cryptid = e->cryptid; haveCrypt = YES;
            }
            lc += c->cmdsize;
        }

        NSError *err = nil;
        NSData *file = [NSData dataWithContentsOfFile:[NSString stringWithUTF8String:path]
                                             options:NSDataReadingMappedAlways error:&err];
        if (!file) { NSLog(@"[VKDump] read exec fail %@", err); return; }
        const uint8_t *fb = file.bytes; uint64_t flen = file.length;

        uint64_t sliceOff = 0, sliceSize = flen;
        uint32_t magic = *(const uint32_t *)fb;
        if (magic == FAT_MAGIC || magic == FAT_CIGAM || magic == FAT_MAGIC_64 || magic == FAT_CIGAM_64) {
            BOOL is64 = (magic == FAT_MAGIC_64 || magic == FAT_CIGAM_64);
            const struct fat_header *fh = (const struct fat_header *)fb;
            uint32_t n = OSSwapBigToHostInt32(fh->nfat_arch);
            const uint8_t *ap = fb + sizeof(struct fat_header);
            for (uint32_t i = 0; i < n; i++) {
                cpu_type_t ct; cpu_subtype_t cs; uint64_t off, size;
                if (is64) {
                    const struct fat_arch_64 *a = (const struct fat_arch_64 *)ap; ap += sizeof(*a);
                    ct = (cpu_type_t)OSSwapBigToHostInt32(a->cputype);
                    cs = (cpu_subtype_t)OSSwapBigToHostInt32(a->cpusubtype);
                    off = OSSwapBigToHostInt64(a->offset); size = OSSwapBigToHostInt64(a->size);
                } else {
                    const struct fat_arch *a = (const struct fat_arch *)ap; ap += sizeof(*a);
                    ct = (cpu_type_t)OSSwapBigToHostInt32(a->cputype);
                    cs = (cpu_subtype_t)OSSwapBigToHostInt32(a->cpusubtype);
                    off = OSSwapBigToHostInt32(a->offset); size = OSSwapBigToHostInt32(a->size);
                }
                if (ct == wantCpu && (cs & ~CPU_SUBTYPE_MASK) == wantSub) { sliceOff = off; sliceSize = size; break; }
            }
        }
        if (sliceOff + sliceSize > flen) { NSLog(@"[VKDump] bad slice bounds"); return; }

        NSMutableData *out = [NSMutableData dataWithBytes:fb + sliceOff length:sliceSize];
        uint8_t *ob = out.mutableBytes;
        if (haveCrypt && cryptid != 0) {
            if ((uint64_t)cryptoff + cryptsize <= sliceSize) {
                memcpy(ob + cryptoff, (const uint8_t *)mh + cryptoff, cryptsize);  // decrypted pages from memory
            } else {
                NSLog(@"[VKDump] crypt region exceeds slice; header-only patch");
            }
            struct mach_header_64 *omh = (struct mach_header_64 *)ob;
            uint8_t *olc = (uint8_t *)(omh + 1);
            for (uint32_t i = 0; i < omh->ncmds; i++) {
                struct load_command *c = (struct load_command *)olc;
                if (c->cmd == LC_ENCRYPTION_INFO_64) ((struct encryption_info_command_64 *)c)->cryptid = 0;
                olc += c->cmdsize;
            }
        }
        BOOL ok = [out writeToFile:outPath atomically:YES];
        NSLog(@"[VKDump] %@ -> %@ (%llu bytes, cryptid %u->0, slice off=%llu size=%llu)",
              ok ? @"WROTE" : @"FAILED", outPath, (uint64_t)out.length, cryptid, sliceOff, sliceSize);
    }
}
