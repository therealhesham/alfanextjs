import { prisma } from '@/lib/prisma';

export interface QuoteDTO {
  id: string;
  clientName: string;
  clientId: string | null;
  date: Date | null;
  totalPrice: string;
  generatedPdfUrl: string | null;
  brandName: string;
  brandId: string | null;
  creatorName: string;
  createdById: string | null;
  quoteNumber: string;
  approvalTime: Date | null;
  rejectionReason: string | null;
  statusName: string;
  statusId: string | null;
}

export async function getQuotesMapped(page: number = 1, limit: number = 20): Promise<QuoteDTO[]> {
  const skip = (page - 1) * limit;
  const quotes = await prisma.elevator_quotes.findMany({
    skip,
    take: limit,
    include: {
      users_elevator_quotes_client_user_idTousers: true, // Client
      users_elevator_quotes_created_by_user_idTousers: true, // Created By
      brands: true, // Brand
      quote_statuses: true, // Status
    },
    orderBy: {
      created_at: 'desc',
    },
  });

  return quotes.map((quote) => ({
    id: quote.id?.toString() || '',
    clientName: quote.users_elevator_quotes_client_user_idTousers?.name || 'غير محدد',
    clientId: quote.client_user_id?.toString() || null,
    date: quote.created_at,
    totalPrice: quote.total_price?.toString() || '0',
    generatedPdfUrl: quote.pdf_url || null,
    brandName: quote.brands?.name || 'غير محدد',
    brandId: quote.brand_id?.toString() || null,
    creatorName: quote.users_elevator_quotes_created_by_user_idTousers?.name || 'غير محدد',
    createdById: quote.created_by_user_id?.toString() || null,
    quoteNumber: quote.quote_number?.toString() || quote.id?.toString() || '',
    approvalTime: quote.approval_time || null,
    rejectionReason: quote.rejection_reason || null,
    statusName: quote.quote_statuses?.name || 'لا يوجد حالة',
    statusId: quote.quote_status_id?.toString() || null,
  }));
}
