/// Which screenshot the full-screen viewer opens on.
struct ScreenshotSelection: Identifiable, Hashable {
    let index: Int
    var id: Int { index }
}
