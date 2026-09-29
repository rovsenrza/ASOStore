import SwiftUI

/// Server progress made continuous: it eases to each report, then creeps towards the next
/// step so the bar keeps moving while Apple or the signer works. It never passes the value
/// of the next report, and never goes back.
struct SmoothedProgress<Content: View>: View {
    let progress: Double?
    @ViewBuilder let content: (_ shown: Double, _ stage: InstallStage) -> Content

    @State private var shown = 0.03
    /// Stale creep animations must not undo a newer report.
    @State private var generation = 0

    var body: some View {
        content(shown, InstallStage(progress: progress))
            .onChange(of: progress, initial: true) { _, reported in
                advance(to: reported)
            }
    }

    private func advance(to reported: Double?) {
        generation += 1
        let current = generation
        let stage = InstallStage(progress: reported)
        let target = max(shown, reported ?? 0.04)

        withAnimation(.smooth(duration: 0.7)) {
            shown = target
        } completion: {
            guard current == generation else { return }
            // Slower the closer it gets; stops short of where the next report lands.
            withAnimation(.easeOut(duration: 40)) {
                shown = max(target, stage.ceiling - 0.03)
            }
        }
    }
}

/// A determinate ring in the tint colour.
struct ProgressRing: View {
    let progress: Double
    var lineWidth: CGFloat = 2.5

    var body: some View {
        ZStack {
            Circle()
                .stroke(.tint.opacity(0.2), lineWidth: lineWidth)
            Circle()
                .trim(from: 0, to: progress)
                .stroke(.tint, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
                .rotationEffect(.degrees(-90))
        }
    }
}

/// «42 %», counting through every value while the progress animates.
struct AnimatedPercent: View, Animatable {
    var value: Double

    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    var body: some View {
        Text("\(Int((min(value, 1) * 100).rounded())) %")
            .monospacedDigit()
    }
}

/// The wide progress on the app page: what is happening, how far, and which of the four steps.
struct InstallProgressBar: View {
    let progress: Double?

    var body: some View {
        SmoothedProgress(progress: progress) { shown, stage in
            VStack(alignment: .leading, spacing: 9) {
                HStack(alignment: .firstTextBaseline) {
                    Text(stage.title)
                        .font(.subheadline.weight(.semibold))
                        .id(stage)
                        .transition(.push(from: .bottom).combined(with: .opacity))
                    Spacer()
                    AnimatedPercent(value: shown)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
                .clipped()

                track(shown)

                Text("Шаг \(Self.step(stage)) из 4")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .animation(Motion.state, value: stage)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("Подготовка")
            .accessibilityValue("\(stage.title), \(Int(shown * 100)) %")
        }
    }

    private func track(_ shown: Double) -> some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                Capsule().fill(.tint.opacity(0.16))
                Capsule()
                    .fill(.tint)
                    .frame(width: max(8, proxy.size.width * shown))
                    .overlay { Sheen().clipShape(Capsule()) }
            }
        }
        .frame(height: 7)
    }

    /// Profile, signing, checking, done: the steps a customer can make sense of.
    static func step(_ stage: InstallStage) -> Int {
        switch stage {
        case .queued, .profile: 1
        case .waitingForSigner, .signing: 2
        case .verifying, .finishing: 3
        case .ready: 4
        }
    }
}

/// A soft highlight sweeping along a filling bar: work is in progress even between reports.
private struct Sheen: View {
    @State private var sweep = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        GeometryReader { proxy in
            LinearGradient(colors: [.clear, .white.opacity(0.45), .clear], startPoint: .leading, endPoint: .trailing)
                .frame(width: max(40, proxy.size.width * 0.4))
                .offset(x: sweep ? proxy.size.width : -proxy.size.width * 0.4)
        }
        .opacity(reduceMotion ? 0 : 1)
        .onAppear {
            withAnimation(.linear(duration: 1.4).repeatForever(autoreverses: false)) { sweep = true }
        }
        .allowsHitTesting(false)
    }
}
