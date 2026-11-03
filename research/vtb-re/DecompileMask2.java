import ghidra.app.script.GhidraScript;
import ghidra.app.decompiler.*;
import ghidra.program.model.address.Address;
import ghidra.program.model.listing.*;
import java.util.*;

public class DecompileMask2 extends GhidraScript {
    DecompInterface dec;
    Set<String> dumped = new HashSet<>();
    FunctionManager fm;

    String decomp(Function fn) {
        DecompileResults r = dec.decompileFunction(fn, 150, monitor);
        if (r != null && r.decompileCompleted()) return r.getDecompiledFunction().getC();
        return "<decompile failed>";
    }
    void dump(Function fn, String tag) {
        if (fn == null) return;
        String k = fn.getEntryPoint().toString();
        if (dumped.contains(k)) return;
        dumped.add(k);
        println("### FUNC " + fn.getName() + " @ " + k + "  (" + tag + ")");
        println(decomp(fn));
        println("### END\n");
    }
    // BFS up the call graph from a seed function, decompiling each up to depth levels
    void traceUp(Function seed, int depth, String tag) {
        if (seed == null) { println("(no func for " + tag + ")"); return; }
        Deque<Object[]> q = new ArrayDeque<>();
        q.add(new Object[]{seed, 0});
        while (!q.isEmpty()) {
            Object[] e = q.poll();
            Function f = (Function)e[0]; int d = (Integer)e[1];
            dump(f, tag + " depth=" + d);
            if (d < depth) for (Function c : f.getCallingFunctions(monitor)) q.add(new Object[]{c, d+1});
        }
    }
    public void run() throws Exception {
        dec = new DecompInterface();
        dec.openProgram(currentProgram);
        fm = currentProgram.getFunctionManager();
        long[] seeds = {0x100a36fc0L, 0x100a37094L, 0x100349d88L, 0x10013d740L, 0x10013d618L};
        String[] tags = {"deceptium-url-getter","deceptium-caller","welcomes-fn","openURL","didFinishLaunching"};
        for (int i=0;i<seeds.length;i++) {
            println("\n========== SEED " + tags[i] + " @ " + toAddr(seeds[i]) + " ==========");
            Function fn = fm.getFunctionContaining(toAddr(seeds[i]));
            traceUp(fn, 4, tags[i]);
        }
        println("===== DONE =====");
    }
}
