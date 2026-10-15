// Frida gadget SCRIPT-MODE recon for Supercell anti-tamper. Runs automatically at launch (no
// listener, no wait), installs hooks on the detection primitives, and writes every call with a
// symbolized backtrace to the app's own Library/Caches/RuStoreRecon/recon.log, which we pull
// over USB afterwards. Goal: find the integrity-check site that poisons the thread
// (pc=lr=sp=0x123456789a00) before it fires.
'use strict';

var logPath = null;
try {
  var home = Memory.allocUtf8String("HOME");
  var getenv = new NativeFunction(Module.getExportByName(null, 'getenv'), 'pointer', ['pointer']);
  var h = getenv(home);
  var base = h.isNull() ? '/tmp' : h.readUtf8String();
  logPath = base + '/Library/Caches/RuStoreRecon';
  var mk = new NativeFunction(Module.getExportByName(null, 'mkdir'), 'int', ['pointer', 'int']);
  mk(Memory.allocUtf8String(logPath), 0x1ff);
  logPath = logPath + '/recon.log';
} catch (e) { logPath = '/tmp/recon.log'; }

var logFile = new File(logPath, 'a');
function w(line) {
  try { logFile.write(line + '\n'); logFile.flush(); } catch (e) {}
}
w('=== recon start ' + new Date().toISOString() + ' pid=' + Process.id + ' ===');

function modOff(a) {
  var m = Process.findModuleByAddress(a);
  return m ? (m.name + '+0x' + a.sub(m.base).toString(16)) : a.toString();
}
function bt(ctx) {
  var out = [];
  try {
    var f = Thread.backtrace(ctx, Backtracer.FUZZY);
    for (var i = 0; i < f.length && out.length < 16; i++) out.push(modOff(f[i]));
  } catch (e) {}
  return out;
}
function rec(tag, extra, ctx) {
  w('[' + tag + '] ' + (extra || ''));
  var frames = bt(ctx);
  for (var i = 0; i < frames.length; i++) w('    ' + frames[i]);
}

function hook(name, onEnter) {
  var p = Module.findExportByName(null, name);
  if (!p) { w('hook-miss ' + name); return; }
  try { Interceptor.attach(p, { onEnter: onEnter }); w('hook-ok ' + name); }
  catch (e) { w('hook-err ' + name + ' ' + e); }
}

hook('ptrace', function (a) { rec('ptrace', 'req=' + a[0].toInt32(), this.context); });
hook('syscall', function (a) { var n = a[0].toInt32(); if (n === 26 || n === 185) rec('syscall', 'n=' + n, this.context); });
hook('csops', function (a) { rec('csops', 'pid=' + a[0].toInt32() + ' ops=' + a[1].toInt32(), this.context); });
hook('csops_audittoken', function (a) { rec('csops_audittoken', 'ops=' + a[1].toInt32(), this.context); });
hook('sysctl', function (a) {
  var p = [];
  try { for (var i = 0; i < Math.min(a[1].toInt32(), 4); i++) p.push(a[0].add(i * 4).readU32()); } catch (e) {}
  rec('sysctl', 'mib=' + p.join(','), this.context);
});
hook('sysctlbyname', function (a) { try { rec('sysctlbyname', a[0].readUtf8String(), this.context); } catch (e) {} });
hook('getppid', function (a) { rec('getppid', '', this.context); });
['exit', '_exit', 'abort', 'kill', 'pthread_kill', 'raise', 'exit_group', '__assert_rtn'].forEach(function (fn) {
  hook(fn, function (a) { rec('KILL:' + fn, '', this.context); });
});
hook('dlopen', function (a) { try { rec('dlopen', a[0].isNull() ? '(null)' : a[0].readUtf8String(), this.context); } catch (e) {} });
hook('open', function (a) { try { var p = a[0].readUtf8String(); if (p && /frida|cydia|substrate|jailbreak|\/var\/jb|cycript|RuStore|\/bin\/|MobileSubstrate/i.test(p)) rec('open', p, this.context); } catch (e) {} });
hook('stat', function (a) { try { var p = a[0].readUtf8String(); if (p && /frida|cydia|substrate|jailbreak|\/var\/jb|cycript|RuStore/i.test(p)) rec('stat', p, this.context); } catch (e) {} });
hook('_dyld_get_image_name', function (a) { this.i = a[0].toInt32(); });

Process.setExceptionHandler(function (d) {
  w('[EXCEPTION] ' + d.type + ' pc=' + d.context.pc + ' x30=' + (d.context.lr || '?') + ' sp=' + d.context.sp);
  try { var f = Thread.backtrace(d.context, Backtracer.FUZZY); for (var i = 0; i < f.length && i < 20; i++) w('    ' + modOff(f[i])); } catch (e) {}
  return false;
});

var mods = Process.enumerateModules().filter(function (m) { return /laser|Scid|Recaptcha/i.test(m.name); });
mods.forEach(function (m) { w('MODULE ' + m.name + ' @ ' + m.base + ' sz=0x' + m.size.toString(16)); });
w('=== hooks installed, resuming ===');
