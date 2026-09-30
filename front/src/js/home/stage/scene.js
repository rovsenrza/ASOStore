import {
  ACESFilmicToneMapping, CapsuleGeometry, Color, DoubleSide, Group, InstancedBufferAttribute, InstancedMesh,
  MathUtils, Matrix4, Mesh, MeshPhysicalMaterial, Object3D, PerspectiveCamera, PlaneGeometry, PMREMGenerator,
  Quaternion, Raycaster, Scene, ShaderMaterial, SRGBColorSpace, TextureLoader, Vector2, Vector3, WebGLRenderer,
} from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { TIERS } from './tier.js';

/*
 * The hero scene. At rest the logo's "A" floats in an orbit of real catalog icons, built as
 * thick rounded tiles. Scrolling through the pinned hero (progress 0 → 1) sends the glyph
 * back, raises an iPhone, and docks the icons onto its home screen one by one. The cursor
 * tilts the whole stage and lifts the tile under it.
 */

const tileVertex = /* glsl */ `
  attribute vec2 aCell;
  varying vec2 vUv;
  varying vec2 vCell;
  varying vec3 vNormal;
  varying vec3 vView;
  varying float vFront;
  varying float vDepth;

  void main() {
    vUv = uv;
    vCell = aCell;
    vFront = step(0.6, normal.z);
    vec4 world = modelMatrix * instanceMatrix * vec4(position, 1.0);
    vNormal = normalize(mat3(modelMatrix) * mat3(instanceMatrix) * normal);
    vec4 view = viewMatrix * world;
    vView = -view.xyz;
    vDepth = -view.z;
    gl_Position = projectionMatrix * view;
  }
`;

const tileFragment = /* glsl */ `
  uniform sampler2D uAtlas;
  uniform vec2 uGrid;
  uniform vec3 uLight;
  uniform vec3 uFog;
  varying vec2 vUv;
  varying vec2 vCell;
  varying vec3 vNormal;
  varying vec3 vView;
  varying float vFront;
  varying float vDepth;

  vec2 cellUv(vec2 local) {
    // Atlas rows run top to bottom; the texture is flipped on upload.
    return vec2((vCell.x + local.x) / uGrid.x, 1.0 - (vCell.y + 1.0 - local.y) / uGrid.y);
  }

  void main() {
    vec3 face = texture2D(uAtlas, cellUv(clamp(vUv, 0.02, 0.98))).rgb;
    vec3 edge = texture2D(uAtlas, cellUv(vec2(0.08, 0.5))).rgb * 0.72;
    vec3 color = mix(edge, face, vFront);

    vec3 n = normalize(vNormal);
    vec3 v = normalize(vView);
    vec3 l = normalize(uLight);
    float diffuse = max(dot(n, l), 0.0);
    float rim = pow(1.0 - max(dot(n, v), 0.0), 3.0);
    float spec = pow(max(dot(n, normalize(l + v)), 0.0), 60.0);

    color = color * (0.62 + 0.48 * diffuse) + spec * 0.35 + rim * vec3(0.55, 0.78, 1.0) * 0.45;
    color = mix(color, uFog, smoothstep(15.0, 26.0, vDepth) * 0.55);
    gl_FragColor = vec4(color, 1.0);
    #include <tonemapping_fragment>
    #include <colorspace_fragment>
  }
`;

const ribbonVertex = /* glsl */ `
  uniform float uTime;
  uniform float uProgress;
  varying vec2 vUv;
  varying float vShade;

  void main() {
    vUv = uv;
    vec3 p = position;
    float wave = sin(p.x * 0.34 + uTime * 0.5) * 0.9 + sin(p.x * 0.11 - uTime * 0.2) * 1.4;
    float twist = sin(p.x * 0.22 + uTime * 0.35) * 0.9;
    p.y += wave * (1.0 - 0.4 * uProgress);
    p.z += p.y * sin(twist) * 0.6 + cos(p.x * 0.18 + uTime * 0.3) * 1.2;
    vShade = 0.6 + 0.4 * cos(twist);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
  }
`;

const ribbonFragment = /* glsl */ `
  varying vec2 vUv;
  varying float vShade;

  void main() {
    // White band with a sky edge and, along the lower edge, the thin red thread of the logo.
    vec3 white = vec3(1.0);
    vec3 sky = vec3(0.54, 0.78, 1.0);
    vec3 red = vec3(0.88, 0.15, 0.24);
    vec3 color = mix(sky, white, smoothstep(0.15, 0.55, vUv.y));
    color = mix(color, red, smoothstep(0.1, 0.06, vUv.y) * smoothstep(0.0, 0.03, vUv.y));
    float fade = smoothstep(0.0, 0.12, vUv.x) * smoothstep(1.0, 0.88, vUv.x);
    gl_FragColor = vec4(color * vShade, 0.78 * fade);
    #include <colorspace_fragment>
  }
`;

const screenFragment = /* glsl */ `
  uniform float uFill;
  varying vec2 vUv;

  float roundedBox(vec2 p, vec2 size, float r) {
    vec2 q = abs(p) - size + r;
    return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r;
  }

  void main() {
    vec2 p = vUv - 0.5;
    float shape = roundedBox(p * vec2(1.0, 2.1), vec2(0.5, 1.05), 0.11);
    if (shape > 0.0) discard;
    vec3 top = vec3(0.14, 0.61, 0.97);
    vec3 bottom = vec3(0.04, 0.27, 0.9);
    vec3 color = mix(bottom, top, smoothstep(0.0, 1.0, vUv.y + 0.15 * vUv.x));
    float island = roundedBox((vUv - vec2(0.5, 0.955)) * vec2(1.0, 2.1), vec2(0.16, 0.035), 0.035);
    color = mix(vec3(0.01, 0.04, 0.17), color, smoothstep(0.0, 0.004, island));
    color *= 0.85 + 0.15 * uFill;
    gl_FragColor = vec4(color, 1.0);
    #include <colorspace_fragment>
  }
`;

const screenVertex = /* glsl */ `
  varying vec2 vUv;
  void main() {
    vUv = uv;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

const ease = (t) => 1 - (1 - t) ** 3;
const clamp01 = (t) => Math.min(1, Math.max(0, t));

/** Capsule from a to b (in the glyph's plane), radius r. */
function stroke(a, b, r, material) {
  const from = new Vector3(a[0], a[1], 0);
  const to = new Vector3(b[0], b[1], 0);
  const direction = to.clone().sub(from);
  const mesh = new Mesh(new CapsuleGeometry(r, direction.length(), 12, 32), material);
  mesh.position.copy(from).add(to).multiplyScalar(0.5);
  mesh.quaternion.setFromUnitVectors(new Vector3(0, 1, 0), direction.normalize());
  return mesh;
}

export async function createStage({ canvas, tier, icons, atlasUrl, onSlow }) {
  const settings = TIERS[tier];
  const renderer = new WebGLRenderer({ canvas, alpha: true, antialias: settings.antialias, powerPreference: 'high-performance' });
  let dpr = Math.min(window.devicePixelRatio || 1, settings.dpr);
  renderer.setPixelRatio(dpr);
  renderer.outputColorSpace = SRGBColorSpace;
  renderer.toneMapping = ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;

  const scene = new Scene();
  const pmrem = new PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  pmrem.dispose();

  const camera = new PerspectiveCamera(30, 1, 0.1, 100);
  camera.position.set(0, 0, 15);

  // The stage sits right of the headline: keep its centre of mass there.
  const root = new Group();
  root.position.x = 0.7;
  scene.add(root);

  // The glyph: the logo's A as three glossy capsules.
  const glyphMaterial = new MeshPhysicalMaterial({ color: new Color('#ffffff'), roughness: 0.22, metalness: 0, clearcoat: 1, clearcoatRoughness: 0.12, sheen: 0.4, sheenColor: new Color('#9fd0ff') });
  const glyph = new Group();
  glyph.add(
    stroke([-1.2, -1.95], [0.4, 1.7], 0.42, glyphMaterial),
    stroke([-0.4, 1.7], [1.2, -1.95], 0.42, glyphMaterial),
    stroke([-2, -1], [2, -1], 0.37, glyphMaterial),
  );
  root.add(glyph);

  // The ribbon behind everything.
  const ribbonMaterial = new ShaderMaterial({
    vertexShader: ribbonVertex,
    fragmentShader: ribbonFragment,
    uniforms: { uTime: { value: 0 }, uProgress: { value: 0 } },
    transparent: true,
    depthWrite: false,
    side: DoubleSide,
  });
  const ribbon = new Mesh(new PlaneGeometry(34, 0.75, 320, 6), ribbonMaterial);
  ribbon.position.set(0, -0.6, -3.2);
  ribbon.rotation.set(0.25, 0, -0.22);
  root.add(ribbon);

  // The phone.
  const phone = new Group();
  const body = new Mesh(new RoundedBoxGeometry(2.3, 4.8, 0.3, 6, 0.36), new MeshPhysicalMaterial({ color: new Color('#0d1b52'), roughness: 0.35, metalness: 0.6, clearcoat: 0.6 }));
  const screenMaterial = new ShaderMaterial({ vertexShader: screenVertex, fragmentShader: screenFragment, uniforms: { uFill: { value: 0 } } });
  const screen = new Mesh(new PlaneGeometry(2.1, 4.6), screenMaterial);
  screen.position.z = 0.152;
  phone.add(body, screen);
  root.add(phone);

  // Icon tiles.
  const atlas = await new TextureLoader().loadAsync(atlasUrl);
  atlas.colorSpace = SRGBColorSpace;
  atlas.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
  const count = Math.min(settings.tiles, icons.apps.length);
  const tileGeometry = new RoundedBoxGeometry(1, 1, 0.22, 4, 0.2);
  const cells = new Float32Array(count * 2);
  icons.apps.slice(0, count).forEach((app, i) => {
    cells[i * 2] = app.index % icons.columns;
    cells[i * 2 + 1] = Math.floor(app.index / icons.columns);
  });
  tileGeometry.setAttribute('aCell', new InstancedBufferAttribute(cells, 2));
  const tileMaterial = new ShaderMaterial({
    vertexShader: tileVertex,
    fragmentShader: tileFragment,
    uniforms: {
      uAtlas: { value: atlas },
      uGrid: { value: new Vector2(icons.columns, icons.rows) },
      uLight: { value: new Vector3(0.4, 0.8, 1) },
      uFog: { value: new Color('#1f63e6') },
    },
  });
  const tiles = new InstancedMesh(tileGeometry, tileMaterial, count);
  tiles.frustumCulled = false;
  root.add(tiles);

  // Per-tile orbit parameters and home-screen slots (4 × 6 grid on the phone).
  const orbit = Array.from({ length: count }, (_, i) => ({
    angle: (i / count) * Math.PI * 2,
    radius: 3.1 + (i % 3) * 0.85,
    height: Math.sin(i * 1.7) * 1.8,
    tilt: new Vector3(Math.sin(i * 2.3) * 0.5, Math.cos(i * 1.3) * 0.6, Math.sin(i) * 0.3),
    size: 0.78 + (i % 4) * 0.08,
  }));
  const slot = (i) => new Vector3(((i % 4) - 1.5) * 0.49, 1.52 - Math.floor(i / 4) * 0.56, 0.3);
  const lift = new Float32Array(count);

  const dummy = new Object3D();
  const phoneMatrix = new Matrix4();
  const orbitQuat = new Quaternion();
  const slotQuat = new Quaternion();
  const from = new Vector3();
  const to = new Vector3();
  const towardCamera = new Vector3();

  let progress = 0;
  let shownProgress = 0;
  const pointer = new Vector2(0, 0);
  const tilt = new Vector2(0, 0);
  const raycaster = new Raycaster();
  let hovered = -1;

  function resize() {
    const { clientWidth: width, clientHeight: height } = canvas;
    if (!width || !height) return;
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
  }

  function layout(time) {
    const p = shownProgress;
    const e = ease(p);

    glyph.position.set(MathUtils.lerp(0.1, 0.2, e), MathUtils.lerp(0.1, 2.3, e) + Math.sin(time * 0.6) * 0.08, MathUtils.lerp(0, -4, e));
    glyph.rotation.set(Math.sin(time * 0.4) * 0.06, MathUtils.lerp(-0.25, 0.3, e) + Math.sin(time * 0.3) * 0.1, 0);
    glyph.scale.setScalar(MathUtils.lerp(1, 0.6, e));

    phone.position.set(0.4, MathUtils.lerp(-8.5, -0.2, e), MathUtils.lerp(0.4, 1.2, e));
    phone.rotation.set(MathUtils.lerp(0.3, 0.06, e), MathUtils.lerp(-0.7, -0.22, e), MathUtils.lerp(0.12, 0.02, e));
    phone.updateMatrix();
    phoneMatrix.copy(phone.matrix);
    slotQuat.setFromRotationMatrix(phoneMatrix);
    screenMaterial.uniforms.uFill.value = e;

    ribbonMaterial.uniforms.uTime.value = time;
    ribbonMaterial.uniforms.uProgress.value = e;

    towardCamera.copy(camera.position).normalize();
    for (let i = 0; i < count; i += 1) {
      const o = orbit[i];
      const angle = o.angle + time * 0.09;
      from.set(Math.sin(angle) * o.radius, o.height + Math.sin(time * 0.5 + i) * 0.15, Math.cos(angle) * o.radius * 0.55 - 0.6);
      orbitQuat.setFromEuler(dummy.rotation.set(o.tilt.x + Math.sin(time * 0.4 + i) * 0.15, o.tilt.y + angle * 0.15, o.tilt.z));

      const docks = i < 24;
      const t = ease(clamp01((p - i * 0.012) / 0.62));
      if (docks) {
        to.copy(slot(i)).applyMatrix4(phoneMatrix);
      } else {
        to.copy(from).multiplyScalar(1.8).setZ(from.z - 4);
      }
      dummy.position.lerpVectors(from, to, t);
      dummy.quaternion.slerpQuaternions(orbitQuat, docks ? slotQuat : orbitQuat, t);

      const target = i === hovered && t < 0.5 ? 1 : 0;
      lift[i] += (target - lift[i]) * 0.12;
      dummy.position.addScaledVector(towardCamera, lift[i] * 0.9);
      const size = docks ? MathUtils.lerp(o.size, 0.4, t) : o.size * (1 - t);
      dummy.scale.setScalar(size * (1 + lift[i] * 0.18));
      dummy.updateMatrix();
      tiles.setMatrixAt(i, dummy.matrix);
    }
    tiles.instanceMatrix.needsUpdate = true;

    tilt.lerp(pointer, 0.06);
    root.rotation.set(-tilt.y * 0.12, tilt.x * 0.22 + MathUtils.lerp(0, -0.08, e), 0);
  }

  // Frame budget: drop resolution when frames run long, hand back to the DOM if that is not enough.
  let frames = 0;
  let spent = 0;
  let last = performance.now();
  let running = false;
  let visible = true;
  let raf = 0;

  function frame(now) {
    raf = requestAnimationFrame(frame);
    const dt = now - last;
    last = now;
    frames += 1;
    spent += dt;
    if (frames === 90) {
      const average = spent / frames;
      frames = 0;
      spent = 0;
      if (average > 24) {
        if (dpr > 1) {
          dpr = Math.max(1, dpr - 0.25);
          renderer.setPixelRatio(dpr);
          resize();
        } else {
          stop();
          onSlow?.();
          return;
        }
      }
    }
    shownProgress += (progress - shownProgress) * 0.14;
    layout(now / 1000);
    renderer.render(scene, camera);
  }

  function start() {
    if (running || !visible || document.hidden) return;
    running = true;
    last = performance.now();
    raf = requestAnimationFrame(frame);
  }

  function stop() {
    running = false;
    cancelAnimationFrame(raf);
  }

  const onPointer = (event) => {
    const rect = canvas.getBoundingClientRect();
    pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -(((event.clientY - rect.top) / rect.height) * 2 - 1));
    raycaster.setFromCamera(pointer, camera);
    const hit = raycaster.intersectObject(tiles, false)[0];
    hovered = hit?.instanceId ?? -1;
  };
  const onVisibility = () => (document.hidden ? stop() : start());
  const visibility = new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (visible) start(); else stop();
  });
  const resizer = new ResizeObserver(resize);

  window.addEventListener('pointermove', onPointer, { passive: true });
  document.addEventListener('visibilitychange', onVisibility);
  visibility.observe(canvas);
  resizer.observe(canvas);
  resize();
  layout(0);
  renderer.render(scene, camera);
  start();

  return {
    setProgress(value) { progress = clamp01(value); },
    dispose() {
      stop();
      window.removeEventListener('pointermove', onPointer);
      document.removeEventListener('visibilitychange', onVisibility);
      visibility.disconnect();
      resizer.disconnect();
      renderer.dispose();
    },
  };
}
