const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function main() {
  const quoteData = {
      client_user_id: 1,
      created_by_user_id: 1, 
      number_of_elevators: 1,
      machine_type: 'Test',
      total_price: "5000",
      status_enum: 'MANAGEMENT_QUOTE_APPROVAL',
  };

  const newQuote = await prisma.elevator_quotes.create({
    data: {
      ...quoteData,
      total_price: quoteData.total_price ? parseFloat(quoteData.total_price) : null,
    }
  });

  console.log('Created:', newQuote.id, newQuote.total_price);
}

main().catch(console.error).finally(() => prisma.$disconnect());
