const fs = require('fs');

function decodeDoubleUtf8(buffer) {
  // Try to interpret the buffer as ISO-8859-1 (binary) text, then convert it to utf8
  // If it's double UTF-8, the raw bytes contain the UTF-8 representation of the corrupted text.
  // Let's decode it:
  let str = buffer.toString('utf8');
  
  // Convert characters back to bytes
  let bytes = [];
  for (let i = 0; i < str.length; i++) {
    bytes.push(str.charCodeAt(i) & 0xFF);
  }
  
  let decoded1 = Buffer.from(bytes).toString('utf8');
  
  // Try again
  let bytes2 = [];
  for (let i = 0; i < decoded1.length; i++) {
    bytes2.push(decoded1.charCodeAt(i) & 0xFF);
  }
  
  let decoded2 = Buffer.from(bytes2).toString('utf8');

  // Let's try one more time
  let bytes3 = [];
  for (let i = 0; i < decoded2.length; i++) {
    bytes3.push(decoded2.charCodeAt(i) & 0xFF);
  }
  let decoded3 = Buffer.from(bytes3).toString('utf8');

  return { decoded1, decoded2, decoded3 };
}

function main() {
  const content = fs.readFileSync('schema/system/mq.php');
  
  // Let's find L1420 to L1435
  // We split by newline byte (0x0A)
  const lines = [];
  let currentLine = [];
  for (let i = 0; i < content.length; i++) {
    if (content[i] === 0x0A) {
      lines.push(Buffer.from(currentLine));
      currentLine = [];
    } else {
      if (content[i] !== 0x0D) { // Skip CR
        currentLine.push(content[i]);
      }
    }
  }
  if (currentLine.length > 0) {
    lines.push(Buffer.from(currentLine));
  }

  console.log(`Extracted ${lines.length} lines.`);
  for (let i = 1420; i <= 1435; i++) {
    const lineBuf = lines[i - 1];
    const dec = decodeDoubleUtf8(lineBuf);
    console.log(`L${i} RAW UTF8: ${lineBuf.toString('utf8').trim()}`);
    console.log(`L${i} DEC1: ${dec.decoded1.trim()}`);
    console.log(`L${i} DEC2: ${dec.decoded2.trim()}`);
    console.log(`L${i} DEC3: ${dec.decoded3.trim()}`);
    console.log('---');
  }
}

main();
