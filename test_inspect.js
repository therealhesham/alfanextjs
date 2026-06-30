const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function main() {
  try {
    const counts = {
      maintenance_contracts: await prisma.maintenance_contracts.count(),
      elevator_quotes: await prisma.elevator_quotes.count(),
      users: await prisma.users.count(),
      projects: await prisma.projects.count(),
      brands: await prisma.brands.count(),
      quote_statuses: await prisma.quote_statuses.count(),
    };
    console.log('Database Table Counts:', counts);
  } catch (err) {
    console.error('Error:', err);
  } finally {
    await prisma.$disconnect();
  }
}

main();
