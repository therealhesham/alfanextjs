const fs = require('fs');

function main() {
  const content = fs.readFileSync('schema/system/mq.php', 'utf8');
  const lines = content.split('\n');
  
  console.log('Searching for statuses in mq.php...');
  
  // Find lines containing common status keywords
  const matches = [];
  lines.forEach((line, idx) => {
    // Check for Arabic terms indicating stage/status or Phase
    if (line.includes('المرحلة') || line.includes('استلام') || line.includes('حالة') || line.includes('status')) {
      matches.push({ lineNum: idx + 1, content: line.trim() });
    }
  });

  console.log(`Found ${matches.length} matching lines. Displaying first 100...`);
  matches.slice(0, 100).forEach(m => {
    console.log(`L${m.lineNum}: ${m.content}`);
  });
}

main();
