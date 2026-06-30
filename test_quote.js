const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function main() {
  const quote = await prisma.elevator_quotes.findFirst({
    orderBy: { id: 'desc' }
  });
  console.log(quote);
}

main().catch(console.error).finally(() => prisma.$disconnect());
