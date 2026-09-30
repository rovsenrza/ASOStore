import { gsap } from 'gsap';
import { Flip } from 'gsap/Flip';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger, Flip);

/**
 * The homepage choreography, one timeline per section, all scrubbed by scroll:
 * - desktop: the hero pins while the 3D stage docks the icons into the phone; the category
 *   rail pins and travels sideways; the phone in "how it works" turns with the steps;
 * - phones: no pinning, light reveals only;
 * - reduced motion: nothing moves.
 * Content is visible by default: only elements still below the fold get a start state.
 */
export function startMotion({ stage }) {
  const media = gsap.matchMedia();
  const belowFold = (elements) => gsap.utils.toArray(elements).filter((el) => el.getBoundingClientRect().top > window.innerHeight * 0.9);

  media.add({
    desktop: '(min-width: 901px) and (prefers-reduced-motion: no-preference)',
    phone: '(max-width: 900px) and (prefers-reduced-motion: no-preference)',
  }, ({ conditions }) => {
    const { desktop } = conditions;
    const cleanups = [];

    // Hero: pin on desktop and let scroll assemble the phone; on phones, drift the stage.
    const hero = document.querySelector('[data-hero]');
    if (desktop) {
      ScrollTrigger.create({
        trigger: hero,
        start: 'top top',
        end: '+=90%',
        pin: true,
        scrub: true,
        onUpdate: (self) => stage?.setProgress(self.progress),
      });
      gsap.to('.hero__copy', {
        yPercent: -12,
        opacity: 0.35,
        ease: 'none',
        scrollTrigger: { trigger: hero, start: 'top top', end: '+=90%', scrub: true },
      });
    } else {
      ScrollTrigger.create({
        trigger: hero,
        start: 'top top',
        end: 'bottom top',
        onUpdate: (self) => stage?.setProgress(self.progress),
      });
      gsap.to('[data-stage]', { yPercent: -10, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: true } });
    }

    // Category rail: sideways on desktop, native swipe on phones.
    const rail = document.querySelector('[data-rail]');
    const viewport = document.querySelector('[data-rail-viewport]');
    const track = document.querySelector('[data-rail-track]');
    if (desktop && rail && track) {
      viewport.classList.add('is-pinned');
      const distance = () => Math.max(0, track.scrollWidth - viewport.clientWidth);
      const travel = gsap.to(track, {
        x: () => -distance(),
        ease: 'none',
        scrollTrigger: {
          trigger: rail,
          start: 'top top',
          end: () => `+=${distance()}`,
          pin: true,
          scrub: 0.6,
          invalidateOnRefresh: true,
        },
      });
      gsap.utils.toArray('.rail__panel').forEach((panel) => {
        gsap.from(panel.querySelectorAll('.rail__icons li'), {
          y: 40,
          opacity: 0,
          scale: 0.8,
          stagger: 0.04,
          ease: 'expo.out',
          duration: 0.9,
          scrollTrigger: { trigger: panel, containerAnimation: travel, start: 'left 85%' },
        });
      });
      cleanups.push(() => viewport.classList.remove('is-pinned'));
    }

    // "How it works": the phone turns a little with each step.
    if (desktop) {
      gsap.fromTo('[data-phone]', { rotationY: -14, rotationX: 6 }, {
        rotationY: 12,
        rotationX: -4,
        ease: 'none',
        scrollTrigger: { trigger: '[data-how]', start: 'top bottom', end: 'bottom top', scrub: true },
      });
    }

    // Headings rise out of a mask as their sections arrive.
    belowFold('main h2').forEach((heading) => {
      gsap.fromTo(heading, { clipPath: 'inset(0 0 100% 0)', y: 28 }, {
        clipPath: 'inset(0 0 0% 0)',
        y: 0,
        duration: 1.1,
        ease: 'expo.out',
        scrollTrigger: { trigger: heading, start: 'top 88%' },
      });
    });

    // The catalog wall lands in rows.
    const wallItems = belowFold('.wall__item');
    gsap.set(wallItems, { opacity: 0, y: 30, scale: 0.9 });
    ScrollTrigger.batch(wallItems, {
      start: 'top 92%',
      onEnter: (batch) => gsap.to(batch, { opacity: 1, y: 0, scale: 1, stagger: 0.03, duration: 0.8, ease: 'expo.out' }),
    });

    // gsap reverts its own tweens when the query stops matching; these are ours.
    return () => cleanups.forEach((cleanup) => cleanup());
  });

  // Fonts and lazy images change heights; measure again once they settle.
  window.addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
  return { gsap, Flip };
}
