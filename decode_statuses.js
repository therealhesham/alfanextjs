const fs = require('fs');

function decodeDoubleUtf8(str) {
  try {
    // Convert string to bytes representing ISO-8859-1, then interpret those bytes as UTF-8
    const buf = Buffer.from(str, 'binary');
    return buf.toString('utf8');
  } catch (e) {
    return str;
  }
}

function main() {
  const content = fs.readFileSync('schema/system/mq.php', 'binary');
  const lines = content.split('\n');
  
  console.log('Searching and decoding lines with Arabic characters in mq.php...');
  
  // Let's print out lines L1420 to L1435 decoded
  for (let i = 1420; i <= 1435; i++) {
    const rawLine = lines[i - 1];
    const decodedLine = decodeDoubleUtf8(rawLine);
    console.log(`L${i}: ${decodedLine.trim()}`);
  }
}

main();
