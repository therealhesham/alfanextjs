const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function main() {
  try {
    const alterQuery = `
      ALTER TABLE elevator_quotes 
      MODIFY COLUMN status_enum ENUM(
        'اعتماد العرض من الادارة',
        'اعتماد العرض من العميل',
        'اعتماد العقد من الادارة',
        'اعتماد العقد من العميل',
        'سداد الدفعة الاولى',
        'طلب مواد المرحلة الاولى',
        'تسليم مواد المرحلة الأولى',
        'استلام مواد المرحلة الأولى',
        'محضر استلام المرحلة الأولى',
        'سداد الدفعة الثانية',
        'طلب مواد المرحلة الثانية',
        'تسليم مواد المرحلة الثانية',
        'استلام مواد المرحلة الثانية',
        'محضر استلام المرحلة الثانية',
        'سداد الدفعة الثالثة',
        'طلب مواد المرحلة الثالثة',
        'تسليم مواد المرحلة الثالثة',
        'استلام مواد المرحلة الثالثة',
        'محضر استلام المرحلة الثالثة',
        'سداد الدفعة الرابعة',
        'طلب مواد المرحلة الرابعة',
        'تسليم مواد المرحلة الرابعة',
        'استلام مواد المرحلة الرابعة',
        'محضر استلام المرحلة الرابعة',
        'صيانة الضمان',
        'ملغي'
      ) NULL DEFAULT NULL
    `;
    await prisma.$executeRawUnsafe(alterQuery);
    console.log('ALTER TABLE status_enum succeeded!');
    
    const columns = await prisma.$queryRawUnsafe("SHOW COLUMNS FROM elevator_quotes LIKE 'status_enum'");
    console.log('New status_enum definition:', columns);
  } catch (err) {
    console.error('Error during ALTER TABLE:', err);
  } finally {
    await prisma.$disconnect();
  }
}

main();
