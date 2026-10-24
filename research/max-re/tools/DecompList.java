// Decompile a list of addresses (one hex per line, e.g. 0x100edb68c) to C.
// Args: <input-address-file> <output-c-file>. Reuses the already-analyzed maxproj DB.
import ghidra.app.script.GhidraScript;
import ghidra.app.decompiler.DecompInterface;
import ghidra.app.decompiler.DecompileResults;
import ghidra.program.model.address.Address;
import ghidra.program.model.listing.Function;
import java.nio.file.*;
import java.util.*;

public class DecompList extends GhidraScript {
    public void run() throws Exception {
        String[] args = getScriptArgs();
        String inPath = args[0], outPath = args[1];
        List<String> lines = Files.readAllLines(Paths.get(inPath));
        DecompInterface di = new DecompInterface();
        di.openProgram(currentProgram);
        StringBuilder sb = new StringBuilder();
        for (String raw : lines) {
            String s = raw.trim();
            if (s.isEmpty() || s.startsWith("#")) continue;
            long a = Long.parseLong(s.replaceFirst("^0x", ""), 16);
            Address addr = currentProgram.getAddressFactory().getDefaultAddressSpace().getAddress(a);
            sb.append("\n// ================= 0x").append(Long.toHexString(a)).append(" =================\n");
            Function fn = getFunctionContaining(addr);
            if (fn == null) {
                // No function defined here yet — create one so the decompiler can run.
                fn = createFunction(addr, null);
            }
            if (fn == null) { sb.append("// NO FUNCTION at 0x").append(Long.toHexString(a)).append("\n"); continue; }
            sb.append("// ").append(fn.getName(true)).append(" @ ").append(fn.getEntryPoint()).append("\n");
            try {
                DecompileResults r = di.decompileFunction(fn, 120, monitor);
                if (r != null && r.decompileCompleted()) sb.append(r.getDecompiledFunction().getC());
                else sb.append("// decompile failed: ").append(r != null ? r.getErrorMessage() : "null").append("\n");
            } catch (Exception e) { sb.append("// exception: ").append(e.getMessage()).append("\n"); }
        }
        Files.write(Paths.get(outPath), sb.toString().getBytes());
        println("WROTE " + outPath);
    }
}
