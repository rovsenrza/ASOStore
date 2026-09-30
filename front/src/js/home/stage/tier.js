/**
 * How much 3D this device gets. 'none' keeps the rendered still (reduced motion, data saver,
 * software renderers, weak phones); 'low' is the light phone scene; 'mid' and 'high' differ
 * in pixel ratio, antialiasing and how many icon tiles fly.
 */
export function pickTier() {
  const media = (query) => window.matchMedia(query).matches;
  if (media('(prefers-reduced-motion: reduce)') || navigator.connection?.saveData) return 'none';

  const probe = document.createElement('canvas');
  const gl = probe.getContext('webgl2', { failIfMajorPerformanceCaveat: true });
  if (!gl) return 'none';
  const info = gl.getExtension('WEBGL_debug_renderer_info');
  const renderer = info ? String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL)) : '';
  gl.getExtension('WEBGL_lose_context')?.loseContext();
  if (/swiftshader|llvmpipe|software|basic render/i.test(renderer)) return 'none';

  const cores = navigator.hardwareConcurrency || 4;
  const memory = navigator.deviceMemory || 8;
  // Phones and tablets get a light scene; very weak ones keep the still.
  if (window.innerWidth < 901 || media('(pointer: coarse)')) return cores >= 4 ? 'low' : 'none';
  return cores >= 8 && memory >= 8 ? 'high' : 'mid';
}

export const TIERS = {
  high: { dpr: 2, antialias: true, tiles: 32 },
  mid: { dpr: 1.25, antialias: false, tiles: 24 },
  low: { dpr: 2, antialias: true, tiles: 12 },
};
