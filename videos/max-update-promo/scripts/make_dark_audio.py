"""Dark, hard music bed for the MAX update promo (22 s, deterministic, no network)."""
import os, subprocess, wave
import numpy as np

SR = 44100
OUT = os.path.join(os.path.dirname(__file__), "..", "assets", "audio")
rng = np.random.default_rng(11)
DUR = 22.0
B = 0.6  # 100 BPM


def ta(s):
    return np.arange(int(s * SR)) / SR


def lp(x, c, p=2):
    f = np.fft.rfftfreq(len(x), 1 / SR)
    return np.fft.irfft(np.fft.rfft(x) / (1 + (f / c) ** (2 * p)), len(x))


def hp(x, c):
    f = np.fft.rfftfreq(len(x), 1 / SR)
    return np.fft.irfft(np.fft.rfft(x) * (1 - 1 / (1 + (f / c) ** 4)), len(x))


def place(buf, sig, at, g=1.0):
    i = int(at * SR); j = min(len(buf), i + len(sig))
    if 0 <= i < len(buf):
        buf[i:j] += sig[: j - i] * g


def kick():
    t = ta(0.5); f = 40 + 140 * np.exp(-t * 30)
    return np.tanh(2.2 * np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 6)) + 0.3 * np.exp(-t * 400) * rng.standard_normal(len(t))


def hit():
    t = ta(2.4); f = 32 + 90 * np.exp(-t * 9)
    body = np.tanh(3 * np.sin(2 * np.pi * np.cumsum(f) / SR)) * np.exp(-t * 1.8)
    noise = lp(rng.standard_normal(len(t)), 2500) * np.exp(-t * 5) * 0.6
    return body + noise


def tick():
    t = ta(0.05)
    return hp(rng.standard_normal(len(t)), 8000) * np.exp(-t * 90) * 0.3


def sub(freq, d):
    t = ta(d)
    x = np.tanh(1.8 * np.sin(2 * np.pi * freq * t))
    return x * np.minimum(1, t / 0.005) * np.exp(-t * 5) * 0.6


def drone(freqs, d):
    t = ta(d); x = np.zeros(len(t))
    for fq in freqs:
        for dt in (-0.1, 0, 0.1):
            ff = fq * 2 ** (dt / 12)
            x += sum(np.sin(2 * np.pi * ff * k * t + k) / k for k in range(1, 7))
    return lp(x, 500) * np.minimum(1, t / 1.5) * np.minimum(1, (d - t) / 0.8) * 0.05


def riser(d):
    t = ta(d)
    n = hp(rng.standard_normal(len(t)), 1500) * (t / d) ** 2.5
    return n * 0.35


D1, A1, F1, C2 = 36.7, 55.0, 43.65, 65.4
L = np.zeros(int(DUR * SR))
place(L, drone([73.4, 110.0, 146.8], 17.4), 0)
place(L, drone([73.4, 87.3, 110.0], 3.0), 19.0)
place(L, riser(1.0), 16.4)
for at in (0.25, 2.2, 5.8, 9.6, 13.4, 17.4, 19.0):
    place(L, hit(), at, 0.9 if at in (0.25, 19.0) else 0.65)
bars = [D1, D1, F1, C2 / 2 * 1.0, D1, A1 / 2 * 1.0]
t = 2.2
i = 0
while t < 17.3:
    place(L, kick(), t, 0.8)
    root = [D1, D1, F1, D1][(i // 8) % 4]
    for k in range(2):
        place(L, sub(root, B / 2), t + k * B / 2, 0.55 if k == 0 else 0.35)
    for k in range(4):
        place(L, tick(), t + k * B / 4, 0.8 if k == 2 else 0.4)
    t += B; i += 1

L = np.tanh(L * 1.2)
R = np.roll(L, 220)
x = np.stack([L, R], 1)
x = x / (np.abs(x).max() + 1e-9) * 0.9
path = os.path.join(OUT, "dark.wav")
with wave.open(path, "wb") as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR)
    w.writeframes((x * 32767).astype(np.int16).tobytes())
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", path, "-b:a", "192k", os.path.join(OUT, "dark.mp3")], check=True)
os.remove(path)
print("ok")
