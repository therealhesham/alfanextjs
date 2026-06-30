import { PrismaClient } from '@prisma/client';
import fs from 'fs';
import csv from 'csv-parser';
import path from 'path';

const prisma = new PrismaClient();

async function main() {
  const results: any[] = [];
  const csvFilePath = path.join(process.cwd(), 'export - عقد الصيانة - Grid.csv');

  console.log('Reading CSV from:', csvFilePath);

  if (!fs.existsSync(csvFilePath)) {
    console.error('File not found:', csvFilePath);
    process.exit(1);
  }

  await new Promise((resolve, reject) => {
    fs.createReadStream(csvFilePath)
      .pipe(csv({
        mapHeaders: ({ header }) => header.trim()
      }))
      .on('data', (data) => results.push(data))
      .on('end', () => resolve(true))
      .on('error', reject);
  });

  console.log(`Parsed ${results.length} rows. Beginning database insertion...`);
  
  let successCount = 0;
  let skippedCount = 0;
  let errorCount = 0;

  for (let i = 0; i < results.length; i++) {
    const row = results[i];
    try {
      const barcodeId = row['barcodeId']?.trim();
      if (!barcodeId) {
        console.log(`Row ${i + 1}: Skipped (No barcodeId)`);
        skippedCount++;
        continue;
      }

      // 1. Process User (Client)
      let clientUserId = null;
      if (row['العميل']) {
        const clientRaw = row['العميل'].replace(/^'/, ''); // remove leading quote like '+966...
        const parts = clientRaw.split(',');
        let phone = parts[0]?.trim();
        const name = parts.slice(1).join(',')?.trim() || null;

        if (phone) {
          if (phone.startsWith('+966')) {
            phone = phone.substring(4);
          } else if (phone.startsWith('966')) {
            phone = phone.substring(3);
          } else if (phone.startsWith('05')) {
            phone = phone.substring(1);
          }
          
          const user = await prisma.users.upsert({
            where: { phone_number: phone },
            update: { name: name || undefined },
            create: { phone_number: phone, name }
          });
          clientUserId = user.id;
        }
      }

      // 1.5 Process Technician (الموظف)
      let technicianUserId = null;
      if (row['الموظف']) {
        const techRaw = row['الموظف'].replace(/^'/, '');
        const parts = techRaw.split(',');
        let phone = parts[0]?.trim();
        const name = parts.slice(1).join(',')?.trim() || null;

        if (phone) {
          if (phone.startsWith('+966')) {
            phone = phone.substring(4);
          } else if (phone.startsWith('966')) {
            phone = phone.substring(3);
          } else if (phone.startsWith('05')) {
            phone = phone.substring(1);
          }
          
          const user = await prisma.users.upsert({
            where: { phone_number: phone },
            update: { name: name || undefined },
            create: { phone_number: phone, name }
          });
          technicianUserId = user.id;
        }
      }

      // 2. Process Project
      let projectId = null;
      if (row['المشروع']) {
        const projectName = row['المشروع'].trim();
        if (projectName) {
          let project = await prisma.projects.findFirst({
            where: { name: projectName }
          });
          if (!project) {
            project = await prisma.projects.create({
              data: { name: projectName }
            });
          }
          projectId = project.id;
        }
      }

      // 3. Process Group
      let groupId = null;
      if (row['المجموعة']) {
        const groupName = row['المجموعة'].trim();
        if (groupName) {
          let group = await prisma.groups.findFirst({
            where: { name: groupName }
          });
          if (!group) {
            group = await prisma.groups.create({
              data: { name: groupName, is_active: true }
            });
          }
          groupId = group.id;
        }
      }

      // 4. Dates
      const parseDate = (dateStr: string) => {
        if (!dateStr) return null;
        const parts = dateStr.split('/');
        if (parts.length === 3) {
          return new Date(`${parts[2]}-${parts[1]}-${parts[0]}`); // YYYY-MM-DD
        }
        return null;
      };

      const startDate = parseDate(row['بداية العقد']);
      const endDate = parseDate(row['نهاية العقد']);

      // 5. Amount
      const totalAmount = parseFloat(row['الاجمالي']) || 0;

      // 6. Booleans
      const isHidden = row['isHidden']?.toLowerCase() === 'true';
      
      let isActive = true;
      const validStatus = row['ساري / منتهي']?.trim();
      if (validStatus === 'منتهي') {
        isActive = false;
      } else if (validStatus === 'ساري') {
        isActive = true;
      } else if (endDate) {
        isActive = endDate >= new Date();
      }

      // 7. Contract Type / Guarantee
      const contractTypeStr = row['صيانة/ ضمان']?.trim();
      const isGuarantee = contractTypeStr === 'ضمان';
      const contractType = 'preventive'; // Default to preventive since it's unused now
      
      const ownerApproved = row['هل وافق المالك؟']?.toLowerCase() === 'true';
      const clientApproved = row['هل وافق العميل']?.toLowerCase() === 'true';
      
      const baserowRowId = parseInt(row['id'], 10) || null;

      // Ensure location exists and is within limits (sometimes CSV location can be huge or empty)
      const location = row['اللوكيشن']?.trim() || null;

      // UPSERT Contract
      await prisma.maintenance_contracts.upsert({
        where: { barcode_id: barcodeId },
        update: {
          project_id: projectId,
          group_id: groupId,
          client_user_id: clientUserId,
          technician_user_id: technicianUserId,
          start_date: startDate,
          end_date: endDate,
          total_amount: totalAmount,
          is_active: isActive,
          is_guarantee: isGuarantee,
          is_hidden: isHidden,
          owner_approved: ownerApproved,
          client_approved: clientApproved,
          contract_type: contractType as any,
          location: location,
          baserow_row_id: baserowRowId,
        },
        create: {
          barcode_id: barcodeId,
          project_id: projectId,
          group_id: groupId,
          client_user_id: clientUserId,
          technician_user_id: technicianUserId,
          start_date: startDate,
          end_date: endDate,
          total_amount: totalAmount,
          is_active: isActive,
          is_guarantee: isGuarantee,
          is_hidden: isHidden,
          owner_approved: ownerApproved,
          client_approved: clientApproved,
          contract_type: contractType as any,
          location: location,
          baserow_row_id: baserowRowId,
        }
      });
      
      successCount++;
    } catch (e) {
      console.error(`Row ${i + 1}: Error processing barcodeId ${row['barcodeId']}`, e);
      errorCount++;
    }
  }

  console.log('Seeding finished!');
  console.log(`Success: ${successCount}`);
  console.log(`Skipped: ${skippedCount}`);
  console.log(`Errors: ${errorCount}`);
}

main()
  .catch((e) => {
    console.error('Fatal error during seeding:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
