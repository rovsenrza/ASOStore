# Ru AppStore website design

## Direction

A familiar consumer storefront for iPhone owners, executed with the clarity and polish of a premium Russian consumer product. The user chose the category-standard landing structure over themed visual metaphors. The site should show the offer, real catalog apps, package terms, the registration path, and recovery without making the signing process look instant.

The native iOS app is the store. The website is the entry and recovery path. Keep every page in Russian.

## Visual system

- Brand colors: royal blue `#1E5BE6`, deep navy `#0A1F66`, pale blue `#F4F7FF`, white, with red `#D7263D` reserved for small savings accents. The actual CSS tokens live in `front/src/css/tokens.css`.
- Type: Unbounded for large display text and Onest for interface and body text. Both fonts are self-hosted by the Vite build, including Cyrillic subsets.
- Layout: a wide, full-color hero; native scroll for the category rail on touch screens; an early package selector; clear steps; a real catalog wall; honest readiness explanation; short FAQ; direct final action.
- Shapes: rounded controls and icon tiles echo the logo. Avoid floating glass panels, decorative flag imagery, and repeated generic feature cards.
- Spacing: use the named tokens in `tokens.css`. Section spacing is intentionally generous on desktop and reduced at phone widths. Controls remain at least 44 CSS pixels tall.

## 3D and motion

The hero depicts a white logo glyph surrounded by actual catalog icon tiles. A Three.js scene responds to pointer movement and desktop scroll; the tiles dock into a phone as the user scrolls through the hero. GSAP drives the hero and category rail only where the interaction clarifies the story.

The static hero poster is a transparent capture of that same Three.js scene. It loads immediately and remains visible when WebGL is unavailable. The scene is deferred until after load on desktop and until first interaction on eligible phones. Quality tiers control pixel ratio and tile count; slow rendering drops resolution and can return to the poster. `prefers-reduced-motion` and data saver keep the static experience. All text and controls work without motion.

## Content and asset provenance

- `front/public/assets/icons/` contains a dated local snapshot of icon images from the production catalog, prepared by `front/scripts/snapshot-icons.mjs`. The WebGL atlas uses those same icons.
- `front/public/assets/stage/hero-stage-*.webp` are captures of the site's own Three.js scene, resized for responsive loading. They contain no independent stock art.
- Brand files originate from `branding/`. The website's Open Graph image is in `front/public/assets/og/`.
- Package prices are displayed as product copy. The repository does not implement checkout; selecting a package takes the user to device activation.

## Release checks

Check the homepage and inner pages at 375, 768, 1024, 1280, 1440, and 1920 CSS pixels; keyboard navigation; reduced motion; missing API responses; scroll and menu behavior; local links and image responses; and production build output. The hero poster must be visible before WebGL starts and after it fails.
