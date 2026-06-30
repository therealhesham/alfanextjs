const fs = require('fs');
const readline = require('readline');

const fileStream = fs.createReadStream('export - عقد الصيانة - Grid.csv');

const rl = readline.createInterface({
  input: fileStream,
  crlfDelay: Infinity
});

let i = 0;
rl.on('line', (line) => {
  if (i < 3) {
    console.log(`Line ${i}:`, line);
  }
  i++;
  if (i >= 3) rl.close();
});
