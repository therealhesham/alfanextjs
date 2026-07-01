const fs = require('fs');

// Map Windows-1252 character codes to their actual byte values
// Since Windows-1252 maps 0x80-0x9F to Unicode characters like € (0x20AC), we need a mapping table.
const win1252Map = {
  0x20AC: 0x80, 0x201A: 0x82, 0x0192: 0x83, 0x201E: 0x84, 0x2026: 0x85, 0x2020: 0x86, 0x2021: 0x87,
  0x02C6: 0x88, 0x2030: 0x89, 0x0160: 0x8A, 0x2039: 0x8B, 0x0152: 0x8C, 0x017D: 0x8E,
  0x2018: 0x91, 0x2019: 0x92, 0x201C: 0x93, 0x201D: 0x94, 0x2022: 0x95, 0x2013: 0x96, 0x2014: 0x97,
  0x02DC: 0x98, 0x2122: 0x99, 0x0161: 0x9B, 0x203A: 0x9D, 0x0153: 0x9C, 0x017E: 0x9E, 0x0178: 0x9F
};

function stringToWin1252Bytes(str) {
  const bytes = [];
  for (let i = 0; i < str.length; i++) {
    const code = str.charCodeAt(i);
    if (code < 128) {
      bytes.push(code);
    } else if (win1252Map[code] !== undefined) {
      bytes.push(win1252Map[code]);
    } else if (code >= 160 && code <= 255) {
      bytes.push(code);
    } else {
      bytes.push(code & 0xFF); // fallback
    }
  }
  return Buffer.from(bytes);
}

function decodeRecursive(str) {
  let s = str;
  for (let step = 1; step <= 4; step++) {
    const bytes = stringToWin1252Bytes(s);
    try {
      const nextStr = bytes.toString('utf8');
      s = nextStr;
    } catch (e) {
      break;
    }
  }
  return s;
}

function main() {
  const content = fs.readFileSync('schema/system/mq.php', 'utf8');
  const lines = content.split('\n');

  console.log('--- DECODING RULES ---');
  for (let i = 1420; i <= 1435; i++) {
    const line = lines[i - 1];
    const decoded = decodeRecursive(line);
    console.log(`L${i}: ${decoded.trim()}`);
  }
}

main();
