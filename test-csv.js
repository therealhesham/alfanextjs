const fs = require('fs');
const csv = require('csv-parser');
const path = require('path');

const results = [];
fs.createReadStream('export - عقد الصيانة - Grid.csv')
  .pipe(csv({ mapHeaders: ({ header }) => header.trim() }))
  .on('data', (data) => results.push(data))
  .on('end', () => {
    for (let i = 0; i < 5; i++) {
      const row = results[i];
      if (row['العميل']) {
        const clientRaw = row['العميل'].replace(/^'/, '');
        const parts = clientRaw.split(',');
        const phone = parts[0]?.trim();
        const name = parts.slice(1).join(',')?.trim() || null;
        console.log(`Row ${i+1}: phone='${phone}', name='${name}'`);
      }
    }
  });
