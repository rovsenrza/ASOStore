// fishhook — rebind symbols in Mach-O binaries at runtime.
// Copyright (c) 2013, Facebook, Inc. BSD-licensed; see the upstream LICENSE.
// https://github.com/facebook/fishhook
#ifndef fishhook_h
#define fishhook_h

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

#define FISHHOOK_EXPORT __attribute__((visibility("hidden")))

struct rebinding {
  const char *name;
  void *replacement;
  void **replaced;
};

FISHHOOK_EXPORT int rebind_symbols(struct rebinding rebindings[], size_t rebindings_nel);
FISHHOOK_EXPORT int rebind_symbols_image(void *header, intptr_t slide, struct rebinding rebindings[], size_t rebindings_nel);

#ifdef __cplusplus
}
#endif

#endif // fishhook_h
