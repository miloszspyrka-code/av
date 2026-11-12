// Shared UTF-8 / mojibake detection and repair for Alpha Vault.
//
// Some historical commits embedded UTF-8 bytes decoded as Windows-1250 and
// re-encoded as UTF-8 (e.g. "WartoĹ›Ä‡" instead of "Wartość"). This helper
// reverses that transformation per non-ASCII run without touching text that
// is already valid.

const cp1250 = new TextDecoder('windows-1250');
const cp1250Bytes = new Map();
for (let byte = 0; byte < 256; byte++) {
  const char = cp1250.decode(Uint8Array.of(byte));
  if (char && !cp1250Bytes.has(char)) cp1250Bytes.set(char, byte);
}

const utf8 = new TextDecoder('utf-8', { fatal: true });
const MOJIBAKE_LEAD = /[ĹĂÄÂÅâÃ]/;

function repairRun(run) {
  const bytes = new Uint8Array(run.length);
  for (let i = 0; i < run.length; i++) {
    const byte = cp1250Bytes.get(run[i]);
    if (byte === undefined) return null;
    bytes[i] = byte;
  }
  try {
    return utf8.decode(bytes);
  } catch {
    return null;
  }
}

/** Reverse UTF-8 -> Windows-1250 mojibake in a single line. */
export function repairMojibake(line) {
  return line.replace(/[^\x00-\x7F]+/g, (run) => {
    if (!MOJIBAKE_LEAD.test(run)) return run;
    return repairRun(run) ?? run;
  });
}

/** True when the line still carries characters typical for the corruption. */
export function hasMojibake(line) {
  return repairMojibake(line) !== line;
}
