"""Synthesize the music bed and SFX for the MAX notifications promo (deterministic, no network).

Grid: 120 BPM, one beat = 0.5 s. Event times mirror the GSAP timeline in index.html.
"""
import os
import subprocess
import wave

import numpy as np

SR = 44100
OUT = os.path.join(os.path.dirname(__file__), "..", "assets", "audio")
rng = np.random.default_rng(7)


def t_axis(sec):
    return np.arange(int(sec * SR)) / SR


def lowpass(x, cutoff):
    a = np.exp(-2 * np.pi * cutoff / SR)
    y = np.empty_like(x)
    acc = 0.0
    for i, v in enumerate(x):
        acc = (1 - a) * v + a * acc
        y[i] = acc
    return y


def lowpass_fast(x, cutoff, passes=2):
    # FFT brick-ish lowpass with a soft knee: fast and good enough for pads/bass
    n = len(x)
    f = np.fft.rfftfreq(n, 1 / SR)
    X = np.fft.rfft(x)
    X *= 1 / (1 + (f / cutoff) ** (2 * passes))
    return np.fft.irfft(X, n)


def highpass_fast(x, cutoff):
    n = len(x)
    f = np.fft.rfftfreq(n, 1 / SR)
    X = np.fft.rfft(x)
    X *= 1 - 1 / (1 + (f / cutoff) ** 4)
    return np.fft.irfft(X, n)


def note(n):
    return 440.0 * 2 ** ((n - 69) / 12)


def place(buf, sig, at, gain=1.0):
    i = int(at * SR)
    j = min(len(buf), i + len(sig))
    if i < len(buf):
        buf[i:j] += sig[: j - i] * gain


def write(name, x, peak=0.89):
    x = x / (np.max(np.abs(x)) + 1e-9) * peak
    path = os.path.join(OUT, name + ".wav")
    with wave.open(path, "wb") as w:
        w.setnchannels(2 if x.ndim == 2 else 1)
        w.setsampwidth(2)
        w.setframerate(SR)
        data = (x * 32767).astype(np.int16)
        w.writeframes(data.tobytes())
    mp3 = os.path.join(OUT, name + ".mp3")
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", path, "-b:a", "192k", mp3], check=True)
    os.remove(path)


# ── instruments ────────────────────────────────────────────────────────────
def kick():
    t = t_axis(0.45)
    f = 45 + 110 * np.exp(-t * 28)
    ph = 2 * np.pi * np.cumsum(f) / SR
    return np.sin(ph) * np.exp(-t * 7.5) + 0.25 * np.exp(-t * 300) * rng.standard_normal(len(t))


def clap():
    t = t_axis(0.3)
    n = highpass_fast(rng.standard_normal(len(t)), 900)
    env = np.exp(-t * 18) * (1 + 0.6 * np.exp(-((t - 0.012) ** 2) / 1e-5))
    return n * env * 0.5


def hat(open_=False):
    t = t_axis(0.25 if open_ else 0.06)
    n = highpass_fast(rng.standard_normal(len(t)), 7000)
    return n * np.exp(-t * (14 if open_ else 70)) * 0.35


def pluck(freq, dur=0.32):
    t = t_axis(dur)
    saw = sum(np.sin(2 * np.pi * freq * k * t) / k * np.exp(-t * (6 + 5 * k)) for k in range(1, 9))
    return saw * np.exp(-t * 7) * 0.5


def pad(freqs, dur):
    t = t_axis(dur)
    x = np.zeros(len(t))
    for f in freqs:
        for d in (-0.08, 0.0, 0.08):
            ff = f * 2 ** (d / 12)
            x += sum(np.sin(2 * np.pi * ff * k * t + k) / k for k in range(1, 6))
    x = lowpass_fast(x, 1400)
    env = np.minimum(1, t / 0.25) * np.minimum(1, (dur - t) / 0.3)
    return x * env * 0.06


def bass(freq, dur):
    t = t_axis(dur)
    x = np.sin(2 * np.pi * freq * t) + 0.3 * np.sin(2 * np.pi * freq * 2 * t)
    return x * np.minimum(1, t / 0.01) * np.exp(-t * 3) * 0.55


# ── music bed (15 s) ───────────────────────────────────────────────────────
DUR = 15.0
B = 0.5  # beat
L = np.zeros(int(DUR * SR))
R = np.zeros(int(DUR * SR))

# Fmaj7 – G6 – Am7 – Em7, one chord per bar (2 s)
prog = [
    (53, [65, 69, 72, 76]),
    (55, [67, 71, 74, 76]),
    (57, [69, 72, 76, 79]),
    (52, [64, 67, 71, 74]),
]
CALL = (4.75, 8.5)  # drums drop out, bed ducks under the ringtone
END_HIT = 12.25

for bar in range(8):
    t0 = bar * 2.0
    if t0 >= DUR:
        break
    root, chord = prog[bar % 4]
    in_call = CALL[0] <= t0 + 1 < CALL[1]
    p = pad([note(n) for n in chord], 2.05)
    place(L, p, t0, 0.8 if in_call else 1.0)
    place(R, p, t0, 0.8 if in_call else 1.0)
    for s in range(16):  # 8th-note arps
        ts = t0 + s * 0.125
        if ts >= DUR or (in_call and s % 2):
            continue
        n = chord[[0, 1, 2, 3, 2, 1, 3, 2][s % 8]] + (12 if s % 8 == 3 else 0)
        pl = pluck(note(n))
        pan = 0.5 + 0.35 * np.sin(s * 0.9)
        g = 0.45 if in_call else 0.8
        place(L, pl, ts, g * (1 - pan) * 2 * 0.5)
        place(R, pl, ts, g * pan * 2 * 0.5)

for i in range(int(DUR / B)):
    tb = i * B
    in_call = CALL[0] <= tb < CALL[1]
    if tb >= 14.25:
        break
    if not in_call:
        k = kick()
        place(L, k, tb, 0.9)
        place(R, k, tb, 0.9)
        if i % 2 == 1 and tb > 1.0:
            c = clap()
            place(L, c, tb, 0.7)
            place(R, c, tb, 0.7)
    for h in (0.25,):
        hh = hat(open_=(i % 4 == 3))
        place(L, hh, tb + h, 0.6 if in_call else 1.0)
        place(R, hh, tb + h, 0.6 if in_call else 1.0)
    # bass on 8ths, following the bar root
    root = prog[int(tb // 2) % 4][0]
    for h in (0.0, 0.25):
        if not in_call:
            bs = bass(note(root - 12), 0.24)
            place(L, bs, tb + h, 0.9)
            place(R, bs, tb + h, 0.9)

# sidechain pump on every beat outside the call
pump = np.ones(len(L))
tt = np.arange(len(L)) / SR
for i in range(int(DUR / B)):
    tb = i * B
    if CALL[0] <= tb < CALL[1] or tb >= 14.25:
        continue
    seg = (tt >= tb) & (tt < tb + B)
    pump[seg] = 0.55 + 0.45 * np.minimum(1, (tt[seg] - tb) / 0.22)
L *= pump
R *= pump

# final resolve: Cmaj9 hit that rings out
hit = pad([note(n) for n in (60, 64, 67, 71, 74)], 2.75) * 2.2
place(L, hit, END_HIT)
place(R, hit, END_HIT)

fade = np.ones(len(L))
fade[tt > 14.2] = np.maximum(0, 1 - (tt[tt > 14.2] - 14.2) / 0.8)
fade *= np.minimum(1, tt / 0.15)
music = np.stack([L * fade, R * fade], axis=1)
write("music", music, peak=0.8)


# ── ringtone (original marimba motif, ~3.75 s) ─────────────────────────────
def marimba(freq, dur=0.6):
    t = t_axis(dur)
    x = np.sin(2 * np.pi * freq * t) * np.exp(-t * 6)
    x += 0.35 * np.sin(2 * np.pi * freq * 3.93 * t) * np.exp(-t * 22)
    x += 0.12 * np.sin(2 * np.pi * freq * 9.2 * t) * np.exp(-t * 45)
    return x * np.minimum(1, t / 0.003)


ring = np.zeros(int(3.75 * SR))
motif = [76, 79, 83, 88, 83, 79, 81, 84]
for rep in range(2):
    for i, n in enumerate(motif):
        place(ring, marimba(note(n)), rep * 1.75 + i * 0.1875)
write("ringtone", ring)

# ── vibration buzz ─────────────────────────────────────────────────────────
t = t_axis(0.42)
buzz = np.sign(np.sin(2 * np.pi * 165 * t)) * 0.6 + np.sin(2 * np.pi * 330 * t) * 0.3
buzz = lowpass_fast(buzz, 900)
buzz *= (0.75 + 0.25 * np.sin(2 * np.pi * 28 * t)) * np.minimum(1, t / 0.015) * np.minimum(1, (0.42 - t) / 0.04)
write("buzz", buzz, peak=0.7)

# ── message chime (two glassy notes) ───────────────────────────────────────
ch = np.zeros(int(0.9 * SR))
for at, n in ((0.0, 83), (0.09, 88)):
    tt2 = t_axis(0.8)
    tone = (np.sin(2 * np.pi * note(n) * tt2) + 0.2 * np.sin(2 * np.pi * note(n) * 2.01 * tt2)) * np.exp(-tt2 * 7)
    place(ch, tone * np.minimum(1, tt2 / 0.002), at)
write("chime", ch, peak=0.75)

# ── badge tick ─────────────────────────────────────────────────────────────
t = t_axis(0.12)
tick = np.sin(2 * np.pi * (1800 - 6000 * t) * t) * np.exp(-t * 45)
write("tick", tick, peak=0.6)
print("ok")
