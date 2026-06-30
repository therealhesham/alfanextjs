const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function main() {
  try {
    const count = await prisma.quote_statuses.count();
    const statuses = await prisma.quote_statuses.findMany();
    console.log('quote_statuses count:', count);
    console.log('quote_statuses:', statuses);
  } catch (err) {
    console.error('Error:', err);
  } finally {
    await prisma.$disconnect();
  }
}

main();
